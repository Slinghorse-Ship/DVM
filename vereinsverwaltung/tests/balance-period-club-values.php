<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-club.php';
use Kulturhus\Verein\{BalancePeriodClubReport as Report,BalancePeriodClubFilingValues as Values,BalanceSegmentCalculation as Hash,BalancePeriodBusinessFiling as Business};
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodBusinessFiling.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
foreach(['book','tax','book-successor','tax-successor','reversal','reversal-successor'] as $case){
    $v=periodClubFixture($case);$p=$v['period_club'];$income=$p['income'];$before=$income;
    check(Values::build($income,$v['period_source']['review'],$p['previous'])===$v,'exact reconstruction '.$case);
    check($income===$before&&$income['selected_units']===['food','shop'],'all taxable units and unchanged source '.$case);
    check(array_keys($v['asset_schedule']['items'])===['shared']&&array_keys($p['whole']['asset_schedule']['items'])===['shared','exempt'],'original selected asset once, exempt asset retained only in whole '.$case);
    check($v['result_difference_cents']===match($case){'tax'=>10000,'tax-successor'=>6000,default=>0},'tax depreciation not booked twice '.$case);
    check($v['book']['income_cents']===(str_ends_with($case,'successor')?0:9997),'exempt income excluded and prior result not repeated '.$case);
    if($p['previous']){
        denied(fn()=>Values::build($income,$v['period_source']['review']),'missing predecessor rejected '.$case);
        $bad=$p['previous'];$bad['period_club']['whole']['book']['income_cents']++;
        denied(fn()=>Values::build($income,$v['period_source']['review'],$bad),'altered original whole predecessor rejected '.$case);
    }
    $bad=$income;$bad['selected_units']=['food'];
    denied(fn()=>Report::build($bad),'selected subset rejected '.$case);
    $bad=$income;$bad['scope']['source']['entries'][0]['lines'][0]['debit_cents']++;
    denied(fn()=>Report::build($bad),'changed original journal rejected '.$case);
    $bad=$v['period_source']['review'];$bad['period']['start']='2026-01-01';
    denied(fn()=>Values::build($income,$bad,$p['previous']),'changed period rejected '.$case);
    $bad=$v['period_source']['review'];$bad['asset_tax_plans']['exempt']=['months'=>120,'depreciation_account'=>'dep','reason'=>'Synthetic invalid scope','confirmed'=>true];
    denied(fn()=>Values::build($income,$bad,$p['previous']),'exempt asset tax change rejected '.$case);
}
$v=periodClubFixture('internal-advance');
check($v['book']['annual_result_cents']===2007&&$v['period_club']['income']['selected_units']===['club','food','shop'],'internal taxable advances eliminate without false outside receivables');
denied(fn()=>Report::build(periodClubAdvanceIncome(false)),'advances between taxable and exempt spheres require subsidiary profile');
denied(fn()=>Business::assertSource(Report::build(periodClubIncome())),'approved aggregate cannot masquerade as independently kept single business');
echo "$checks dated aggregate value checks passed\n";
