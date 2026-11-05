<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
ob_start();
require_once __DIR__.'/fixtures/php-runtime.php';
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-club-tax-reconciliation.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxFiling as F,TaxFilings,BalanceTaxReconciliations as T,BalanceClearings as W,BalanceSegments as S,BalanceLedger as L,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Subsidiary Club'),('b','Synthetic Other Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('other','b','treasurer',1),('reader','a','member',0)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','read','Synthetic reader','','none','read',0,1); INSERT INTO settings_role_assignments VALUES('reader','a','read',1)");
$settings=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance']);$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
// The fixture executes the REAL Python generator but simulates ERiC transport.
// Native ERiC checks are a separate validation-only test, never an actual send.
$dir=sys_get_temp_dir().'/kh-eric-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);$runtime=$dir.'/runtime.json';$catalog=$dir.'/catalog.json';
register_shutdown_function(static function()use($dir):void{foreach(['runtime.json','catalog.json'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);rmdir($dir);});
$p=['family'=>'balance','label'=>'Synthetic E-Bilanz','year'=>null,'test_only'=>true,'data_art'=>'Bilanz','verfahren'=>'ElsterBilanz','vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema}EBilanz','pdf_supported'=>false,'transport_enabled'=>true];
$cfg=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$catalog,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>['Bilanz_6_9'=>$p],'test_fixture_only'=>true,'balance_test_enabled'=>true,'production_enabled'=>true,'production_versions'=>['Bilanz_6_9']];
if(getenv('VEREIN_PYTHON'))$cfg['test_python']=getenv('VEREIN_PYTHON');
file_put_contents($catalog,json_encode(['version'=>1,'profiles'=>[]]));chmod($catalog,0600);file_put_contents($runtime,json_encode($cfg));chmod($runtime,0600);putenv('VEREIN_ERIC_RUNTIME='.$runtime);
[$r,$units,$alloc,$input]=clearingFixture();$ledger=new L($db,'board');$segments=new S($db,'finance');$cw=new W($db,'board');$t=new T($db,'finance');$tb=new T($db,'board');$f=new F($db,'finance');$fb=new F($db,'board');$filings=new TaxFilings($db,'finance');
denied(fn()=>$f->create(2025,F::SUBSIDIARY_SOURCE),'missing journal not promoted');
$ledger->create(2025);$ledger->setup(2025,1,$r['accounts'],$r['opening'],'equity','Synthetic opening',true);$version=2;
foreach($r['entries'] as $entry)$ledger->post(2025,$version++,$entry['id'],$entry['date'],$entry['description'],'Synthetic evidence',$entry['lines']);
$ledger->close(2025,$version,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic source closed');$segments->save(2025,0,$units,$alloc);
$cw->save(2025,0,1,$input,true,'Synthetic advances');$cw->confirm(2025,1,1,true,'Synthetic board source review');$income=$cw->incomeReconciliation(2025);$review=taxIncomeReview($income);
denied(fn()=>$f->create(2025,F::SUBSIDIARY_SOURCE),'source approval alone is not tax review');
$review['current_adjustments']=[['id'=>'tax-value','reason'=>'Synthetic evidenced value','lines'=>[['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]]];
$t->save(2025,0,$review,'Synthetic valuation');denied(fn()=>$f->create(2025,F::SUBSIDIARY_SOURCE),'draft tax values cannot create filing');$tb->confirm(2025,1,$income['sha256'],true,'Synthetic reviewed computation');
foreach(['member','reader','other'] as $actor)denied(fn()=>(new F($db,$actor))->create(2025,F::SUBSIDIARY_SOURCE),'create authorization '.$actor);
foreach(['separate_business_ledger','approved_club_allocation',F::COMBINED_SOURCE] as $scope)denied(fn()=>$f->create(2025,$scope),'partial profile keeps advance guard '.$scope);
// A separate whole, non-privileged draft retains the original journal. Neither
// the clearing approval nor the subsidiary tax review may approve that draft.
$wholeId=$f->create(2025,'complete_club_ledger');$whole=$filings->get($wholeId);$wholeBinding=$whole['form_data']['balance_preparation'];
check($whole['state']==='draft'&&$whole['xml_data']===null&&!isset($wholeBinding['approval'])&&!$wholeBinding['review']['complete'],'subsidiary review does not approve a whole draft');
check($wholeBinding['profile']['tax_privileged']===false&&!$wholeBinding['profile']['scope_confirmed'],'whole non-privileged scope is not silently confirmed');
check($f->sourceReport($whole)['calculation']===$ledger->report(2025)['calculation'],'whole draft retains the journal without subsidiary tax adjustments');
denied(fn()=>$filings->ready($wholeId,1,'SEND-TEST',true,true),'subsidiary approval cannot make whole draft ready');
$wrongWhole=$whole;$wrongWhole['form_data']['balance_preparation']['profile']['tax_privileged']=true;
denied(fn()=>$f->sourceReport($wrongWhole),'whole profile cannot bypass privileged subsidiary reconciliation');
$sourceBefore=MySqlFixture::rows($db,'balance_years');$id=$f->create(2025,F::SUBSIDIARY_SOURCE);$filing=$filings->get($id);
check($filing['form_data']['balance_preparation']['source_binding']['revision']===2,'exact tax review revision bound');
check($f->previewValues($filing)['subsidiary']['result']['taxable_income_cents']===1507,'draft preview derives all taxable lines');
denied(fn()=>$f->assertCurrent($filing),'draft requires final value confirmation');
denied(fn()=>(new F($db,'other'))->previewValues($filing),'cross-club preview blocked');
$bad=$filing;$bad['form_data']['balance_preparation']['source_binding']['revision']=4;denied(fn()=>$f->previewValues($bad),'forged review binding rejected');
$profile=['business_name'=>'','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'asset_tax_values_reviewed'=>false,'tax_privileged'=>true,'whole_business_name'=>'Synthetic <activity>','whole_book_standard'=>'HGBM',
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$mapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1','receivables'=>'bs.ass.currAss.receiv.trade.other'];
denied(fn()=>$f->save($id,1,$profile,$mapping,['complete'=>true,'current_adjustments'=>[]]),'no duplicate editable tax ledger');
denied(fn()=>$f->save($id,1,$profile,$mapping,['complete'=>true],[]),'no unrelated second mapping');
$bad=$profile;$bad['tax_privileged']=false;denied(fn()=>$f->save($id,1,$bad,$mapping,['complete'=>true]),'taxable whole promotion blocked');
$f->save($id,1,$profile,$mapping,['complete'=>true]);$values=$f->assertCurrent($filings->get($id));check($values['whole']['book']===$values['whole']['tax'],'tax corrections never change whole book presentation');
denied(fn()=>$f->appendAdjustment($id,2,'current_adjustments','No duplicate','cash','expense',1),'shortcut tax change rejected');
denied(fn()=>$f->assetTaxPlan($id,2,'none',null),'shortcut asset change rejected');
denied(fn()=>$f->carryForward($id,2,'none',true),'second independent carry rejected');
check($filings->validate($id,2)['valid'],'real subsidiary generator reaches offline native boundary');
$filing=$filings->get($id);check($filing['form_data']['balance_preparation']['generated']['subsidiary_result_cents']===1507&&isset($filing['form_data']['balance_preparation']['generated']['sections']['subsidiary']),'generated values and evidence bound');
denied(fn()=>$f->approve($id,3,true),'treasurer cannot replace board');denied(fn()=>$filings->ready($id,3,'SEND-TEST',true,true),'send needs explicit filing approval');$fb->approve($id,3,true);
check($filings->ready($id,4,'SEND-TEST',true,true)['id']===$id,'reviewed exact filing ready for simulated test');
function input(array $a,string $k,string $d=''):string{return is_string($a[$k]??null)?$a[$k]:$d;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
require_once __DIR__.'/support/complete-balance-form.php';
$render=static function(string $id,string $actor,array $post=[],bool $review=false)use($db):string{
 $_GET=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>'2025','filing'=>$id];$_POST=$post;$_SESSION=['actor'=>$actor,'csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';
 $balanceLocalHandled=(bool)$post;$balanceLocalRead=false;$balanceLocalOutcome=null;$balanceLocalState=null;$balanceLocalError='';
 if($post)try{$balanceLocalState=\Kulturhus\Verein\Web\BalanceFilingForm::handle($db,$actor,$_GET,$post,'synthetic',$_SESSION);}catch(DomainException $error){$balanceLocalError=$error->getMessage();}
 ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';$html=ob_get_clean();
 if($review){
  $complete=ownCompleteBalanceFormPost($html,'balance-local-send-review');
  $balanceLocalState=\Kulturhus\Verein\Web\BalanceFilingForm::handle($db,$actor,$_GET,$complete,'synthetic',$_SESSION);
  if(($balanceLocalState['send_review']??false)!==true)throw new RuntimeException('Actual complete source-bound approval transition required');
  $_POST=$complete;$_SERVER['REQUEST_METHOD']='POST';$balanceLocalHandled=true;ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';$html=ob_get_clean();
 }
 return $html;
};
$initial=$render($id,'board');check(str_contains($initial,'balance-local-send-review')&&!str_contains($initial,'name="certificate_pin"'),'approved subsidiary initial view requires complete approval transition');
$html=$render($id,'board',[],true);check(str_contains($html,'Gesamtverein und steuerliches Ergebnis')&&str_contains($html,'15,07 €')&&str_contains($html,'Test jetzt senden'),'approved UI shows result and explicit send');
check(str_contains($html,'&lt;activity&gt;')&&!str_contains($html,'<activity>')&&!str_contains($html,'tax-filing-balance-adjust'),'UI escapes inputs and no second valuation editor');
check(str_contains($html,'Summe Aktiva')&&str_contains($html,'Gewinn- und Verlustrechnung')&&str_contains($html,'Synthetic evidenced value'),'review includes complete original figures and tax correction evidence');
$html=$render($id,'reader');check(!str_contains($html,'Test jetzt senden')&&!str_contains($html,'Angaben speichern'),'read-only view has no write/send buttons');
$html=$render($id,'finance',['action'=>'tax-filing-balance-save','filing'=>$id,'version'=>'1','profile'=>['whole_business_name'=>'<keep draft>']]);
check(str_contains($html,'&lt;keep draft&gt;')&&str_contains($html,'&quot;version&quot;: &quot;1&quot;')&&str_contains($html,'data-balance-local-raw')&&!str_contains($html,'id="balance-local-main"'),'failed form keeps escaped values and stale version');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");denied(fn()=>$filings->ready($id,4,'SEND-TEST',true,true),'revoked source reviewer invalidates filing');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
putenv('VEREIN_MODE=production');denied(fn()=>$filings->ready($id,4,'SEND-TEST',true,true),'production cannot use synthetic filing path');putenv('VEREIN_MODE=synthetic');
$setting=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$setting->execute([json_encode(array_replace($settings,['accounting_basis'=>'euer']))]);denied(fn()=>$f->previewValues($filings->get($id)),'EÜR stays outside subsidiary filing');$setting->execute([json_encode($settings)]);
check($filings->send($id,4,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','simulated transport stores exact receipt without network');check($filings->attempts($id)[0]['receipt_bound'],'simulated receipt matches content');
denied(fn()=>$filings->send($id,4,'dummy','123456','SEND-TEST',true,true),'duplicate send refused');
$html=$render($id,'finance');check(str_contains($html,'Übermittlungsprotokoll öffnen')&&!str_contains($html,'Test jetzt senden'),'sent UI only offers receipt');
$copy=$f->create(2025,F::SUBSIDIARY_SOURCE);$f->save($copy,1,$profile,$mapping,['complete'=>true]);
denied(fn()=>$filings->validate($copy,2),'new draft cannot duplicate same transmitted content');
// The next year uses the reviewed computation carry, not an extra entry form.
$ledger->create(2026);[$next,$nu,$na,$ni]=clearingNext($cw->get(2025)['proof'],234);$entry=$next['entries'][0];$ledger->post(2026,1,$entry['id'],$entry['date'],$entry['description'],'Synthetic repayment',$entry['lines']);
$ledger->close(2026,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic next year closed');$segments->carry(2026,0,true);$segments->save(2026,1,$units,$segments->get(2026)['allocation']['allocations']+$na);
$cw->save(2026,0,2,$ni,true,'Synthetic next year source');$cw->confirm(2026,1,2,true,'Synthetic next year reviewed');
$incomeNext=$cw->incomeReconciliation(2026);$nr=taxIncomeReview($incomeNext);$nr['opening_adjustments']=$t->carry(2026)['opening_adjustments'];$t->save(2026,0,$nr,'Synthetic tax carry');$tb->confirm(2026,1,$incomeNext['sha256'],true,'Synthetic reviewed carry');
$nid=$f->create(2026,F::SUBSIDIARY_SOURCE);$f->save($nid,1,$profile,$mapping,['complete'=>true]);$nv=$f->assertCurrent($filings->get($nid));check($nv['subsidiary']['result']['taxable_income_cents']===0&&$nv['subsidiary']['valuation']['accounts']['receivables']['tax_opening_cents']===501,'prior difference carried without current income');
check($filings->validate($nid,2)['valid'],'real generator independently checks two-year carry');$fb->approve($nid,3,true);
$review['current_adjustments'][0]['reason']='Synthetic changed evidence';$t->save(2025,2,$review,'New source draft');
denied(fn()=>$f->assertCurrent($filings->get($copy)),'changed current source invalidates existing validated copy');denied(fn()=>$filings->ready($nid,4,'SEND-TEST',true,true),'changed prior source invalidates next-year send');
$tb->confirm(2025,3,$income['sha256'],true,'Synthetic new review');denied(fn()=>$f->assertCurrent($filings->get($copy)),'same values new review never revives old approval');
check($filings->download($id,'receipt')['mime']==='application/xml','historic receipt remains readable after source changes');
check(array_slice(MySqlFixture::rows($db,'balance_years'),0,count($sourceBefore))===$sourceBefore,'original journal unchanged');
check(!MySqlFixture::foreignKeyErrors($db),'foreign key boundaries intact');
echo "$checks subsidiary filing checks passed; isolated synthetic MySQL and simulated transport only\n";
}
