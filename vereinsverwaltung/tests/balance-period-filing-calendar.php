<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-calendar.php';
use Kulturhus\Verein\{BalanceSubsidiaryClubValues as Filing,BalanceCalendarFilingSource as Calendar,BalancePeriodFilingValues as Period};
$checks=0;
function calendarCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Calendar filing bridge mismatch');++$GLOBALS['checks'];}
function calendarDenied(callable $fn):void{try{$fn();}catch(DomainException){calendarCheck(true);return;}throw new RuntimeException('Forged calendar bridge accepted');}
foreach(['book','tax'] as $variant){
    $v=calendarPeriodFixture($variant);$s=$v['subsidiary'];$before=serialize($s);
    calendarCheck(Filing::build($s)===$v&&serialize($s)===$before);
    calendarCheck($v['period']===['start'=>'2026-01-01','end'=>'2026-06-30']);
    $p=$v['whole']['period_source'];$old=$p['previous'];
    calendarCheck($old['calendar_source']===$s['previous']&&!isset($old['values']['period']));
    calendarCheck($v['whole']['book']===$v['whole']['tax']&&$v['whole']['opening_adjustments']===[]);
    calendarCheck(Calendar::verify($old,'books')===$old['values']);
    foreach(['schedule','amount','plan','source','section','extra'] as $field){
        $bad=$old;$id=array_key_first($bad['asset_schedule']['items']);
        if($field==='schedule')$bad['asset_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($field==='amount')$bad['values']['book']['annual_result_cents']++;
        if($field==='plan')$bad['asset_tax']['tax_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($field==='source')$bad['calendar_source']['valuation']['tax']['annual_result_cents']++;
        if($field==='section')$bad['section']='subsidiary';
        if($field==='extra')$bad['extra']=true;
        calendarDenied(fn()=>Period::books($p['source'],$bad));
    }
    calendarDenied(fn()=>Period::books($p['source']));
    calendarDenied(fn()=>Period::whole($p['source'],$p['review']+['capital_movements'=>[]],$old));
}
echo "$checks calendar-to-period filing checks passed; no storage or transport\n";
