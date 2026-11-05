<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-internal-clearing.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
use Kulturhus\Verein\{BalancePeriodLedger as L,BalancePeriodAreas as A,BalancePeriodTaxReconciliations as T,BalanceTaxFiling as F,BalanceFilingRevision as R,TaxFilings,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic period filing'),('b','Synthetic other'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('other','b','treasurer',1)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic read','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
[$fixture,$units,$alloc,$instructions]=clearingFixture(100);$l=new L($db,'board');$a=new A($db,'board');$t=new T($db,'finance');$tb=new T($db,'board');$f=new F($db,'finance');$fb=new F($db,'board');$tax=new TaxFilings($db,'finance');
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic fiscal year',true);
denied(fn()=>$f->createPeriod($pid),'open period cannot prepare filing');
$l->setup($pid,1,$fixture['opening'],'Synthetic opening',true);
foreach($fixture['entries'] as $entry){$r=$l->report($pid);$l->post($pid,$r['version'],$entry['id'],str_replace(['2025-02','2025-03','2025-04'],['2025-08','2025-09','2026-02'],$entry['date']),$entry['description'],'Synthetic source',$entry['lines']);}
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$r=$l->report($pid);$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic close');$r=$l->report($pid);
$a->prepare($pid,0,$r['closed_sha256'],$units,'Synthetic areas');
foreach($alloc as $key=>$v)$a->edit($pid,$a->view($pid)['version'],$r['closed_sha256'],'line',['key'=>$key,'parts'=>$v['parts']],'Synthetic source');
foreach($instructions['advances'] as $key=>$v){$v['repay_by']='2026-09-30';$a->edit($pid,$a->view($pid)['version'],$r['closed_sha256'],'advance',['id'=>$key]+$v,'Synthetic agreement');}
foreach($instructions['repayments'] as $key=>$v)$a->edit($pid,$a->view($pid)['version'],$r['closed_sha256'],'repayment',['id'=>$key]+$v,'Synthetic repayment');
denied(fn()=>$f->createPeriod($pid),'unapproved allocation cannot prepare filing');
$as=$a->view($pid);$a->confirm($pid,$as['version'],$r['closed_sha256'],$as['sha256'],true,'Synthetic allocation review');
$review=$t->draft($pid);$review['current_adjustments']=[['id'=>'value','reason'=>'Synthetic reviewed value','lines'=>[['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]]];
$t->save($pid,0,$review,'Synthetic tax values');denied(fn()=>$f->createPeriod($pid),'draft tax review is not approval');$tb->confirm($pid,1,$review['income_sha256'],true,'Synthetic tax review');
foreach(['reader','member','other'] as $actor)denied(fn()=>(new F($db,$actor))->createPeriod($pid),'role and tenant create guard '.$actor);
denied(fn()=>$f->create(2026,F::SUBSIDIARY_SOURCE),'dated year cannot use calendar shortcut');
$sourceBefore=MySqlFixture::rows($db,'balance_period_events');$id=$f->createPeriod($pid);$first=$tax->get($id);$b=$first['form_data']['balance_preparation'];
check($b['period']===$period&&$b['period_id']===$pid&&$b['source_binding']['revision']===2,'exact dates and reviewed source persisted');
check($f->previewValues($first)['subsidiary']['result']['taxable_income_cents']===1507,'dated preview uses checked income including valuation');
denied(fn()=>$f->assertCurrent($first),'final review required');
denied(fn()=>(new F($db,'other'))->previewValues($first),'cross tenant preview blocked');
foreach(['period_id','period','source_binding','closing_source_sha256'] as $field){$bad=$first;if($field==='period_id')$bad['form_data']['balance_preparation'][$field]='20260101-20261231';elseif($field==='period')$bad['form_data']['balance_preparation'][$field]['start']='2025-08-01';elseif($field==='source_binding')$bad['form_data']['balance_preparation'][$field]['revision']=4;else $bad['form_data']['balance_preparation'][$field]=str_repeat('f',64);denied(fn()=>$f->previewValues($bad),'forged dated binding '.$field);}
$bad=$first;unset($bad['form_data']['balance_preparation']['period'],$bad['form_data']['balance_preparation']['period_id']);denied(fn()=>$f->previewValues($bad),'removed dates cannot fall back to calendar');
$profile=['business_name'=>'','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'asset_tax_values_reviewed'=>false,'tax_privileged'=>true,'whole_business_name'=>'Synthetic <activity>','whole_book_standard'=>'HGBM','company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$mapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1','receivables'=>'bs.ass.currAss.receiv.trade.other'];
$f->save($id,1,$profile,$mapping,['complete'=>true]);
check($f->assertCurrent($tax->get($id))['period']===$period,'saved dated values stay bound');
check($tax->validate($id,2)['valid'],'dated persisted filing uses real generator');
$originalXml=$tax->get($id)['xml_data'];check(str_contains($originalXml,'2025-07-01')&&str_contains($originalXml,'2026-06-30'),'XML contains actual fiscal dates');
file_put_contents(__DIR__.'/balance-period-workflow-first.xml',$originalXml);
denied(fn()=>$f->approve($id,3,true),'finance role cannot authorize board send');denied(fn()=>$tax->ready($id,3,'SEND-TEST',true,true),'explicit board approval required');$fb->approve($id,3,true);
check($tax->ready($id,4,'SEND-TEST',true,true)['id']===$id,'exact approved dated draft ready');
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $id,string $actor,array $post=[])use($db):string{$_SESSION=['actor'=>$actor];$_GET=['tax_year'=>'2026','filing'=>$id];$_POST=$post;$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_clean();};
$html=$render($id,'board');check(str_contains($html,'01.07.2025 – 30.06.2026')&&str_contains($html,'15,07 €')&&str_contains($html,'Test jetzt senden'),'dated UI displays exact interval result and next step');
file_put_contents(__DIR__.'/balance-period-workflow-ui.html',$html);
check(str_contains($html,'period_id='.$pid)&&str_contains($html,'period_tax=1')&&!str_contains($html,'balance_year=2026'),'review link returns to actual period');
check(str_contains($html,'&lt;activity&gt;')&&!str_contains($html,'<activity>'),'UI values escaped');
$html=$render($id,'reader');check(!str_contains($html,'Test jetzt senden')&&!str_contains($html,'Angaben speichern'),'reader has no writes');
$html=$render($id,'finance',['action'=>'tax-filing-balance-save','filing'=>$id,'version'=>'1','profile'=>['whole_business_name'=>'<stale>']]);check(str_contains($html,'&lt;stale&gt;')&&str_contains($html,'name="version" value="1"'),'stale form preserved');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");denied(fn()=>$tax->ready($id,4,'SEND-TEST',true,true),'revoked board invalidates source');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
putenv('VEREIN_MODE=production');denied(fn()=>$tax->ready($id,4,'SEND-TEST',true,true),'production still cannot send synthetic draft');putenv('VEREIN_MODE=synthetic');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$f->previewValues($tax->get($id)),'EÜR untouched');$setting->execute([json_encode($settings)]);
check($tax->send($id,4,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','simulated dated send records receipt without network');check($tax->attempts($id)[0]['receipt_bound'],'dated receipt exactly bound');
$original=$tax->get($id);$attempts=$tax->attempts($id);denied(fn()=>$tax->send($id,4,'dummy','123456','SEND-TEST',true,true),'duplicate send prevented');
$copy=$f->createPeriod($pid);$f->save($copy,1,$profile,$mapping,['complete'=>true]);denied(fn()=>$tax->validate($copy,2),'same period cannot be sent as a new first filing');
check(array_column((new R($db,'finance'))->choices($tax->get($copy)),'filing_id')===[$id],'original receipt offered for exactly this period');
$data=['status'=>'sK','reason'=>'Synthetic other correction','legal_basis'=>'Synthetic checked permission','source_id'=>$id,'confirmed'=>true];$f->revision($copy,2,$data);check($tax->validate($copy,3)['valid'],'unchanged dated B GuV allows other correction');
check(MySqlFixture::rows($db,'balance_period_events')===$sourceBefore,'filing workflow never rewrites journal');
// Correct the frozen period. Both the original filing and its receipt survive.
$r=$l->report($pid);$correction=['kind'=>'error','reason'=>'Synthetic missed expense','legal_basis'=>'Synthetic reviewed evidence','confirmed'=>true,'entries'=>[['id'=>str_repeat('e',32),'date'=>'2026-06-30','description'=>'Synthetic additional expense','evidence'=>'Synthetic invoice','lines'=>[['account'=>'expense','debit_cents'=>101,'credit_cents'=>0,'sphere'=>'4'],['account'=>'cash','debit_cents'=>0,'credit_cents'=>101,'sphere'=>'']]]]];
$l->prepareClosingCorrection($pid,$r['version'],$r['closed_sha256'],$correction);$r=$l->report($pid);$l->confirmClosingCorrection($pid,$r['version'],$r['closed_sha256'],$r['correction_draft']['proof']['sha256'],true,'Synthetic corrected close');
denied(fn()=>$f->assertCurrent($tax->get($copy)),'new closing invalidates old source and approval');denied(fn()=>$f->createPeriod($pid),'corrected original needs new area and tax review');
$r=$l->report($pid);$as=$a->view($pid);$a->prepare($pid,$as['version'],$r['closed_sha256'],$units,'Synthetic corrected allocation');
foreach([0,1] as $i)$a->edit($pid,$a->view($pid)['version'],$r['closed_sha256'],'line',['key'=>str_repeat('e',32).':'.$i,'parts'=>['food'=>101]],'Synthetic corrected source');
$as=$a->view($pid);$a->confirm($pid,$as['version'],$r['closed_sha256'],$as['sha256'],true,'Synthetic corrected review');$review=$t->draft($pid);$ts=$t->get($pid);$t->save($pid,$ts['version'],$review,'Synthetic updated tax source');$ts=$t->get($pid);$tb->confirm($pid,$ts['version'],$review['income_sha256'],true,'Synthetic updated tax review');
$amend=$f->createPeriod($pid);$f->save($amend,1,$profile,$mapping,['complete'=>true]);denied(fn()=>$tax->validate($amend,2),'corrected journal requires explicit declaration type');
denied(fn()=>$f->revision($amend,2,$data),'changed dated B GuV is not other correction');$data['status']='B';$f->revision($amend,2,$data);check($tax->validate($amend,3)['valid'],'amended dated generator and historical receipt match');
file_put_contents(__DIR__.'/balance-period-workflow-correction.xml',$tax->get($amend)['xml_data']);
$fb->approve($amend,4,true);check($tax->send($amend,5,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','amended dated simulated receipt');
check($tax->get($id)===$original&&$tax->attempts($id)===$attempts,'original values approval XML receipt immutable');check($tax->download($id,'receipt')['mime']==='application/xml','historic receipt remains available');
$html=$render($id,'reader');check(str_contains($html,'15,07 €')&&str_contains($html,'Übermittlungsprotokoll öffnen')&&!str_contains($html,'Test jetzt senden'),'historical UI retains original figures after a correction');
$copy2=$f->createPeriod($pid);$f->save($copy2,1,$profile,$mapping,['complete'=>true]);check(array_column((new R($db,'finance'))->choices($tax->get($copy2)),'filing_id')===[$amend],'correction chain offers latest receipt only');denied(fn()=>$f->revision($copy2,2,$data),'superseded original cannot be selected');
// Same ending year, different obligation and exact prior-value carry.
$pid2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short successor',true);$r2=$l->report($pid2);$l->close($pid2,$r2['version'],$r2['journal_sha256'],$all,'Synthetic successor close');$r2=$l->report($pid2);
$a->prepare($pid2,0,$r2['closed_sha256'],$units,'Synthetic successor areas');$as=$a->view($pid2);$a->confirm($pid2,$as['version'],$r2['closed_sha256'],$as['sha256'],true,'Synthetic successor reviewed');$review2=$t->draft($pid2);$t->save($pid2,0,$review2,'Synthetic exact carry');$tb->confirm($pid2,1,$review2['income_sha256'],true,'Synthetic successor tax reviewed');
$next=$f->createPeriod($pid2);$f->save($next,1,$profile,$mapping,['complete'=>true]);check((new R($db,'finance'))->choices($tax->get($next))===[],'other period same ending year not a correction source');
denied(fn()=>$f->revision($next,2,array_replace($data,['source_id'=>$amend])),'cannot correct another period ending in same year');
check($f->assertCurrent($tax->get($next))['subsidiary']['valuation']['accounts']['receivables']['tax_opening_cents']===501,'short successor carries exact tax opening');check($tax->validate($next,2)['valid'],'short successor real generation after corrected prior');$fb->approve($next,3,true);
file_put_contents(__DIR__.'/balance-period-workflow-successor.xml',$tax->get($next)['xml_data']);
check($tax->send($next,4,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','different period same year sends as first declaration');
$recent=array_column($tax->recent(2026),null,'id');check($recent[$id]['period_label']==='01.07.2025 – 30.06.2026'&&$recent[$next]['period_label']==='01.07.2026 – 31.12.2026'&&!isset($recent[$id]['form_data']),'list distinguishes actual periods without exposing source packets');
$html=$render('','reader');check(str_contains($html,'E-Bilanz · 01.07.2025 – 30.06.2026')&&str_contains($html,'E-Bilanz · 01.07.2026 – 31.12.2026'),'overview separates two fiscal periods in same year');
class PeriodFilingRedirect extends RuntimeException{public function __construct(public array $route){parent::__construct('Synthetic redirect');}}
function redirectTo(array $route):never{throw new PeriodFilingRedirect($route);}
$controller=static function(array $post,string $actor='finance')use($db):array{$_POST=$post;$_SESSION=['actor'=>$actor];$action=$post['action'];try{require __DIR__.'/../src/Finance/Tax/TaxFilingActions.php';}catch(PeriodFilingRedirect $e){return $e->route;}throw new RuntimeException('No redirect');};
$post=['action'=>'tax-filing-balance-create','period_id'=>$pid2,'tax_year'=>'2025','balance_scope'=>F::SUBSIDIARY_SOURCE];denied(fn()=>$controller($post,'reader'),'action authorization rechecked');denied(fn()=>$controller(array_replace($post,['balance_scope'=>'separate_business_ledger'])),'empty successor cannot turn mixed original into separate business');
$route=$controller($post);check($route['tax_year']==='2026'&&$tax->get($route['filing'])['form_data']['balance_preparation']['period_id']===$pid2,'action derives date from source not posted year');
// An ambiguous attempt blocks a new draft for that obligation, never auto-retries.
$unknown=$f->createPeriod($pid2);$otherProfile=$profile;$otherProfile['company']['tax_number']='5192050001999';$f->save($unknown,1,$otherProfile,$mapping,['complete'=>true]);$tax->validate($unknown,2);$fb->approve($unknown,3,true);
$ambiguous=$tax->send($unknown,4,'crash-after-claim','123456','SEND-TEST',true,true);check($ambiguous['state']==='unknown','uncertain simulated send remains unknown');
$retry=$f->createPeriod($pid2);$f->save($retry,1,$otherProfile,$mapping,['complete'=>true]);denied(fn()=>$tax->validate($retry,2),'uncertain prior blocks duplicate workflow');
check(!MySqlFixture::foreignKeyErrors($db),'foreign keys intact');
echo "$checks dated filing checks passed; isolated synthetic MySQL; actual generation, simulated transport only\n";
}
