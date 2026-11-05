<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-club-tax-reconciliation.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxReconciliations.php';
use Kulturhus\Verein\{BalanceTaxReconciliations as T,BalanceClearings as W,BalanceSegments as S,BalanceLedger as L,Database,SettingsSchema,TaxPreparation,BalanceSegmentCalculation};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);$migrations=MySqlFixture::rows($db,'schema_migrations');Database::migrate($db);check($migrations===MySqlFixture::rows($db,'schema_migrations'),'migration replay stable');
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Tax Reconciliation'),('b','Synthetic Other Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('other','b','treasurer',1),('reader','a','member',0)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic reader','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
[$r,$units,$alloc,$input]=clearingFixture();$ledger=new L($db,'board');$segments=new S($db,'finance');$cw=new W($db,'board');$t=new T($db,'finance');$tb=new T($db,'board');
$ledger->create(2025);$ledger->setup(2025,1,$r['accounts'],$r['opening'],'equity','Synthetic opening',true);$version=2;
foreach($r['entries'] as $entry)$ledger->post(2025,$version++,$entry['id'],$entry['date'],$entry['description'],'Synthetic evidence',$entry['lines']);
$ledger->close(2025,$version,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic source closed');$segments->save(2025,0,$units,$alloc);
check($t->get(2025)['income']===null,'no invented source before approval');denied(fn()=>$t->carry(2025),'no unapproved beginning data');
$cw->save(2025,0,1,$input,true,'Synthetic advances');$cw->confirm(2025,1,1,true,'Synthetic board source review');$income=$cw->incomeReconciliation(2025);$review=taxIncomeReview($income);
foreach(['member','other'] as $actor){denied(fn()=>(new T($db,$actor))->get(2025),'unauthorized read '.$actor);denied(fn()=>(new T($db,$actor))->save(2025,0,$review,'No'),'unauthorized write '.$actor);}
$before=MySqlFixture::snapshot($db);$t->get(2025);$t->carry(2025);check(MySqlFixture::snapshot($db)===$before,'GET and carry suggestion never write');
denied(fn()=>(new T($db,'reader'))->save(2025,0,$review,'No'),'finance reader cannot save');
putenv('VEREIN_BALANCE_WORKSPACE=0');denied(fn()=>$t->get(2025),'operator gate unchanged');putenv('VEREIN_BALANCE_WORKSPACE=1');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$t->get(2025),'EÜR stays outside balance workflow');denied(fn()=>$t->save(2025,0,$review,'No'),'EÜR write rejected');$setting->execute([json_encode($settings)]);
$review['current_adjustments']=[['id'=>'tax-value','reason'=>'Synthetic evidenced value','lines'=>[['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]]];
$firstReview=$review;$firstSave=$t->save(2025,0,$review,'Synthetic value review');$draft=$t->get(2025);check($draft['values']['result']['tax_book_cents']===1507&&!$draft['current_approval'],'persisted draft not board approval');
check(str_starts_with($t->files(2025)['steuerliche-nebenrechnung/uebersicht.txt'],'ENTWURF'),'draft export clearly marked');
check((new T($db,'reader'))->get(2025)===$draft,'reader sees same source-bound state');
denied(fn()=>$t->save(2025,0,$review,'Stale'),'stale own version');$bad=$review;$bad['income_sha256']=str_repeat('b',64);denied(fn()=>$t->save(2025,1,$bad,'Stale'),'stale source hash');
denied(fn()=>$t->confirm(2025,1,$income['sha256'],true,'No'),'treasurer cannot confirm board review');denied(fn()=>$tb->confirm(2025,1,$income['sha256'],false,'No'),'explicit confirmation required');
$firstApproval=$tb->confirm(2025,1,$income['sha256'],true,'Synthetic tax review');$approved=$t->get(2025);check($approved['current_approval']&&!$approved['tax_export_ready'],'board value review does not authorize transmission');
denied(fn()=>$tb->confirm(2025,2,$income['sha256'],true,'No'),'duplicate approval rejected');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");check(!$t->get(2025)['current_approval'],'revoked board authority invalidates source and review');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$bundle=(new TaxPreparation($db,'finance'))->build(2025,2025,'Synthetic activities');
check($bundle['snapshot']['reports'][2025]['balance_tax_reconciliation']['current_approval'],'year-end bundle includes exact review status');
foreach($t->files(2025) as $name=>$bytes)check($bundle['snapshot']['file_hashes']['2025/'.$name]===hash('sha256',$bytes),'export file bound '.$name);
$sourceRows=[MySqlFixture::rows($db,'balance_years'),MySqlFixture::rows($db,'balance_entries')];
$before=MySqlFixture::snapshot($db);$bad=$review;$bad['current_adjustments'][0]['lines'][0]['debit_cents']++;denied(fn()=>$t->save(2025,2,$bad,'Invalid'),'unbalanced review rejected');check(MySqlFixture::snapshot($db)===$before,'failed review and audit rolled back');
foreach(['UPDATE','DELETE'] as $verb){$blocked=false;try{$db->exec($verb==='UPDATE'?"UPDATE balance_tax_reconciliation_reviews SET sha256=REPEAT('0',64)":"DELETE FROM balance_tax_reconciliation_reviews");}catch(PDOException $e){$blocked=$e->getCode()==='45000';}check($blocked,'immutable native history '.$verb);}
$ledger->create(2026);[$next,$nu,$na,$ni]=clearingNext($cw->get(2025)['proof'],234);$entry=$next['entries'][0];$ledger->post(2026,1,$entry['id'],$entry['date'],$entry['description'],'Synthetic repayment',$entry['lines']);
$ledger->close(2026,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year closed');$segments->carry(2026,0,true);$segments->save(2026,1,$units,$segments->get(2026)['allocation']['allocations']+$na);
$cw->save(2026,0,2,$ni,true,'Synthetic next year source');$cw->confirm(2026,1,2,true,'Synthetic next year reviewed');
$nextIncome=$cw->incomeReconciliation(2026);$nextReview=taxIncomeReview($nextIncome);$carry=$t->carry(2026);$nextReview['opening_adjustments']=$carry['opening_adjustments'];
$firstNextReview=$nextReview;$firstNextSave=$t->save(2026,0,$nextReview,'Synthetic exact prior carry');$firstNextApproval=$tb->confirm(2026,1,$nextIncome['sha256'],true,'Synthetic next tax review');$nextState=$t->get(2026);
check($nextState['current_approval']&&$nextState['values']['result']['tax_book_cents']===0&&$nextState['values']['valuation']['accounts']['receivables']['tax_opening_cents']===501,'two-year persisted value carry exact');
$review['current_adjustments'][0]['reason']='Synthetic revised proof';$t->save(2025,2,$review,'Revised prior evidence');check(!$t->get(2026)['carry_current']&&!$t->get(2026)['current_approval'],'prior review edit invalidates following approval');
denied(fn()=>$t->carry(2026),'cannot carry unreviewed prior');$tb->confirm(2025,3,$income['sha256'],true,'Prior rechecked');
denied(fn()=>$t->save(2026,2,$nextReview,'No board'),'only board may replace stale prior binding');
$nextReview['opening_adjustments']=$tb->carry(2026)['opening_adjustments'];$stalePriorRows=MySqlFixture::snapshot($db);denied(fn()=>$tb->save(2026,2,$nextReview,'Reject silent prior rebinding',['prior_source'=>$carry['prior_source']]),'fresh original prior expectation rejects a newer economically identical prior selection');check(MySqlFixture::snapshot($db)===$stalePriorRows,'stale explicit prior cannot create a review or audit');$refreshReview=$nextReview;$refreshSave=$tb->save(2026,2,$nextReview,'Board checked revised prior');$tb->confirm(2026,3,$nextIncome['sha256'],true,'Following year rechecked');check($t->get(2026)['current_approval'],'explicit new prior review restored');
check(array_slice(MySqlFixture::rows($db,'balance_years'),0,count($sourceRows[0]))===$sourceRows[0]&&array_slice(MySqlFixture::rows($db,'balance_entries'),0,count($sourceRows[1]))===$sourceRows[1],'tax reviews never modify original source rows');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')!=='1'){
    $expected=$t->get(2026);$restored=MySqlFixture::restoreBackup($db);check((new T($restored,'finance'))->get(2026)===$expected,'encrypted roundtrip restores exact source, history and carry');check(!MySqlFixture::foreignKeyErrors($restored),'restored FK boundaries');
}else echo "SKIP optional backup roundtrip: dedicated synthetic database\n";
// A source approval change invalidates current and subsequent tax approvals.
$cw->save(2025,2,1,$input,true,'Revised original source review');check(!$t->get(2025)['source_current']&&!$t->get(2026)['current_approval'],'source edit invalidates transitive review');
check(str_starts_with($t->files(2025)['steuerliche-nebenrechnung/uebersicht.txt'],'ENTWURF'),'stale export cannot claim approval');
$cw->confirm(2025,3,1,true,'Source rechecked');$fresh=$cw->incomeReconciliation(2025);$review['income_sha256']=$fresh['sha256'];
denied(fn()=>$tb->confirm(2025,4,$fresh['sha256'],true,'No'),'old values cannot be confirmed against new source');
$t->save(2025,4,$review,'Rebuilt exact source');$tb->confirm(2025,5,$fresh['sha256'],true,'New source values checked');check($t->get(2025)['current_approval'],'source refresh explicitly recomputed');
// Exact first outcomes remain immutable evidence after real later source and prior changes.
$unchanged=MySqlFixture::snapshot($db);
check($t->save(2025,0,$firstReview,'Synthetic value review')===$firstSave,'late exact save returns actual first result after source replacement');
check($tb->confirm(2025,1,$income['sha256'],true,'Synthetic tax review')===$firstApproval,'late exact board confirmation returns first result after source replacement');
check((new T($db,'reader'))->original(2025,1,$firstSave['sha256'])===$firstSave,'reader selects exact historic tax evidence');
check($t->save(2026,0,$firstNextReview,'Synthetic exact prior carry')===$firstNextSave&&$tb->confirm(2026,1,$nextIncome['sha256'],true,'Synthetic next tax review')===$firstNextApproval,'old carried tax requests retain their actual original reviewed prior');
check($tb->save(2026,2,$refreshReview,'Board checked revised prior')===$refreshSave,'accepted prior refresh returns exact first outcome');
$originalContext=$t->forAction(2025,0,2,$income['scope']['review']['clearing']['sha256'],null);
check($originalContext['state']['income']===$income&&$originalContext['state']['current_approval']===false,'old action context reconstructs actual original source without current approval');
$otherSourceContext=$t->forAction(2025,0,4,$fresh['scope']['review']['clearing']['sha256'],null);
check($otherSourceContext['state']['income']===$fresh,'rejected stale form retains supplied source even when first acceptance used another');
$badFirst=$firstReview;$badFirst['current_adjustments'][0]['reason']='Changed retry';denied(fn()=>$t->save(2025,0,$badFirst,'Synthetic value review'),'changed replay content cannot borrow first result');
denied(fn()=>$tb->save(2025,0,$firstReview,'Synthetic value review'),'another actor cannot borrow accepted operation');
denied(fn()=>$tb->confirm(2025,1,$income['sha256'],false,'Synthetic tax review'),'unchecked replay cannot borrow approval');
denied(fn()=>$t->save(2025,0,$firstReview,'Synthetic value review',['prior_source'=>['year'=>2024,'revision'=>1,'sha256'=>str_repeat('1',64)]]),'replay must match explicit original prior selection');
denied(fn()=>$t->save(2025,0,$firstReview,'Synthetic value review',['clearing_source'=>['revision'=>4,'sha256'=>$fresh['scope']['review']['clearing']['sha256']]]),'replay must match explicit original clearing selection');
check(MySqlFixture::snapshot($db)===$unchanged,'all original results and rejected repetitions leave every source and audit row unchanged');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");$revoked=MySqlFixture::snapshot($db);
denied(fn()=>$tb->confirm(2025,1,$income['sha256'],true,'Synthetic tax review'),'old approval retry rechecks current personal board authority');
denied(fn()=>$tb->save(2026,2,$refreshReview,'Board checked revised prior'),'old prior-refresh retry rechecks current personal board authority');
check(MySqlFixture::snapshot($db)===$revoked,'revoked old approvals make no changes');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$corrections=new \Kulturhus\Verein\BalanceClosingCorrections($db,'board');$closed=$ledger->report(2025);
$corrections->save(2025,0,$closed['closed_sha256'],['kind'=>'error','reason'=>'Own pending original correction','legal_basis'=>'Own evidenced pending correction','confirmed'=>true,'entries'=>[['id'=>str_repeat('e',32),'date'=>'2025-12-31','description'=>'Own pending tax source','evidence'=>'Own correction evidence','lines'=>[['account'=>'expense','debit_cents'=>1,'credit_cents'=>0,'sphere'=>'4'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'']]]]]);
$pendingRows=MySqlFixture::snapshot($db);
check($t->save(2025,0,$firstReview,'Synthetic value review')===$firstSave&&$tb->confirm(2025,1,$income['sha256'],true,'Synthetic tax review')===$firstApproval,'pending current closing correction does not replace accepted originals');
check($t->save(2026,0,$firstNextReview,'Synthetic exact prior carry')===$firstNextSave&&$tb->confirm(2026,1,$nextIncome['sha256'],true,'Synthetic next tax review')===$firstNextApproval,'pending prior closing correction does not replace carried accepted originals');
check(MySqlFixture::snapshot($db)===$pendingRows,'pending-source original reads and repeats are entirely read-only');
$corrections->discard(2025,1,$closed['closed_sha256'],'Own explicit pending correction cleanup');
// Real view and controller, synthetic actors and POST data only.
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor,array $post=[],array $query=[])use($db):string{$_SESSION=['actor'=>$actor];$_GET=$query;$_POST=$post;$year=2025;ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-reconciliation.php';return ob_get_clean();};
$t->save(2025,6,$review,'Synthetic current draft');
$html=$render('board');check(str_contains($html,'Steuerliches Ergebnis')&&str_contains($html,'15,07 €')&&str_contains($html,'balance-tax-reconciliation-confirm'),'board sees result and exact-draft review');
file_put_contents(__DIR__.'/balance-tax-reconciliation-ui.html',$html);
check(!str_contains($render('finance'),'balance-tax-reconciliation-confirm'),'finance cannot confirm board review in UI');
$html=$render('reader');check(str_contains($html,'15,07 €')&&!str_contains($html,'<form'),'reader has no forms');
check(!str_contains($render('member'),'15,07'),'unauthorized view has no data');
$beforeHistoryType=MySqlFixture::snapshot($db);$arrayHistory=$render('reader',[],['tax_history_page'=>['1']]);check(str_contains($arrayHistory,'Verlaufsseite der Steuerwerte vollständig auswählen.')&&!str_contains($arrayHistory,'data-tax-history-link')&&MySqlFixture::snapshot($db)===$beforeHistoryType,'array history selection gives a read-only explanation without silently selecting page one');
$old=['action'=>'balance-tax-reconciliation-value','balance_year'=>'2025','version'=>'2','income_sha256'=>$income['sha256'],'tax_clearing_revision'=>'2','tax_clearing_sha256'=>$income['scope']['review']['clearing']['sha256'],'tax_prior_year'=>'','tax_prior_revision'=>'','tax_prior_sha256'=>'','tax_request_key'=>str_repeat('1',32),'reviewed'=>'1','amount'=>'12,34','evidence'=>'<keep draft>','debit_account'=>'receivables','credit_account'=>'expense'];
$recoveryBefore=MySqlFixture::snapshot($db);$recovered=$render('reader',$old);preg_match('/<pre hidden data-original-finance-retained-fields>(.*?)<\/pre>/s',$recovered,$recoveryMatch);check(str_contains($recovered,'data-original-finance-inputs')&&!str_contains($recovered,'<form')&&json_decode(html_entity_decode($recoveryMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR)===array_diff_key($old,['csrf'=>true])&&MySqlFixture::snapshot($db)===$recoveryBefore,'annual original tax entries remain completely readable with read rights and no writes');
$html=$render('finance',$old);check(str_contains($html,'value="12,34"')&&str_contains($html,'&lt;keep draft&gt;')&&str_contains($html,'name="version" value="2"')&&str_contains($html,'name="income_sha256" value="'.$income['sha256'].'"'),'failed input escaped, retained and never upgraded to current version');
check(substr_count($html,'<form')===1&&str_contains($html,'name="reviewed" value="1" required checked'),'failed original value form alone retains explicit confirmation');
$badMeta=$old;$badMeta['tax_clearing_revision']=['2'];$html=$render('finance',$badMeta);check(!str_contains($html,'<form')&&str_contains($html,'&lt;keep draft&gt;'),'malformed original source retains escaped entries without offering a replacement business form');preg_match('/<pre hidden data-tax-retained-fields>(.*?)<\/pre>/s',$html,$retainedMatch);check(json_decode(html_entity_decode($retainedMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR)===$badMeta&&str_contains($html,'<dt>Betrag in Euro</dt>'),'incomplete source keeps every original field privately while displaying understandable business labels');
class TaxReconciliationRedirect extends RuntimeException {public function __construct(public array $parameters){parent::__construct('Synthetic redirect');}}
function redirectTo(array $p):never{throw new TaxReconciliationRedirect($p);}
$act=static function(string $action,array $post,string $actor='finance')use($db):array{$_SESSION=['actor'=>$actor];$_POST=$post;$year=2025;$version=(int)input($post,'version');try{require __DIR__.'/../src/Finance/Balance/BalanceTaxReconciliationActions.php';}catch(TaxReconciliationRedirect $r){return $r->parameters;}throw new RuntimeException('Missing redirect');};
$post=['balance_year'=>'2025','tax_clearing_revision'=>'4','tax_clearing_sha256'=>$fresh['scope']['review']['clearing']['sha256'],'tax_prior_year'=>'','tax_prior_revision'=>'','tax_prior_sha256'=>'','tax_request_key'=>str_repeat('2',32),'version'=>'7','income_sha256'=>$fresh['sha256'],'reviewed'=>'1','evidence'=>'Synthetic controller review','tax_reconciliation_form_complete'=>'1'];
$bad=$post;unset($bad['tax_reconciliation_form_complete']);denied(fn()=>$act('balance-tax-reconciliation-prepare',$bad),'truncated form blocked');
$bad=$post;$bad['income_sha256']=str_repeat('0',64);denied(fn()=>$act('balance-tax-reconciliation-prepare',$bad),'controller stale source blocked');
denied(fn()=>$act('balance-tax-reconciliation-prepare',$post,'reader'),'controller repeats write authorization');
$redirect=$act('balance-tax-reconciliation-prepare',$post);check($redirect['tax_reconciliation']==='1'&&$t->get(2025)['version']===8,'prepare preserves review and returns same page');
$post['version']='8';denied(fn()=>$act('balance-tax-reconciliation-confirm',$post),'controller repeats board authorization');$act('balance-tax-reconciliation-confirm',$post,'board');check($t->get(2025)['current_approval'],'controller approves exact draft');
$post['version']='9';$post+=['correction_kind'=>'non_deductible_expense','source_line'=>'journal:'.str_repeat('a',32).':0','amount'=>'1,03','legal_basis'=>'Synthetic documented rule'];
$act('balance-tax-reconciliation-off-balance',$post);$changed=$t->get(2025);check(!$changed['current_approval']&&$changed['values']['result']['taxable_income_cents']===1610,'new correction invalidates review and changes only tax result');
$post['version']='10';$post['period']='off_balance_adjustments';$post['adjustment_id']=$changed['values']['review']['off_balance_adjustments'][0]['id'];
$act('balance-tax-reconciliation-remove',$post);check($t->get(2025)['values']['result']['taxable_income_cents']===1507,'targeted removal restores result without losing valuation');
$post['version']='11';$post['debit_account']='receivables';$post['credit_account']='expense';$post['amount']='0,05';$act('balance-tax-reconciliation-value',$post);check($t->get(2025)['values']['result']['taxable_income_cents']===1512,'value controller uses exact cents and sphere 4');
$post['version']='12';$post['amount']='1.000,00';$before=MySqlFixture::snapshot($db);denied(fn()=>$act('balance-tax-reconciliation-value',$post),'ambiguous thousands not silently misparsed');check(MySqlFixture::snapshot($db)===$before,'bad controller write leaves data unchanged');
$post['amount']='1,00';$post['reviewed']='0';denied(fn()=>$act('balance-tax-reconciliation-value',$post),'form explicit check required');
// Only synthetic data: a rehashed invalid extra revision must not be accepted.
$history=$t->get(2025)['history'];$p=end($history);unset($p['sha256']);$p['revision']++;$p['previous_sha256']=str_repeat('0',64);
$db->prepare('INSERT INTO balance_tax_reconciliation_reviews VALUES(?,?,?,?,?)')->execute(['a',2025,$p['revision'],json_encode($p),BalanceSegmentCalculation::hash($p)]);
denied(fn()=>$t->get(2025),'rehashed broken history rejected');
echo "$checks tax reconciliation persistence checks passed; synthetic MySQL only, no transport\n";
}
