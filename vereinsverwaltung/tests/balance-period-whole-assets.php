<?php declare(strict_types=1);
require __DIR__.'/balance-period-filing-workflow-assets.php';
$priorChecks=$n;$n=0;$previousWhole=null;
$wholeProfile=$assetProfile;unset($wholeProfile['whole_business_name'],$wholeProfile['whole_book_standard']);$wholeProfile['business_name']='Synthetic complete club with assets';$wholeProfile['tax_privileged']=false;
foreach([$pid,$pid2] as $target){
    $id=$datedFiling->createPeriod($target,'complete_club_ledger');
    if($previousWhole!==null)$datedFiling->carryForward($id,1,$previousWhole,true);
    $filing=$datedTax->get($id);$b=$filing['form_data']['balance_preparation'];$report=$datedFiling->sourceReport($filing);
    $review=$b['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;
    $map=[];foreach($report['accounts'] as $key=>$account){$choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};if($choice!==null)$map[$key]=$choice;}
    $datedFiling->save($id,$filing['version'],$wholeProfile,$map,$review);$filing=$datedTax->get($id);
    $v=$datedFiling->assertCurrent($filing);
    check($v['asset_schedule']===$report['asset_schedule']&&$v['tax']===$v['book'],'whole assets keep original plans and every sphere');
    check($datedTax->validate($id,$filing['version'])['valid'],'whole original dated assets accepted by real generator');
    $filing=$datedTax->get($id);$datedFiling->approve($id,$filing['version'],true);$filing=$datedTax->get($id);
    $html=$renderFiling($id);check(str_contains($html,'Synthetic amplifier')&&str_contains($html,'Restbuchwert')&&str_contains($html,'Gesamter Verein · nicht steuerbegünstigt'),'whole asset UI handles nested period schedules');
    check($datedTax->send($id,$filing['version'],'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','whole asset simulated receipt');
    file_put_contents(__DIR__.'/balance-period-workflow-whole-assets-'.($previousWhole===null?'first':'successor').'.xml',$datedTax->get($id)['xml_data']);
    $previousWhole=$id;
}
check($originalEvents===\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events'),'whole asset workflow never changes invoices or journal');
echo "$n dated whole asset workflow checks passed plus $priorChecks subsidiary asset checks; simulated transport only\n";
