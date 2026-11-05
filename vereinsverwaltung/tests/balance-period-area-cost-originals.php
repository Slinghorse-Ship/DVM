<?php declare(strict_types=1);
// Retain actual corrected, nonzero HGB/tax cost sources and their partial periods.
// This historical evidence test never requests a currently blocked tax filing.
require __DIR__.'/balance-period-cost-valuation.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';
use Kulturhus\Verein\{BalancePeriodAreas as CostAreas,BalanceSegmentCalculation as CostAllocation,BalanceAssetAllocation as CostParts};
$base=$n;$n=0;$areas=new CostAreas($db,'admin');$weights=['food'=>2,'shop'=>3];
$units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic capital','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
foreach([$pid,$next]as$target){
 $report=$ledger->report($target);$areas->prepare($target,0,$report['closed_sha256'],$units,'Own actual shared cost evidence');$state=$areas->view($target);
 foreach(CostAllocation::sources($state['source'])as$key=>$row){if(str_starts_with($key,'opening:')&&$state['prior']!==null)continue;$areas->edit($target,$state['version'],$report['closed_sha256'],'line',['key'=>$key,'parts'=>CostParts::cents($row['cents'],$weights)],'Own actual retained cost posting and correction');$state=$areas->view($target);}
 if($state['prior']===null)foreach(['land','financial']as$item){$areas->edit($target,$state['version'],$report['closed_sha256'],'asset',['item'=>$item,'weights'=>$weights,'measure'=>'uses'],'Own original same-sphere cost use');$state=$areas->view($target);}
 check($state['proof']['complete'],'actual nonzero dated cost allocation is complete');$review=$areas->confirm($target,$state['version'],$report['closed_sha256'],$state['sha256'],true,'Own actual cost area board review');$current=$areas->view($target);$income=$areas->incomeReconciliation($target);
 check($areas->originalIncome($target,$review['revision'],$review['sha256'])===$income,'actual historical nonzero cost income equals independently rebuilt live original income');
 check(!$areas->originalState($target,$review['revision'],$review['sha256'])['current_approval'],'historical cost allocation grants no current approval');
 check($income['result']['whole_cents']===$report['calculation']['annual_result_cents'],'actual source correction and cost recovery never repeat prior expense');
}
echo "$n historical dated cost area checks passed plus $base original cost checks; synthetic MySQL only, no filing or transport\n";
