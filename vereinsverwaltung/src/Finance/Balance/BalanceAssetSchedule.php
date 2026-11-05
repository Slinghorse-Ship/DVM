<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceDisposalAccounts.php';
require_once __DIR__.'/BalanceCostAssetSources.php';
require_once __DIR__.'/BalancePartnershipBookSources.php';
require_once __DIR__.'/BalanceOwnIntangibleSources.php';

/** Retained-cost rollforward for invoice-linked, supported asset plans. Not an XBRL filing. */
final class BalanceAssetSchedule
{
    public function __construct(private PDO $db,private string $actorId){}

    public function build(int $year):array
    {
        BalanceCalculation::year($year);$actor=Permissions::actor($this->db,$this->actorId);
        if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $report=(new BalanceLedger($this->db,$this->actorId))->report($year);
            if($report['state']==='closed'){
                $out=$report['asset_schedule']??['year'=>$year,'items'=>[],'totals'=>[],'issues'=>['Zu diesem älteren Abschluss wurde noch kein Anlagenverzeichnis festgeschrieben.'],'ready_for_xbrl'=>false];
            }else{
                $sources=new BalanceSources($this->db,$this->actorId);$acquisitions=[];$annual=[];$disposals=[];$known=[];$issues=[];
                $rows=DocumentCatalog::query($this->db,"SELECT source_type,source_id,tax_year FROM balance_sources WHERE club_id=? AND source_type IN ('invoice','depreciation') AND tax_year<=? ORDER BY tax_year,source_type,source_id LIMIT 10001",[$actor['club_id'],$year])->fetchAll();
                if(count($rows)>10000)throw new DomainException('Zu viele Quellen für ein gemeinsames Anlagenverzeichnis.');
                foreach($rows as $row){
                    $b=$sources->binding($row['source_type'],$row['source_id']);
                    if($row['source_type']==='invoice'&&empty($b['snapshot']['asset_accounts']))continue;
                    if($row['source_type']==='depreciation'){
                        [, $disposal]=AssetDisposal::unpack(json_decode($b['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR));
                        if($disposal&&TaxCatalog::documentYear($disposal['date'])===(int)$row['tax_year']){
                            $itemId=$b['snapshot']['annual']['item_id'];
                            if(isset($disposals[$itemId]))throw new DomainException('Für einen Gegenstand sind mehrere Ausmusterungen gespeichert. Bestand prüfen.');
                            $disposals[$itemId]=$b;
                        }
                        elseif(!$disposal&&(int)$row['tax_year']<$year)continue;
                    }
                    $preview=$sources->preview((int)$b['tax_year'],$row['source_type'],$row['source_id'],$b['options']);
                    if($preview['issues']||!hash_equals($b['source_hash'],$preview['hash']))$issues[]=$row['source_id'].': Anschaffung oder Abschreibungsnachweis wurde verändert.';
                    try{
                        $history=(new BalanceSourceHistory($this->db,$this->actorId))->checked($b);
                        foreach($history as $revision)foreach(array_merge($revision['payload']['entry_ids'],$revision['payload']['reversal_ids']) as $id)$known[$id]=true;
                        foreach($b['entry_ids'] as $id)$known[$id]=true;
                    }catch(DomainException|\JsonException){$issues[]=$row['source_id'].': Belegverlauf des Gegenstands prüfen.';}
                    if($row['source_type']==='invoice')$acquisitions[]=$b;
                    elseif((int)$row['tax_year']===$year)$annual[$b['snapshot']['annual']['item_id']]=$b;
                }
                $ownPlans=[];
                foreach(DocumentCatalog::query($this->db,'SELECT item_id,payload FROM asset_book_plans WHERE club_id=? ORDER BY item_id',[$actor['club_id']])->fetchAll() as $row){
                    $plan=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                    if(($plan['method']??null)===OwnIntangiblePlans::METHOD&&(int)substr($plan['acquired_on'],0,4)<=$year){OwnIntangiblePlans::original($plan);$ownPlans[$row['item_id']]=$plan;}
                }
                $out=self::calculate($year,$report['calculation'],$acquisitions,$annual,$disposals,$ownPlans);
                foreach($out['items'] as $id=>&$item){
                    $inventory=DocumentCatalog::query($this->db,'SELECT code,name FROM inventory_items WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch();
                    $item['name']=$inventory?($inventory['code'].' · '.$inventory['name']):$id;
                    foreach($out['issues'] as &$issue)if(str_starts_with($issue,$id.': '))$issue=$item['name'].substr($issue,strlen($id));unset($issue);
                }unset($item);
                foreach($report['entries'] as $entry)if(!isset($known[$entry['id']])){
                    foreach($entry['lines'] as $line)if(($report['accounts'][$line['account']]['group']??'')==='fixed_assets'){
                        $issues[]='Nicht beleggebundene Anlagenbewegung: '.$entry['description'].'. Zugang, Abgang oder Umbuchung gesondert abstimmen.';break;
                    }
                }
                $out['issues']=array_values(array_unique(array_merge($issues,$out['issues'])));
            }
            if($own)$this->db->commit();return $out;
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public static function calculate(int $year,?array $calculation,array $acquisitions,array $annual,array $disposals=[],array $ownPlans=[]):array
    {
        BalanceCalculation::year($year);
        $fields=['cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents'];
        $costAssets=false;$exceptional=false;
        foreach($annual as $b)if(isset($b['snapshot']['exceptional']))$exceptional=true;
        $partnerships=false;
        foreach($acquisitions as $acquisition)foreach($acquisition['snapshot']['assets'] as $asset){$plan=json_decode($asset['payload']??'null',true,64,JSON_THROW_ON_ERROR);if(($plan['method']??null)==='cost')$costAssets=true;if(($plan['method']??null)===PartnershipBookPlans::METHOD)$partnerships=true;}
        foreach($annual as $id=>$binding){
            [, $proof]=AssetDisposal::unpack(json_decode($binding['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR));
            if($proof&&TaxCatalog::documentYear($proof['date'])===$year)$disposals[$id]=$binding;
            elseif($proof&&!isset($disposals[$id]))throw new DomainException('Ursprünglicher Teilabgang fehlt im Anlagenverlauf.');
        }
        if($disposals||$costAssets||$partnerships||$ownPlans)$fields=array_merge($fields,['disposals_cents','accumulated_disposals_cents','book_disposals_cents']);
        if($costAssets||$partnerships||$ownPlans||$exceptional)$fields=array_merge($fields,['impairment_cents','writeup_cents']);
        $out=['year'=>$year,'items'=>[],'totals'=>array_fill_keys($fields,0),'issues'=>[],'ready_for_xbrl'=>false];$byAccount=[];
        if(!$calculation){$out['issues'][]='Bilanzjournal und Anfangsbestände fehlen.';return $out;}
        foreach($acquisitions as $binding){
            $snapshot=$binding['snapshot'];
            foreach($snapshot['assets'] as $asset){
                $id=$asset['item_id'];$account=$snapshot['asset_accounts'][$id]??null;if(!$account)continue;
                if(isset($out['items'][$id]))throw new DomainException('Gegenstand im Anlagenverzeichnis doppelt zugeordnet.');
                $plan=json_decode($asset['payload'],true,64,JSON_THROW_ON_ERROR);
                if(($plan['method']??null)==='substance_original'){require_once __DIR__.'/BalanceSubstanceSources.php';if(!isset($annual[$id])){$out['issues'][]=$id.': Ursprüngliche Jahresmenge fehlt.';continue;}$item=BalanceSubstanceSources::item($plan,$binding,$id,$account,$year,$annual[$id]);$out['items'][$id]=$item;foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $key=>$field)$byAccount[$account][$key]=($byAccount[$account][$key]??0)+$item[$field];continue;}
                if(isset($annual[$id]['snapshot']['exceptional'])){
                    require_once __DIR__.'/BalanceExceptionalSources.php';$item=BalanceExceptionalSources::item($plan,$binding,$id,$account,$year,$annual[$id]);
                    $out['items'][$id]=$item;foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;
                    foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $key=>$field)$byAccount[$account][$key]=($byAccount[$account][$key]??0)+$item[$field];continue;
                }
                if(($plan['method']??null)===PartnershipBookPlans::METHOD){
                    $item=BalancePartnershipBookSources::item($plan,$binding,$id,$account,$year,$annual[$id]??null);
                    if($item['partnership_book']===null)$out['issues'][]=$id.': Ursprüngliches HGB-Beteiligungsregister für dieses Abschlussjahr noch nicht bestätigt.';
                    $out['items'][$id]=$item;foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;
                    foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $key=>$field)$byAccount[$account][$key]=($byAccount[$account][$key]??0)+$item[$field];continue;
                }
                if(($plan['method']??null)==='cost'){
                    $item=BalanceCostAssetSources::item($plan,$binding,$id,$account,$year,$annual[$id]??null);
                    if($item['valuation']===null)$out['issues'][]=$id.': Bewertung und Buch-/Steuerwerte für dieses Abschlussjahr noch nicht bestätigt.';
                    $out['items'][$id]=$item;foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;
                    foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $key=>$field)$byAccount[$account][$key]=($byAccount[$account][$key]??0)+$item[$field];
                    continue;
                }
                $gwg=is_array($plan)&&($plan['method']??'')==='gwg_immediate';
                if(!is_array($plan)||!in_array($plan['method']??'',['linear_monthly','gwg_immediate','degressive'],true)||!is_int($plan['basis_cents']??null)||$plan['basis_cents']<=0||$plan['basis_cents']>BalanceCalculation::LIMIT
                    ||!is_int($plan['residual_cents']??null)||$plan['residual_cents']<0||$plan['residual_cents']>=$plan['basis_cents']
                    ||!is_int($plan['months']??null)||(!$gwg&&($plan['months']<12||$plan['months']>1200||$plan['months']%12!==0))
                    ||!is_string($plan['acquired_on']??null)||!TaxCatalog::documentYear($plan['acquired_on']))throw new DomainException('Gespeicherten Abschreibungsplan prüfen.');
                $start=TaxCatalog::documentYear($plan['acquired_on']);if($start>$year)throw new DomainException('Künftige Anschaffung im Anlagenverzeichnis.');
                $proof=null;$disposalYear=null;
                if(isset($disposals[$id])){
                    $event=$disposals[$id]['snapshot']['annual'];
                    [$eventPlan,$proof]=AssetDisposal::unpack(json_decode($event['snapshot'],true,512,JSON_THROW_ON_ERROR));
                    if(!$proof||$eventPlan!==$plan)throw new DomainException('Ausmusterung passt nicht zum Anschaffungsplan.');
                    AssetDisposal::check($plan,$proof);$disposalYear=TaxCatalog::documentYear($proof['date']);
                    if(($event['item_id']??null)!==$id||(int)$event['year']!==$disposalYear||$disposalYear>$year)throw new DomainException('Gegenstand und Ausmusterungsjahr prüfen.');
                    $check=AssetDisposal::schedule($plan,$disposalYear,$proof);
                    foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)if((int)$event[$key]!==$check[$key])throw new DomainException('Festgestellter Anlagenabgang ist nicht abgestimmt.');
                }
                $schedule=AssetDisposal::schedule($plan,$year,$proof);$record=$annual[$id]['snapshot']['annual']??null;
                $posted=0;
                if($record){
                    [$recordPlan,$recordDisposal]=AssetDisposal::unpack(json_decode($record['snapshot'],true,512,JSON_THROW_ON_ERROR));
                    $partial=($proof['kind']??null)==='partial_scrap';
                    $same=$recordPlan===$plan&&$recordDisposal===($disposalYear===$year||$partial?$proof:null)&&(int)$record['year']===$year&&($disposalYear===null||$disposalYear===$year||$partial);
                    foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)$same=$same&&(int)$record[$key]===$schedule[$key];
                    if(!$same)$out['issues'][]=$id.': Festgestellte Abschreibung passt nicht zum Anschaffungsplan.';
                    else $posted=$schedule['depreciation_cents'];
                }elseif($schedule['depreciation_cents']>0)$out['issues'][]=$id.': Jahresabschreibung von '.ContributionRates::label($schedule['depreciation_cents']).' noch nicht in die Bilanz übernommen.';
                $previouslyDisposed=$disposalYear!==null&&$disposalYear<$year;
                $costOpen=$start<$year&&!$previouslyDisposed?$plan['basis_cents']:0;$bookOpen=$start<$year?$schedule['opening_cents']:0;
                $item=['item_id'=>$id,'invoice_id'=>$binding['source_id'],'receipt_number'=>$snapshot['entry']['display_number'],'account'=>$account,'skr_code'=>$plan['account'],'sphere'=>$plan['sphere'],
                    'acquired_on'=>$plan['acquired_on'],'months'=>$plan['months'],'residual_cents'=>$plan['residual_cents'],
                    'cost_opening_cents'=>$costOpen,'additions_cents'=>$start===$year?$plan['basis_cents']:0,'cost_closing_cents'=>$plan['basis_cents'],
                    'accumulated_opening_cents'=>$costOpen-$bookOpen,'depreciation_cents'=>$posted,'accumulated_closing_cents'=>$costOpen-$bookOpen+$posted,
                    'book_opening_cents'=>$bookOpen,'book_closing_cents'=>$plan['basis_cents']-($costOpen-$bookOpen+$posted),'expected_depreciation_cents'=>$schedule['depreciation_cents'],
                    'acquisition_hash'=>$binding['source_hash'],'depreciation_hash'=>$annual[$id]['source_hash']??null];
                AssetOriginalDepreciation::code($plan);
                if(array_key_exists('depreciation_code',$plan))$item['depreciation_code']=$plan['depreciation_code'];
                if(isset($plan['original_leasing']))$item['original_leasing']=$plan['original_leasing'];
                if($gwg){$item['method']='gwg_immediate';$item['gwg']=$plan['gwg'];}
                elseif($plan['method']==='degressive'){$item['method']='degressive';$item['degressive']=$plan['degressive'];}
                if($disposals){
                    $item['disposals_cents']=$disposalYear===$year?$plan['basis_cents']:0;
                    $item['accumulated_disposals_cents']=$disposalYear===$year?$costOpen-$bookOpen+$posted:0;
                    $item['book_disposals_cents']=$item['disposals_cents']-$item['accumulated_disposals_cents'];
                    if($proof){
                        $item['basis_cents']=$plan['basis_cents'];$item['disposal']=$proof;$item['disposal_hash']=$disposals[$id]['source_hash'];
                        if(array_key_exists('disposal_accounts',$disposals[$id]['snapshot']))$item['disposal_accounts']=$disposals[$id]['snapshot']['disposal_accounts'];
                        $item['cost_closing_cents']=0;
                        $item['accumulated_closing_cents']=0;
                        $item['book_closing_cents']=0;
                        if($proof['kind']==='partial_scrap'){
                            $values=AssetPartialDisposal::preview($plan,$proof,$year)['book'];
                            foreach($fields as $field)$item[$field]=$values[$field];
                            $item['partial_plan']=$plan;
                        }
                    }
                }
                $out['items'][$id]=$item;foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;
                $byAccount[$account]['opening']=($byAccount[$account]['opening']??0)+$bookOpen;
                $byAccount[$account]['closing']=($byAccount[$account]['closing']??0)+$item['book_closing_cents'];
            }
        }
        foreach($ownPlans as $id=>$plan){
            if(isset($out['items'][$id]))throw new DomainException('Entwicklungsprojekt ist zusätzlich einer Anschaffungsrechnung zugeordnet. Original prüfen.');
            if(!isset($annual[$id])){$out['issues'][]=$id.': Originales HGB-Entwicklungsregister für dieses Jahr noch nicht vollständig ins Bilanzjournal übernommen.';continue;}
            $item=BalanceOwnIntangibleSources::item($plan,$annual[$id],$year);$account=$item['account'];$out['items'][$id]=$item;
            foreach($fields as $field)$out['totals'][$field]+=$item[$field]??0;
            foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $key=>$field)$byAccount[$account][$key]=($byAccount[$account][$key]??0)+$item[$field];
        }
        foreach($annual as $id=>$binding)if(!isset($out['items'][$id]))$out['issues'][]=$id.': Abschreibung ohne zugehörige Anschaffung im Anlagenverzeichnis.';
        foreach($disposals as $id=>$binding)if(!isset($out['items'][$id]))$out['issues'][]=$id.': Ausmusterung ohne zugehörige Anschaffung im Anlagenverzeichnis.';
        foreach($calculation['accounts'] as $id=>$account)if($account['group']==='fixed_assets'){
            if(($byAccount[$id]['opening']??0)!==$account['opening_cents']||($byAccount[$id]['closing']??0)!==$account['closing_cents'])$out['issues'][]=$account['name'].': Einzelgegenstände und Bilanzsaldo stimmen nicht überein. Anfangsbestand, Abgang oder Umbuchung prüfen.';
            unset($byAccount[$id]);
        }
        if($byAccount)$out['issues'][]='Anlagenkonto fehlt im Bilanzjournal.';
        BalanceDisposalAccounts::checkMovements($calculation['accounts'],$out['items'],$year);
        BalanceCostAssetSources::checkMovements($calculation['accounts'],$out['items']);
        BalanceOwnIntangibleSources::checkFixed($calculation,$out['items']);
        if($partnerships&&!array_filter($out['items'],static fn($item)=>($item['method']??null)===PartnershipBookPlans::METHOD&&$item['partnership_book']===null))BalancePartnershipBookSources::checkFixed($calculation,$out['items']);
        if(!array_filter($out['items'],static fn($item)=>($item['method']??null)==='cost'&&$item['valuation']===null))BalanceCostAssetSources::checkFixed($calculation['accounts'],$out['items']);
        ksort($out['items'],SORT_STRING);return $out;
    }
}
