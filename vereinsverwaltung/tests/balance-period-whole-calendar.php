<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-whole-calendar.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodWholeFiling.php';
use Kulturhus\Verein\{BalanceCalendarFilingSource as Calendar,BalancePeriodFilingValues as Period,BalancePeriodWholeFiling as Workflow};
$wcChecks=0;
function wcCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Whole calendar carry regression');++$GLOBALS['wcChecks'];}
function wcDenied(callable $fn):void{try{$fn();}catch(DomainException){wcCheck(true);return;}throw new RuntimeException('Invalid whole calendar carry accepted');}
foreach(['book','tax','all-tax'] as $variant){
    $v=wholeCalendarFixture($variant);$proof=$v['period_source'];$previous=$proof['previous'];$before=serialize($previous);
    wcCheck(Calendar::verify($previous,'whole')===$previous['values']);
    wcCheck(Period::whole($proof['source'],$proof['review'],$previous)===$v&&serialize($previous)===$before);
    wcCheck($v['period']===['start'=>'2026-01-01','end'=>'2026-06-30']&&!isset($previous['values']['period']));
    $c=$previous['calendar_source'];$carry=Workflow::carry($c['whole_club_scope']['source'],$previous['values'],$proof['source'],$c['review']);
    wcCheck($carry['period_values']===$previous&&$proof['review']['opening_adjustments']===($carry['entry']?[$carry['entry']]:[]));
    wcCheck($v['capital_comparison']['opening_adjustment_cents']===match($variant){'tax'=>1300,'all-tax'=>1900,default=>0});
    wcCheck($v['result_difference_cents']===match($variant){'tax'=>600,'all-tax'=>900,default=>0});
    foreach(['source','book','tax','plans','capital','section','extra','missing'] as $kind){
        $bad=$previous;$id=array_key_first($bad['asset_schedule']['items']);
        if($kind==='source')$bad['calendar_source']['whole_club_scope']['source']['closed_sha256']=str_repeat('f',64);
        if($kind==='book')$bad['asset_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($kind==='tax')$bad['asset_tax']['tax_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($kind==='plans')$bad['calendar_source']['review']['asset_tax_plans']=['invented'=>[]];
        if($kind==='capital')$bad['values']['capital_comparison']['contributions_cents']++;
        if($kind==='section')$bad['section']='books';
        if($kind==='extra')$bad['extra']=true;
        if($kind==='missing')$bad=null;
        wcDenied(fn()=>Period::whole($proof['source'],$proof['review'],$bad));
    }
    wcDenied(fn()=>Period::books($proof['source'],$previous));
    wcDenied(fn()=>Period::build($proof['source'],$proof['review'],$previous));
    $bad=$proof['review'];$bad['opening_adjustments']=[];
    if($variant!=='book')wcDenied(fn()=>Period::whole($proof['source'],$bad,$previous));
    if($variant==='all-tax')wcDenied(fn()=>\Kulturhus\Verein\BalancePeriodTaxValues::build($proof['source'],$proof['review'],['asset_tax'=>$previous['asset_tax']]));
}
require_once __DIR__.'/../src/Web/View.php';$values=$v;$b=['review'=>$proof['review'],'carry_forward'=>['asset_tax_plans'=>$proof['review']['asset_tax_plans']]];
$report=$proof['source'];$editable=true;$fid='synthetic';$hidden='';$_POST=[];$money=fn(int $v):string=>number_format($v/100,2,',','.').' €';
ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-assets.php';$html=ob_get_clean();
wcCheck(substr_count($html,'Synthetic all-areas taxable corporation')===2&&!str_contains($html,'name="tax_years"'));
echo "$wcChecks whole calendar-to-period checks passed; synthetic arithmetic only\n";
