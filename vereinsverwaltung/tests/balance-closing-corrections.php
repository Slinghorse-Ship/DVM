<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClosingCorrections.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxReconciliations.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceClosingCorrections as C,BalanceLedger as L,BalanceSegmentCalculation as SC,BalanceSegments as S,BalanceClearings as W,BalanceTaxReconciliations as T,BalanceTaxFiling as F,Database,SettingsSchema,TaxPreparation};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
function pair(string $d,string $c,int $n):array{return [['account'=>$d,'debit_cents'=>$n,'credit_cents'=>0,'sphere'=>in_array($d,['expense','revenue'],true)?'4':''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>in_array($c,['expense','revenue'],true)?'4':'']];}
function allocate(array $r):array{$out=[];foreach(SC::sources($r) as $id=>$line)$out[$id]=['parts'=>['shop'=>$line['cents']],'reason'=>'Synthetic actual business'];return $out;}
function proposal(string $id='a',int $cents=401):array{return ['kind'=>'error','reason'=>'Synthetic missing invoice <script>','legal_basis'=>'Synthetic reviewed legal basis','confirmed'=>true,'entries'=>[['id'=>str_repeat($id,32),'date'=>'2025-12-31','description'=>'Synthetic accrual','evidence'=>'Synthetic invoice','lines'=>pair('expense','liabilities',$cents)]]];}
$db=MySqlFixture::connect();Database::migrate($db);$schema=MySqlFixture::rows($db,'schema_migrations');Database::migrate($db);check($schema===MySqlFixture::rows($db,'schema_migrations'),'schema replay stable');
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Correction Club'),('b','Synthetic EÜR Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('other','b','treasurer',1),('reader','a','member',0)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic reader','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$ledger=new L($db,'board');$c=new C($db,'finance');$board=new C($db,'board');$s=new S($db,'board');$w=new W($db,'board');$t=new T($db,'board');
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
$ledger->create(2025);$ledger->setup(2025,1,L::defaultChart(),pair('cash','equity',100000),'equity','Synthetic opening',true);$ledger->post(2025,2,str_repeat('1',32),'2025-10-01','Synthetic revenue','Synthetic receipt',pair('cash','revenue',2000));$ledger->close(2025,3,$all,'Synthetic board review');
$raw=$ledger->report(2025);$hash=$raw['closed_sha256'];$units=['shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
$s->save(2025,0,$units,allocate($raw));$s->confirm(2025,1,true,'Synthetic allocation');
$w->save(2025,0,2,['advances'=>[],'repayments'=>[]],true,'Synthetic no internal debts');$w->confirm(2025,1,2,true,'Synthetic review');
$t->save(2025,0,$t->draft(2025),'Synthetic tax values');$t->confirm(2025,1,$t->get(2025)['income']['sha256'],true,'Synthetic tax review');
check($t->get(2025)['current_approval'],'tax chain initially approved');
$filing=(new F($db,'board'))->create(2025,F::SUBSIDIARY_SOURCE);
$ledger->create(2026);$ledger->close(2026,1,$all,'Synthetic following close');$ledger->create(2027);
$s->carry(2026,0,true);$s->confirm(2026,1,true,'Synthetic next allocation');$w->save(2026,0,2,['advances'=>[],'repayments'=>[]],true,'Synthetic next clearing');$w->confirm(2026,1,2,true,'Synthetic next review');
$t->save(2026,0,$t->draft(2026),'Synthetic next tax');$t->confirm(2026,1,$t->get(2026)['income']['sha256'],true,'Synthetic next tax review');
$before=MySqlFixture::rows($db,'balance_years');$entries=MySqlFixture::rows($db,'balance_entries');
$snapshot=MySqlFixture::snapshot($db);$c->get(2025);check(MySqlFixture::snapshot($db)===$snapshot,'read is non-mutating');
foreach(['member','other'] as $actor){denied(fn()=>(new C($db,$actor))->get(2025),'unrelated read refused '.$actor);denied(fn()=>(new C($db,$actor))->save(2025,0,$hash,proposal()),'unrelated write refused '.$actor);}
denied(fn()=>(new C($db,'reader'))->save(2025,0,$hash,proposal()),'reader cannot amend');
putenv('VEREIN_BALANCE_WORKSPACE=0');denied(fn()=>$c->save(2025,0,$hash,proposal()),'operator gate preserved');putenv('VEREIN_BALANCE_WORKSPACE=1');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$c->save(2025,0,$hash,proposal()),'EÜR not converted');$setting->execute([json_encode($settings)]);
$draft=$c->save(2025,0,$hash,proposal());check($c->get(2025)['draft']['calculation']['annual_result_cents']===1599,'prepared corrected profit');
check($ledger->report(2025)['issues']!==[]&&$ledger->report(2025)['closed_sha256']===$hash,'pending correction blocks use without replacing original');
$sameSnapshot=MySqlFixture::snapshot($db);check($c->save(2025,0,$hash,proposal())===$draft&&MySqlFixture::snapshot($db)===$sameSnapshot,'identical original draft returns its exact first result without another row');
denied(fn()=>$c->save(2025,0,$hash,proposal('a',402)),'changed original draft cannot reuse first result');
check((new C($db,'reader'))->original(2025,1,$draft['sha256'])===$draft,'current reader can read exact first correction');
denied(fn()=>(new C($db,'other'))->original(2025,1,$draft['sha256']),'foreign club cannot read first correction');
denied(fn()=>$c->original(2025,1,str_repeat('0',64)),'different original checksum refuses');
denied(fn()=>$c->confirm(2025,1,$hash,$draft['proof']['sha256'],true,'No'),'treasurer cannot approve own authority');
denied(fn()=>$board->confirm(2025,1,$hash,$draft['proof']['sha256'],false,'No'),'explicit review required');
denied(fn()=>$board->confirm(2025,1,$hash,str_repeat('0',64),true,'No'),'different proposal refused');
denied(fn()=>(new F($db,'board'))->sourceReport((new \Kulturhus\Verein\TaxFilings($db,'board'))->get($filing)),'old tax draft blocked while correction pending');
$reviewed=$board->confirm(2025,1,$hash,$draft['proof']['sha256'],true,'Synthetic verified correction');$changed=$ledger->report(2025);
check($changed['calculation']['annual_result_cents']===1599&&$changed['issues']===[],'confirmed closing active');
check($ledger->archivedReport(2025,$hash)===$raw,'original closing still exactly retrievable');
check(MySqlFixture::rows($db,'balance_years')===$before&&MySqlFixture::rows($db,'balance_entries')===$entries,'no original journal rows changed');
check(!$s->get(2025)['current_approval']&&!$w->get(2025)['current_approval']&&!$t->get(2025)['current_approval'],'original S W T histories readable but not current');
denied(fn()=>$s->confirm(2025,2,true,'No'),'old allocation cannot be blindly reconfirmed');
$s->save(2025,2,$units,allocate($changed));$s->confirm(2025,3,true,'Synthetic corrected allocation');
$w->save(2025,2,4,['advances'=>[],'repayments'=>[]],true,'Synthetic corrected clearing');$w->confirm(2025,3,4,true,'Synthetic corrected review');
$t->save(2025,2,$t->draft(2025),'Synthetic corrected tax');$t->confirm(2025,3,$t->get(2025)['income']['sha256'],true,'Synthetic corrected tax review');
check($t->get(2025)['current_approval']&&count($t->get(2025)['history'])===4,'new S W T review retains every old proof');
$correctedFiling=(new F($db,'board'))->create(2025,F::SUBSIDIARY_SOURCE);
denied(fn()=>(new F($db,'board'))->assertCurrent((new \Kulturhus\Verein\TaxFilings($db,'board'))->get($correctedFiling)),'amendment needs explicit reviewed filing status');
check($ledger->report(2026)['issues']!==[]&&$ledger->report(2027)['issues']!==[],'both following years require reconciliation');
$carry=['kind'=>'carry','reason'=>'Synthetic changed prior','legal_basis'=>'Synthetic exact prior carry','confirmed'=>true,'entries'=>[]];$r26=$c->get(2026);$p26=$c->save(2026,0,$r26['report']['closed_sha256'],$carry);$board->confirm(2026,1,$r26['report']['closed_sha256'],$p26['proof']['sha256'],true,'Synthetic closing carry');
$new26=$ledger->report(2026);check(!$new26['issues']&&$new26['calculation']['annual_result_cents']===0&&$new26['calculation']['liability_total_cents']===401,'closed following year updated without repeated expense');
check(!$s->get(2026)['current_approval']&&!$w->get(2026)['current_approval']&&!$t->get(2026)['current_approval'],'historical next-year proofs readable but no longer approved');
$s->rebase(2026,2,true,'Synthetic corrected prior allocation');$s->confirm(2026,3,true,'Synthetic revised carry allocation');$w->save(2026,2,4,['advances'=>[],'repayments'=>[]],true,'Synthetic revised carry');$w->confirm(2026,3,4,true,'Synthetic revised carry review');
$t->save(2026,2,$t->draft(2026),'Synthetic revised next tax');$t->confirm(2026,3,$t->get(2026)['income']['sha256'],true,'Synthetic revised next tax review');
check($t->get(2026)['current_approval'],'corrected following-year tax reconciliation independently reviewed');
denied(fn()=>$ledger->post(2027,1,str_repeat('7',32),'2027-03-01','No','No',pair('cash','revenue',5)),'post blocked with stale current opening');
denied(fn()=>(new L($db,'finance'))->reconcileOpening(2027,1,$new26['closed_sha256'],true,'No'),'opening change needs board');
$ledger->reconcileOpening(2027,1,$new26['closed_sha256'],true,'Synthetic carry check');
$new27=$ledger->report(2027);check($new27['calculation']['liability_total_cents']===401&&$new27['calculation']['annual_result_cents']===0,'open following year gets exact corrected balances');
check(count(MySqlFixture::rows($db,'balance_opening_corrections'))===1,'old opening retained in immutable history');
$ledger->post(2027,2,str_repeat('7',32),'2027-03-01','Synthetic new revenue','Synthetic receipt',pair('cash','revenue',5));check($ledger->report(2027)['calculation']['annual_result_cents']===5,'posting resumes after explicit opening review');
$another=$c->save(2025,2,$changed['closed_sha256'],proposal('b',2));$c->discard(2025,3,$changed['closed_sha256'],'Synthetic abandoned amendment');check($c->get(2025)['current']&&$ledger->report(2025)['closed_sha256']===$changed['closed_sha256'],'discard preserves last approved closing');
check(count($c->get(2025)['history'])===4,'discard and original draft retained');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");check(!$c->get(2025)['current']&&$ledger->report(2025)['issues']!==[],'revoked board approval blocks active amended closing');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
foreach(['balance_closing_corrections','balance_opening_corrections'] as $table){try{$db->exec('DELETE FROM '.$table);throw new RuntimeException('History deletion allowed');}catch(PDOException){check(true,'immutable database protection '.$table);}}
$bundle=(new TaxPreparation($db,'board'))->build(2025,2025,'Synthetic activity');check(isset($bundle['files']['2025/korrekturabschluss/pruefverlauf.json'])&&$bundle['snapshot']['reports'][2025]['balance']['calculation']['annual_result_cents']===1599,'annual package includes original history and corrected figures');
check(!MySqlFixture::foreignKeyErrors($db),'foreign keys intact');
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}function csrf():string{return hidden('csrf','synthetic-csrf');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor)use($db):string{$_GET=['balance_year'=>'2025','corrections'=>'1'];$_POST=[];$_SESSION=['actor'=>$actor];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();};
$d=$c->save(2025,4,$changed['closed_sha256'],proposal('c',3));$html=$render('board');check(str_contains($html,'Korrekturabschluss bestätigen')&&str_contains($html,'synthetic-csrf')&&!str_contains($html,'<script>'),'board form escaped and CSRF-protected');
check(str_contains($html,'value="3,00"')===false&&str_contains($html,'value="0,03"'),'saved correction input prefilled as cents');
$readerHtml=$render('reader');check(!str_contains($readerHtml,'value="balance-correction-confirm"')&&!str_contains($readerHtml,'value="balance-correction-save"'),'reader has no write controls');
$finHtml=$render('finance');check(!str_contains($finHtml,'value="balance-correction-confirm"')&&str_contains($finHtml,'value="balance-correction-save"'),'treasurer prepares but cannot approve');
file_put_contents(__DIR__.'/balance-closing-corrections-ui.html',$html);
final class CorrectionRedirect extends RuntimeException{public function __construct(public array $target){parent::__construct('Synthetic redirect');}}
function redirectTo(array $target):never{throw new CorrectionRedirect($target);}
$control=static function(array $post,string $actor='finance')use($db):array{
    $_POST=$post;$_SESSION=['actor'=>$actor];$action=input($post,'action');
    try{require __DIR__.'/../src/Finance/Balance/BalanceActions.php';}catch(CorrectionRedirect $r){return $r->target;}
    throw new RuntimeException('Controller did not redirect');
};
$post=['action'=>'balance-correction-save','balance_year'=>'2025','correction_revision'=>'5','base_hash'=>$changed['closed_sha256'],
    'correction_kind'=>'error','reason'=>'Synthetic controller <script>','legal_basis'=>'Synthetic checked legality','confirmed'=>'1',
    'request_key'=>str_repeat('d',32),'booked_on'=>'2025-12-31','description'=>'Synthetic controller entry','evidence'=>'Synthetic controller evidence',
    'lines'=>[['account'=>'expense','debit'=>'0,09','credit'=>'','sphere'=>'4'],['account'=>'liabilities','debit'=>'','credit'=>'0,09','sphere'=>'']]];
$snap=MySqlFixture::snapshot($db);$bad=$post;$bad['confirmed']='';denied(fn()=>$control($bad),'controller requires explicit legal check');
$bad=$post;$bad['correction_revision']='4';denied(fn()=>$control($bad),'controller rejects stale revision');
$bad=$post;$bad['lines'][0]['debit']='1.234';denied(fn()=>$control($bad),'controller refuses ambiguous money');
denied(fn()=>$control($post,'reader'),'controller rechecks write role');check(MySqlFixture::snapshot($db)===$snap,'failed controllers never change any row');
foreach(['balance_year','correction_revision','base_hash']as$key){$bad=$post;$bad[$key]=['forged'];denied(fn()=>$control($bad),'controller refuses array original metadata '.$key);}
foreach(['-1','01','1e0','10000']as$value){$bad=$post;$bad['correction_revision']=$value;denied(fn()=>$control($bad),'controller refuses noncanonical original revision '.$value);}
check(MySqlFixture::snapshot($db)===$snap,'malformed metadata preserves every whole original');
$target=$control($post);$draftNow=$c->get(2025);check($target['corrections']==='1'&&$draftNow['draft']['calculation']['annual_result_cents']===1590,'controller uses exact cents and stays on correction page');
check($target['correction_original']==='6'&&$target['correction_sha256']===$draftNow['history'][5]['sha256'],'actual controller redirects to its exact immutable original result');
$originalRender=static function(string$actor,array$get)use($db):string{$_GET=$get+['balance_year'=>'2025','corrections'=>'1'];$_POST=[];$_SESSION=['actor'=>$actor];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();};
$originalHtml=$originalRender('reader',$target);check(str_contains($originalHtml,'data-balance-correction-original="'.$target['correction_sha256'].'"')&&!preg_match('/<(form|input|select|textarea|button)\b/i',$originalHtml)&&!str_contains($originalHtml,'<script>'),'current reader receives exact escaped original outcome without submitted controls');
check(str_contains($originalHtml,'Synthetic controller &lt;script&gt;'),'literal original note remains escaped');
check(str_contains($originalRender('reader',['corrections'=>'0']),'Abschluss berichtigen / Korrektur ansehen'),'financial reader can reach correction history from its closed annual report');
$carryHtml=$originalRender('reader',['balance_year'=>'2026','correction_original'=>'1','correction_sha256'=>$p26['sha256']]);check(str_contains($carryHtml,'Jahresanfang:')&&str_contains($carryHtml,'Verbindlichkeiten')&&str_contains($carryHtml,'4,01'),'original pure prior-year carry shows its changed opening balances even without a new expense entry');
$wrongTarget=$target;$wrongTarget['correction_sha256']=str_repeat('0',64);check(!str_contains($originalRender('reader',$wrongTarget),'data-balance-correction-original'),'wrong original checksum offers no result');
check(!str_contains($originalRender('other',$target),'data-balance-correction-original'),'foreign club receives no original result');
$postedRender=static function(array$post,string$actor='board')use($db):string{$_GET=['balance_year'=>'2025','corrections'=>'1'];$_POST=$post;$_SESSION=['actor'=>$actor];$_SERVER['REQUEST_METHOD']='POST';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();};
$forms=static function(string$html):DOMXPath{$d=new DOMDocument();$priorErrors=libxml_use_internal_errors(true);try{$d->loadHTML('<?xml encoding="UTF-8">'.$html);}finally{libxml_clear_errors();libxml_use_internal_errors($priorErrors);}return new DOMXPath($d);};
$failed=['action'=>'balance-correction-confirm','balance_year'=>'2025','correction_revision'=>'0','base_hash'=>str_repeat('1',64),'proof_hash'=>str_repeat('2',64),'note'=>'0 retained <&> 🙂','reviewed'=>'1'];
$x=$forms($postedRender($failed));$f=$x->query('//form[input[@name="action" and @value="balance-correction-confirm"]]')->item(0);check($f!==null,'failed personal review remains its own form');
foreach(['correction_revision','base_hash','proof_hash','note']as$key)check($x->query('.//input[@name="'.$key.'"]',$f)->item(0)?->getAttribute('value')===$failed[$key],'failed personal review retains original '.$key);
check($x->query('.//input[@name="reviewed" and @checked]',$f)->length===1,'failed personal review retains explicit review');
$sf=$x->query('//form[input[@name="action" and @value="balance-correction-save"]]')->item(0);check($x->query('.//input[@name="correction_revision"]',$sf)->item(0)->getAttribute('value')==='6','other preparation form uses its own current revision');
$failed['action']='balance-correction-discard';$x=$forms($postedRender($failed,'finance'));$f=$x->query('//form[input[@name="action" and @value="balance-correction-discard"]]')->item(0);check($x->query('.//input[@name="note"]',$f)->item(0)->getAttribute('value')===$failed['note']&&$x->query('.//input[@name="base_hash"]',$f)->item(0)->getAttribute('value')===$failed['base_hash'],'failed discard retains its own note and original source');
$confirm=['action'=>'balance-correction-confirm','balance_year'=>'2025','correction_revision'=>'6','base_hash'=>$changed['closed_sha256'],'proof_hash'=>$draftNow['draft']['sha256'],'reviewed'=>'1','note'=>'Synthetic controller board review'];
denied(fn()=>$control($confirm),'controller repeats board authorization');$control($confirm,'board');check($c->get(2025)['current']&&$ledger->report(2025)['calculation']['annual_result_cents']===1590,'controller confirms exact prepared correction');
check(!$c->get(2026)['current']&&$ledger->report(2027)['issues']!==[],'second correction invalidates both following years again');
$sameSnapshot=MySqlFixture::snapshot($db);$control($confirm,'board');check(MySqlFixture::snapshot($db)===$sameSnapshot,'controller returns first accepted confirmation without another row');
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board2','a','treasurer',1); UPDATE actors SET is_superuser=0 WHERE id='board'");
$reaffirmHtml=$postedRender(['action'=>'balance-correction-reaffirm','balance_year'=>'2025','correction_revision'=>'1','base_hash'=>str_repeat('3',64),'note'=>'0 retained reaffirm <&> 🙂','reviewed'=>'1'],'board2');$x=$forms($reaffirmHtml);$f=$x->query('//form[input[@name="action" and @value="balance-correction-reaffirm"]]')->item(0);check($f!==null&&$x->query('.//input[@name="note"]',$f)->item(0)->getAttribute('value')==='0 retained reaffirm <&> 🙂'&&$x->query('.//input[@name="base_hash"]',$f)->item(0)->getAttribute('value')===str_repeat('3',64),'offered renewed review retains its own original source and note');
$finalHash=$c->get(2025)['report']['closed_sha256'];denied(fn()=>$c->reaffirm(2025,7,$finalHash,true,'No'),'ordinary finance cannot reaffirm');
$reaffirmed=(new C($db,'board2'))->reaffirm(2025,7,$finalHash,true,'Synthetic new board review');check($c->get(2025)['current']&&$c->get(2025)['report']['closed_sha256']===$finalHash,'new board can reaffirm same closing without extra accounting entries');
check($c->get(2025)['history'][7]['state']==='reaffirmed'&&count($c->get(2025)['history'])===8,'new board review preserves original approval history');
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$sameSnapshot=MySqlFixture::snapshot($db);
check($c->save(2025,0,$hash,proposal())===$draft,'first draft remains exact after genuinely later corrections');
check($board->confirm(2025,1,$hash,$draft['proof']['sha256'],true,'Synthetic verified correction')===$reviewed,'first board confirmation remains exact after later results');
check($c->save(2026,0,$r26['report']['closed_sha256'],$carry)===$p26,'original prior-year carry replays against its historical prior source');
check((new C($db,'board2'))->reaffirm(2025,7,$finalHash,true,'Synthetic new board review')===$reaffirmed,'first reaffirmation remains exact');
check(MySqlFixture::snapshot($db)===$sameSnapshot,'all historical result reads and repeats preserve every whole row');
denied(fn()=>$board->confirm(2025,1,$hash,$draft['proof']['sha256'],false,'Synthetic verified correction'),'unchecked review cannot adopt an earlier confirmation');
denied(fn()=>$c->save(2025,0,$hash,proposal('z',401)),'another original entry cannot adopt an earlier result');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");
denied(fn()=>$board->confirm(2025,1,$hash,$draft['proof']['sha256'],true,'Synthetic verified correction'),'replay rechecks current board authority');
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
foreach(['balance_closing_corrections','balance_opening_corrections'] as $table){try{$db->exec('UPDATE '.$table." SET sha256=REPEAT('0',64)");throw new RuntimeException('History update allowed');}catch(PDOException){check(true,'immutable update protection '.$table);}}
echo "$checks closing-correction workflow checks passed; synthetic MySQL only\n";
}
