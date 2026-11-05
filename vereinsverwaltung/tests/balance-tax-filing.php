<?php declare(strict_types=1);
require_once __DIR__.'/fixtures/php-runtime.php';
ob_start();require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceLedger,BalanceTaxFiling,Database,SettingsSchema,TaxFilings};
use Kulturhus\Verein\Tests\MySqlFixture;
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$checks++;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Balance Club'),('b','Other Synthetic Club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('finance','a','treasurer',0),('board','a','treasurer',1),('member','a','member',0),('editor','a','editor',0),('other','b','treasurer',1)");
$dir=sys_get_temp_dir().'/kh-eric-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);$runtime=$dir.'/runtime.json';$catalog=$dir.'/catalog.json';
register_shutdown_function(static function()use($dir):void{foreach(['runtime.json','catalog.json'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);rmdir($dir);});
$p=['family'=>'balance','label'=>'Synthetic E-Bilanz','year'=>null,'test_only'=>true,'data_art'=>'Bilanz','verfahren'=>'ElsterBilanz','vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema}EBilanz','pdf_supported'=>false,'transport_enabled'=>true];
$cfg=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$catalog,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>['Bilanz_6_9'=>$p],'test_fixture_only'=>true,'balance_test_enabled'=>true,'production_enabled'=>true,'production_versions'=>['Bilanz_6_9']];
if(getenv('VEREIN_PYTHON'))$cfg['test_python']=getenv('VEREIN_PYTHON');
file_put_contents($catalog,json_encode(['version'=>1,'profiles'=>[]]));chmod($catalog,0600);file_put_contents($runtime,json_encode($cfg));chmod($runtime,0600);putenv('VEREIN_ERIC_RUNTIME='.$runtime);
$svc=new BalanceTaxFiling($db,'finance');$bs=new BalanceTaxFiling($db,'board');$tax=new TaxFilings($db,'finance');
denied(fn()=>$svc->create(2025),'operator flags required');putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');putenv('VEREIN_MODE=production');denied(fn()=>$svc->create(2025),'production runtime cannot create test filing');putenv('VEREIN_MODE=synthetic');
denied(fn()=>$svc->create(2025),'EÜR club blocked');$settings=SettingsSchema::defaults('tax');$settings['accounting_basis']='balance';$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
denied(fn()=>$svc->create(2025),'missing journal blocked');
$ledger=new BalanceLedger($db,'finance');$ledger->create(2025);denied(fn()=>$svc->create(2025),'unclosed journal blocked');
$chart=BalanceLedger::defaultChart();
$pair=static fn($d,$c,$n)=>[['account'=>$d,'debit_cents'=>$n,'credit_cents'=>0,'sphere'=>in_array($d,['revenue','expense'])?'4':''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>in_array($c,['revenue','expense'])?'4':'']];
$ledger->setup(2025,1,$chart,$pair('cash','equity',50000),'equity','Synthetic opening',true);
$ledger->post(2025,2,bin2hex(random_bytes(16)),'2025-01-02','Synthetic sale','Synthetic invoice',$pair('cash','revenue',10000));
$ledger->post(2025,3,bin2hex(random_bytes(16)),'2025-01-03','Synthetic cost','Synthetic invoice',$pair('expense','cash',2000));
(new BalanceLedger($db,'board'))->close(2025,4,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic accounting review');
$before=MySqlFixture::rows($db,'balance_years');$id=$svc->create(2025);
denied(fn()=>$svc->create(2024),'unsupported year blocked');denied(fn()=>(new BalanceTaxFiling($db,'member'))->create(2025),'member blocked');denied(fn()=>(new BalanceTaxFiling($db,'other'))->approve($id,1,true),'cross club blocked');
denied(fn()=>$tax->create('Bilanz_6_9','production',2025),'generic form factory cannot bypass generator');denied(fn()=>$tax->save($id,1,[],[]),'generic editing cannot bypass source binding');
check($tax->get($id)['form_data']['balance_preparation']['profile']['company']['name']==='','empty draft never copies real club data');
denied(fn()=>$tax->validate($id,1),'unreviewed tax values blocked');
$profile=['business_name'=>'Synthetischer Geschäftsbetrieb','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'tax_privileged'=>true,'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$review=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
$mapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'];
denied(fn()=>$svc->save($id,1,$profile,['cash'=>'bs.eqLiab.liab.trade'],$review),'mismatched account class blocked');
denied(fn()=>$svc->save($id,1,$profile,$mapping,$review+['unknown'=>true]),'unknown review inputs rejected');
$svc->save($id,1,$profile,[],$review);denied(fn()=>$tax->validate($id,2),'used unmapped equity/revenue cannot generate');
$svc->save($id,2,$profile,$mapping,$review);check($tax->validate($id,3)['valid'],'real generator reaches offline native validation boundary');
$r=$tax->get($id);check($r['state']==='validated'&&str_contains($r['xml_data'],'700000004')&&$r['form_data']['balance_preparation']['generated']['fact_count']>500,'exact generated test XML persisted');
denied(fn()=>$tax->ready($id,4,'SEND-TEST',true,true),'send without board approval blocked');denied(fn()=>$svc->approve($id,4,true),'treasurer cannot grant board approval');denied(fn()=>$bs->approve($id,4,false),'approval needs express review');
$bs->approve($id,4,true);check($tax->ready($id,5,'SEND-TEST',true,true)['id']===$id,'approved exact version ready');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");denied(fn()=>$tax->ready($id,5,'SEND-TEST',true,true),'revoked board authority invalidates stored approval');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$cfg['balance_test_enabled']=false;file_put_contents($runtime,json_encode($cfg));denied(fn()=>$tax->ready($id,5,'SEND-TEST',true,true),'runtime update invalidates approval');$cfg['balance_test_enabled']=true;file_put_contents($runtime,json_encode($cfg));
$svc->save($id,5,$profile,$mapping,$review);$r=$tax->get($id);check(!isset($r['form_data']['balance_preparation']['approval'])&&$r['xml_data']===null&&$r['state']==='draft','editing clears approval validation and XML');
denied(fn()=>$bs->approve($id,6,true),'draft cannot be approved');$tax->validate($id,6);$bs->approve($id,7,true);
$tax->validate($id,8);check(!isset($tax->get($id)['form_data']['balance_preparation']['approval']),'revalidation clears old board approval even with same XML');$bs->approve($id,9,true);
putenv('VEREIN_MODE=production');denied(fn()=>$tax->ready($id,10,'SEND-TEST',true,true),'test source blocked after switching runtime to production');putenv('VEREIN_MODE=synthetic');
$sent=(new \Kulturhus\Verein\TaxFilingEvidence($db,'finance'))->send($id,10,'dummy','123456','SEND-TEST',true,true);check($sent['state']==='sent_test','UI send wrapper stores confirmed test receipt');
check($tax->download($id,'receipt')['mime']==='application/xml','E-Bilanz receipt remains downloadable when ERiC produces no PDF');
check($tax->attempts($id)[0]['receipt_bound'],'receipt matches exact XML environment and ticket');
$savedAttempt=$db->query("SELECT id,response FROM tax_filing_attempts WHERE filing_id='$id'")->fetch();
foreach(['xml_sha256'=>'wrong','environment'=>'production','transfer_ticket'=>'wrong','sent'=>false,'code'=>99] as $key=>$value){
    $bad=json_decode($savedAttempt['response'],true);$bad[$key]=$value;
    $db->prepare('UPDATE tax_filing_attempts SET response=? WHERE id=?')->execute([json_encode($bad),$savedAttempt['id']]);
    check(!$tax->attempts($id)[0]['receipt_bound'],'mismatched receipt '.$key.' rejected');
}
$db->prepare('UPDATE tax_filing_attempts SET response=? WHERE id=?')->execute([$savedAttempt['response'],$savedAttempt['id']]);
$savedXml=$tax->get($id)['xml_data'];
$db->prepare('UPDATE tax_filings SET xml_data=? WHERE id=?')->execute(['<truncated/>',$id]);
check(!$tax->attempts($id)[0]['receipt_bound'],'damaged stored XML does not show confirmed receipt');
$db->prepare('UPDATE tax_filings SET xml_data=? WHERE id=?')->execute([$savedXml,$id]);
check(count($tax->attempts($id))===1,'exactly one send attempt');denied(fn()=>$tax->send($id,10,'dummy','123456','SEND-TEST',true,true),'repeat send blocked');denied(fn()=>$svc->save($id,12,$profile,$mapping,$review),'sent draft immutable');
$make=static function(string $name)use($svc,$bs,$tax,$profile,$mapping,$review):array{static $sequence=0;$id=$svc->create(2025);$p=$profile;$p['business_name']=$name;$p['company']['tax_number']=(string)(5192050001000+(++$sequence));$svc->save($id,1,$p,$mapping,$review);$tax->validate($id,2);$bs->approve($id,3,true);return [$id,4];};
$duplicate=$svc->create(2025);$svc->save($duplicate,1,$profile,$mapping,$review);denied(fn()=>$tax->validate($duplicate,2),'new draft cannot masquerade as another first filing');
$renamed=$profile;$renamed['business_name']='Synthetic renamed same business';$svc->save($duplicate,2,$renamed,$mapping,$review);denied(fn()=>$tax->validate($duplicate,3),'renaming same business cannot bypass prior filing');
[$copy,$ver]=$make('Synthetic separate scope');
[$uncertain,$ver]=$make('Synthetic uncertain');check($tax->send($uncertain,$ver,'crash-after-claim','123456','SEND-TEST',true,true)['state']==='unknown','missing transport response stays unknown');denied(fn()=>$tax->validate($uncertain,6),'unknown send cannot be revalidated or repeated');
[$failed,$ver]=$make('Synthetic PIN correction');check($tax->send($failed,$ver,'wrong-pin','123456','SEND-TEST',true,true)['state']==='failed','proven pretransport error stays failed');$tax->validate($failed,6);denied(fn()=>$tax->ready($failed,7,'SEND-TEST',true,true),'retry still needs renewed board approval');$bs->approve($failed,7,true);check($tax->send($failed,8,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','explicit revalidated approved retry succeeds');
check($before===MySqlFixture::rows($db,'balance_years'),'tax workflow leaves closed ledger unchanged');
[$adjusted,$ver]=$make('Synthetic adjusted');
denied(fn()=>$svc->appendAdjustment($adjusted,$ver,'opening_adjustments','Wrong opening profit','cash','expense',100),'opening correction cannot use P&L');
denied(fn()=>$svc->appendAdjustment($adjusted,$ver,'current_adjustments','Wrong amount','cash','equity',0),'zero adjustment blocked');
$svc->appendAdjustment($adjusted,$ver,'current_adjustments','Synthetic tax-value difference','cash','expense',100);
$changed=$tax->get($adjusted);check(!$changed['form_data']['balance_preparation']['review']['complete']&&!isset($changed['form_data']['balance_preparation']['approval'])&&$changed['xml_data']===null,'adjustment clears completed review and approval');
denied(fn()=>$tax->validate($adjusted,5),'new adjustment needs renewed overall tax-value review');
$newReview=$changed['form_data']['balance_preparation']['review'];unset($newReview['year'],$newReview['journal_sha256']);$newReview['complete']=true;
$adjustProfile=$tax->get($adjusted)['form_data']['balance_preparation']['profile'];unset($adjustProfile['kind'],$adjustProfile['source_scope'],$adjustProfile['asset_scope']);$svc->save($adjusted,5,$adjustProfile,$mapping,$newReview);check($tax->validate($adjusted,6)['valid'],'reviewed adjustment reaches generator');
check(count($tax->get($adjusted)['form_data']['balance_preparation']['review']['current_adjustments'])===1,'review retains adjustment details');
check($before===MySqlFixture::rows($db,'balance_years'),'tax adjustment does not alter commercial ledger');
denied(fn()=>$svc->save($adjusted,5,$profile,$mapping,$review),'stale review cannot erase a later adjustment');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')!=='1'){
    $restored=MySqlFixture::restoreBackup($db);foreach(['tax_filings','tax_filing_attempts'] as $table)check(MySqlFixture::rows($db,$table)===MySqlFixture::rows($restored,$table),'restore preserves '.$table.' XML approvals and receipts');
}else echo "SKIP optional backup roundtrip: dedicated synthetic database\n";
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic-csrf');}function url(array $v):string{return '?'.http_build_query($v);}
require_once __DIR__.'/support/complete-balance-form.php';
$render=static function(string $id,string $actor,array $post=[],bool $review=false,string $specialist='')use($db):string{
 $_GET=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>'2025','filing'=>$id];$_POST=$post;$_SESSION=['actor'=>$actor,'csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';
 if($specialist!=='')$_GET['balance_local_details']='1';
 $balanceLocalHandled=(bool)$post;$balanceLocalRead=false;$balanceLocalOutcome=null;$balanceLocalState=null;$balanceLocalError='';
 if($post)try{$balanceLocalState=\Kulturhus\Verein\Web\BalanceFilingForm::handle($db,$actor,$_GET,$post,'synthetic-csrf',$_SESSION);}catch(DomainException $error){$balanceLocalError=$error->getMessage();}
 ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';$html=ob_get_clean();
 if($specialist!==''){
  $dom=new DOMDocument();$oldErrors=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$xp=new DOMXPath($dom);$link=$xp->query('//article[h3="'.$specialist.'"]/a[@data-balance-specialist-open]')->item(0);if(!$link)throw new RuntimeException('Actual offered specialist task required');parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$route);$_GET=$route;}finally{libxml_clear_errors();libxml_use_internal_errors($oldErrors);}
  ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';$html=ob_get_clean();
 }
 if($review){
  $complete=ownCompleteBalanceFormPost($html,'balance-local-send-review');
  $balanceLocalState=\Kulturhus\Verein\Web\BalanceFilingForm::handle($db,$actor,$_GET,$complete,'synthetic-csrf',$_SESSION);
  if(($balanceLocalState['send_review']??false)!==true)throw new RuntimeException('Actual complete source-bound approval transition required');
  $_POST=$complete;$_SERVER['REQUEST_METHOD']='POST';$balanceLocalHandled=true;ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';$html=ob_get_clean();
 }
 return $html;
};
$initial=$render($copy,'finance');check(str_contains($initial,'balance-local-send-review')&&!str_contains($initial,'name="certificate_pin"'),'approved initial view requires its separate complete approval transition');
$html=$render($copy,'finance',[],true);check(str_contains($html,'Test jetzt senden')&&str_contains($html,'synthetic-csrf'),'approved view offers protected explicit send');
$html=$render($id,'finance');check(str_contains($html,'Übermittlungsprotokoll')&&!str_contains($html,'name="certificate_pin"'),'sent view hides certificate and new send');
check(str_contains($html,'Technischer Eingang bestätigt.')&&str_contains($html,'keine spätere E-Bilanz-Rückmeldung'),'sent SGEB view separates technical receipt and inapplicable later returns');
$html=$render($uncertain,'finance');check(str_contains($html,'Übermittlung nicht erneut starten')&&!str_contains($html,'name="certificate_pin"'),'uncertain view blocks blind retry');
[$waiting,$ver]=$make('Synthetic awaiting approval');$tax->validate($waiting,$ver);
$html=$render($waiting,'finance');check(str_contains($html,'Vorstand muss')&&!str_contains($html,'Test jetzt senden'),'treasurer cannot send unapproved validated filing');
$html=$render($waiting,'board');check(str_contains($html,'Entwurf für den Test freigeben')&&!str_contains($html,'Test jetzt senden'),'board sees separate exact-draft approval before send');
$output=getenv('VEREIN_BALANCE_UI_OUTPUT');
if($output){
    if(!is_dir($output)||!str_starts_with(realpath($output),'/workspace/.local/'))throw new RuntimeException('Private UI output required.');
    foreach(['balance-tax-approve'=>[$waiting,'board'],'balance-tax-approved'=>[$copy,'finance'],'balance-tax-sent'=>[$id,'finance'],'balance-tax-unknown'=>[$uncertain,'finance']] as $name=>[$viewId,$actor]){
        $css=file_get_contents(__DIR__.'/../public/style.css').file_get_contents(__DIR__.'/../public/tax-filings.css').file_get_contents(__DIR__.'/../public/tax-guided.css');
        file_put_contents($output.'/'.$name.'.html','<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-Bilanz TEST</title><style>'.$css.'</style><main style="margin:0;padding:16px">'.$render($viewId,$actor).'</main></html>');
    }
}
echo "$checks balance tax filing checks passed; actual generator, offline transport fixture only\n";
