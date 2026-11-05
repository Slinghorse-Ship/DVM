<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{BalanceLedger,BalanceTaxFiling};
$prior=$tax->get($adjusted);$bs->approve($adjusted,(int)$prior['version'],true);
$prior=$tax->get($adjusted);$tax->send($adjusted,(int)$prior['version'],'dummy','123456','SEND-TEST',true,true);
$prior=$tax->get($adjusted);$priorProfile=array_intersect_key($prior['form_data']['balance_preparation']['profile'],$profile);
$ledger->create(2026);(new BalanceLedger($db,'board'))->close(2026,1,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year');
$next=$svc->create(2026);$svc->save($next,1,$priorProfile,$mapping,$review);
denied(fn()=>$tax->validate($next,2),'following year cannot omit tax carry decision');
denied(fn()=>$svc->carryForward($next,2,$adjusted,false),'carry requires explicit confirmation');
denied(fn()=>$svc->carryForward($next,2,$waiting,true),'unsent source cannot be used');
denied(fn()=>$svc->carryForward($next,2,$failed,true),'different business cannot be used');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->carryForward($next,2,$adjusted,true),'cross club carry denied');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");
$svc->carryForward($next,2,$adjusted,true);$nextRow=$tax->get($next);$b=$nextRow['form_data']['balance_preparation'];
check(isset($b['carry_forward']),'board change preserves already transmitted historical approval');
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
check(!$b['review']['complete']&&count($b['review']['opening_adjustments'])===1,'carry requires fresh review and stores one adjustment');
denied(fn()=>$svc->carryForward($next,3,$adjusted,true),'duplicate carry denied');
$nextReview=$b['review'];unset($nextReview['year'],$nextReview['journal_sha256']);$nextReview['complete']=true;
$erased=$nextReview;$erased['opening_adjustments']=[];
denied(fn()=>$svc->save($next,3,$priorProfile,$mapping,$erased),'carry cannot be silently erased');
$changed=$nextReview;$changed['opening_adjustments'][0]['lines'][0]['debit_cents']++;
denied(fn()=>$svc->save($next,3,$priorProfile,$mapping,$changed),'carry amount cannot be edited');
$svc->save($next,3,$priorProfile,$mapping,$nextReview);$values=$svc->assertCurrent($tax->get($next));
check($values['accounts']['cash']['tax_opening_cents']-$values['accounts']['cash']['book_opening_cents']===100,'tax asset difference survives year change');
check($values['accounts']['equity']['tax_opening_cents']-$values['accounts']['equity']['book_opening_cents']===-100,'prior tax profit moves to equity');
check($values['result_difference_cents']===0&&$values['tax']['annual_result_cents']===0,'prior difference is not current-year income');
check($tax->validate($next,4)['valid'],'carried values reach actual XBRL generator');
$sourceCopy=$prior['form_data'];$sourceCopy['balance_preparation']['review']['current_adjustments'][0]['reason']='Changed after confirmation';
$db->prepare('UPDATE tax_filings SET form_data=? WHERE id=?')->execute([json_encode($sourceCopy),$adjusted]);
denied(fn()=>$svc->assertCurrent($tax->get($next)),'later source drift invalidates carry');
$db->prepare('UPDATE tax_filings SET form_data=? WHERE id=?')->execute([json_encode($prior['form_data']),$adjusted]);
check($svc->assertCurrent($tax->get($next))['tax']['annual_result_cents']===0,'restored original source verifies');
echo "$checks combined filing/carry checks passed; synthetic MySQL only\n";
}
