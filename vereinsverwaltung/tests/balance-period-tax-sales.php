<?php declare(strict_types=1);
require __DIR__.'/balance-period-area-sales.php';
// Hybrid invoice validation resets PHP's process-wide limit during the source
// fixture. This CLI-only continuation is a separate, synthetic test phase.
if(PHP_SAPI!=='cli'||getenv('VEREIN_MODE')!=='synthetic')throw new RuntimeException('Synthetic CLI tax sale test only');
set_time_limit(0);
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodTaxReconciliations.php';
use Kulturhus\Verein\{BalancePeriodTaxReconciliations as PeriodTaxes,BalanceClubTaxReconciliation as TaxValue};
$base=$n;$n=0;$tax=new PeriodTaxes($db,'sales-admin');$timing=[];
$measure=static function(string $name,callable $fn)use(&$timing){$start=microtime(true);$value=$fn();$timing[$name]=round(microtime(true)-$start,3);echo 'TIMING '.$name.' '.$timing[$name]." seconds\n";return $value;};
$review=$measure('first_draft',fn()=>$tax->draft($pid));
foreach($tax->get($pid)['income']['scope']['source']['asset_schedule']['items'] as $itemId=>$item){
    if(($item['plan']['method']??'linear_monthly')!=='linear_monthly')continue;
    $dep=\Kulturhus\Verein\BalanceSources::depreciationAccount($item['plan'],null);
    $review['asset_tax_plans'][$itemId]=['months'=>120,'depreciation_account'=>$dep['id'],'reason'=>'Synthetic reviewed separate tax life','confirmed'=>true];
}
$before=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events');$tax->save($pid,0,$review,'Synthetic separate tax sales');$first=$tax->get($pid);TaxValue::verify($first['values']);
check($first['values']['valuation']['asset_tax']['items']['gain19']['sale']['book']['gain'],'original posted sale evidence preserved');
check($first['values']['valuation']['asset_tax']['items']['loss19']['sale']['tax']['gain']===false,'tax loss preserves issued original and exact invoice source');
$measure('first_approval',fn()=>$tax->confirm($pid,1,$review['income_sha256'],true,'Synthetic tax sale board'));
$review2=$measure('next_draft',fn()=>$tax->draft($next));$measure('next_save',fn()=>$tax->save($next,0,$review2,'Synthetic tax sale carry'));$measure('next_approval',fn()=>$tax->confirm($next,1,$review2['income_sha256'],true,'Synthetic next tax board'));$second=$measure('next_get',fn()=>$tax->get($next));
$measure('next_verify_arithmetic',fn()=>TaxValue::verify($second['values']));
$overview=$measure('next_page_overview',fn()=>$tax->overview($next));check($overview===['state'=>$second,'carry_issue'=>''],'page reads current sales and carry in one verified snapshot');
$measure('next_area_source',fn()=>(new \Kulturhus\Verein\BalancePeriodAreas($db,'sales-admin'))->reviewWithIncome($next));
check($second['current_approval']&&$second['period']!==$first['period'],'real issued sale tax values carried into distinct next period');
check($before===\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events'),'tax sales never alter original journal');
$bundle=(new \Kulturhus\Verein\BalancePeriodExport($db,'sales-admin'))->build($next);check(isset($bundle['files']['steuerliche-nebenrechnung/nachweis.json']),'real issued asset tax proof integrated with workpapers');
echo "$n dated tax sale checks passed plus $base area checks; synthetic issued originals, no transport\n";
