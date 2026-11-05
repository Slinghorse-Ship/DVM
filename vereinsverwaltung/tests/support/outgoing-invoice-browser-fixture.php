<?php declare(strict_types=1);
use Kulturhus\Native\{TestSupport,Integration\ManagerBridge,Language\Catalog};
require_once __DIR__.'/free-list-interface-browser-fixture.php';
require_once __DIR__.'/outgoing-invoice-snapshots.php';
$oiConfig=TestSupport::config();if(($oiConfig['owner']??'')!=='isolated-native-ci'||($oiConfig['label']??'')!=='native-outgoing-invoice-interface')throw new RuntimeException('Owned complete invoice browser required.');
$oiCatalog=new Catalog($db,(new ManagerBridge($db,'own-list-admin','native',static fn()=>null))->access());$oiLocale=$oiCatalog->locale();
$oiBrowser=ownFreeListInterfaceBrowser($db,['account'=>$outgoingInvoiceFixture['account'],'history_id'=>$outgoingInvoiceHistory['id'],'history_first'=>$outgoingInvoiceHistory['first']['key']]);
register_shutdown_function(static function()use(&$oiBrowser):void{foreach($oiBrowser['servers']as[$process,$log]){if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);}});
$oiRoot=$oiBrowser['input']['root'];$oiBrowser['input']['app']=dirname(__DIR__,2);$oiBrowser['input']['php_command']=TestSupport::phpCommand();$oiBrowser['input']['config']=$oiConfig;$oiBrowser['input']['original_admin_locale']=$oiLocale;file_put_contents($oiRoot.'/fixture.json',json_encode($oiBrowser['input'],JSON_THROW_ON_ERROR));
$oiTables=array_values(array_diff($outgoingInvoiceFixture['tables'],['native_auth_sessions','native_auth_limits','native_language_preferences']));$oiOldRows=ownOutgoingInvoiceEarlierRows($db,$oiTables);$oiBefore=ownOriginalDatabaseHash($db,$oiTables,$oiOldRows);$oiCounts=[];
foreach(['outgoing_invoices','outgoing_invoice_history','native_settings_requests','finance_entries','finance_transactions','finance_allocations','documents','document_files','outgoing_invoice_deliveries','assigned_numbers','accounting_positions']as$table)$oiCounts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$oiPrivate=[];foreach(['auth','private']as$name)$oiPrivate[$name]=hash_file('sha256',$oiRoot.'/'.$name.'.key');
try{
    $process=proc_open(['node',dirname(__DIR__).'/outgoing-invoice-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process))throw new RuntimeException('Actual whole invoice browser unavailable.');fwrite($pipes[0],json_encode($oiBrowser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual whole invoice browser failed.');
}finally{foreach($oiBrowser['servers']as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}$oiBrowser['servers']=[];}
$oiResult=json_decode(file_get_contents($oiRoot.'/outgoing-invoice-actions.json'),true,64,JSON_THROW_ON_ERROR);$oiIssued=2*$oiResult['cases'];$oiAccepted=3*$oiIssued;
$check($oiCatalog->locale()===$oiLocale,'actual whole invoice browser restores the exact earlier personal interface language');
$check(ownOriginalDatabaseHash($db,$oiTables,$oiOldRows)===$oiBefore,'actual complete invoice browser preserves every whole earlier business row original document and private first');
foreach(['outgoing_invoices','finance_entries','documents','document_files','assigned_numbers','accounting_positions']as$table)$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$oiCounts[$table]+$oiIssued,'each deliberate browser issuance creates exactly its actual new original in '.$table);
foreach(['outgoing_invoice_history','native_settings_requests']as$table)$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$oiCounts[$table]+$oiAccepted,'each actual browser save review and issue appends precisely one whole result in '.$table);
foreach(['finance_transactions','finance_allocations','outgoing_invoice_deliveries']as$table)$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$oiCounts[$table],'actual invoice browser makes no payment allocation or delivery in '.$table);
foreach($oiResult['originals']as$original)$check($outgoingInvoiceFixture['requests']->receipt($original['key'])['original_json']===$original['json'],'actual earlier browser invoice first retains its exact whole JSON after later cases and formats');
foreach($outgoingInvoiceHistory['firsts']as$original)if($outgoingInvoiceFixture['requests']->receipt($original['key'])!==$original)throw new RuntimeException('An earlier complete invoice original changed.');$check(true,'all twenty-six genuinely earlier native draft firsts remain exact after every browser case');
foreach($oiPrivate as$name=>$digest)$check(hash_file('sha256',$oiRoot.'/'.$name.'.key')===$digest,'actual complete invoice browser retains its private '.$name.' key');
