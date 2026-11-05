<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-values.php';
use Kulturhus\Verein\{BalancePeriodFilingValues as F,BalanceSegmentCalculation as H};
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
foreach(['cross','short-first','calendar','successor'] as $variant){
    $values=datedFixture($variant);$p=$values['period_source'];
    check(F::build($p['source'],$p['review'],$p['previous'])===$values,'deterministic dated generator boundary '.$variant);
}
$base=datedFixture('successor');
foreach(['missing_previous','changed_previous','changed_review','changed_carry','other_sphere','retained_asset','wrong_period','unconfirmed'] as $mutation){
    $p=$base['period_source'];
    if($mutation==='missing_previous')$p['previous']=null;
    if($mutation==='changed_previous'){
        $p['previous']['tax']['annual_result_cents']++;
        $hash=$p['previous'];unset($hash['sha256'],$hash['transmission']);$p['previous']['sha256']=H::hash($hash);
    }
    if($mutation==='changed_review')$p['previous']['period_source']['review']['current_adjustments'][0]['reason']='Different approval';
    if($mutation==='changed_carry')$p['review']['opening_adjustments'][0]['lines'][0]['debit_cents']++;
    if($mutation==='other_sphere'){$p['previous']['period_source']['source']['entries'][0]['lines'][1]['sphere']='1';}
    if($mutation==='retained_asset')$p['review']['asset_tax_plans']['foreign']=['months'=>60];
    if($mutation==='wrong_period')$p['review']['period']['start']='2026-08-01';
    if($mutation==='unconfirmed')$p['review']['complete']=false;
    $refused=false;try{F::build($p['source'],$p['review'],$p['previous']);}catch(DomainException){$refused=true;}
    check($refused,'invalid dated source is refused: '.$mutation);
}
echo "$count dated generator-boundary checks passed; no database or network\n";
