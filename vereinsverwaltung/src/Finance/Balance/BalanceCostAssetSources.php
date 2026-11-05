<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';

/** Annual cost-asset confirmation -> original acquisition and individually dated journal lines. */
final class BalanceCostAssetSources
{
    public function __construct(private PDO $db,private string $actor){}
    public static function codes(string $kind,string $event,bool $permanent=true,?string $profile=null,?string $original=null,?string $account=null):array
    {
        if(!in_array($kind,['land','financial','construction','intangible_advance'],true))throw new DomainException('Geprüfte Bewertungsart fehlt.');
        if($original!==null&&$profile===null)throw new DomainException('Originales Gegenkonto benötigt den belegten Finanzanlagenzusammenhang.');
        if($profile!==null){
            if($kind!=='financial')throw new DomainException('Darlehens- oder Wertpapierprofil gehört zu einer Finanzanlage.');
            return ['group'=>$event==='writeup'?'revenue':'expense','code'=>FinancialInvestment::counterCode($event,$permanent,$profile,$original,$account)];
        }
        if($event==='writeup')return ['group'=>'revenue','code'=>$kind==='intangible_advance'?'49110':($kind!=='financial'?'49100':'49120')];
        if($event==='disposal')return ['group'=>'expense','code'=>$kind==='intangible_advance'?'68960':($kind!=='financial'?'68950':'68970')];
        if($event==='impairment')return ['group'=>'expense','code'=>$kind==='intangible_advance'?'62100':($kind!=='financial'?'62300':($permanent?'72000':'72010'))];
        throw new DomainException('Geprüftes Bewertungsereignis fehlt.');
    }
    public function annual(array &$p,array $row,bool $lock):void
    {
        $actor=Permissions::actor($this->db,$this->actor);$club=$actor['club_id'];
        $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);[$plan,$disposal]=AssetDisposal::unpack($snapshot);
        if($disposal!==null||!isset($snapshot['valuation']))throw new DomainException('Kostenbewertung mit unverändertem Originalplan auswählen.');
        $valuation=$snapshot['valuation'];$source=$valuation['source'];
        if($source['period']!==BalancePeriod::calendar($p['year']))throw new DomainException('Bewertung und bestätigtes Abschlussjahr stimmen nicht überein.');
        $value=$valuation['book'];
        foreach(['opening_cents'=>$value['book_opening_cents']+$value['additions_cents'],'depreciation_cents'=>0,'closing_cents'=>$value['book_closing_cents']] as $field=>$expected)
            if((int)$row[$field]!==$expected)throw new DomainException('Bestätigte Kostenbewertung wurde verändert.');
        (new AssetValuationEvidence($this->db,$this->actor))->valuation($valuation,$lock);
        $sources=new BalanceSources($this->db,$this->actor);
        if($source['previous']!==null){
            $year=$p['year']-1;$id=$row['item_id'].':'.$year;
            $prior=DocumentCatalog::query($this->db,'SELECT snapshot FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?'.($lock?' FOR UPDATE':''),[$club,$row['item_id'],$year])->fetch();
            if(!$prior||(json_decode($prior['snapshot'],true,512,JSON_THROW_ON_ERROR)['valuation']??null)!==$source['previous'])throw new DomainException('Bewertungsvortrag gehört nicht zum bestätigten Vorjahresstand.');
            $binding=$sources->binding('depreciation',$id);$fresh=$sources->preview($year,'depreciation',$id,[],$lock);
            if(!$binding||$fresh['issues']||!hash_equals($binding['source_hash'],$fresh['hash']))throw new DomainException('Vorherige Anlagenbewertung zuerst vollständig ins Bilanzjournal übernehmen.');
            (new BalanceSourceHistory($this->db,$this->actor))->checked($binding);
        }
        $links=DocumentCatalog::query($this->db,'SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=?'.($lock?' FOR UPDATE':''),[$club,$row['item_id']])->fetchAll();
        if(count($links)!==1)throw new DomainException('Kostenanlage genau einer ursprünglichen Anschaffungsrechnung zuordnen.');
        $binding=$sources->binding('invoice',$links[0]['entry_id']);
        if(!$binding||(int)$binding['tax_year']>$p['year'])throw new DomainException('Ursprüngliche Anschaffungsrechnung zuerst ins Bilanzjournal übernehmen.');
        $fresh=$sources->preview((int)$binding['tax_year'],'invoice',$links[0]['entry_id'],$binding['options'],$lock);
        if($fresh['issues']||!hash_equals($binding['source_hash'],$fresh['hash']))throw new DomainException('Anschaffung oder ursprünglicher Anlagenplan wurde verändert.');
        (new BalanceSourceHistory($this->db,$this->actor))->checked($binding);
        $fixed=$binding['snapshot']['asset_accounts'][$row['item_id']]??null;
        $original=array_values(array_filter($binding['snapshot']['assets'],static fn($asset)=>$asset['item_id']===$row['item_id']))[0]??null;
        if(!is_string($fixed)||!$original||json_decode($original['payload'],true,64,JSON_THROW_ON_ERROR)!==$plan)throw new DomainException('Anlagenkonto und unveränderter Originalplan fehlen.');
        $p['snapshot']=['annual'=>$row,'invoice_id'=>$binding['source_id'],'acquisition_sha256'=>$binding['source_hash'],'fixed_account'=>$fixed,'valuation'=>$valuation];
        $p['accounts']=[$fixed=>$fresh['accounts'][$fixed]];$p['evidence']='Bestätigte Anlagenbewertung '.$row['item_id'].' · '.$p['year'];
        self::vouchers($p,$plan,$valuation,$fixed);
    }
    public static function vouchers(array &$p,array $plan,array $valuation,string $fixed):void
    {
        $catalog=TaxCatalog::accounts($p['year']);$accounts=[];
        foreach($valuation['movements'] as $movement){
            $event=self::event($valuation['source']['events'],$movement['event_id']);
            $type=self::codes($plan['valuation_kind'],$event['kind'],$event['permanent']??true,$plan['financial_profile']??null,$event['counter_code']??null,$plan['account']);$code=$type['code'];
            if(!($catalog[$code]['active']??false))throw new DomainException('Geprüftes Bewertungskonto des Abschlussjahrs fehlt.');
            $key=FinancialInvestment::key($code,$plan['financial_profile']??null);$p['accounts'][$key]=['name'=>$catalog[$code]['name'],'group'=>$type['group'],'skr_code'=>$code];$accounts[$code]=$key;
            $up=$type['group']==='revenue';$amount=$up?$movement['book']['writeup_cents']:$movement['book']['impairment_cents']+$movement['book']['book_disposals_cents'];
            if($amount>0)$p['vouchers'][]=['date'=>$movement['on'],'description'=>mb_substr('Anlagenbewertung · '.$event['reason'],0,500),
                'lines'=>BalanceCalculation::lines([['account'=>$fixed,'debit_cents'=>$up?$amount:0,'credit_cents'=>$up?0:$amount,'sphere'=>''],
                    ['account'=>$key,'debit_cents'=>$up?0:$amount,'credit_cents'=>$up?$amount:0,'sphere'=>$plan['sphere']]],$p['accounts'])];
        }
        $p['snapshot']['valuation_accounts']=$accounts;
    }
    private static function event(array $events,string $identifier):array
    {
        foreach($events as $event)if($event['id']===$identifier)return $event;
        throw new DomainException('Ursprüngliches Bewertungsereignis fehlt.');
    }
    public static function movement(array $item,string $code):?int
    {
        if(($item['method']??null)!=='cost')return null;
        $value=$item['valuation']??null;if(!is_array($value))throw new DomainException('Bestätigte Kostenbewertung fehlt.');
        $plan=$item['cost_plan'];$amount=0;$matched=false;
        foreach($value['movements'] as $movement){
            $event=self::event($value['source']['events'],$movement['event_id']);
            $type=self::codes($plan['valuation_kind'],$event['kind'],$event['permanent']??true,$plan['financial_profile']??null,$event['counter_code']??null,$plan['account']);
            if($type['code']!==$code)continue;$matched=true;
            $side=$item['valuation_side']??'book';if(!in_array($side,['book','tax'],true))throw new DomainException('Buch- oder Steuerbewertung vollständig nachweisen.');
            $amount+=$type['group']==='revenue'?$movement[$side]['writeup_cents']:$movement[$side]['impairment_cents']+$movement[$side]['book_disposals_cents'];
        }
        return $matched?$amount:null;
    }
    public static function checkMovements(array $accounts,array $items):void
    {
        $expected=[];
        foreach($items as $item){
            if(($item['method']??null)!=='cost'||$item['valuation']===null)continue;
            $keys=[];
            foreach($item['valuation']['source']['events'] as $event){
                $type=self::codes($item['cost_plan']['valuation_kind'],$event['kind'],$event['permanent']??true,$item['cost_plan']['financial_profile']??null,$event['counter_code']??null,$item['cost_plan']['account']);$code=$type['code'];
                $id=$item['valuation_accounts'][$code]??null;$account=$accounts[$id??'']??null;
                if(!is_string($id)||!$account||$account['group']!==$type['group']||$account['skr_code']!==$code)
                    throw new DomainException('Originales Gegenkonto der Anlagenbewertung fehlt.');
                $keys[$code]=$id;
            }
            if($keys!==$item['valuation_accounts'])throw new DomainException('Gegenkontennachweis der Anlagenbewertung wurde verändert.');
            foreach($keys as $code=>$id)$expected[$id]=($expected[$id]??0)+self::movement($item,(string)$code);
        }
        foreach($accounts as $id=>$account)if(str_starts_with($id,'src_asset_valuation_')&&($account['amount_cents']??null)!==($expected[$id]??0))
            throw new DomainException('Anlagenbewertung und Betrag auf ihrem bestätigten Gegenkonto stimmen nicht überein.');
    }
    public static function checkFixed(array $accounts,array $items,?array $entries=null):void
    {
        if($entries!==null){
            $indexed=[];$cancelled=[];$turnover=[];
            foreach($entries as $entry){if(isset($indexed[$entry['id']]))throw new DomainException('Buchungsnachweis ist doppelt.');$indexed[$entry['id']]=$entry;}
            foreach($entries as $entry){
                $target=$entry['reversal_of']??null;if($target===null)continue;$original=$indexed[$target]??null;
                if(!$original||($original['reversal_of']??null)!==null||isset($cancelled[$target])||$entry['date']<$original['date'])throw new DomainException('Originale Bewertungsberichtigung vollständig nachweisen.');
                $reverse=array_map(static fn($line)=>['account'=>$line['account'],'debit_cents'=>$line['credit_cents'],'credit_cents'=>$line['debit_cents'],'sphere'=>$line['sphere']],$original['lines']);
                if($entry['lines']!==$reverse)throw new DomainException('Bewertungsstorno stimmt nicht mit der ursprünglichen Buchung überein.');
                $cancelled[$target]=$cancelled[$entry['id']]=true;
            }
            foreach($entries as $entry)if(!isset($cancelled[$entry['id']]))foreach($entry['lines'] as $line){
                foreach(['debit_cents','credit_cents'] as $field)$turnover[$line['account']][$field]=($turnover[$line['account']][$field]??0)+$line[$field];
            }
            foreach($accounts as $id=>&$account)foreach(['debit_cents','credit_cents'] as $field)$account[$field]=$turnover[$id][$field]??0;unset($account);
        }
        $expected=[];
        foreach($items as $item)if(($item['method']??null)==='cost')$expected[$item['account']]=['opening_cents'=>0,'debit_cents'=>0,'credit_cents'=>0,'closing_cents'=>0];
        foreach($items as $item){
            $id=$item['account'];if(!isset($expected[$id]))continue;
            $expected[$id]['opening_cents']+=$item['book_opening_cents'];
            $expected[$id]['closing_cents']+=$item['book_closing_cents'];
            $expected[$id]['debit_cents']+=$item['additions_cents']+($item['writeup_cents']??0);
            $expected[$id]['credit_cents']+=$item['depreciation_cents']+($item['impairment_cents']??0)+($item['book_disposals_cents']??0);
        }
        foreach($expected as $id=>$row)foreach($row as $field=>$amount)if(($accounts[$id][$field]??null)!==$amount)
            throw new DomainException('Ursprüngliche Kostenbewertung und sämtliche Bewegungen auf dem Anlagekonto stimmen nicht überein.');
    }
    public static function item(array $plan,array $acquisition,string $id,string $fixed,int $year,?array $annual):array
    {
        $base=AssetValuation::fromBookPlan($plan);$start=(int)substr($base['acquired_on'],0,4);$value=null;
        if($annual!==null){
            $snapshot=json_decode($annual['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR);[$original]=AssetDisposal::unpack($snapshot);
            if($original!==$plan||($snapshot['valuation']['source']['period']??null)!==BalancePeriod::calendar($year))throw new DomainException('Bewertung, Originalplan und Abschlussjahr stimmen nicht überein.');
            $value=$snapshot['valuation'];$row=$value['book'];
        }else{
            $basis=$base['basis_cents'];$opening=$start<$year?$basis:0;
            $row=array_fill_keys(AssetValuation::FIELDS,0);
            $row['cost_opening_cents']=$row['book_opening_cents']=$opening;$row['additions_cents']=$start===$year?$basis:0;
            $row['cost_closing_cents']=$row['book_closing_cents']=$basis;
        }
        return ['item_id'=>$id,'invoice_id'=>$acquisition['source_id'],'receipt_number'=>$acquisition['snapshot']['entry']['display_number'],
            'account'=>$fixed,'skr_code'=>$base['account'],'sphere'=>$base['sphere'],'acquired_on'=>$base['acquired_on'],'months'=>0,'residual_cents'=>0,
            'depreciation_cents'=>0,'expected_depreciation_cents'=>0,'method'=>'cost','cost_plan'=>$plan,'valuation'=>$value,
            'acquisition_hash'=>$acquisition['source_hash'],'depreciation_hash'=>$annual['source_hash']??null,
            'valuation_accounts'=>$annual['snapshot']['valuation_accounts']??[]]+$row;
    }
}
