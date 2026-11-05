<?php declare(strict_types=1);
// Reuse isolated native-boundary setup and its existing permissions/approval regressions.
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\Tests\MySqlFixture;
$operatingBaseChecks=$checks;
$db->exec("INSERT INTO clubs VALUES('costs','Synthetic operating accounts');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('cost-finance','costs','treasurer',0),('cost-board','costs','treasurer',1),('cost-member','costs','member',0)");
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('costs','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
$costLedger=new \Kulturhus\Verein\BalanceLedger($db,'cost-finance');$costLedger->create(2025);
$costChart=\Kulturhus\Verein\BalanceLedger::defaultChart();
$costCases=json_decode(file_get_contents(__DIR__.'/fixtures/ebilanz-operating-accounts.json'),true,64,JSON_THROW_ON_ERROR);
$costCodes=array_merge(...array_values($costCases['automatic']));
$costCodes=array_merge($costCodes,array_map('strval',array_keys($costCases['details'])));
$costLines=[];
foreach($costCodes as $code){
    $key='cost-'.$code;$costChart[$key]=['name'=>'Synthetic cost '.$code,'group'=>'expense','skr_code'=>$code];
    $costLines[]=['account'=>$key,'debit_cents'=>1234,'credit_cents'=>0,'sphere'=>'4'];
}
$costLines[]=['account'=>'cash','debit_cents'=>0,'credit_cents'=>1234*count($costCodes),'sphere'=>''];
$costLedger->setup(2025,1,$costChart,$pair('cash','equity',1000000),'equity','Synthetic cost opening',true);
$costLedger->post(2025,2,bin2hex(random_bytes(16)),'2025-12-01','Synthetic costs','Synthetic evidence',$costLines);
(new \Kulturhus\Verein\BalanceLedger($db,'cost-board'))->close(2025,3,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete accounting review');
$costService=new \Kulturhus\Verein\BalanceTaxFiling($db,'cost-finance');$costTax=new \Kulturhus\Verein\TaxFilings($db,'cost-finance');
$costId=$costService->create(2025);$costReview=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
$costProfile=$profile;$costProfile['business_name']='Synthetic operating account test';
$costProfile['company']['tax_number']='5192050001276';
$costMapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings'];
foreach($costCases['details'] as $code=>$choices)$costMapping['cost-'.$code]='is.netIncome.regular.operatingTC.'.$choices[0];
$retained=MySqlFixture::rows($db,'balance_entries');
foreach(array_keys($costCases['details']) as $code){
    $wrong=$costMapping;$wrong['cost-'.$code]='is.netIncome.regular.operatingTC.otherCost.administration';
    denied(fn()=>$costService->save($costId,1,$costProfile,$wrong,$costReview),'persist rejects unrelated detail for '.$code);
}
foreach(['51290','53000','54000','60100','68550'] as $code){
    $wrong=$costMapping;$wrong['cost-'.$code]='is.netIncome.regular.operatingTC.otherCost.insurance';
    denied(fn()=>$costService->save($costId,1,$costProfile,$wrong,$costReview),'persist rejects changed meaning for exact code '.$code);
}
$costService->save($costId,1,$costProfile,$costMapping,$costReview);
check($costTax->get($costId)['form_data']['balance_preparation']['mapping']===$costMapping,'reviewed detail choices persisted without change');
check($costTax->validate($costId,2)['valid'],'all cost groups and reviewed details generated from closed MySQL journal');
$costState=$costTax->get($costId);
check($costState['state']==='validated'&&$costState['form_data']['balance_preparation']['generated']['fact_count']>500,'generated XML stored as test only');
denied(fn()=>(new \Kulturhus\Verein\BalanceTaxFiling($db,'cost-member'))->save($costId,3,$costProfile,$costMapping,$costReview),'member cannot change reviewed mapping');
denied(fn()=>$costService->approve($costId,3,true),'treasurer cannot approve own operating filing');
(new \Kulturhus\Verein\BalanceTaxFiling($db,'cost-board'))->approve($costId,3,true);
check(isset($costTax->get($costId)['form_data']['balance_preparation']['approval']),'board approval binds exact generated cost filing');
$costMapping['cost-68370']='is.netIncome.regular.operatingTC.otherCost.concessLicenses.toNonresidentRecipients';
$costService->save($costId,4,$costProfile,$costMapping,$costReview);$costState=$costTax->get($costId);
check($costState['xml_data']===null&&!isset($costState['form_data']['balance_preparation']['approval']),'changed recipient classification invalidates XML and approval');
check($retained===MySqlFixture::rows($db,'balance_entries'),'classification never rewrites closed bookkeeping');
check($costTax->attempts($costId)===[],'operating test makes no transport attempt');
echo ($checks-$operatingBaseChecks)." additional operating filing checks passed; no transport; $operatingBaseChecks reused base checks\n";
