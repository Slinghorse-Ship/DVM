<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-business-calendar.php';
use Kulturhus\Verein\{BalancePeriodFilingValues as F,BalanceCalendarFilingSource as Calendar,BalancePeriodBusinessFiling as Business};
$businessChecks=0;
function businessCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Business period proof regression');++$GLOBALS['businessChecks'];}
function businessDenied(callable $fn):void{try{$fn();}catch(DomainException){businessCheck(true);return;}throw new RuntimeException('Invalid business proof accepted');}
foreach(['book','tax','tax-successor'] as $variant){
    $v=businessCalendarFixture($variant);$p=$v['period_source'];$prior=$p['previous'];$before=serialize($p);
    businessCheck(F::build($p['source'],$p['review'],$prior)===$v&&serialize($p)===$before);
    businessCheck($v['source_spheres']===['4']&&!isset($v['whole_club_scope'])&&!isset($v['capital_comparison']));
    $c=$prior['calendar_source']??$prior['period_source'];$pv=$prior['values']??$prior;
    $carry=Business::carry($c['source'],$pv,$p['source'],$c['review']);
    businessCheck($carry['period_values']===$prior&&$p['review']['opening_adjustments']===($carry['entry']?[$carry['entry']]:[]));
    if($variant!=='tax-successor')businessCheck(Calendar::verify($prior,'business')===$prior['values']);
    $bad=$p['review'];$bad['capital_movements']=[];businessDenied(fn()=>F::build($p['source'],$bad,$prior));
    $bad=$p['review'];$bad['complete']=false;businessDenied(fn()=>F::build($p['source'],$bad,$prior));
    businessDenied(fn()=>F::build($p['source'],$p['review'],null));
    $bad=$p['source'];$id=array_key_first($bad['asset_schedule']['items']);$bad['asset_schedule']['items'][$id]['plan']['sphere']='1';
    businessDenied(fn()=>F::build($bad,$p['review'],$prior));
    $bad=$p['source'];$bad['asset_schedule']['items'][$id]['sphere_change']=['schema'=>1];businessDenied(fn()=>F::build($bad,$p['review'],$prior));
    $bad=$p['source'];$bad['entries'][0]['lines'][0]['sphere']='1';businessDenied(fn()=>F::build($bad,$p['review'],$prior));
    if($variant!=='tax-successor')foreach(['section','source','tax','assets','review','extra'] as $case){
        $bad=$prior;$id=array_key_first($bad['asset_schedule']['items']);
        if($case==='section')$bad['section']='whole';
        if($case==='source')$bad['calendar_source']['source']['closed_sha256']=str_repeat('f',64);
        if($case==='tax')$bad['asset_tax']['tax_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($case==='assets')$bad['asset_schedule']['items'][$id]['schedule']['book_closing_cents']++;
        if($case==='review')$bad['calendar_source']['review']['asset_tax_plans']=['missing'=>[]];
        if($case==='extra')$bad['extra']=true;
        businessDenied(fn()=>F::build($p['source'],$p['review'],$bad));
    }
}
echo "$businessChecks business period and calendar checks passed; synthetic arithmetic only\n";
