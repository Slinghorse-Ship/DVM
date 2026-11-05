<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodLedger.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodExport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalancePeriodLedger as P,BalanceLedger as L,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
function p(string $start,string $end):array{return ['start'=>$start,'end'=>$end];}
function pair(string $d,string $c,int $n):array{return [['account'=>$d,'debit_cents'=>$n,'credit_cents'=>0,'sphere'=>in_array($d,['expense','revenue'],true)?'1':''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>in_array($c,['expense','revenue'],true)?'1':'']];}
$db=MySqlFixture::connect();Database::migrate($db);$schema=MySqlFixture::rows($db,'schema_migrations');Database::migrate($db);check($schema===MySqlFixture::rows($db,'schema_migrations'),'migration idempotent');
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Period Club'),('b','Synthetic EÜR Club'),('c','Synthetic Legacy Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('other','b','treasurer',1),('reader','a','member',0),('legacy','c','treasurer',1)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic reader','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','fiscal_start'=>'2025-07-01']);
$save=$db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,\'tax\',?,1,?)');$save->execute(['a',json_encode($settings),gmdate('c')]);$save->execute(['c',json_encode(array_replace($settings,['fiscal_start'=>'2025-01-01'])),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
$period=p('2025-07-01','2026-06-30');$l=new P($db,'board');$f=new P($db,'finance');$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
foreach(['member','reader','other'] as $actor)denied(fn()=>(new P($db,$actor))->create($period,'Synthetic no',true),'write denied '.$actor);
putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$l->create($period,'Synthetic no',true),'period release gate');putenv('VEREIN_BALANCE_PERIODS=1');
denied(fn()=>$l->create($period,'Synthetic no',false),'definition requires review');
$id=$l->create($period,'Synthetic reviewed fiscal year',true);check($id==='20250701-20260630','identity includes both boundaries');
$r=$l->report($id);check($r['state']==='setup'&&$r['period']===$period&&$r['year']===2026,'stored explicit period');
denied(fn()=>(new P($db,'other'))->report($id),'tenant-scoped report');denied(fn()=>(new P($db,'member'))->periods(),'member cannot enumerate journals');
check(count((new P($db,'reader'))->periods())===1,'reader can read period list');
$snap=MySqlFixture::snapshot($db);$l->report($id);check(MySqlFixture::snapshot($db)===$snap,'read-only report');
denied(fn()=>$l->create(p('2026-01-01','2026-12-31'),'Synthetic no',true),'overlap rejected');
$l->setup($id,1,pair('cash','equity',10000),'Synthetic opening',true);
denied(fn()=>$l->setup($id,1,[],'Synthetic no',true),'stale or repeated opening rejected');
foreach(['2025-06-30','2026-07-01','2026-02-30'] as $date)denied(fn()=>$l->post($id,2,str_repeat('1',32),$date,'Synthetic no','Synthetic no',pair('cash','revenue',51)),'date outside actual period rejected '.$date);
$l->post($id,2,str_repeat('1',32),'2025-12-31','Synthetic income <script>','Synthetic receipt',pair('cash','revenue',51));
$r=$l->report($id);check($r['calculation']['annual_result_cents']===51&&$r['calculation']['period']===$period,'posting in previous calendar year counted in period');
denied(fn()=>$l->post($id,2,str_repeat('2',32),'2026-01-01','Synthetic no','Synthetic no',pair('cash','revenue',7)),'stale tab cannot post');
denied(fn()=>$l->post($id,3,str_repeat('1',32),'2026-01-01','Synthetic no','Synthetic no',pair('cash','revenue',7)),'request replay does not duplicate entry');
denied(fn()=>$l->post($id,3,str_repeat('2',32),'2025-12-30','Synthetic no','Synthetic no',[],str_repeat('1',32)),'reversal cannot predate original');
$l->post($id,3,str_repeat('2',32),'2026-01-01','Synthetic reversal','Synthetic correction',[],str_repeat('1',32));
$r=$l->report($id);check(count($r['entries'])===2&&$r['calculation']['annual_result_cents']===0,'reversal preserves original and exact cents');
denied(fn()=>$l->post($id,4,str_repeat('3',32),'2026-01-01','Synthetic no','Synthetic no',[],str_repeat('1',32)),'cannot reverse same original twice');
$l->post($id,4,str_repeat('3',32),'2026-06-30','Synthetic revenue','Synthetic receipt',pair('cash','revenue',101));
denied(fn()=>$l->addAccount($id,5,'bad','Synthetic bad','expense','99999'),'unknown annual SKR rejected');
$l->addAccount($id,5,'postage','','expense','68000');check($l->report($id)['accounts']['postage']['skr_code']==='68000','explicit annual catalog account retained');
$r=$l->report($id);
denied(fn()=>$f->close($id,6,$r['journal_sha256'],$all,'Synthetic no'),'treasurer cannot sign closing');
denied(fn()=>$l->close($id,6,str_repeat('0',64),$all,'Synthetic no'),'closing bound to preview hash');
denied(fn()=>$l->close($id,6,$r['journal_sha256'],array_replace($all,['tax'=>false]),'Synthetic no'),'all review confirmations required');
$l->close($id,6,$r['journal_sha256'],$all,'Synthetic board review');$closed=$l->report($id);check($closed['state']==='closed'&&$closed['closed_sha256']!==null,'immutable closing created');
denied(fn()=>$l->post($id,7,str_repeat('4',32),'2026-06-30','Synthetic no','Synthetic no',pair('cash','revenue',1)),'closed journal cannot post');
denied(fn()=>$l->create(p('2026-08-01','2026-12-31'),'Synthetic no',true),'gap rejected');
$id2=$l->create(p('2026-07-01','2026-12-31'),'Synthetic reviewed short year',true);$r2=$l->report($id2);
check($r2['year']===$closed['year']&&$id!==$id2&&count($l->periods())===2,'two periods ending same year remain separate');
check($r2['calculation']['asset_total_cents']===10101&&$r2['calculation']['annual_result_cents']===0&&$r2['previous']['sha256']===$closed['closed_sha256'],'exact signed carry without duplicate annual income');
denied(fn()=>$l->post($id2,1,str_repeat('5',32),'2026-06-30','Synthetic no','Synthetic no',pair('cash','revenue',1)),'preceding period date not accepted in next period');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");check($l->report($id)['issues']!==[]&&$l->report($id2)['issues']!==[],'revoked reviewer blocks chain');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
check($l->report($id2)['issues']===[],'same authority restored');
foreach(['balance_periods','balance_period_events'] as $table){foreach(['DELETE FROM '.$table,'UPDATE '.$table.' SET club_id=club_id'] as $sql){try{$db->exec($sql);throw new RuntimeException('history changed');}catch(PDOException){check(true,'native immutable guard '.$sql);}}}
$legacy=new L($db,'legacy');$legacy->create(2025);$legacy->setup(2025,1,L::defaultChart(),pair('cash','equity',12345),'equity','Synthetic legacy opening',true);$legacy->close(2025,2,$all,'Synthetic old closing');
$before=MySqlFixture::rows($db,'balance_years');$pl=new P($db,'legacy');$pid=$pl->create(p('2026-01-01','2026-06-30'),'Synthetic transition',true);
check($pl->report($pid)['previous']['kind']==='calendar'&&$pl->report($pid)['calculation']['asset_total_cents']===12345,'explicit legacy closing adopted as predecessor');
denied(fn()=>$legacy->create(2026),'legacy creation cannot overlap dated journals');check(MySqlFixture::rows($db,'balance_years')===$before,'legacy snapshot remains byte-for-byte unchanged');
check(!MySqlFixture::foreignKeyErrors($db),'foreign keys intact');
$export=new \Kulturhus\Verein\BalancePeriodExport($db,'board');$bundle=$export->build($id);
check($bundle['snapshot']['period']===$period&&$bundle['snapshot']['tax_export_ready']===false,'working papers carry exact period without ELSTER claim');
check(count($bundle['snapshot']['history'])===7&&$bundle['snapshot']['history'][6]['sha256']===$closed['closed_sha256'],'export includes complete immutable review chain');
check(str_contains($bundle['files']['buchungsjournal.csv'],'2025-12-31')&&str_contains($bundle['files']['buchungsjournal.csv'],'2026-06-30'),'export retains entries from both calendar years');
check(str_contains($export->zip($id),'PK'),'authenticated ZIP export built');
denied(fn()=>(new \Kulturhus\Verein\BalancePeriodExport($db,'other'))->build($id),'export cannot cross tenant');
$taxBundle=(new \Kulturhus\Verein\TaxPreparation($db,'board'))->build(2026,2026,'Synthetic activities');
check(count($taxBundle['snapshot']['balance_periods'])===2&&isset($taxBundle['files']['abschlusszeitraeume/'.$id.'/kontensalden.csv'],$taxBundle['files']['abschlusszeitraeume/'.$id2.'/kontensalden.csv']),'tax working package retains two separate periods ending same year');
check($taxBundle['snapshot']['balance_periods'][$id]['report']['calculation']['annual_result_cents']===101&&$taxBundle['snapshot']['balance_periods'][$id2]['report']['calculation']['annual_result_cents']===0,'package does not count carry as second income');
$pre=(new \Kulturhus\Verein\TaxPreparation($db,'board'))->build(2025,2025,'Synthetic activities');check(!isset($pre['snapshot']['balance_periods']),'period selected by ending year not sliced into two years');
$settingsStmt=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$settingsStmt->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);
check(!isset((new \Kulturhus\Verein\TaxPreparation($db,'board'))->build(2026,2026,'Synthetic activities')['snapshot']['balance_periods']),'EÜR package remains EÜR');
denied(fn()=>$l->post($id2,1,str_repeat('5',32),'2026-07-01','No','No',pair('cash','revenue',1)),'existing period cannot override EÜR setting');$settingsStmt->execute([json_encode($settings)]);
putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');denied(fn()=>(new \Kulturhus\Verein\BalanceTaxFiling($db,'board'))->create(2026),'dated period cannot be silently sent as calendar year');
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic-csrf');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor,string $which)use($db):string{$_GET=['periods'=>'1','period_id'=>$which];$_POST=[];$_SESSION=['actor'=>$actor];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();};
$html=$render('board',$id);check(str_contains($html,'01.07.2025 – 30.06.2026')&&!str_contains($html,'<script>'),'period view shows full boundary and escapes source text');
check(!str_contains($html,'value="balance-period-post"'),'closed period view hides posting');
$openHtml=$render('board',$id2);check(str_contains($openHtml,'min="2026-07-01" max="2026-12-31"')&&str_contains($openHtml,'synthetic-csrf'),'form uses actual dates and CSRF');
check(!str_contains($render('reader',$id2),'value="balance-period-post"')&&!str_contains($render('reader',$id2),'value="balance-period-create"'),'reader has no write forms');
check(!str_contains($render('finance',$id2),'value="balance-period-close"'),'treasurer cannot approve closing via UI');
final class PeriodRedirect extends RuntimeException{public function __construct(public array $target){parent::__construct('Synthetic redirect');}}
function redirectTo(array $target):never{throw new PeriodRedirect($target);}
$control=static function(array $post,string $actor='board')use($db):array{$_POST=$post;$_SESSION=['actor'=>$actor];$action=input($post,'action');try{require __DIR__.'/../src/Finance/Balance/BalanceActions.php';}catch(PeriodRedirect $r){return $r->target;}throw new RuntimeException('Controller did not redirect');};
$post=['action'=>'balance-period-post','period_journal_form_complete'=>'1','journal_hash'=>$l->report($id2)['journal_sha256'],'period_id'=>$id2,'version'=>'1','request_key'=>str_repeat('8',32),'booked_on'=>'2026-07-01','description'=>'=Synthetic formula','evidence'=>'Synthetic receipt','lines'=>[['account'=>'cash','debit'=>'1,01','credit'=>'','sphere'=>''],['account'=>'revenue','debit'=>'','credit'=>'1,01','sphere'=>'1']]];
$before=MySqlFixture::snapshot($db);$bad=$post;$bad['booked_on']='2026-06-30';denied(fn()=>$control($bad),'controller date cannot target predecessor');
$bad=$post;$bad['lines'][0]['debit']='1.234';denied(fn()=>$control($bad),'controller rejects ambiguous decimal');denied(fn()=>$control($post,'reader'),'controller repeats finance access');check(MySqlFixture::snapshot($db)===$before,'invalid controller requests leave DB unchanged');
$target=$control($post);check($target['period_id']===$id2&&$target['periods']==='1'&&$l->report($id2)['calculation']['annual_result_cents']===101,'successful POST stays on exact period');
$acceptedControllerRows=MySqlFixture::snapshot($db);check($control($post)===$target&&isset($target['period_original'],$target['period_original_sha256'])&&MySqlFixture::snapshot($db)===$acceptedControllerRows,'controller repeated original POST returns exact first receipt without duplicate rows');
check(str_contains($export->build($id2)['files']['buchungsjournal.csv'],"'=Synthetic formula"),'CSV neutralizes spreadsheet formulas');
$_GET=['periods'=>'1','period_id'=>$id2];$_POST=array_replace($post,['version'=>'1']);$_SESSION=['actor'=>'board'];$_SERVER['REQUEST_METHOD']='POST';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$retry=ob_get_clean();
check(str_contains($retry,'value="1,01"')&&str_contains($retry,'name="version" value="1"'),'failed form keeps entered cents and stale version for explicit reload');
file_put_contents(__DIR__.'/balance-period-ledger-ui.html',$openHtml);
$priorOriginal=$l->report($id);$originalEntries=$priorOriginal['entries'];
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board2','a','treasurer',1); UPDATE actors SET is_superuser=0 WHERE id='board'");
denied(fn()=>$export->build($id),'export blocked after review authority revoked');
denied(fn()=>$f->reaffirm($id,7,$closed['closed_sha256'],true,'Synthetic no'),'ordinary finance cannot reaffirm');
check(str_contains($render('board2',$id),'value="balance-period-reaffirm"'),'new board sees review renewal instead of impossible dead end');
$_GET=['periods'=>'1','period_id'=>$id];$_POST=['action'=>'balance-period-reaffirm','period_id'=>$id,'version'=>'1','closed_hash'=>str_repeat('3',64),'evidence'=>'0 retained personal period renewal <&> 🙂','reviewed'=>'1'];$_SESSION=['actor'=>'board2'];$_SERVER['REQUEST_METHOD']='POST';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$renewalHtml=ob_get_clean();check(str_contains($renewalHtml,'value="'.e($_POST['evidence']).'"')&&str_contains($renewalHtml,'name="closed_hash" value="'.str_repeat('3',64).'"'),'offered renewal retains original note and closing source');
$newBoard=new P($db,'board2');$newBoard->reaffirm($id,7,$closed['closed_sha256'],true,'Synthetic new board review');$reaffirmed=$newBoard->report($id);
check($reaffirmed['approval_current']&&$reaffirmed['closed_sha256']===$closed['closed_sha256']&&$reaffirmed['entries']===$originalEntries,'new board approval preserves all figures and closing identity');
check($newBoard->report($id2)['issues']===[]&&count($newBoard->history($id))===8,'following period resumes with original and new reviews retained');
denied(fn()=>$newBoard->reaffirm($id,7,$closed['closed_sha256'],true,'Synthetic no'),'stale reaffirmation rejected');
putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$newBoard->post($id2,2,str_repeat('9',32),'2026-07-02','No','No',pair('cash','revenue',1)),'disabled period flag denies writing');putenv('VEREIN_BALANCE_PERIODS=1');
echo "$n period persistence checks passed; synthetic MySQL only\n";
}
