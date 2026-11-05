<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2028-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClearings.php';require_once __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-internal-clearing.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceClearings as W,BalanceSegments as S,BalanceLedger as L,BalanceTaxFiling as T,Database,SettingsSchema,TaxPreparation};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $work,string $why):void{try{$work();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Clearing Club'),('b','Synthetic Euer Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('editor','a','editor',0),('other','b','treasurer',1)");
$db->exec("INSERT INTO actors(id,club_id,role) VALUES('reader','a','member'); INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic Finance Reader','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
[$fixture,$units,$alloc,$input]=clearingFixture();$ledger=new L($db,'board');$ledger->create(2025);$ledger->setup(2025,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic opening',true);$v=2;
foreach($fixture['entries'] as $e)$ledger->post(2025,$v++,$e['id'],$e['date'],$e['description'],'Synthetic source',$e['lines']);
$board=new W($db,'board');$finance=new W($db,'finance');$segments=new S($db,'finance');
denied(fn()=>$finance->get(2025),'unclosed year rejected');
$ledger->close(2025,$v,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete journal');
denied(fn()=>$finance->save(2025,0,0,$input,true,'Review'),'no unbound workpaper before allocation');
$segments->save(2025,0,$units,$alloc);$journal=MySqlFixture::rows($db,'balance_years');$entries=MySqlFixture::rows($db,'balance_entries');
check($finance->get(2025)['version']===0,'empty current workpaper');
$finance->assertExportable(2025);check(true,'no unrelated restriction without workpaper');
foreach(['member','editor','other'] as $actor){denied(fn()=>(new W($db,$actor))->get(2025),'unauthorized read '.$actor);denied(fn()=>(new W($db,$actor))->save(2025,0,1,$input,true,'No'),'unauthorized write '.$actor);}
$before=MySqlFixture::snapshot($db);$finance->get(2025);check(MySqlFixture::snapshot($db)===$before,'GET never writes');
putenv('VEREIN_BALANCE_WORKSPACE=0');denied(fn()=>$finance->get(2025),'operator gate');putenv('VEREIN_BALANCE_WORKSPACE=1');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$finance->get(2025),'EÜR read gate');denied(fn()=>$finance->save(2025,0,1,$input,true,'No'),'EÜR write gate');$setting->execute([json_encode($settings)]);
denied(fn()=>$finance->save(2025,0,1,$input,false,'No'),'explicit review before saving');
denied(fn()=>$finance->save(2025,0,1,$input,true,''),'change reason mandatory');
$saved=$finance->save(2025,0,1,$input,true,'Synthetic source review');$s=$finance->get(2025);
$originalRows=MySqlFixture::snapshot($db);check($finance->save(2025,0,1,$input,true,'Synthetic source review')===$saved&&MySqlFixture::snapshot($db)===$originalRows,'same original clearing preparation returns its first proof without another whole row');
denied(fn()=>$finance->incomeReconciliation(2025),'draft cannot provide reviewed income evidence');
check($saved['revision']===1&&$s['proof']['totals']['closing_cents']===903&&!$s['current_approval'],'exact persisted draft');
check((new W($db,'reader'))->get(2025)['proof']===$s['proof'],'finance reader can inspect proof');denied(fn()=>(new W($db,'reader'))->save(2025,1,1,$input,true,'No'),'finance reader cannot write');
denied(fn()=>$finance->save(2025,0,1,$input,true,'Stale'),'stale own version');denied(fn()=>$finance->save(2025,1,2,$input,true,'Stale'),'stale allocation version');
$bad=$input;$bad['repayments'][str_repeat('c',32)]['cents']=101;$before=MySqlFixture::snapshot($db);denied(fn()=>$finance->save(2025,1,1,$bad,true,'Bad'),'wrong original amount');check(MySqlFixture::snapshot($db)===$before,'invalid write and audit rolled back');
denied(fn()=>$finance->confirm(2025,1,1,true,'No'),'finance writer is not board');denied(fn()=>$board->confirm(2025,1,1,false,'No'),'explicit board check');
$originalApproval=$board->confirm(2025,1,1,true,'Synthetic board review');check($finance->get(2025)['current_approval'],'current review active');
$originalIncome=$finance->incomeReconciliation(2025);
denied(fn()=>$board->confirm(2025,2,1,true,'Duplicate'),'no duplicate confirmation');
denied(fn()=>$finance->assertExportable(2025),'review is not tax approval');
foreach(['separate_business_ledger','approved_club_allocation',T::COMBINED_SOURCE] as $scope)denied(fn()=>(new T($db,'finance'))->create(2025,$scope),'partial tax profile cannot bypass clearing '.$scope);
// The complete, non-privileged corporation uses the unchanged whole journal;
// analytical advances between its own areas do not require a tax projection.
// Preparing that distinct draft must not approve either the journal or filing.
$wholeService=new T($db,'finance');$wholeId=$wholeService->create(2025,'complete_club_ledger');
$wholeFilings=new \Kulturhus\Verein\TaxFilings($db,'finance');$wholeDraft=$wholeFilings->get($wholeId);$wholeBinding=$wholeDraft['form_data']['balance_preparation'];
check($wholeDraft['state']==='draft'&&$wholeDraft['xml_data']===null&&!isset($wholeBinding['approval'])&&!$wholeBinding['review']['complete'],'whole draft has no XML or tax approval');
check($wholeBinding['profile']['tax_privileged']===false&&!$wholeBinding['profile']['scope_confirmed'],'whole non-privileged scope still needs explicit confirmation');
check($wholeService->sourceReport($wholeDraft)['calculation']===$ledger->report(2025)['calculation'],'whole draft retains all original income and balances');
denied(fn()=>$wholeFilings->ready($wholeId,1,'SEND-TEST',true,true),'clearing review cannot make whole draft ready');
$wrongScope=$wholeDraft;$wrongScope['form_data']['balance_preparation']['profile']['tax_privileged']=true;
denied(fn()=>$wholeService->sourceReport($wrongScope),'whole profile cannot bypass privileged-area reconciliation');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");check(!$finance->get(2025)['current_approval'],'revoked board rights invalidate review');denied(fn()=>$finance->incomeReconciliation(2025),'revoked board invalidates income evidence');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$changed=$alloc;$changed['opening:0']['reason']='Synthetic source note updated';$segments->save(2025,1,$units,$changed);
check(!$finance->get(2025)['source_current']&&!$finance->get(2025)['current_approval'],'source edit invalidates review');
denied(fn()=>$finance->incomeReconciliation(2025),'changed source invalidates income evidence');
denied(fn()=>$board->confirm(2025,2,2,true,'Old'),'cannot approve old source');
denied(fn()=>$finance->save(2025,2,1,$input,true,'Stale'),'stale source form cannot overwrite');
$finance->save(2025,2,2,$input,true,'Rechecked new allocation');$board->confirm(2025,3,2,true,'Reviewed revised source');
check(count($finance->get(2025)['history'])===4,'old source reviews remain in history');
$originalRows=MySqlFixture::snapshot($db);
check($finance->save(2025,0,1,$input,true,'Synthetic source review')===$saved&&$board->confirm(2025,1,1,true,'Synthetic board review')===$originalApproval&&MySqlFixture::snapshot($db)===$originalRows,'first clearing and board review stay exact after genuinely later allocation and clearing reviews');
check((new W($db,'reader'))->original(2025,1,$saved['sha256'])===$saved&&MySqlFixture::snapshot($db)===$originalRows,'current finance reader opens exact first immutable clearing without writes');
check((new W($db,'reader'))->originalIncome(2025,2,$originalApproval['sha256'])===$originalIncome&&MySqlFixture::snapshot($db)===$originalRows,'historical income equals its actual originally reviewed scope after later source changes without granting current approval');
$draftRow=$finance->get(2025)['history'][2];denied(fn()=>$finance->originalIncome(2025,3,$draftRow['sha256']),'historical income refuses a mathematically valid but unreviewed draft source');
foreach([
 fn()=>$finance->save(2025,0,1,$input,false,'Synthetic source review'),
 fn()=>$finance->save(2025,0,1,$input,true,'Changed original note'),
 fn()=>$finance->save(2025,0,2,$input,true,'Synthetic source review'),
 fn()=>$board->save(2025,0,1,$input,true,'Synthetic source review'),
 fn()=>$board->confirm(2025,1,1,false,'Synthetic board review'),
 fn()=>(new W($db,'reader'))->save(2025,0,1,$input,true,'Synthetic source review'),
 fn()=>(new W($db,'other'))->original(2025,1,$saved['sha256']),
 fn()=>$finance->original(2025,1,str_repeat('a',64)),
]as$attempt)denied($attempt,'changed intent source checkbox or current personal role cannot adopt accepted original clearing');
check(MySqlFixture::snapshot($db)===$originalRows,'every refused original clearing repeat leaves all whole rows and audits unchanged');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");$revoked=MySqlFixture::snapshot($db);denied(fn()=>$board->confirm(2025,1,1,true,'Synthetic board review'),'original clearing confirmation rechecks current personal board authority');check(MySqlFixture::snapshot($db)===$revoked,'revoked-board repeat changes no whole row');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");

$files=$finance->files(2025);check(count($files)===8&&str_contains($files['verrechnungen/LESEN.txt'],'vom Vorstand geprüft')&&!str_contains($files['verrechnungen/LESEN.txt'],'ENTWURF'),'accurate reviewed download');
$result=json_decode($files['verrechnungen/ergebnisabgleich.json'],true,512,JSON_THROW_ON_ERROR);
check($result===$finance->incomeReconciliation(2025)&&!$result['tax_values_reviewed']&&!$result['tax_export_ready'],'reviewed book-result evidence, not a tax or transmission approval');
check(str_contains($files['verrechnungen/ergebnisabgleich.txt'],'keine Steuererklärung')&&str_contains($files['verrechnungen/ergebnisabgleich.txt'],$result['sha256']),'readable summary bound to the same proof');
check((new W($db,'reader'))->incomeReconciliation(2025)===$result,'finance reader has same reviewed evidence');
foreach(['member','editor','other'] as $actor)denied(fn()=>(new W($db,$actor))->incomeReconciliation(2025),'income evidence preserves actor and club boundaries '.$actor);
$before=MySqlFixture::snapshot($db);$finance->incomeReconciliation(2025);check(MySqlFixture::snapshot($db)===$before,'income reconciliation never changes data');
check(json_decode($files['verrechnungen/bereichsabgleich.json'],true,512,JSON_THROW_ON_ERROR)===$finance->get(2025)['statement'],'download includes exact unit reconciliation');
$bundle=(new TaxPreparation($db,'finance'))->build(2025,2025,'Synthetic activities');check(isset($bundle['files']['2025/verrechnungen/nachweis.json'])&&$bundle['snapshot']['reports'][2025]['balance_clearings']['current_approval'],'year-end package contains bound workpaper');
check($bundle['snapshot']['file_hashes']['2025/verrechnungen/nachweis.json']===hash('sha256',$bundle['files']['2025/verrechnungen/nachweis.json']),'package hash binds exact proof');
foreach(['ergebnisabgleich.json','ergebnisabgleich.txt'] as $name)check($bundle['snapshot']['file_hashes']['2025/verrechnungen/'.$name]===hash('sha256',$bundle['files']['2025/verrechnungen/'.$name]),'year-end export binds income evidence '.$name);
$segments->transfer(2025,2,str_repeat('a',32),'equity',true,'Synthetic permanent allocation');
check(!$finance->get(2025)['source_current'],'later permanent funding makes workpaper stale');
denied(fn()=>$finance->save(2025,4,3,$input,true,'Conflict'),'cannot be both permanent and refundable');
$segments->removeTransfer(2025,3,str_repeat('a',32),'Removed conflicting permanent funding');$finance->save(2025,4,4,$input,true,'Rechecked refund evidence');$board->confirm(2025,5,4,true,'Rechecked proof');
check(MySqlFixture::rows($db,'balance_years')===$journal&&MySqlFixture::rows($db,'balance_entries')===$entries,'source ledger untouched by all workpaper changes');
foreach(['UPDATE balance_clearing_reviews SET sha256=REPEAT(\'a\',64)','DELETE FROM balance_clearing_reviews'] as $sql){$blocked=false;try{$db->exec($sql);}catch(PDOException $e){$blocked=$e->getCode()==='45000';}check($blocked,'native history immutability');}
// Following year: explicit carry of prior reviewed case, then later source revision.
$prior=$finance->get(2025)['proof'];[$next,$nextUnits,$nextAlloc,$nextInput]=clearingNext($prior,234);
$ledger->create(2026);$ledger->post(2026,1,$next['entries'][0]['id'],$next['entries'][0]['date'],'Synthetic repayment','Synthetic proof',$next['entries'][0]['lines']);$ledger->close(2026,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next-year review');
// Beginning balances must come through the explicit, source-bound carry workflow.
denied(fn()=>$segments->save(2026,0,$units,$nextAlloc),'manual save cannot bypass original carried balances');
denied(fn()=>$finance->save(2026,0,0,$nextInput,true,'No'),'workpaper cannot bypass opening allocation');
$finance->save(2025,6,4,$input,true,'New prior review');$board->confirm(2025,7,4,true,'Prior re-reviewed');
// Isolate the persistence/revision mechanics with an explicitly empty carry.
$db->exec("INSERT INTO clubs VALUES('carry','Synthetic Empty Carry'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('carry-board','carry','treasurer',1),('carry-finance','carry','treasurer',0)");
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('carry','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
$cl=new L($db,'carry-board');$cs=new S($db,'carry-board');$cw=new W($db,'carry-board');$cf=new W($db,'carry-finance');$empty=['advances'=>[],'repayments'=>[]];
$cl->create(2024);$cl->setup(2024,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic opening',true);$cl->close(2024,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic closed');
$opening=array_filter($alloc,static fn($key)=>str_starts_with($key,'opening:'),ARRAY_FILTER_USE_KEY);
$cs->save(2024,0,$units,$opening);$cs->confirm(2024,1,true,'Synthetic segments');$cw->save(2024,0,2,$empty,true,'Explicit no advances');$cw->confirm(2024,1,2,true,'Empty proof reviewed');
$cl->create(2025);$cl->close(2025,1,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year');$cs->carry(2025,0,true);$cs->confirm(2025,1,true,'Synthetic carried opening');
$cf->save(2025,0,2,$empty,true,'Explicit empty carry');$cw->confirm(2025,1,2,true,'Reviewed carry');check($cf->get(2025)['current_approval']&&$cf->get(2025)['history'][0]['carry_source']['revision']===2,'exact reviewed empty prior source carried');
$cw->assertExportable(2025);check(true,'reviewed empty workpaper is not an artificial tax blocker');
$cf->save(2024,2,2,$empty,true,'New prior review');check(!$cf->get(2025)['carry_current']&&!$cf->get(2025)['current_approval'],'changed prior approval invalidates next year');
denied(fn()=>$cf->save(2025,2,2,$empty,true,'No'),'unreviewed prior cannot be carried');$cw->confirm(2024,3,2,true,'Prior re-reviewed');
denied(fn()=>$cf->save(2025,2,2,$empty,true,'No board'),'changed segment carry cannot be bypassed');
$cs->rebase(2025,2,true,'Board checked changed prior clearing');
denied(fn()=>$cf->save(2025,2,3,$empty,true,'No board'),'only board can replace changed workpaper carry');
$cw->save(2025,2,3,$empty,true,'Board checked changed prior');check(!$cf->get(2025)['current_approval']&&$cf->get(2025)['carry_current'],'rebased carry needs renewed check');$cw->confirm(2025,3,3,true,'Rebased carry reviewed');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')!=='1'){
$restored=MySqlFixture::restoreBackup($db);check((new W($restored,'board'))->get(2025)===$board->get(2025)&&(new W($restored,'carry-board'))->get(2025)===$cw->get(2025),'encrypted backup restores history source and carry identically');
check(!MySqlFixture::foreignKeyErrors($restored),'restored FK boundaries');
}else echo "SKIP optional backup roundtrip: unchanged storage contract\n";
// Real view/controller, synthetic session only.
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor,array $post=[])use($db):string{$_SESSION=['actor'=>$actor];$_GET=[];$_POST=$post;$year=2025;ob_start();require __DIR__.'/../views/Finance/Balance/balance-clearings.php';return ob_get_clean();};
$html=$render('finance');check(str_contains($html,'Vorschüsse zwischen Vereinsbereichen')&&str_contains($html,'9,03'),'real view shows current outstanding total by cases');
check(!str_contains($html,'value="balance-clearing-confirm"'),'finance cannot confirm board review');
check(!str_contains($render('member'),'Gastronomie'),'no data in unauthorized view');
$readerHtml=$render('reader');check(str_contains($readerHtml,'Gastronomie')&&!str_contains($readerHtml,'value="balance-clearing-save"')&&!str_contains($readerHtml,'value="balance-clearing-confirm"'),'read-only UI has no write actions');
$finance->save(2025,8,4,$input,true,'Prepared for view check');$html=$render('board');check(str_contains($html,'value="balance-clearing-confirm"')&&str_contains($html,'synthetic'),'board action is CSRF protected');
file_put_contents(__DIR__.'/balance-clearings-ui.html',$html);
$post=['action'=>'balance-clearing-save','balance_year'=>'2025','version'=>'2','segment_version'=>'1','evidence'=>'Keep <draft>','advances'=>[['id'=>str_repeat('f',32),'voucher'=>str_repeat('a',32),'from'=>'club','to'=>'food','amount'=>'1.234,56','reason'=>'<bad>','repay_by'=>'2025-05-31']],'repayments'=>[]];
$recoveryBefore=MySqlFixture::snapshot($db);$recovered=$render('reader',$post);preg_match('/<pre hidden data-original-finance-retained-fields>(.*?)<\/pre>/s',$recovered,$recoveryMatch);check(str_contains($recovered,'data-original-finance-inputs')&&!str_contains($recovered,'<form')&&json_decode(html_entity_decode($recoveryMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR)===array_diff_key($post,['csrf'=>true])&&MySqlFixture::snapshot($db)===$recoveryBefore,'original advances and repayments retain every nested entry with read rights and no writes');
$html=$render('finance',$post);check(str_contains($html,'value="1.234,56"')&&str_contains($html,'Keep &lt;draft&gt;')&&!str_contains($html,'<bad>'),'invalid form retained and escaped');check(str_contains($html,'name="version" value="2"')&&str_contains($html,'name="segment_version" value="1"'),'both stale form versions retained');
class ClearingRedirect extends RuntimeException {public function __construct(public array $parameters){parent::__construct('Synthetic redirect');}}function redirectTo(array $p):never{throw new ClearingRedirect($p);}
$act=static function(string $action,array $post,string $actor='finance')use($db):array{$_SESSION=['actor'=>$actor];$basis=(new W($db,$actor))->forAction(2025,(int)input($post,'version'),(int)input($post,'segment_version'));$source=end($basis['segments']['history']);$_POST=$post+['balance_year'=>'2025','clearing_segment_sha256'=>$source['sha256']];$year=2025;$version=(int)input($post,'version');try{require __DIR__.'/../src/Finance/Balance/BalanceClearingActions.php';}catch(ClearingRedirect $r){return $r->parameters;}throw new RuntimeException('Missing redirect');};
$post=['version'=>'9','segment_version'=>'4','evidence'=>'Controller review','reviewed'=>'1','clearing_form_complete'=>'1','advances'=>[],'repayments'=>[]];foreach($input as $kind=>$rows)foreach($rows as $id=>$row){$row['id']=$id;$row['amount']=number_format($row['cents']/100,2,',','');unset($row['cents']);$post[$kind][]=$row;}
$cut=$post;unset($cut['clearing_form_complete']);denied(fn()=>$act('balance-clearing-save',$cut),'truncated form cannot change stored proof');
$cut=$post;array_pop($cut['advances']);denied(fn()=>$act('balance-clearing-save',$cut),'missing stored row is not implicit deletion');
$firstControllerPost=$post;$redirect=$act('balance-clearing-save',$post);check($redirect['clearings']==='1'&&$finance->get(2025)['version']===10,'controller preserves page and persists exact cents');
$post['version']='10';$post['advances'][0]['amount']='1.000,00';$before=MySqlFixture::snapshot($db);denied(fn()=>$act('balance-clearing-save',$post),'ambiguous thousands blocked');check(MySqlFixture::snapshot($db)===$before,'bad form leaves database and audit unchanged');
denied(fn()=>$act('balance-clearing-confirm',['version'=>'10','segment_version'=>'4','evidence'=>'No','reviewed'=>'1']),'controller repeats board authorization');
$act('balance-clearing-confirm',['version'=>'10','segment_version'=>'4','evidence'=>'Final review','reviewed'=>'1'],'board');check($finance->get(2025)['current_approval'],'controller board check works');
$controllerRows=MySqlFixture::snapshot($db);check($redirect['clearing_original']==='10'&&$act('balance-clearing-save',$firstControllerPost)===$redirect&&MySqlFixture::snapshot($db)===$controllerRows,'actual controller repeat after a later board review returns the first immutable clearing redirect without writes');
$source=(new S($db,'finance'))->get(2025);$bound=end($source['history']);$rawOriginal=$firstControllerPost+['balance_year'=>'2025','clearing_segment_sha256'=>$bound['sha256']];
$rawAct=static function(array$values)use($db):void{$_SESSION=['actor'=>'finance'];$_POST=$values;$year=2025;$version=9;$action='balance-clearing-save';require __DIR__.'/../src/Finance/Balance/BalanceClearingActions.php';};
foreach(['balance_year'=>['2025e0','2024',[]],'version'=>['09','9e0',[]],'segment_version'=>['04','4e0',[]],'clearing_segment_sha256'=>[str_repeat('a',64),'',[]]]as$field=>$variants)foreach($variants as$value){$bad=$rawOriginal;$bad[$field]=$value;denied(fn()=>$rawAct($bad),'actual clearing controller rejects replaced or ambiguous original scalar metadata');}
check(MySqlFixture::snapshot($db)===$controllerRows,'every rejected original controller metadata variant preserves all whole rows');

$segmentRender=static function()use($db):string{$_SESSION=['actor'=>'board'];$_GET=[];$_POST=[];$year=2025;ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';return ob_get_clean();};
$html=$segmentRender();check(str_contains($html,'Aufteilung mit Verrechnungsnachweis geprüft')&&!str_contains($html,'Aufteilung noch offen'),'segment view recognizes checked internal reconciliation');
check(str_contains($html,'Bereichsabgleich und Nachweise öffnen')&&!str_contains($html,'value="balance-segment-confirm"'),'internal statement never exposes an unsupported tax approval shortcut');
$html=$render('board');check(str_contains($html,'Abgleich je Vereinsbereich')&&str_contains($html,'Schulden gegenüber Dritten'),'review surface separates internal and external balances');
// Forge only inside the isolated fixture. Existing rows cannot be altered.
$p=$finance->get(2025)['history'][0];unset($p['sha256']);$p['revision']=12;$p['previous_sha256']=str_repeat('0',64);
$db->prepare('INSERT INTO balance_clearing_reviews VALUES(?,?,?,?,?)')->execute(['a',2025,12,json_encode($p),\Kulturhus\Verein\BalanceSegmentCalculation::hash($p)]);
denied(fn()=>$finance->get(2025),'rehashed broken history rejected');
echo "$checks clearing persistence checks passed; isolated synthetic MySQL, no transport\n";
}
