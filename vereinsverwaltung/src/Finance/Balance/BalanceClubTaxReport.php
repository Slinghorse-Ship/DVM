<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';

use DomainException;
require_once __DIR__.'/BalanceSegmentCalculation.php';

/** Reproducible §64(2) AO aggregate. Never a filter of only the income statement. */
final class BalanceClubTaxReport
{
    public const FIELDS=['cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents'];
    public const DISPOSALS=['disposals_cents','accumulated_disposals_cents','book_disposals_cents'];

    /** Permissions/history are checked by BalanceSegments; drafts cannot be used here. */
    public static function fromState(array $state,?array $previous=null):array
    {
        $history=$state['history']??[];$last=is_array($history)&&$history?end($history):null;
        if(($state['current_approval']??false)!==true||($state['state']??null)!=='reviewed'||!$last||$last['state']!=='reviewed'
            ||$state['version']!==$last['revision']||$state['allocation']!==$last['allocation'])throw new DomainException('Zuerst die vollständige Betriebsaufteilung vom Vorstand freigeben lassen.');
        $proof=$last;unset($proof['sha256']);
        if(BalanceSegmentCalculation::hash($proof)!==$last['sha256'])throw new DomainException('Freigabenachweis der Betriebsaufteilung stimmt nicht überein.');
        $source=array_intersect_key($state['journal'],array_flip(['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule']));
        $allocation=$state['allocation'];
        $allocationProof=['units'=>$allocation['units'],'allocations'=>$allocation['allocations']];
        if(!empty($allocation['capital_carry'])||!empty($allocation['transfers'])||!empty($allocation['asset_shares']))$allocationProof['capital_carry']=$allocation['capital_carry'];
        if(!empty($allocation['transfers'])||!empty($allocation['asset_shares']))$allocationProof['transfers']=$allocation['transfers'];
        if(!empty($allocation['asset_shares']))$allocationProof['asset_shares']=$allocation['asset_shares'];
        $allocationProof['sha256']=$allocation['sha256'];
        $scope=['schema'=>$allocation['schema'],'kind'=>'tax_privileged_aggregate','source'=>$source,
            'allocation'=>$allocationProof,
            'review'=>['revision'=>$last['revision'],'sha256'=>$last['sha256']],
            'previous'=>$previous['club_scope']??null];
        return self::rebuild($scope);
    }

    /** Rebuild both sides of every original posting, all taxable units and the retained assets. */
    public static function rebuild(array $scope,int $depth=0):array
    {
        return BalanceSourceRead::run(static fn()=>self::rebuildFromSources($scope,$depth));
    }
    private static function rebuildFromSources(array $scope,int $depth=0):array
    {
        if($depth>100||array_keys($scope)!==['schema','kind','source','allocation','review','previous']||!in_array($scope['schema'],[1,2,3,4],true)||$scope['kind']!=='tax_privileged_aggregate')throw new DomainException('Nachweis der steuerlichen Zusammenführung fehlt.');
        $s=$scope['source'];$a=$scope['allocation'];$review=$scope['review'];
        $allocationKeys=match($scope['schema']){4=>['units','allocations','capital_carry','transfers','asset_shares','sha256'],3=>['units','allocations','capital_carry','transfers','sha256'],2=>['units','allocations','capital_carry','sha256'],default=>['units','allocations','sha256']};
        if(!is_array($s)||!is_array($a)||array_keys($a)!==$allocationKeys||($scope['schema']===2&&empty($a['capital_carry']))||($scope['schema']===3&&empty($a['transfers']))||($scope['schema']===4&&empty($a['asset_shares']))||!is_array($review)||array_keys($review)!==['revision','sha256']
            ||!is_int($review['revision'])||$review['revision']<2||!is_string($review['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$review['sha256']))throw new DomainException('Aufteilung und Freigabestand prüfen.');
        foreach(['accounts','opening','entries','calculation','issues','asset_schedule'] as $key)if(!is_array($s[$key]??null))throw new DomainException('Vollständigen Ursprungsabschluss einschließlich Anlagenverzeichnis nachweisen.');
        if(!is_int($s['year']??null)||!is_string($s['closed_sha256']??null)||!is_string($s['result_account']??null)
            ||($s['accounts'][$s['result_account']]['group']??null)!=='equity'||!is_array($a['units']??null)||!is_array($a['allocations']??null))throw new DomainException('Ursprungsabschluss und Ergebnisvortrag prüfen.');
        $allocation=BalanceSegmentCalculation::rebuild($s,$a);
        if(!$allocation['complete']||$allocation['sha256']!==$a['sha256'])throw new DomainException('Vollständige unveränderte Betriebsaufteilung erforderlich.');
        $selected=array_keys(array_filter($allocation['units'],static fn($u)=>$u['sphere']==='4'));
        if(!$selected)throw new DomainException('Keine steuerpflichtigen Geschäftsbetriebe vorhanden. Keine leere E-Bilanz anlegen.');
        $opening=[];$entries=[];
        foreach(BalanceSegmentCalculation::sources($s) as $key=>$source){
            $amount=0;foreach($selected as $unit)$amount+=$allocation['allocations'][$key]['parts'][$unit]??0;
            if(!$amount)continue;
            $line=$source['line'];$line['debit_cents']=$line['debit_cents']>0?$amount:0;$line['credit_cents']=$line['credit_cents']>0?$amount:0;
            if($source['voucher']==='opening')$opening[]=$line;
            else{
                $id=$source['voucher'];$entries[$id]??=['id'=>$id,'date'=>$source['date'],'description'=>$source['description'],'lines'=>[]];
                $entries[$id]['lines'][]=$line;
            }
        }
        foreach($allocation['capital_carry']??[] as $account=>$parts){
            $amount=0;foreach($selected as $unit)$amount+=$parts[$unit]??0;
            if($amount!==0)$opening[]=BalanceSegmentCalculation::capitalLine($account,$amount);
        }
        $eliminations=[];
        foreach($allocation['transfers']??[] as $id=>$transfer){
            $debit=$credit=0;
            foreach($selected as $unit){$net=$transfer['parts'][$unit]??0;$debit+=max(0,$net);$credit+=max(0,-$net);}
            if($debit!==$credit)$entries[$id]['lines'][]=BalanceSegmentCalculation::capitalLine($transfer['account'],$debit-$credit);
            $eliminations[$transfer['account']]=($eliminations[$transfer['account']]??0)+min($debit,$credit);
        }
        $entries=array_values($entries);$calculation=BalanceCalculation::build($s['year'],$s['accounts'],$opening,$entries);
        if($calculation['issues'])throw new DomainException('Zusammengeführte Geschäftsbetriebe sind nicht abgestimmt.');
        foreach($calculation['accounts'] as $id=>$row)foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $field){
            $sum=0;foreach($selected as $unit)$sum+=$allocation['segments'][$unit]['calculation']['accounts'][$id][$field];
            if(in_array($field,['debit_cents','credit_cents'],true))$sum-=$eliminations[$id]??0;
            if($sum!==$row[$field])throw new DomainException('Geschäftsbetriebe und Kontennachweis stimmen nicht überein.');
        }
        $schedule=self::assets($s,$calculation);
        $previous=null;
        if(($s['previous_sha256']??null)!==null){
            if(!is_array($scope['previous']))throw new DomainException('Freigegebene steuerliche Zusammenführung des Vorjahres fehlt.');
            $prior=self::rebuild($scope['previous'],$depth+1);
            if($s['year']!==$prior['year']+1||$s['previous_sha256']!==$prior['club_scope']['source']['closed_sha256']||$s['result_account']!==$prior['result_account'])throw new DomainException('Vorjahresbindung der Geschäftsbetriebe prüfen.');
            foreach($prior['accounts'] as $id=>$account)if(!isset($s['accounts'][$id])||$account['group']!==$s['accounts'][$id]['group']||$account['skr_code']!==$s['accounts'][$id]['skr_code'])throw new DomainException('Kontenänderung im steuerlichen Vortrag gesondert prüfen.');
            $priorAllocation=BalanceSegmentCalculation::rebuild($prior['club_scope']['source'],$prior['club_scope']['allocation']);
            $expected=BalanceSegmentCalculation::carry($prior['club_scope']['source'],$priorAllocation,$s);
            foreach($expected['units'] as $id=>$unit)if(($allocation['units'][$id]['sphere']??null)!==$unit['sphere'])throw new DomainException('Vereinsbereiche des Vorjahres unverändert fortführen.');
            foreach($expected['allocations'] as $key=>$row)if(($allocation['allocations'][$key]['parts']??null)!==$row['parts'])throw new DomainException('Anfangsbestände eines Bereichs stimmen nicht mit dem Vorjahr überein.');
            if(($allocation['capital_carry']??[])!==($expected['capital_carry']??[]))throw new DomainException('Kapitalvortrag stimmt nicht mit dem Vorjahr überein.');
            foreach($expected['asset_shares']??[] as $id=>$share)if(($allocation['asset_shares'][$id]['weights']??null)!==$share['weights']||($allocation['asset_shares'][$id]['measure']??null)!==$share['measure'])throw new DomainException('Nutzungsaufteilung einer fortgeführten Anlage nicht unbemerkt ändern.');
            $net=static function(array $lines):array{$out=[];foreach($lines as $l)$out[$l['account']]=($out[$l['account']]??0)+$l['debit_cents']-$l['credit_cents'];$out=array_filter($out,static fn($v)=>$v!==0);ksort($out);return $out;};
            if($net(BalanceCalculation::carryForward($prior['calculation'],$s['result_account']))!==$net($opening))throw new DomainException('Steuerliche Anfangsbestände entsprechen nicht der Vorjahresaufteilung.');
            $previous=$prior['closed_sha256'];
        }elseif($scope['previous']!==null)throw new DomainException('Unerwarteter Vorjahresnachweis.');
        return ['year'=>$s['year'],'state'=>'closed','closed_sha256'=>BalanceSegmentCalculation::hash($scope),'previous_sha256'=>$previous,
            'result_account'=>$s['result_account'],'issues'=>[],'accounts'=>$s['accounts'],'opening'=>$opening,'entries'=>$entries,
            'calculation'=>$calculation,'asset_schedule'=>$schedule,'club_scope'=>$scope];
    }

    private static function assets(array $source,array $calculation):array
    {
        $schedule=$source['asset_schedule']??null;
        if(!is_array($schedule)||($schedule['year']??null)!==$source['year']||!is_array($schedule['items']??null)||!is_array($schedule['totals']??null)||($schedule['issues']??null)!==[])throw new DomainException('Vollständiges festgeschriebenes Anlagenverzeichnis aller Vereinsbereiche erforderlich.');
        $fields=self::FIELDS;if(array_key_exists('disposals_cents',$schedule['totals']))$fields=array_merge($fields,self::DISPOSALS);
        if(array_key_exists('impairment_cents',$schedule['totals']))$fields=array_merge($fields,['impairment_cents','writeup_cents']);
        if(array_diff(array_keys($schedule['totals']),$fields)||array_diff($fields,array_keys($schedule['totals'])))throw new DomainException('Unbekannter Bewegungsfall im Anlagenverzeichnis.');
        $fields=array_keys($schedule['totals']);
        $totals=array_fill_keys($fields,0);$selected=$totals;$items=[];$allAccounts=[];$selectedAccounts=[];
        foreach($schedule['items'] as $id=>$item){
            if(!is_array($item)||($item['item_id']??null)!==$id||!in_array($item['sphere']??null,['1','2','3','4'],true)
                ||($source['accounts'][$item['account']??'']['group']??null)!=='fixed_assets'||$source['accounts'][$item['account']]['skr_code']!==($item['skr_code']??null))throw new DomainException('Anlagengegenstand und ursprünglichen Vereinsbereich prüfen.');
            foreach($fields as $field){
                $amount=$item[$field]??(in_array($field,['impairment_cents','writeup_cents',...self::DISPOSALS],true)&&($item['method']??null)!=='cost'?0:null);
                if(!is_int($amount)||$amount<0||$amount>BalanceCalculation::LIMIT)throw new DomainException('Anlagenwerte müssen vollständig in ganzen Cent vorliegen.');
                $totals[$field]+=$amount;if($item['sphere']==='4')$selected[$field]+=$amount;
            }
            if($item['cost_opening_cents']+$item['additions_cents']-($item['disposals_cents']??0)!==$item['cost_closing_cents']
                ||$item['accumulated_opening_cents']+$item['depreciation_cents']+($item['impairment_cents']??0)-($item['writeup_cents']??0)-($item['accumulated_disposals_cents']??0)!==$item['accumulated_closing_cents']
                ||$item['cost_opening_cents']-$item['accumulated_opening_cents']!==$item['book_opening_cents']
                ||$item['cost_closing_cents']-$item['accumulated_closing_cents']!==$item['book_closing_cents']
                ||($item['disposals_cents']??0)-($item['accumulated_disposals_cents']??0)!==($item['book_disposals_cents']??0))throw new DomainException('Anlagenbewegungen sind nicht abgestimmt.');
            if(($item['method']??null)==='cost')BalanceAssetAllocation::checkYear($item,$source['year']);
            foreach(['opening_cents'=>$item['book_opening_cents'],'closing_cents'=>$item['book_closing_cents']] as $field=>$amount){
                $allAccounts[$item['account']][$field]=($allAccounts[$item['account']][$field]??0)+$amount;
                if($item['sphere']==='4')$selectedAccounts[$item['account']][$field]=($selectedAccounts[$item['account']][$field]??0)+$amount;
            }
            if($item['sphere']==='4')$items[$id]=$item; // Never rewrite sphere, value or provenance.
        }
        if($totals!==$schedule['totals'])throw new DomainException('Anlagensummen des gesamten Vereins stimmen nicht mit den Gegenständen überein.');
        foreach([[$source['calculation'],$allAccounts],[$calculation,$selectedAccounts]] as [$ledger,$accounts])foreach($ledger['accounts'] as $id=>$account)if($account['group']==='fixed_assets'){
            foreach(['opening_cents','closing_cents'] as $field)if(($accounts[$id][$field]??0)!==$account[$field])throw new DomainException('Anlagenwerte und Betriebsaufteilung widersprechen sich. Keine automatische Umverteilung.');
        }
        return array_replace($schedule,['items'=>$items,'totals'=>$selected]);
    }
}
