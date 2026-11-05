<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodExport.php';
require __DIR__.'/fixtures/balance-internal-clearing.php';
use Kulturhus\Verein\{BalancePeriodAreas as A,BalancePeriodLedger as L,BalancePeriodExport as E,BalanceSegmentCalculation as S,BalanceInternalClearing as W,BalanceClearingStatement as C,BalanceCalculation as B,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic dated areas'),('b','Synthetic other'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('other','b','treasurer',1)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic read','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
[$fixture,$units,$alloc,$instructions]=clearingFixture(100);
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$l=new L($db,'board');$id=$l->create($period,'Synthetic fiscal year',true);$l->setup($id,1,$fixture['opening'],'Synthetic original opening',true);
foreach($fixture['entries'] as $entry){$date=str_replace(['2025-02','2025-03','2025-04'],['2025-08','2025-09','2026-02'],$entry['date']);$r=$l->report($id);$l->post($id,$r['version'],$entry['id'],$date,$entry['description'],'Synthetic source',$entry['lines']);}
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$r=$l->report($id);$l->close($id,$r['version'],$r['journal_sha256'],$all,'Synthetic board close');$closed=$l->report($id);
$original=MySqlFixture::rows($db,'balance_period_events');$a=new A($db,'board');$view=$a->view($id);$hash=$view['source']['closed_sha256'];
check($view['state']==='missing'&&$view['source']['period']===$period,'actual period and no invented area review');
$before=MySqlFixture::snapshot($db);$a->view($id);check($before===MySqlFixture::snapshot($db),'view is read-only');
foreach(['other','member'] as $actor)denied(fn()=>(new A($db,$actor))->view($id),'cannot read foreign or unauthorized areas '.$actor);
foreach(['reader','member','other'] as $actor)denied(fn()=>(new A($db,$actor))->prepare($id,0,$hash,$units,'Synthetic no'),'cannot write areas '.$actor);
$firstPrepare=$a->prepare($id,0,$hash,$units,'Synthetic basis');$s=$a->view($id);check($s['version']===1&&!$s['proof']['complete'],'initial draft shows missing allocations');
denied(fn()=>$a->prepare($id,0,$hash,$units,'Synthetic stale'),'stale tab cannot replace review');
foreach($alloc as $key=>$value){$s=$a->view($id);$a->edit($id,$s['version'],$hash,'line',['key'=>$key,'parts'=>$value['parts']],$value['reason']);}
$s=$a->view($id);check(!$s['proof']['complete']&&!$s['proof']['allocation']['missing'],'unexplained advances block approval');
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreaForm.php';$pendingAdvanceChecked=false;
foreach($instructions['advances'] as $key=>$row){$row['repay_by']=$row['to']==='food'?'2026-05-31':'2026-09-30';$a->edit($id,$a->view($id)['version'],$hash,'advance',['id'=>$key]+$row,'Synthetic agreement');if(!$pendingAdvanceChecked){$pending=$a->view($id);$pendingChoices=\Kulturhus\Verein\BalancePeriodAreaForm::advanceChoices($pending);check(!$pending['proof']['complete']&&isset($pendingChoices[$key])&&$pendingChoices[$key]['pending'],'saved advance remains selectable for repayment while other source allocations are incomplete');$pendingAdvanceChecked=true;}}
foreach($instructions['repayments'] as $key=>$row)$a->edit($id,$a->view($id)['version'],$hash,'repayment',['id'=>$key]+$row,'Synthetic repayment');
$s=$a->view($id);check($s['proof']['complete']&&$s['proof']['statement']['eliminated_internal_cents']['closing_cents']===903,'mixed advance and partial repayment reconcile');
$shop=substr(hash('sha256','clearing shop'),0,32);$food=substr(hash('sha256','clearing food'),0,32);
check($s['proof']['clearing']['cases'][$shop]['status']==='open','due after June not falsely overdue in December');
check($s['proof']['clearing']['cases'][$food]['status']==='overdue','due before actual closing is overdue');
check($s['proof']['clearing']['cases'][$food]['origin_period']===$period,'origin records full date interval');
denied(fn()=>(new A($db,'finance'))->confirm($id,$s['version'],$hash,$s['sha256'],true,'No'),'treasurer prepares but cannot confirm');
denied(fn()=>$a->confirm($id,$s['version'],$hash,str_repeat('0',64),true,'No'),'confirmation bound to exact review');
denied(fn()=>$a->confirm($id,$s['version'],$hash,$s['sha256'],false,'No'),'explicit confirmation required');
$firstBoardVersion=$s['version'];$firstBoardHash=$s['sha256'];$firstBoard=$a->confirm($id,$s['version'],$hash,$s['sha256'],true,'Synthetic board area review');$first=$a->view($id);
check($first['current_approval']&&$first['proof']['statement']['club_calculation']===$closed['calculation'],'approved subdivisions leave original totals intact');
check(MySqlFixture::rows($db,'balance_period_events')===$original,'area work never rewrites original journal');
$bundle=(new E($db,'board'))->build($id);check(isset($bundle['files']['vereinsbereiche.csv'],$bundle['files']['vereinsbereiche.json'])&&str_contains($bundle['files']['vereinsbereiche.csv'],$id),'export contains exact dated reviews and totals');
$income=$a->incomeReconciliation($id);check($income['period']===$period&&$income['result']['whole_cents']===$closed['calculation']['annual_result_cents']&&$income['internal_balances']['closing_received_cents']===903,'result reconciliation separates all taxable operations and internal advances');
check(!$income['tax_values_reviewed']&&!$income['tax_export_ready']&&str_contains($bundle['files']['ergebnisabgleich.txt'],'2025-07-01 bis 2026-06-30'),'working result is dated and not a tax filing');
$id2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short year',true);$r2=$l->report($id2);$pay=str_repeat('d',32);
$l->post($id2,$r2['version'],$pay,'2026-08-01','Synthetic final repayment','Synthetic source',[['account'=>'cash','debit_cents'=>234,'credit_cents'=>0,'sphere'=>''],['account'=>'cash','debit_cents'=>0,'credit_cents'=>234,'sphere'=>'']]);
$r2=$l->report($id2);$l->close($id2,$r2['version'],$r2['journal_sha256'],$all,'Synthetic short close');$hash2=$l->report($id2)['closed_sha256'];
$a->prepare($id2,0,$hash2,$units,'Synthetic carry');$s2=$a->view($id2);
check($s2['source']['year']===$first['source']['year']&&$s2['period_id']!==$first['period_id'],'same end year remains two separate reviews');
foreach(['club','food'] as $ordinal=>$unit)$a->edit($id2,$a->view($id2)['version'],$hash2,'line',['key'=>$pay.':'.$ordinal,'parts'=>[$unit=>234]],'Synthetic repayment source');
$a->edit($id2,$a->view($id2)['version'],$hash2,'repayment',['id'=>str_repeat('e',32),'voucher'=>$pay,'advance'=>$food,'cents'=>234,'reason'=>'Synthetic final settlement'],'Synthetic settlement');
$s2=$a->view($id2);check($s2['proof']['complete']&&$s2['proof']['clearing']['totals']===['opening_cents'=>903,'advanced_cents'=>0,'repaid_cents'=>234,'closing_cents'=>669],'nonempty advance carried across two periods ending same year');
check($s2['proof']['statement']['club_calculation']['annual_result_cents']===0,'repayment does not create income or expense');
denied(fn()=>$a->edit($id2,$s2['version'],$hash2,'line',['key'=>'opening:0','parts'=>['club'=>100]],'No'),'carried opening not manually replaced');
$a->confirm($id2,$s2['version'],$hash2,$s2['sha256'],true,'Synthetic second review');check($a->view($id2)['current_approval'],'second period approval independent');
$income2=$a->incomeReconciliation($id2);check($income2['scope']['previous']===$income['scope']&&$income2['result']['whole_cents']===0&&$income2['internal_balances']['closing_received_cents']===669,'result reconciliation binds separate same-end-year predecessor without repayment income');
$originalSecond=$a->view($id2);$selectedOriginal=$a->originalState($id2,$originalSecond['version'],$originalSecond['sha256']);
check(!$selectedOriginal['current_approval']&&$selectedOriginal['source']===$originalSecond['source'],'original dated state uses actual retained sources without granting approval');
check($a->originalIncome($id2,$originalSecond['version'],$originalSecond['sha256'])===$income2,'pure dated original income exactly equals its then-current approved scope');
$forgedIncome=$income2['scope'];$forgedIncome['source']['period']['start']='2026-06-01';denied(fn()=>\Kulturhus\Verein\BalanceClubIncomeReconciliation::rebuild($forgedIncome),'result proof cannot substitute different dates');
require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReconciliation.php';denied(fn()=>\Kulturhus\Verein\BalanceClubTaxReconciliation::build($income2,[]),'dated result cannot enter unadapted calendar tax computation');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");denied(fn()=>$a->view($id),'revoked closing authority blocks first period');denied(fn()=>$a->view($id2),'revoked closing authority blocks successor');check($a->originalIncome($id2,$originalSecond['version'],$originalSecond['sha256'])===$income2,'original dated prior chain survives actual revoked board authority');
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
// Re-review of prior proof invalidates later signatures, but not original figures.
$a->prepare($id,$a->view($id)['version'],$hash,$units,'Synthetic renewed review');$s=$a->view($id);$a->confirm($id,$s['version'],$hash,$s['sha256'],true,'Synthetic renewed board review');
check(!$a->view($id2)['current_approval'],'new prior approval requires explicit successor reconciliation');
denied(fn()=>(new E($db,'board'))->build($id2),'stale successor review blocks export');
$a->prepare($id2,$a->view($id2)['version'],$hash2,$units,'Synthetic rebase');$s2=$a->view($id2);$a->confirm($id2,$s2['version'],$hash2,$s2['sha256'],true,'Synthetic rebase review');check($a->view($id2)['current_approval'],'unchanged running allocations survive explicit rebase');
$oldReviews=MySqlFixture::rows($db,'balance_period_area_reviews');$oldHistory=$a->view($id)['history'];
$correction=['kind'=>'error','reason'=>'Synthetic missing expense','legal_basis'=>'Synthetic original proof','confirmed'=>true,'entries'=>[['id'=>str_repeat('f',32),'date'=>'2026-06-30','description'=>'Synthetic correction','evidence'=>'Synthetic original','lines'=>[['account'=>'expense','debit_cents'=>7,'credit_cents'=>0,'sphere'=>'1'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>7,'sphere'=>'']]]]];
$r=$l->report($id);$l->prepareClosingCorrection($id,$r['version'],$r['closed_sha256'],$correction);$r=$l->report($id);$l->confirmClosingCorrection($id,$r['version'],$r['closed_sha256'],$r['correction_draft']['proof']['sha256'],true,'Synthetic correction review');
$s=$a->view($id);check(!$s['source_current']&&!$s['current_approval'],'confirmed closing correction invalidates old area review');$hash=$s['source']['closed_sha256'];
$a->prepare($id,$s['version'],$hash,$units,'Synthetic corrected areas');$s=$a->view($id);
check($s['proof']['complete']&&$s['input']['instructions']===$first['input']['instructions'],'correction retains advances and allocates unambiguous new expense');
$a->confirm($id,$s['version'],$hash,$s['sha256'],true,'Synthetic corrected area approval');
check(array_slice($a->view($id)['history'],0,count($oldHistory))===$oldHistory,'correction preserves every earlier area snapshot');
check($a->originalIncome($id2,$originalSecond['version'],$originalSecond['sha256'])===$income2,'original dated income and nonempty prior advance survive actual predecessor correction');
$beforeRepeat=MySqlFixture::snapshot($db);
check($a->prepare($id,0,$first['source']['closed_sha256'],$units,'Synthetic basis')===$firstPrepare,'same original prepare returns its actual first row after genuine source replacement');
check($a->confirm($id,$firstBoardVersion,$first['source']['closed_sha256'],$firstBoardHash,true,'Synthetic board area review')===$firstBoard,'same original confirmation returns its first actual reviewed row after newer reviews');
check(MySqlFixture::snapshot($db)===$beforeRepeat,'both original repeats leave every whole stored original unchanged');
$carry=['kind'=>'carry','reason'=>'Synthetic corrected predecessor','legal_basis'=>'Synthetic carry proof','confirmed'=>true,'entries'=>[]];$r=$l->report($id2);
$l->prepareClosingCorrection($id2,$r['version'],$r['closed_sha256'],$carry);$r=$l->report($id2);$l->confirmClosingCorrection($id2,$r['version'],$r['closed_sha256'],$r['correction_draft']['proof']['sha256'],true,'Synthetic corrected opening');
$hash2=$l->report($id2)['closed_sha256'];$a->prepare($id2,$a->view($id2)['version'],$hash2,$units,'Synthetic corrected carry');$s2=$a->view($id2);
check($s2['proof']['complete']&&$s2['proof']['clearing']['totals']['closing_cents']===669&&$s2['proof']['statement']['club_calculation']['annual_result_cents']===0,'corrected source and nonempty internal carry do not repeat prior expense');
$a->confirm($id2,$s2['version'],$hash2,$s2['sha256'],true,'Synthetic corrected successor review');
check(count($a->view($id2)['history'])>2&&$a->view($id2)['current_approval'],'corrected successor review and original history both retained');
foreach(['UPDATE balance_period_area_reviews SET revision=revision','DELETE FROM balance_period_area_reviews'] as $sql){try{$db->exec($sql);throw new RuntimeException('review changed');}catch(PDOException){check(true,'native immutable history guard');}}
putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$a->prepare($id,$a->view($id)['version'],$hash,$units,'No'),'configured-off period write gate');putenv('VEREIN_BALANCE_PERIODS=1');
$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$a->prepare($id,$a->view($id)['version'],$hash,$units,'No'),'EÜR cannot acquire area writes');$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($settings)]);
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}function csrf():string{return hidden('csrf','synthetic-csrf');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor,array $post=[])use($db,$id):string{$_SESSION=['actor'=>$actor];$_GET=['period_id'=>$id,'areas'=>'1','area_voucher'=>str_repeat('a',32)];$_POST=$post;$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-periods.php';return ob_get_clean();};
$html=$render('board');check(str_contains($html,'01.07.2025 – 30.06.2026')&&str_contains($html,'Vereinsbereiche prüfen'),'guided view retains exact period');
check(str_contains($html,'synthetic-csrf')&&str_contains($html,'balance-period-area-advance'),'writer sees protected advance form');
check(!str_contains($render('reader'),'value="balance-period-area-'),'reader receives no write actions');
$failed=$render('board',['action'=>'balance-period-area-line','period_id'=>$id,'closed_hash'=>$first['source']['closed_sha256'],'area_prior_period'=>'','area_prior_revision'=>'','area_prior_sha256'=>'','area_request_key'=>str_repeat('1',32),'area_form_complete'=>'1','area_version'=>'1','area_voucher'=>str_repeat('a',32),'area_line'=>str_repeat('a',32).':0','parts'=>['food'=>'3<script>'],'evidence'=>'Synthetic <script>']);
check(str_contains($failed,'value="3&lt;script&gt;"')&&str_contains($failed,'value="Synthetic &lt;script&gt;"'),'failed form retains and escapes amount and evidence');
check(str_contains($failed,'name="area_version" value="1"'),'failed form does not silently adopt latest version');
final class AreaRedirect extends RuntimeException{public function __construct(public array $target){parent::__construct('Synthetic redirect');}}
function redirectTo(array $target):never{throw new AreaRedirect($target);}
$control=static function(array $post,string $actor='board')use($db):array{$_SESSION=['actor'=>$actor];$_POST=$post;$action=input($post,'action');try{require __DIR__.'/../src/Finance/Balance/BalanceActions.php';}catch(AreaRedirect $r){return $r->target;}throw new RuntimeException('Missing redirect');};
$s=$a->view($id);$post=['area_prior_period'=>'','area_prior_revision'=>'','area_prior_sha256'=>'','area_request_key'=>str_repeat('2',32),'area_form_complete'=>'1','action'=>'balance-period-area-line','period_id'=>$id,'area_version'=>(string)$s['version'],'closed_hash'=>$hash,'area_line'=>str_repeat('f',32).':0','area_voucher'=>str_repeat('f',32),'parts'=>['club'=>'0,07'],'evidence'=>'Synthetic controller'];
$recoveryBefore=MySqlFixture::snapshot($db);$recovered=$render('reader',$post);preg_match('/<pre hidden data-original-finance-retained-fields>(.*?)<\/pre>/s',$recovered,$recoveryMatch);check(str_contains($recovered,'data-original-finance-inputs')&&!str_contains($recovered,'<form')&&json_decode(html_entity_decode($recoveryMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR)===array_diff_key($post,['csrf'=>true])&&MySqlFixture::snapshot($db)===$recoveryBefore,'dated original area entries remain completely readable with read rights and no writes');
$bad=$post;$bad['parts']['club']='0,001';$before=MySqlFixture::snapshot($db);denied(fn()=>$control($bad),'controller rejects fractional cents');denied(fn()=>$control($post,'reader'),'controller repeats write permission');check($before===MySqlFixture::snapshot($db),'rejected controller requests leave every table unchanged');
$route=$control($post);check(($route['period_id']??null)===$id&&($route['areas']??null)==='1'&&($route['area_voucher']??null)===str_repeat('f',32),'controller keeps exact period and selected source');
$beforeRepeat=MySqlFixture::snapshot($db);check($control($post)===$route&&MySqlFixture::snapshot($db)===$beforeRepeat,'actual repeated controller request returns the same original receipt without another row');$s=$a->view($id);$a->confirm($id,$s['version'],$hash,$s['sha256'],true,'Synthetic controller review');
// Update successor approval after this deliberate new source review.
$a->prepare($id2,$a->view($id2)['version'],$hash2,$units,'Synthetic final carry');$s2=$a->view($id2);$a->confirm($id2,$s2['version'],$hash2,$s2['sha256'],true,'Synthetic final carry review');
file_put_contents(__DIR__.'/balance-period-areas-ui.html',$html);
check(MySqlFixture::foreignKeyErrors($db)===[],'tenant and review foreign keys intact');
$state=$a->view($id);$_SESSION=['actor'=>'reader'];$_GET=['periods'=>'1','period_id'=>$id,'areas'=>'1','area_original'=>(string)$firstBoard['revision'],'area_original_sha256'=>$firstBoard['sha256']];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-periods.php';$originalHtml=ob_get_clean();check(str_contains($originalHtml,'data-period-area-original=')&&!str_contains($originalHtml,'<form'),'actual reader opens exact original area receipt with no business form');
// Even a newly rehashed forged record cannot substitute an invented predecessor.
$s2=$a->view($id2);$forged=end($s2['history']);unset($forged['sha256']);$forged['revision']++;$forged['previous_sha256']=$s2['sha256'];$forged['state']='draft';$forged['carry']['revision']=9999;
$db->prepare('INSERT INTO balance_period_area_reviews(club_id,period_id,revision,payload,sha256) VALUES(?,?,?,?,?)')->execute(['a',$id2,$forged['revision'],L::json($forged),S::hash($forged)]);
denied(fn()=>$a->view($id2),'rehashed nonexistent predecessor cannot validate carried balances');
echo "$checks dated area workflow checks passed; synthetic MySQL only, no mail/bank/tax submission\n";
}
