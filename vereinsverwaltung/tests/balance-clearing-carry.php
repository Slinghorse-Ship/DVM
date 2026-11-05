<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClearings.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require_once __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-internal-clearing.php';
use Kulturhus\Verein\{BalanceClearings as W,BalanceSegments as S,BalanceLedger as L,Database,SettingsSchema,BalanceTaxFiling as T};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $work,string $why):void{try{$work();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Clearing Carry'),('b','Synthetic Other'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('other','b','treasurer',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
[$fixture,$units,$alloc,$input]=clearingFixture();$empty=['advances'=>[],'repayments'=>[]];$ledger=new L($db,'board');$segments=new S($db,'finance');$sb=new S($db,'board');$finance=new W($db,'finance');$board=new W($db,'board');
$ledger->create(2025);$ledger->setup(2025,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic beginning balances',true);$v=2;
foreach($fixture['entries'] as $e)$ledger->post(2025,$v++,$e['id'],$e['date'],$e['description'],'Synthetic source',$e['lines']);
$ledger->close(2025,$v,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic closed original');$segments->save(2025,0,$units,$alloc);
$ledger->create(2026);
$finance->save(2025,0,1,$empty,true,'Incomplete empty proof');
check(!$finance->get(2025)['statement']['complete'],'unexplained original differences visible');
denied(fn()=>$board->confirm(2025,1,1,true,'No'),'cannot confirm incomplete reconciliation');
$finance->save(2025,1,1,$input,true,'Synthetic cash advances');$board->confirm(2025,2,1,true,'Synthetic verified reconciliation');
$prior=$finance->get(2025);check($prior['current_approval']&&$prior['statement']['complete']&&!$prior['segments']['current_approval'],'reviewed workpaper is distinct from tax allocation approval');
[$next,$nextUnits,$nextAlloc,$nextInput]=clearingNext($prior['proof'],234);
$e=$next['entries'][0];$ledger->post(2026,1,$e['id'],$e['date'],$e['description'],'Synthetic original refund',$e['lines']);$ledger->close(2026,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year closed');
$journal=MySqlFixture::rows($db,'balance_years');$entries=MySqlFixture::rows($db,'balance_entries');
denied(fn()=>$segments->carry(2026,0,false),'explicit carry review');
foreach(['member','other'] as $actor)denied(fn()=>(new S($db,$actor))->carry(2026,0,true),'carry authorization '.$actor);
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");denied(fn()=>$segments->carry(2026,0,true),'revoked prior reviewer blocks carry');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$carried=$segments->carry(2026,0,true);$s=$segments->get(2026);check($carried['schema']===2&&$s['carry_current']&&$s['carry_forward']['revision']===1&&$s['carry_forward']['clearing']['revision']===3,'carry binds exact allocation and clearing revisions');
check(!$s['allocation']['complete']&&!$s['current_approval']&&!$s['tax_export_ready'],'internal carry does not make unallocated current postings tax-ready');
$bad=$s['allocation']['allocations'];$bad['opening:0']['parts']['club']++;$bad['opening:0']['parts']['food']--;
denied(fn()=>$segments->save(2026,1,$units,$bad+$nextAlloc),'original unit openings cannot be altered');
$segments->save(2026,1,$units,$s['allocation']['allocations']+$nextAlloc);
$firstCarryClearing=$finance->save(2026,0,2,$nextInput,true,'Synthetic refund and outstanding amount');$firstCarryApproval=$board->confirm(2026,1,2,true,'Synthetic following year checked');
$current=$finance->get(2026);check($current['current_approval']&&$current['statement']['complete'],'nonempty carried reconciliation can be reviewed');
check($current['proof']['totals']===['opening_cents'=>903,'advanced_cents'=>0,'repaid_cents'=>234,'closing_cents'=>669],'exact opening and partial repayment');
$income=$finance->incomeReconciliation(2026);$firstCarriedIncome=$income;check($income['result']['taxable_book_cents']===0&&$income['internal_balances']['closing_received_cents']===669,'actual reviewed two-year income evidence ignores repayments');
foreach(['separate_business_ledger','approved_club_allocation',T::COMBINED_SOURCE] as $scope)denied(fn()=>(new T($db,'finance'))->create(2026,$scope),'workpaper cannot unlock partial tax profile '.$scope);
$wholeService=new T($db,'finance');$wholeId=$wholeService->create(2026,'complete_club_ledger');
$wholeFilings=new \Kulturhus\Verein\TaxFilings($db,'finance');$wholeDraft=$wholeFilings->get($wholeId);$wholeBinding=$wholeDraft['form_data']['balance_preparation'];
check($wholeDraft['state']==='draft'&&$wholeDraft['xml_data']===null&&!isset($wholeBinding['approval'])&&!$wholeBinding['review']['complete'],'whole carry draft has no inherited tax approval');
check($wholeBinding['profile']['tax_privileged']===false&&!$wholeBinding['profile']['scope_confirmed'],'whole carry does not silently confirm non-privileged status');
check($wholeService->sourceReport($wholeDraft)['calculation']===$ledger->report(2026)['calculation'],'whole carry keeps original opening and repayments');
denied(fn()=>$wholeFilings->ready($wholeId,1,'SEND-TEST',true,true),'carried clearing review cannot approve whole filing');
$before=MySqlFixture::snapshot($db);$segments->get(2026);$finance->get(2026);check(MySqlFixture::snapshot($db)===$before,'all history reconstruction is read-only');
// A change in just the prior workpaper must invalidate the opening binding as well.
$finance->save(2025,3,1,$input,true,'Revised evidence');
check(!$segments->get(2026)['carry_current']&&!$finance->get(2026)['current_approval'],'prior proof edit invalidates both opening and next review');
denied(fn()=>$finance->incomeReconciliation(2026),'prior review change invalidates income evidence');
denied(fn()=>$segments->save(2026,2,$units,$current['segments']['allocation']['allocations']),'stale carried opening cannot be edited');
denied(fn()=>$sb->rebase(2026,2,true,'Not approved yet'),'unreviewed prior proof blocks rebase');$board->confirm(2025,4,1,true,'Prior rechecked');
denied(fn()=>$segments->rebase(2026,2,true,'No board'),'only board can rebase');
denied(fn()=>$sb->rebase(2026,1,true,'Stale'),'stale rebase blocked');
$sb->rebase(2026,2,true,'Updated prior evidence checked');$rebased=$segments->get(2026);
check($rebased['carry_current']&&$rebased['carry_forward']['clearing']['revision']===5&&$rebased['version']===3,'clearing-only revision can be rebased');
check($rebased['allocation']['allocations']===$current['segments']['allocation']['allocations'],'rebase preserves current postings and identical original balances');
denied(fn()=>$finance->save(2026,2,3,$nextInput,true,'No board'),'only board may replace stale workpaper carry');
$board->save(2026,2,3,$nextInput,true,'Updated proof bound');$board->confirm(2026,3,3,true,'Updated next year reviewed');
check($finance->get(2026)['current_approval'],'rebased workpaper can be reviewed');
// Third year binding and transitive invalidation through both kinds of prior history.
$ledger->create(2027);$ledger->close(2027,1,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic third year');
$segments->carry(2027,0,true);$finance->save(2027,0,1,$empty,true,'Unchanged outstanding advance');$board->confirm(2027,1,1,true,'Third year checked');
check($finance->get(2027)['current_approval']&&$finance->get(2027)['proof']['totals']['closing_cents']===669,'third year original case carried');
$original2026=$finance->get(2026);$changed=$alloc;$changed['opening:0']['reason']='New synthetic original evidence';$segments->save(2025,1,$units,$changed);
check(!$segments->get(2026)['carry_current']&&!$segments->get(2027)['carry_current']&&!$finance->get(2027)['current_approval'],'source allocation edit invalidates all dependent years');
$finance->save(2025,5,2,$input,true,'New allocation checked');$board->confirm(2025,6,2,true,'Updated allocation and advances');
$sb->rebase(2026,3,true,'Updated original allocation');$board->save(2026,4,4,$nextInput,true,'Rebased original allocation');$board->confirm(2026,5,4,true,'Next year rechecked');
check(!$segments->get(2027)['carry_current'],'third year never silently refreshes');
$sb->rebase(2027,1,true,'New prior reviewed');$board->save(2027,2,2,$empty,true,'Third year prior refreshed');$board->confirm(2027,3,2,true,'Third year rechecked');
check($finance->get(2027)['current_approval'],'transitive rebase explicitly completed');
$income=$finance->incomeReconciliation(2027);check($income['scope']['previous']['previous']['source']['year']===2025&&$income['internal_balances']['closing_received_cents']===669,'income proof retains three authorized source years');
// Original carry outcomes use actual historical sources even after newer prior-year reviews.
$originalRows=MySqlFixture::snapshot($db);check($finance->save(2026,0,2,$nextInput,true,'Synthetic refund and outstanding amount')===$firstCarryClearing&&$board->confirm(2026,1,2,true,'Synthetic following year checked')===$firstCarryApproval&&MySqlFixture::snapshot($db)===$originalRows,'original carried clearing and board review remain exact after transitive three-year rebases');
$originalCarried=$segments->original(2026,1,$carried['sha256']);check($originalCarried===$carried&&MySqlFixture::snapshot($db)===$originalRows,'original allocation carry retains its exact historical clearing reference after three-year rebases');
$incomeOriginalRows=MySqlFixture::snapshot($db);check($finance->originalIncome(2026,2,$firstCarryApproval['sha256'])===$firstCarriedIncome&&MySqlFixture::snapshot($db)===$incomeOriginalRows,'historical income retains the actual original reviewed prior scope through transitive three-year rebases');
// A newly pending correction in the PRIOR year cannot substitute for either old original.
$correctionService=new \Kulturhus\Verein\BalanceClosingCorrections($db,'board');$closing=$ledger->report(2025);$pending=$correctionService->save(2025,0,$closing['closed_sha256'],['kind'=>'error','reason'=>'Own pending prior correction','legal_basis'=>'Own separately reviewed evidence','confirmed'=>true,'entries'=>[['id'=>str_repeat('f',32),'date'=>'2025-12-31','description'=>'Own pending original prior expense','evidence'=>'Own prior invoice','lines'=>[['account'=>'expense','debit_cents'=>1,'credit_cents'=>0,'sphere'=>'4'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'']]]]]);
$originalRows=MySqlFixture::snapshot($db);check($finance->save(2026,0,2,$nextInput,true,'Synthetic refund and outstanding amount')===$firstCarryClearing&&$board->confirm(2026,1,2,true,'Synthetic following year checked')===$firstCarryApproval&&$segments->original(2026,1,$carried['sha256'])===$carried&&MySqlFixture::snapshot($db)===$originalRows,'exact original carried clearing and allocation survive a newly pending prior-year closing correction without any writes');
check($finance->originalIncome(2026,2,$firstCarryApproval['sha256'])===$firstCarriedIncome&&MySqlFixture::snapshot($db)===$originalRows,'historical income remains exact while a new prior-year closing correction blocks current work');
$correctionService->discard(2025,1,$closing['closed_sha256'],'Own explicit cleanup of pending prior correction');
// Restoring the encrypted backup retains both original and superseded source bindings.
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')!=='1'){
    $current=$finance->get(2027);$restored=MySqlFixture::restoreBackup($db);
    check((new W($restored,'board'))->get(2027)===$current,'encrypted restore reproduces three-year reconciliation exactly');
    check((new W($restored,'board'))->incomeReconciliation(2027)===$income,'encrypted restore reproduces income proof exactly');
    check(!MySqlFixture::foreignKeyErrors($restored),'restored relational boundaries');
}else echo "SKIP optional backup roundtrip: dedicated synthetic database\n";
check(MySqlFixture::rows($db,'balance_entries')===$entries,'no source voucher modified by clearing workflow');
check(array_slice(MySqlFixture::rows($db,'balance_years'),0,count($journal))===$journal,'first two original journals unchanged');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);
denied(fn()=>$segments->carry(2027,4,true),'EÜR cannot start balance carry');denied(fn()=>$finance->get(2027),'EÜR does not expose clearing workspace');$setting->execute([json_encode($settings)]);
// Corrupted source reference is rejected even if an operator recalculates the row hash.
$payload=$segments->get(2027)['history'][1];unset($payload['sha256']);$payload['revision']=3;$payload['previous_sha256']=$segments->get(2027)['history'][1]['sha256'];$payload['carry_forward']['clearing']['revision']++;
$db->prepare('INSERT INTO balance_segment_reviews VALUES(?,?,?,?,?)')->execute(['a',2027,3,json_encode($payload),\Kulturhus\Verein\BalanceSegmentCalculation::hash($payload)]);
denied(fn()=>$segments->get(2027),'nonexistent prior review reference rejected');
echo "$checks clearing carry checks passed; isolated synthetic MySQL, no transport\n";
}
