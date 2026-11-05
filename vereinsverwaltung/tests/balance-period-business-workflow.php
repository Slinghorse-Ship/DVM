<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
use Kulturhus\Verein\{BalancePeriodLedger as L,BalanceTaxFiling as F,TaxFilings,BalanceFilingRevision as R,Database,SettingsSchema,Tests\MySqlFixture};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic separate business'),('b','Synthetic other'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('other','b','treasurer',1)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic read','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$l=new L($db,'board');$f=new F($db,'finance');$fb=new F($db,'board');$tax=new TaxFilings($db,'finance');
$period=['start'=>'2025-07-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic fiscal year',true);
denied(fn()=>$f->createPeriod($pid,'separate_business_ledger'),'open business journal cannot create tax draft');
$line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
$l->setup($pid,1,[$line('cash',100000,0),$line('equity',0,100000)],'Synthetic opening',true);
$l->post($pid,2,md5('revenue'),'2025-08-10','Synthetic earned revenue','Synthetic source',[$line('cash',10000,0),$line('revenue',0,10000,'4')]);
$l->post($pid,3,md5('cost'),'2026-03-10','Synthetic cost','Synthetic source',[$line('expense',1000,0,'4'),$line('cash',0,1000)]);
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$r=$l->report($pid);$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic approved business');
foreach(['reader','member','other'] as $actor)denied(fn()=>(new F($db,$actor))->createPeriod($pid,'separate_business_ledger'),'business tenant and role guard '.$actor);
$id=$f->createPeriod($pid,'separate_business_ledger');$first=$tax->get($id);$b=$first['form_data']['balance_preparation'];
check($b['period']===$period&&$b['business_source']===$f->sourceReport($first),'exact dated original persisted, no partial projection');
check($f->previewValues($first)['book']['annual_result_cents']===9000,'business preview uses real dated income');
denied(fn()=>$f->assertCurrent($first),'business tax review required');
foreach(['period','period_id','closing_source_sha256','business_source'] as $key){$bad=$first;if($key==='period')$bad['form_data']['balance_preparation'][$key]['start']='2025-08-01';elseif($key==='business_source')$bad['form_data']['balance_preparation'][$key]['opening'][0]['debit_cents']++;else $bad['form_data']['balance_preparation'][$key]='bad';denied(fn()=>$f->sourceReport($bad),'forged business binding '.$key);}
$bad=$first;unset($bad['form_data']['balance_preparation']['period'],$bad['form_data']['balance_preparation']['period_id']);denied(fn()=>$f->sourceReport($bad),'removed business dates cannot fall back to calendar');
$profile=['business_name'=>'Synthetic <business>','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'asset_tax_values_reviewed'=>false,'tax_privileged'=>true,
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$map=['cash'=>'bs.ass.currAss.cashEquiv.bank','receivables'=>'bs.ass.currAss.receiv.trade.other','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'];
$review=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
$f->save($id,1,$profile,$map,$review);$f->appendAdjustment($id,2,'current_adjustments','Synthetic tax difference','receivables','expense',501);
$rv=$tax->get($id)['form_data']['balance_preparation']['review'];unset($rv['year'],$rv['journal_sha256'],$rv['period']);$rv['complete']=true;
$f->save($id,3,$profile,$map,$rv);check($tax->validate($id,4)['valid'],'persisted dated business generates real XML');
file_put_contents(__DIR__.'/balance-period-workflow-business-first.xml',$tax->get($id)['xml_data']);
denied(fn()=>$f->approve($id,5,true),'treasurer cannot replace board approval');$fb->approve($id,5,true);
check($tax->send($id,6,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','simulated business receipt bound');$original=$tax->get($id);$originalAttempts=$tax->attempts($id);
require_once __DIR__.'/../src/Web/View.php';
$render=static function(string $fid,string $actor='finance',array $post=[])use($db):string{$_SESSION=['actor'=>$actor,'csrf'=>'synthetic'];$_GET=['tax_year'=>'2026','filing'=>$fid];$_POST=$post;$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_clean();};
$html=$render($id,'reader');check(str_contains($html,'01.07.2025 – 30.06.2026')&&str_contains($html,'Synthetic &lt;business&gt;')&&!str_contains($html,'Angaben speichern'),'reader sees correct escaped dates and business without actions');
$draft=$f->createPeriod($pid,'separate_business_ledger');$html=$render($draft,'finance',['action'=>'tax-filing-balance-save','filing'=>$draft,'version'=>'0','profile'=>['business_name'=>'<stale>']]);
check(str_contains($html,'&lt;stale&gt;')&&str_contains($html,'name="version" value="0"'),'stale business form retains input and original concurrency version');
$before=MySqlFixture::rows($db,'balance_period_events');$correct=$f->createPeriod($pid,'separate_business_ledger');$f->save($correct,1,$profile,$map,$review);
denied(fn()=>$tax->validate($correct,2),'duplicate business first filing rejected');
$f->revision($correct,2,['status'=>'B','reason'=>'Synthetic corrected tax valuation','legal_basis'=>'Synthetic checked correction','source_id'=>$id,'confirmed'=>true]);
$f->appendAdjustment($correct,3,'current_adjustments','Synthetic corrected difference','receivables','expense',701);
$rv=$tax->get($correct)['form_data']['balance_preparation']['review'];unset($rv['year'],$rv['journal_sha256'],$rv['period']);$rv['complete']=true;$f->save($correct,4,$profile,$map,$rv);
check($tax->validate($correct,5)['valid'],'business tax correction generates XML without changing original');$fb->approve($correct,6,true);$tax->send($correct,7,'dummy','123456','SEND-TEST',true,true);
file_put_contents(__DIR__.'/balance-period-workflow-business-correction.xml',$tax->get($correct)['xml_data']);
check(MySqlFixture::rows($db,'balance_period_events')===$before,'business tax changes do not rewrite original ledger');
$pid2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short successor',true);$r=$l->report($pid2);$l->close($pid2,$r['version'],$r['journal_sha256'],$all,'Synthetic successor close');
$next=$f->createPeriod($pid2,'separate_business_ledger');$nextB=$tax->get($next)['form_data']['balance_preparation'];
check(array_column($f->carryForwardChoices(2026,$nextB['period'],'single_taxable_business'),'id')===[$correct],'only latest adjacent business receipt offered');
check($f->carryForwardChoices(2026,$nextB['period'],'whole_taxable_club')===[],'whole-club carry options remain separate');
$html=$render($next);check(str_contains($html,'Vorherigen Abschluss übernehmen')&&!str_contains($html,'Test jetzt senden'),'missing prior uses guided carry rather than invented zero values');
denied(fn()=>$f->carryForward($next,1,$id,true),'superseded business tax values cannot seed successor');$f->carryForward($next,1,$correct,true);
$sourceProfile=$tax->get($correct)['form_data']['balance_preparation']['profile'];
$nextB=$tax->get($next)['form_data']['balance_preparation'];check($nextB['profile']['tax_privileged']===true&&$nextB['profile']['company']===$sourceProfile['company']&&!$nextB['review']['complete'],'saved prior identity including normalized fields copied once with new review required');
$rv=$nextB['review'];unset($rv['year'],$rv['journal_sha256'],$rv['period']);$rv['complete']=true;$f->save($next,2,$profile,$map,$rv);
$v=$f->assertCurrent($tax->get($next));check($v['accounts']['receivables']['tax_opening_cents']===701&&$v['tax']['annual_result_cents']===0,'tax correction carried as opening, not income again');
check((new R($db,'finance'))->choices($tax->get($next))===[],'same ending year is not the same filing obligation');
check($tax->validate($next,3)['valid'],'short business successor generates exact period XML');$fb->approve($next,4,true);$tax->send($next,5,'dummy','123456','SEND-TEST',true,true);
file_put_contents(__DIR__.'/balance-period-workflow-business-successor.xml',$tax->get($next)['xml_data']);
check($tax->get($id)===$original&&$tax->attempts($id)===$originalAttempts,'business originals approvals XML receipts preserved');
$oldHtml=$render($id,'reader');$r=$l->report($pid);$proof=['kind'=>'error','reason'=>'Synthetic missing original cost','legal_basis'=>'Synthetic checked correction','confirmed'=>true,'entries'=>[['id'=>md5('late-cost'),'date'=>'2026-06-30','description'=>'Synthetic late cost','evidence'=>'Synthetic original','lines'=>[$line('expense',10,0,'4'),$line('cash',0,10)]]]];
$l->prepareClosingCorrection($pid,$r['version'],$r['closed_sha256'],$proof);$r=$l->report($pid);$l->confirmClosingCorrection($pid,$r['version'],$r['closed_sha256'],$r['correction_draft']['proof']['sha256'],true,'Synthetic board confirmed correction');
check($oldHtml===$render($id,'reader'),'historical business figures survive original journal correction');
denied(fn()=>$f->assertCurrent($tax->get($next)),'changed predecessor blocks fresh sending of dependent business values');
$mixed=$l->report($pid);$proof['entries'][0]['id']=md5('exempt-cost');$proof['entries'][0]['lines'][0]['sphere']='1';
$l->prepareClosingCorrection($pid,$mixed['version'],$mixed['closed_sha256'],$proof);$mixed=$l->report($pid);$l->confirmClosingCorrection($pid,$mixed['version'],$mixed['closed_sha256'],$mixed['correction_draft']['proof']['sha256'],true,'Synthetic mixed source');
$snapshot=MySqlFixture::snapshot($db);denied(fn()=>$f->createPeriod($pid,'separate_business_ledger'),'multi-sphere journal cannot masquerade as single business');check($snapshot===MySqlFixture::snapshot($db),'rejected mixed source does not create draft');
putenv('VEREIN_MODE=production');denied(fn()=>$f->createPeriod($pid,'separate_business_ledger'),'production remains closed');putenv('VEREIN_MODE=synthetic');
check(MySqlFixture::foreignKeyErrors($db)===[],'business workflow keys intact');
echo "$checks dated business workflow checks passed; simulated transport only\n";
}
