<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Finance,Settings,Inventory,Documents};

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('writer','a','treasurer',0),('reader','a','member',0),('admin','a','member',1)");
$settings=new Settings($db,'admin');
$role=$settings->saveRole('',0,['name'=>'Synthetischer Finanzleser','finance_access'=>'read','members_access'=>'none']);$settings->assignRole('reader',$role,0);
$writer=new Finance($db,'writer');$reader=new Finance($db,'reader');
$account=$writer->createAccount('Synthetische Bank','offline','0','2026-01-01');
$entry=$writer->createEntry(['kind'=>'income','description'=>'Synthetischer Beleg','amount'=>'60','category'=>'general','document_date'=>'2026-08-01','due_date'=>'2026-08-01'],bin2hex(random_bytes(16)));
$transaction=$writer->createTransaction(['account'=>$account,'booked_on'=>'2026-08-01','direction'=>'income','amount'=>'100','description'=>'Synthetischer Umsatz','counterparty'=>'Synthetischer Kontakt'],bin2hex(random_bytes(16)));
$cash=$writer->createAccount('Synthetische Kasse','cash','0','2026-01-01');
function renderFinance(Finance $finance,string $actor,string $template,array $query=[],array $extra=[],array $post=[]):string{
    global $db; // Match the dispatcher context used by linked contribution tariff views.
    $_GET=$query;$_POST=$post;$_SESSION=['actor'=>$actor,'csrf'=>str_repeat('a',64)];http_response_code(200);
    $canFinance=true;$view=$query['view']??'finance';$tab=$query['tab']??'bank';$views=['finance'=>'Finanzen','donations'=>'Spenden'];
    $enableEnvironment='PRODUCTION';$enableError='';$enableStatus=null;$enableBanks=[];$enableConnections=[];$enableData=null;
    $financeEntry=null;$accountRows=$finance->accounts();$goodsRows=$finance->goods();
    extract($extra,EXTR_SKIP);
    ob_start();try{require \Kulturhus\Verein\Web\Templates::path($template);return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}
}
foreach(['bank','goods'] as $tab){
    $query=['view'=>$tab==='goods'?'donations':'finance','tab'=>$tab];
    $html=renderFinance($reader,'reader','finance',$query);
    check(str_contains($html,'data-finance-readonly'),'reader sees scope notice: '.$tab);
    check(!str_contains($html,'new_account')&&!str_contains($html,'new_transaction')&&!str_contains($html,'new_goods'),'reader has no creation links: '.$tab);
    check(!str_contains($html,'method="post"'),'reader overview contains no mutation forms: '.$tab);
    $writable=renderFinance($writer,'writer','finance',$query);
    check(str_contains($writable,$tab==='bank'?'new_account':'new_goods'),'writer retains creation links: '.$tab);
}
foreach(['new_entry','new_account','new_transaction','new_goods','edit_positions'] as $action){
    $html=renderFinance($reader,'reader','finance',[$action=>'1']);
    check(http_response_code()===403&&!str_contains($html,'<form'),'direct write screen denied: '.$action);
}
foreach(['create-entry','create-account','create-transaction','create-goods','update-positions'] as $action){
    $html=renderFinance($reader,'reader','finance',[],[],['action'=>$action]);
    check(http_response_code()===403&&!str_contains($html,'<form'),'rejected post does not redisplay a write form: '.$action);
}
$writerForm=renderFinance($writer,'writer','finance',['tab'=>'bank','new_account'=>'1']);
check(str_contains($writerForm,'Konto speichern'),'writer retains usable account creation form');
check(str_contains($writerForm,'account-kind-option')&&str_contains($writerForm,'Grundangaben'),'account creation uses the guided two-step layout');
check(!str_contains($writerForm,'Weitere Handkasse / Konto'),'account list has no duplicate creation link');
$staleFintsForm=renderFinance($writer,'writer','finance',['tab'=>'bank','new_account'=>'1','fints'=>'1']);
check(str_contains($staleFintsForm,'Onlinekonto')&&str_contains($staleFintsForm,'offline-online')&&!str_contains($staleFintsForm,'fints-login-form')&&!str_contains($staleFintsForm,'fints-schedule'),'account choice stays separate from FinTS wizard');
foreach([[$reader,'reader',false],[$writer,'writer',true]] as [$finance,$actor,$write]){
    $html=renderFinance($finance,$actor,'finance-overview',[],['overview'=>$finance->overview('2026-01-01','2026-12-31'),'period'=>'12','reportStart'=>'2026-01-01','reportEnd'=>'2026-12-31']);
    check(str_contains($html,'new_account')===$write,'dashboard account creation follows role: '.$actor);
    check(str_contains($html,'new_entry')===$write,'dashboard receipt creation follows role: '.$actor);
    $html=renderFinance($finance,$actor,'finance',['tab'=>'bank','account'=>$cash],['inventory'=>new Inventory($db,$actor),'documents'=>new Documents($db,$actor)]);
    check(str_contains($html,'Kassenbuch als CSV'),'reader and writer retain cashbook export: '.$actor);
    check(str_contains($html,'name="action" value="close-cashbook"')===$write,'cash closure follows role: '.$actor);
    $html=renderFinance($finance,$actor,'transaction',['transaction'=>$transaction]);
    check(str_contains($html,'Synthetischer Umsatz'),'transaction remains readable: '.$actor);
    check(str_contains($html,'name="action" value="allocate"')===$write,'transaction assignment follows role: '.$actor);
    $html=renderFinance($finance,$actor,'entry-payments',['manual_payment'=>'1'],['r'=>$finance->entry($entry)]);
    check(str_contains($html,'Belegbetrag'),'payment totals remain readable: '.$actor);
    check(str_contains($html,'name="action" value="record-payment"')===$write,'new payment follows role: '.$actor);
    check(str_contains($html,'name="action" value="cancel-entry"')===$write,'discard follows role: '.$actor);
    $html=renderFinance($finance,$actor,'positions-detail',[],['positionType'=>'entry','positionId'=>$entry,'positionEditable'=>true,'positionRoute'=>['entry'=>$entry]]);
    $positionDocument=new DOMDocument();$previousXmlErrors=libxml_use_internal_errors(true);try{$positionDocument->loadHTML('<?xml encoding="UTF-8">'.$html);}finally{libxml_clear_errors();libxml_use_internal_errors($previousXmlErrors);}$positionXPath=new DOMXPath($positionDocument);
    check($positionXPath->evaluate('string(//h2)')==='Positionen & Kontierung','positions remain readable: '.$actor);
    check((int)$positionXPath->evaluate('count(//a[contains(@href,"edit_positions=1")])')===($write?1:0),'position editing follows role: '.$actor);
}
$writer->allocate($transaction,1,$entry,1,'20',bin2hex(random_bytes(16)));
foreach([[$reader,'reader',false],[$writer,'writer',true]] as [$finance,$actor,$write]){
    $html=renderFinance($finance,$actor,'allocation-history',[],['r'=>$finance->entry($entry),'allocationRows'=>$finance->allocations($entry),'allocationReturn'=>'entry']);
    check(str_contains($html,'Synthetischer Beleg'),'allocation history remains readable: '.$actor);
    check(str_contains($html,'name="action" value="release-allocation"')===$write,'releasing an assignment follows role: '.$actor);
}
$filtered=renderFinance($reader,'reader','bank',['tab'=>'bank','bank_q'=>'No matching synthetic payment']);
check(str_contains($filtered,'Keine Umsätze für diese Filter gefunden.')&&str_contains($filtered,'Filter zurücksetzen'),'empty search explains filters and offers reset');
$invalid=renderFinance($reader,'reader','bank',['tab'=>'bank','assignment'=>'invalid']);
check(str_contains($invalid,'role="alert"')&&str_contains($invalid,'Zurück zu allen Konten und Umsätzen'),'invalid filter offers a valid recovery route');
check(str_contains($writerForm,'>Abbrechen</a>'),'account creation has a non-submitting cancel link');
restore_error_handler();
echo "$checks finance read-only view checks passed\n";
