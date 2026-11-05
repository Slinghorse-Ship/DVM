<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-combined.php';
use Kulturhus\Verein\{BalancePeriodCombinedValues as V,BalanceSegmentCalculation as S};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
foreach(['book','tax','book-successor','tax-successor','reversal','reversal-successor','internal-advance'] as $kind){
    $v=periodCombinedFixture($kind);$p=$v['taxable'];$proof=$p['period_club'];$prior=$proof['previous']?V::fromPartial($proof['previous']):null;
    check(V::build($proof['income'],$p['period_source']['review'],$prior)===$v,'same combined original '.$kind);
    check($v['whole']===$proof['whole']&&$v['period']===$p['period'],'full original retained '.$kind);
    check($v['whole']['book']===$v['whole']['tax'],'no change to whole books '.$kind);
    check(count($v['whole']['asset_schedule']['items'])===($kind==='internal-advance'?0:2)&&count($p['asset_schedule']['items'])===($kind==='internal-advance'?0:1),'original assets neither lost nor duplicated '.$kind);
    foreach($v['reconciliation']['accounts'] as $rows)foreach($rows as $r)check($r['whole']===$r['taxable_book']+$r['other_areas']-($r['internal_elimination']??0)&&$r['taxable_tax']===$r['taxable_book']+$r['tax_adjustment'],'account reconciliation '.$kind);
    if($prior){$bad=$prior;$bad['whole']['book']['income_cents']++;unset($bad['sha256'],$bad['transmission']);$bad+=['sha256'=>S::hash($bad),'transmission'=>'unavailable'];denied(fn()=>V::build($proof['income'],$p['period_source']['review'],$bad),'resealed unrelated whole predecessor');}
    $bad=$p;$bad['period_club']['income']['selected_units']=['food'];unset($bad['sha256'],$bad['transmission']);$bad+=['sha256'=>S::hash($bad),'transmission'=>'unavailable'];
    denied(fn()=>V::fromPartial($bad),'no subset promotion '.$kind);
}
echo "$checks dated combined value checks passed; no persistence or transport\n";
