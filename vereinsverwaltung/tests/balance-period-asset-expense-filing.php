<?php declare(strict_types=1);
require __DIR__.'/balance-period-sphere-filing.php';
use Kulturhus\Verein\{BalanceClubTaxReconciliation as AssetReconciliation,BalancePeriodTaxReconciliations as AssetReviews};
use Kulturhus\Verein\Tests\MySqlFixture;
$baseChecks=$n;$n=0;$originalRecords=array_map(fn($id)=>$taxService->get($id),$ids);$originalJournal=$l->history($pid);
$first=$taxReview->get($pid);$originalHistory=$first['history'];$choices=AssetReconciliation::correctionSources($first['values']);$assetKey=null;
foreach($choices as $key=>$row)if(($row['asset_id']??null)==='device'){$assetKey=$key;$assetExpense=$row;break;}
check(is_string($assetKey)&&$assetExpense['cents']>0,'actual stored item tax depreciation available');
$renderExpense=static function(string $actor='sphere-admin',array $post=[])use($db,$pid):string{
    $_SESSION=['actor'=>$actor,'csrf'=>'synthetic'];$_GET=['periods'=>'1','period_tax'=>'1','period_id'=>$pid];$_POST=$post;$_SERVER['REQUEST_METHOD']='GET';$year=2026;
    ob_start();try{require __DIR__.'/../views/Finance/Balance/balance-tax-reconciliation.php';return ob_get_contents();}finally{ob_end_clean();}
};
$html=$renderExpense();check(str_contains($html,'value="'.$assetKey.'"')&&str_contains($html,'INV-S')&&str_contains($html,'Abschreibung (steuerlich)'),'named tax item offered in actual form');
check(!str_contains($html,'<select name="asset"'),'unsupported competing tax plan not offered');
foreach($first['values']['off_balance']['sources'] as $key=>$row)if(($first['values']['valuation']['accounts'][$row['account']]['skr_code']??'')==='62200')check(!str_contains($html,'value="'.$key.'"'),'ambiguous raw depreciation absent from selection');
$post=['action'=>'balance-period-tax-off-balance','period_id'=>$pid,'version'=>'2','source_line'=>$assetKey,'amount'=>'0,001','legal_basis'=>'<Synthetic keep>','evidence'=>'Synthetic failed form'];
$html=$renderExpense('sphere-admin',$post);check(str_contains($html,'value="'.$assetKey.'" selected')&&str_contains($html,'value="0,001"')&&str_contains($html,'&lt;Synthetic keep&gt;'),'failed item selection and values stay visible and escaped');
check(!str_contains($renderExpense('sphere-reader'),'<form'),'reader sees results without write forms');
$review=$first['values']['review'];$delta=max(1,intdiv($assetExpense['cents'],2));
$review['off_balance_adjustments'][]=['id'=>'specific-asset','kind'=>'non_deductible_expense','sources'=>[$assetKey=>$delta],'reason'=>'Synthetic reviewed asset expense','legal_basis'=>'Synthetic documented tax rule','confirmed'=>true];
$snapshot=MySqlFixture::snapshot($db);
foreach(['sphere-reader','sphere-member','other'] as $actor)denied(fn()=>(new AssetReviews($db,$actor))->save($pid,2,$review,'Synthetic denied'),'item correction role or tenant denied '.$actor);
$bad=$review;$bad['off_balance_adjustments'][0]['sources'][$assetKey]=$assetExpense['cents']+1;
denied(fn()=>$taxReview->save($pid,2,$bad,'Synthetic oversized'),'stored excess rejected');
check($snapshot===MySqlFixture::snapshot($db),'rejected item corrections make no writes');
$taxReview->save($pid,2,$review,'Synthetic explicit item correction');$changed=$taxReview->get($pid);
check(!$changed['current_approval']&&$changed['values']['result']['taxable_income_cents']===$first['values']['result']['taxable_income_cents']+$delta,'item correction saves as unapproved draft');
check(str_contains($renderExpense(),'Abschreibung (steuerlich) · noch '.number_format(($assetExpense['cents']-$delta)/100,2,',','.').' €'),'remaining selectable amount shown after partial correction');
check(array_slice($changed['history'],0,count($originalHistory))===$originalHistory,'previous tax evidence immutable');
denied(fn()=>$taxReview->save($pid,2,$review,'Synthetic stale'),'stale repeated correction denied');
denied(fn()=>$taxService->ready($ids[0],4,'SEND-TEST',true,true),'old prepared XML cannot use new unapproved tax result');
check(!$taxReview->get($p2)['carry_current'],'successor requires review of corrected prior');
$taxReview->confirm($pid,3,$review['income_sha256'],true,'Synthetic board checked exact item amount');
$successorReview=$taxReview->draft($p2);check($successorReview['off_balance_adjustments']===[],'old addback is not copied into successor');
$taxReview->save($p2,2,$successorReview,'Synthetic reviewed prior tax source');$taxReview->confirm($p2,3,$successorReview['income_sha256'],true,'Synthetic reviewed unchanged carry');
foreach([$pid,$p2] as $i=>$periodId){
    $state=$taxReview->get($periodId);check($state['current_approval']&&$state['values']['result']['additions_cents']===($i?0:$delta),'approved current-period correction only');
    $id=$filings->createPeriod($periodId);$filings->save($id,1,$profile,$mapping,['complete'=>true]);
    $valid=$taxService->validate($id,2);check($valid['valid'],'real new item-correction XML generation');
    $record=$taxService->get($id);$xml=$record['xml_data'];
    check($i||str_contains($xml,'Steuerliche Abschreibung: INV-S - Synthetic equipment'),'XML identifies actual asset without internal key');
    check(!str_contains($xml,'asset-depreciation:'),'internal source hashes not shown as XML explanation');
    $filings->approve($id,3,true);check($taxService->ready($id,4,'SEND-TEST',true,true)['id']===$id,'new exact XML and source approved');
    check($taxService->attempts($id)===[],'no item correction sent');
    file_put_contents(__DIR__.'/balance-period-workflow-asset-expense-'.($i?'successor':'first').'.xml',$xml);
}
check(array_map(fn($id)=>$taxService->get($id),$ids)===$originalRecords,'previous prepared declarations and XML unchanged');
check($l->history($pid)===$originalJournal,'item tax correction does not alter journal');
// Removing a correction changes the current review, never the earlier evidence.
$beforeRemoval=$taxReview->get($pid);$remove=$beforeRemoval['values']['review'];$remove['off_balance_adjustments']=[];
$taxReview->save($pid,4,$remove,'Synthetic item correction removed');$removed=$taxReview->get($pid);
check(!$removed['current_approval']&&$removed['values']===$first['values'],'removal restores original calculation but requires fresh approval');
check(array_slice($removed['history'],0,count($beforeRemoval['history']))===$beforeRemoval['history'],'removal preserves every previous review');
check(!MySqlFixture::foreignKeyErrors($db),'item expense workflow foreign keys intact');
echo "$n stored item-expense checks passed plus $baseChecks sphere filing checks; actual XML, no transport\n";
