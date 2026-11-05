<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-subsidiary.php';
use Kulturhus\Verein\{BalanceSubsidiaryClubValues as V,BalancePeriodFilingValues as F};
$n=0;
function subsidiaryCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Dated subsidiary boundary regression');++$GLOBALS['n'];}
function subsidiaryDenied(callable $action):void{try{$action();}catch(DomainException){subsidiaryCheck(true);return;}throw new RuntimeException('Invalid subsidiary packet accepted');}
foreach(['book','tax','off-balance','book-successor','tax-successor','off-balance-successor'] as $variant){
    $v=subsidiaryPeriodFixture($variant);$before=serialize($v);
    subsidiaryCheck(V::build($v['subsidiary'])===$v&&serialize($v)===$before);
    subsidiaryCheck($v['whole']['book']===$v['whole']['tax']&&$v['whole']['period_section']==='books');
    subsidiaryCheck($v['period']===$v['whole']['period']&&$v['period']===$v['subsidiary']['period']&&$v['transmission']==='unavailable');
    $p=$v['whole']['period_source'];subsidiaryCheck(F::books($p['source'],$p['previous'])===$v['whole']);
    subsidiaryDenied(fn()=>F::whole($p['source'],$p['review'],$p['previous']));
}
$v=subsidiaryPeriodFixture('tax-successor');$s=$v['subsidiary'];
$bad=$s;$bad['result']['taxable_income_cents']++;subsidiaryDenied(fn()=>V::build($bad));
$bad=$s;$bad['previous']=null;subsidiaryDenied(fn()=>V::build($bad));
$bad=$s;$bad['review']['period']['start']='2026-01-01';subsidiaryDenied(fn()=>V::build($bad));
$bad=$s;$bad['review']['opening_adjustments']=[];subsidiaryDenied(fn()=>V::build($bad));
$p=$v['whole']['period_source'];$bad=$p['previous'];$bad['period_source']['review']['current_adjustments']=[['id'=>'foreign']];
subsidiaryDenied(fn()=>F::books($p['source'],$bad));
echo "$n dated subsidiary generator-boundary checks passed; no database or transport\n";
