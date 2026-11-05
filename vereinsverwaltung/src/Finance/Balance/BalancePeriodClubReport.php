<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalanceClubIncomeReconciliation.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceCalendarClubSource.php';

/** ALL taxable units of one dated journal, never a caller-selected subset. */
final class BalancePeriodClubReport
{
    private static function check(bool $ok,string $why='Datierte Betriebsaufteilung und Originalbelege stimmen nicht überein.'):void
    {if(!$ok)throw new DomainException($why);}
    public static function build(array $income,int $depth=0,?array $calendarScope=null):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($income,$depth,$calendarScope));
    }
    private static function buildFromSources(array $income,int $depth=0,?array $calendarScope=null):array
    {
        self::check($depth<=100&&isset($income['period'])&&BalanceClubIncomeReconciliation::rebuild($income['scope']??[])===$income);
        $scope=$income['scope'];$source=$scope['source'];$a=$scope['allocation'];
        $selected=array_keys(array_filter($a['units'],static fn($u)=>$u['sphere']==='4'));
        self::check((bool)$selected,'Keine steuerpflichtigen Geschäftsbetriebe vorhanden.');
        // A crossing advance needs a reviewed partial-balance position, not an
        // invented external debt or a permanent capital contribution.
        foreach($scope['clearing']['cases'] as $case)self::check(in_array($case['from'],$selected,true)===in_array($case['to'],$selected,true),
            'Bereichsübergreifende Vorschüsse benötigen die steuerliche Nebenrechnung, nicht diese Teilbilanz.');
        foreach($source['asset_schedule']['items'] as $item)self::check(!isset($item['sphere_change']),
            'Steuerliche Anlagen-Bereichswechsel über die geprüfte Nebenrechnung ausweisen.');
        $prior=null;
        if($scope['previous']!==null){
            $p=BalanceClubIncomeReconciliation::rebuild($scope['previous']);
            $prior=isset($p['period'])?self::build($p,$depth+1,$calendarScope):BalanceCalendarClubSource::report($p,$calendarScope??[]);
        }elseif($calendarScope!==null)throw new DomainException('Dieser Erstabschluss hat keinen Kalender-Vorabschluss.');
        $opening=[];$entries=[];$eliminations=[];
        foreach(BalanceSegmentCalculation::sources($source) as $key=>$row){
            $line=$row['line'];if($row['voucher']==='opening'&&isset($a['capital_carry'][$line['account']]))continue;
            $cents=0;foreach($selected as $unit)$cents+=$a['allocations'][$key]['parts'][$unit]??0;
            if(!$cents)continue;
            $line['debit_cents']=$line['debit_cents']?$cents:0;$line['credit_cents']=$line['credit_cents']?$cents:0;
            if($row['voucher']==='opening')$opening[]=$line;
            else{$entries[$row['voucher']]??=['id'=>$row['voucher'],'date'=>$row['date'],'description'=>$row['description'],'lines'=>[]];$entries[$row['voucher']]['lines'][]=$line;}
        }
        foreach($a['capital_carry']??[] as $account=>$parts){$net=0;foreach($selected as $unit)$net+=$parts[$unit]??0;if($net)$opening[]=BalanceSegmentCalculation::capitalLine($account,$net);}
        foreach($a['transfers']??[] as $id=>$transfer){
            $d=$c=0;foreach($selected as $unit){$n=$transfer['parts'][$unit]??0;$d+=max(0,$n);$c+=max(0,-$n);}
            if($d!==$c)$entries[$id]['lines'][]=BalanceSegmentCalculation::capitalLine($transfer['account'],$d-$c);
            $eliminations[$transfer['account']]=($eliminations[$transfer['account']]??0)+min($d,$c);
        }
        // Keep exact linked cancellations. Netting them without the link would
        // inflate gross acquisitions/disposals during the independent asset check.
        foreach($source['entries'] as $entry)if(isset($entry['reversal_of'])){
            $id=$entry['id'];$target=$entry['reversal_of'];
            if(!isset($entries[$id])&&!isset($entries[$target]))continue;
            self::check(isset($entries[$id],$entries[$target]),'Storno und Original müssen dieselbe Teilbilanz betreffen.');
            $reverse=array_map(static fn($l)=>array_replace($l,['debit_cents'=>$l['credit_cents'],'credit_cents'=>$l['debit_cents']]),$entries[$target]['lines']);
            self::check($entries[$id]['lines']===$reverse,'Stornoaufteilung stimmt nicht mit dem Original überein.');$entries[$id]['reversal_of']=$target;
        }
        // Changes of use WITHIN a sphere cancel in the aggregated assets/capital.
        foreach($source['assets']['movements']??[] as $item=>$moves)foreach($moves as $move){$n=0;foreach($selected as $unit)$n+=max(0,$move['parts'][$unit]['book_transfer_cents']??0);
            foreach([$source['asset_schedule']['items'][$item]['fixed_account'],$source['result_account']] as $key)$eliminations[$key]=($eliminations[$key]??0)+$n;}
        $entries=array_values($entries);$calculation=BalanceCalculation::build($source['year'],$source['accounts'],$opening,$entries,$source['period']);
        self::check(!$calculation['issues'],'Steuerliche Teilbilanz ist nicht vollständig ausgeglichen.');
        $statement=BalanceClearingStatement::build($source,$a,$scope['clearing'],$source['assets']['movements']??[]);
        foreach($calculation['accounts'] as $id=>$row)foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $field){
            $sum=0;foreach($selected as $unit)$sum+=$statement['units'][$unit]['accounts'][$id][$field];
            if(in_array($field,['debit_cents','credit_cents'],true))$sum-=$eliminations[$id]??0;
            self::check($sum===$row[$field]);
        }
        self::check($calculation['annual_result_cents']===$income['result']['taxable_book_cents']);
        $schedule=$source['asset_schedule'];$schedule['items']=array_filter($schedule['items'],static fn($i)=>$i['plan']['sphere']==='4');
        $assets=[];foreach($schedule['items'] as $id=>$item){
            foreach(BalancePeriodAssetAllocation::FIELDS as $field){$n=0;foreach($selected as $u)$n+=$source['assets']['schedules'][$u]['items'][$id][$field]??0;self::check($n===($item['schedule'][$field]??0));}
            foreach(['opening','closing'] as $f)$assets[$item['fixed_account']][$f]=($assets[$item['fixed_account']][$f]??0)+$item['schedule']['book_'.$f.'_cents'];
        }
        // Keep retained items (including zero-value/disposed ones), original plans
        // and source IDs. Shares are evidence, not new separately acquired assets.
        unset($schedule['totals']);
        foreach($calculation['accounts'] as $id=>$row)if($row['group']==='fixed_assets')foreach(['opening','closing'] as $f)self::check(($assets[$id][$f]??0)===$row[$f.'_cents']);
        if($prior!==null){
            self::check(BalancePeriod::follows($prior['period']??BalancePeriod::calendar($prior['year']),$source['period']));
            $net=static function(array $rows):array{$r=[];foreach($rows as $l)$r[$l['account']]=($r[$l['account']]??0)+$l['debit_cents']-$l['credit_cents'];$r=array_filter($r);ksort($r);return $r;};
            self::check($net(BalanceCalculation::carryForward($prior['calculation'],$source['result_account']))===$net($opening),'Betriebliche Anfangswerte stimmen nicht mit dem vorherigen Zeitraum überein.');
        }
        $proof=['schema'=>1,'income'=>$income];
        if($calendarScope!==null)$proof['calendar_scope']=$calendarScope;
        return ['year'=>$source['year'],'state'=>'closed','closed_sha256'=>BalanceSegmentCalculation::hash($proof),'previous_sha256'=>$prior['closed_sha256']??null,
            'result_account'=>$source['result_account'],'issues'=>[],'accounts'=>$source['accounts'],'opening'=>$opening,'entries'=>$entries,
            'calculation'=>$calculation,'asset_schedule'=>$schedule,'period'=>$source['period'],'period_club_scope'=>$proof];
    }
    public static function source(array $report):array
    {return BalanceWholeClubReport::fromReport($report)['whole_club_scope']['source'];}
}
