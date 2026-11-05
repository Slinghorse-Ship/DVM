<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodDisposalEvidence.php';
require_once __DIR__.'/BalancePeriodAssetCarry.php';
require_once __DIR__.'/BalancePeriodSphereEvidence.php';
require_once __DIR__.'/BalanceCostAssetSources.php';

/** Original-plan depreciation, dated journal evidence and per-account reconciliation. */
final class BalancePeriodDepreciation
{
    public function __construct(private PDO $db,private string $actor){}
    private function rows(string $sql,array $args,bool $lock=false):array{return DocumentCatalog::query($this->db,$sql.($lock?' FOR UPDATE':''),$args)->fetchAll();}
    private function club():string
    {
        $a=Permissions::actor($this->db,$this->actor);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');return $a['club_id'];
    }
    public function proposal(array &$p,bool $lock):void
    {
        $club=$this->club();if($lock&&!$this->db->inTransaction())throw new DomainException('Anlagenprüfung benötigt einen Speichervorgang.');
        if(array_diff(array_keys($p['options']),['disposal','sphere_changes','valuations'])||(array_key_exists('disposal',$p['options'])&&!is_array($p['options']['disposal']))||(array_key_exists('sphere_changes',$p['options'])&&!is_array($p['options']['sphere_changes']))||(array_key_exists('valuations',$p['options'])&&!is_array($p['options']['valuations'])))throw new DomainException('Anlagenbewegung und Nachweis prüfen.');
        [$item,$periodId]=explode(':',$p['id'],2);
        if($periodId!==BalancePeriod::key($p['period']))throw new DomainException('Anlage und Abschlusszeitraum passen nicht zusammen.');
        $r=(new BalancePeriodLedger($this->db,$this->actor))->report($periodId,0,false);
        $dayPolicy=BalancePeriodDayAllocation::policy($r['definition']);
        $asset=$this->rows('SELECT i.id,i.code,i.name,i.ownership,i.cash_account,p.version,p.payload FROM inventory_items i JOIN asset_book_plans p ON p.club_id=i.club_id AND p.item_id=i.id WHERE i.club_id=? AND i.id=?',[$club,$item],$lock)[0]??null;
        if(!$asset||$asset['ownership']!=='club'||$asset['cash_account']!==null)throw new DomainException('Eigene Anlage mit bestätigtem Abschreibungsplan auswählen.');
        $plan=json_decode($asset['payload'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($plan)||!is_string($plan['acquired_on']??null)||!TaxCatalog::documentYear($plan['acquired_on']))throw new DomainException('Gespeicherten Anlagenplan prüfen.');
        if($plan['acquired_on']>$p['period']['end'])throw new DomainException('Anlage gehört noch nicht in diesen Abschlusszeitraum.');
        // Historical calendar evidence is bridged explicitly, never ignored or overwritten.
        if($this->rows('SELECT year FROM asset_book_years WHERE club_id=? AND item_id=? AND year>=? LIMIT 1',[$club,$item,(int)substr($p['period']['start'],0,4)],$lock))throw new DomainException('Kalenderjahresnachweise überschneiden sich mit diesem Abschlusszeitraum.');
        $links=$this->rows('SELECT entry_id,ordinal FROM invoice_position_items WHERE club_id=? AND item_id=?',[$club,$item],$lock);
        if(count($links)!==1)throw new DomainException('Anlage genau einer geprüften Anschaffungsposition zuordnen.');
        $sources=new BalancePeriodSources($this->db,$this->actor);$invoice=$sources->binding('invoice',$links[0]['entry_id']);
        $legacy=$invoice===null;$carry=new BalancePeriodAssetCarry($this->db,$this->actor);
        if($legacy){
            if($r['previous']===null)throw new DomainException('Anschaffungsrechnung zuerst in den zugehörigen Abschlusszeitraum übernehmen.');
            $original=$carry->acquisition($links[0]['entry_id'],$p['period'],$lock);
        }else{
            if($invoice['period_issues'])throw new DomainException('Anschaffungsrechnung zuerst in den zugehörigen Abschlusszeitraum übernehmen.');
            $original=$invoice['proposal'];$fresh=$sources->preview($invoice['period'],'invoice',$original['id'],$original['options'],$lock);
            if($fresh['issues']||!hash_equals($original['hash'],$fresh['hash']))throw new DomainException('Anschaffungsrechnung oder Anlagenplan geändert. Zuerst die ursprüngliche Übernahme berichtigen.');
        }
        $fixed=$original['snapshot']['asset_accounts'][$item]??null;
        if(!$fixed||!isset($r['accounts'][$fixed])||$r['accounts'][$fixed]!==$original['accounts'][$fixed])throw new DomainException('Anschaffungskonto und Vortrag stimmen nicht überein.');
        $previous=null;
        if($plan['acquired_on']<$p['period']['start']){
            $ref=$r['previous'];
            if(($ref['kind']??null)==='calendar'){
                if(!$legacy)throw new DomainException('Anschaffung und Kalender-Vortrag sind nicht abgestimmt.');
                $previous=$carry->previous($ref,$item,$plan,$original,$p['period'],$lock);
            }else{
                if(($ref['kind']??null)!=='period')throw new DomainException('Vorherigen Anlagenstand für den tatsächlichen Zeitraum abstimmen.');
                $before=$sources->binding('depreciation',$item.':'.$ref['id']);
                if(!$before||$before['period_state']!=='closed'||$before['period_issues'])throw new DomainException('Vorherigen Abschreibungsstand zuerst prüfen und festschreiben.');
                $previous=$before['proposal'];
            }
            if($previous['snapshot']['plan']!==$plan)throw new DomainException('Anlagenvortrag weicht vom unveränderten Originalplan ab.');
        }elseif($legacy||$invoice['period_id']!==$periodId)throw new DomainException('Anschaffung und Anlagenzugang gehören in denselben Zeitraum.');
        if(($plan['method']??null)==='cost'){
            if(array_diff(array_keys($p['options']),['valuations']))throw new DomainException('Kostenbewertung mit eigenem Bewertungsverlauf bestätigen. Abgang und Bereichswechsel nicht zusätzlich angeben.');
            $base=AssetValuation::fromBookPlan($plan);$old=$previous['snapshot']['valuation']??null;
            if($previous!==null&&!is_array($old))throw new DomainException('Vollständigen unveränderten Bewertungsvortrag nachweisen.');
            $value=AssetValuation::calculate($base,$p['period'],$p['options']['valuations']??[],$old);
            (new AssetValuationEvidence($this->db,$this->actor))->valuation($value,$lock);
            if(!($sourceAccount=TaxCatalog::accounts($p['year'])[$base['account']]??null)||!$sourceAccount['active'])throw new DomainException('Geprüftes Anlagekonto des Abschlussjahrs fehlt.');
            $label=$r['sources']['depreciation:'.$p['id']]['proposal']['snapshot']['name']??($asset['code'].' · '.$asset['name']);
            $p['accounts']=[$fixed=>$original['accounts'][$fixed]];
            $p['snapshot']=['item_id'=>$item,'name'=>$label,'plan'=>$plan,'plan_version'=>(int)$asset['version'],'invoice_id'=>$original['id'],
                'invoice_sha256'=>$original['hash'],'previous_sha256'=>$previous['hash']??null,'fixed_account'=>$fixed,
                'schedule'=>['period'=>$p['period'],'depreciation_cents'=>0]+$value['book'],'valuation'=>$value];
            if($dayPolicy!==null)$p['snapshot']['day_allocation_policy']=$dayPolicy;
            if($legacy)$p['snapshot']['invoice_origin']=['kind'=>'calendar','year'=>$original['year']];
            BalanceCostAssetSources::vouchers($p,$plan,$value,$fixed);$p['evidence']='Bestätigte Anlagenbewertung '.$label.' · '.BalancePeriod::key($p['period']);return;
        }
        if(array_key_exists('valuations',$p['options']))throw new DomainException('Bewertungsverlauf hier nur für nicht planmäßig abzuschreibende Anlagen.');
        $disposal=$p['options']['disposal']??null;$carried=$previous['snapshot']['disposal']??null;
        if($disposal!==null)AssetDisposal::check($plan,$disposal);
        if($carried!==null){if($disposal!==null)throw new DomainException('Ein bereits bestätigter Abgang wird aus dem Vorzeitraum übernommen. Keinen zweiten Abgang erfassen.');$disposal=$carried;}
        elseif($disposal!==null&&!BalancePeriod::contains($p['period'],$disposal['date']??''))throw new DomainException('Neuen Abgang im ausgewählten Abschlusszeitraum erfassen.');
        (new BalancePeriodSale($this->db,$this->actor))->guard($club,$item,$p['period'],$disposal,$lock);
        $calendarSale=null;
        if(($disposal['kind']??null)==='sale'&&($previous['snapshot']['sale_origin']['kind']??null)==='calendar'){
            if($disposal['date']>=$p['period']['start'])throw new DomainException('Historischen Verkauf nicht erneut in diesen Zeitraum übernehmen.');
            $calendarSale=$carry->sale($item,$plan,$disposal,$lock);
            if($calendarSale['sale_origin']!==$previous['snapshot']['sale_origin']||$calendarSale['sale_values']!==$previous['snapshot']['sale_values'])throw new DomainException('Historischen Verkaufsstand und Vortrag erneut abstimmen.');
        }elseif($disposal!==null)(new BalancePeriodDisposalEvidence($this->db,$this->actor))->check($club,$item,$original['id'],$plan,$disposal,$lock);
        $schedule=BalancePeriodDisposal::calculate($plan,$p['period'],$previous['snapshot']['schedule']??null,$disposal,$dayPolicy);
        if($previous!==null){$old=$previous['snapshot']['schedule'];if($old['book_closing_cents']!==$schedule['book_opening_cents']||$old['cost_closing_cents']!==$schedule['cost_opening_cents']||$old['accumulated_closing_cents']!==$schedule['accumulated_opening_cents'])throw new DomainException('Anlagenvortrag weicht vom unveränderten Originalplan ab.');}
        $dep=BalanceSources::depreciationAccount($plan,null);$catalog=TaxCatalog::accounts($p['year']);
        if($dep['code']===''||!($catalog[$dep['code']]['active']??false)||!($catalog[$plan['account']]['active']??false))throw new DomainException('Geprüftes Anlagen- und Abschreibungskonto des Abschlussjahrs fehlt.');
        $p['accounts']=[$fixed=>$original['accounts'][$fixed],$dep['id']=>['name'=>$dep['name'],'group'=>'expense','skr_code'=>$dep['code']]];
        $label=$r['sources']['depreciation:'.$p['id']]['proposal']['snapshot']['name']??($asset['code'].' · '.$asset['name']);
        $p['snapshot']=['item_id'=>$item,'name'=>$label,'plan'=>$plan,'plan_version'=>(int)$asset['version'],'invoice_id'=>$original['id'],'invoice_sha256'=>$original['hash'],'previous_sha256'=>$previous['hash']??null,'fixed_account'=>$fixed,'schedule'=>$schedule];
        if($dayPolicy!==null)$p['snapshot']['day_allocation_policy']=$dayPolicy;
        if($legacy)$p['snapshot']['invoice_origin']=['kind'=>'calendar','year'=>$original['year']];
        if($disposal!==null)$p['snapshot']['disposal']=$disposal;
        $sphereProof=null;$changes=$p['options']['sphere_changes']??[];$oldSphere=$previous['snapshot']['sphere_change']??null;
        if($changes||$oldSphere!==null){
            if($disposal!==null)throw new DomainException('Bereichswechsel und Anlagenabgang gemeinsam gesondert abstimmen.');
            $sphereProof=BalancePeriodSphereChange::build($plan,$p['period'],$changes,$oldSphere,$dayPolicy);
            $sphereEvidence=new BalancePeriodSphereEvidence($this->db,$this->actor);
            $sphereEvidence->originalValues($club,$item,$sphereProof['history'][0]['on']);
            $sphereEvidence->check($club,$item,$sphereProof['history'],$lock);
            $p['snapshot']['sphere_change']=$sphereProof;
        }
        $sale=null;
        if(($disposal['kind']??null)==='sale'){
            $sale=$disposal['date']<$p['period']['start']?($previous['snapshot']['sale_values']??null):BalancePeriodSale::values($disposal,$schedule['book_disposals_cents']);
            if(!$sale)throw new DomainException('Vorgetragenen Verkaufsnachweis prüfen.');
            $p['snapshot']['sale_values']=$sale;
            if($calendarSale!==null)$p['snapshot']['sale_origin']=$calendarSale['sale_origin'];
        }
        $amount=$schedule['depreciation_cents'];
        $when=$disposal!==null&&$disposal['kind']!=='partial_scrap'&&$disposal['date']>=$p['period']['start']?$disposal['date']:$p['period']['end'];
        $depParts=$sphereProof?$sphereProof['book']['depreciation_by_sphere']:[$plan['sphere']=>$amount];
        foreach($depParts as $sphere=>$part)if($part>0)$p['vouchers'][]=['date'=>$when,'description'=>'Abschreibung '.mb_substr($label,0,300),'lines'=>BalanceCalculation::lines([['account'=>$dep['id'],'debit_cents'=>$part,'credit_cents'=>0,'sphere'=>(string)$sphere],['account'=>$fixed,'debit_cents'=>0,'credit_cents'=>$part,'sphere'=>'']],$p['accounts'])];
        if($disposal!==null){
            $code=$sale?$sale['book_code']:($disposal['kind']==='software_end'?'68960':'68950');$loss='src_period_disposal_'.$code;
            if(!($catalog[$code]['active']??false))throw new DomainException('Geprüftes Abgangskonto fehlt für dieses Abschlussjahr.');
            $p['accounts'][$loss]=['name'=>'Restbuchwert Anlagenabgang','group'=>$sale?$sale['group']:'expense','skr_code'=>$code];$p['snapshot']['disposal_accounts']=[$code=>$loss];
            $remaining=$schedule['book_disposals_cents'];
            if($remaining>0)$p['vouchers'][]=['date'=>$disposal['date'],'description'=>($disposal['kind']==='partial_scrap'?'Teilabgang ':'Anlagenabgang ').mb_substr($label,0,300),
                'lines'=>BalanceCalculation::lines([['account'=>$loss,'debit_cents'=>$remaining,'credit_cents'=>0,'sphere'=>$plan['sphere']],['account'=>$fixed,'debit_cents'=>0,'credit_cents'=>$remaining,'sphere'=>'']],$p['accounts'])];
        }
        $p['evidence']='Anlagenplan '.mb_substr($label,0,300).' · '.$p['period']['start'].' bis '.$p['period']['end'];
    }
    public function candidates(array $period):array
    {
        $period=BalancePeriod::check($period);$rows=$this->rows('SELECT i.id,i.code,i.name,p.payload FROM inventory_items i JOIN asset_book_plans p ON p.club_id=i.club_id AND p.item_id=i.id WHERE i.club_id=? ORDER BY i.id',[$this->club()]);$out=[];
        foreach($rows as $row){
            try{$plan=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Anlagenplan '.$row['code'].' ist beschädigt. Original prüfen.');}
            if(!is_array($plan)||!is_string($plan['acquired_on']??null))throw new DomainException('Anschaffungsdatum der Anlage '.$row['code'].' fehlt.');
            if($plan['acquired_on']<=$period['end'])$out[]=['id'=>$row['id'].':'.BalancePeriod::key($period),'name'=>$row['code'].' · '.$row['name'],'date'=>$period['end']];
        }
        return $out;
    }
    public function review(array $r):array
    {
        $issues=[];$items=[];$expected=[];$sources=new BalancePeriodSources($this->db,$this->actor);
        foreach($this->candidates($r['period']) as $candidate){
            $binding=$r['sources']['depreciation:'.$candidate['id']]??null;$p=$sources->preview($r['period'],'depreciation',$candidate['id'],$binding['proposal']['options']??[]);
            if($p['issues']){$issues[]=$candidate['name'].': '.implode(' ',$p['issues']);continue;}
            if(!$binding||!hash_equals($binding['proposal']['hash'],$p['hash']))$issues[]=$candidate['name'].': Abschreibung und Buchwert für diesen Zeitraum noch nicht bestätigt.';
            $items[$p['snapshot']['item_id']]=['source_id'=>$candidate['id'],'source_sha256'=>$p['hash'],'posted'=>$binding!==null&&hash_equals($binding['proposal']['hash'],$p['hash'])]+$p['snapshot'];
            if(($p['snapshot']['disposal']['kind']??null)==='sale'&&BalancePeriod::contains($r['period'],$p['snapshot']['disposal']['date'])){
                try{$items[$p['snapshot']['item_id']]['sale_posting']=BalancePeriodSale::postingEvidence($r,$items[$p['snapshot']['item_id']]);}
                catch(DomainException $e){$issues[]=$candidate['name'].': '.$e->getMessage();}
            }
            $account=$p['snapshot']['fixed_account'];$expected[$account]=($expected[$account]??0)+$p['snapshot']['schedule']['book_closing_cents'];
        }
        foreach($r['calculation']['accounts'] as $key=>$account)if($account['group']==='fixed_assets'&&$account['closing_cents']!==($expected[$key]??0))$issues[]=$account['name'].': Anlagenverzeichnis und Buchungsstand stimmen nicht überein.';
        $flat=[];
        foreach($items as $id=>$item){
            $plan=$item['plan'];$value=$item['schedule'];
            $flat[$id]=['method'=>$plan['method']??'linear_monthly','account'=>$item['fixed_account'],'cost_plan'=>$plan,
                'valuation'=>$item['valuation']??null,'valuation_accounts'=>$item['valuation_accounts']??[]]+$value;
        }
        try{BalanceCostAssetSources::checkMovements($r['calculation']['accounts'],$flat);BalanceCostAssetSources::checkFixed($r['calculation']['accounts'],$flat,$r['entries']);}
        catch(DomainException $error){$issues[]=$error->getMessage();}
        return ['period'=>$r['period'],'items'=>$items,'issues'=>array_values(array_unique($issues))];
    }
}
