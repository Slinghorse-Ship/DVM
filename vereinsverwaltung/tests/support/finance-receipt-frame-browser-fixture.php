<?php declare(strict_types=1);
require_once __DIR__.'/free-list-interface-browser-fixture.php';
if(getenv('DVM_FINANCE_CATALOGUE_INTERFACE_BROWSER')==='1')require __DIR__.'/finance-catalogue-interface-originals.php';
$profiles=[];foreach(['front','direct']as$app)foreach([false,true]as$js)foreach(['de','en']as$locale)$profiles[]=['tag'=>$app.'-'.($js?'js':'nojs').'-'.$locale,'app'=>$app,'js'=>$js,'locale'=>$locale];
$fixture=ownFreeListInterfaceBrowser($db,['profiles'=>$profiles,'entries'=>$entries,'descriptions'=>$descriptions,'catalogue'=>$catalogue??null]);
if(isset($catalogue))file_put_contents($fixture['input']['root'].'/catalogue-reference.json',json_encode($catalogue['byte_reference'],JSON_THROW_ON_ERROR));
$protected=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));
$before=ownOriginalDatabaseHash($db,$protected);$private=[];foreach(['auth','private']as$name)$private[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
$scripts=[];if(getenv('DVM_FINANCE_RECEIPT_BROWSER')==='1')$scripts[]='finance-receipt-frame-browser.mjs';if(getenv('DVM_FINANCE_CATALOGUE_INTERFACE_BROWSER')==='1')$scripts[]='finance-catalogue-interface-browser.mjs';
try{
 foreach($scripts as$script){$p=proc_open(['node',dirname(__DIR__).'/'.$script],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('Actual finance browser failed: '.$script);}
}finally{foreach($fixture['servers']as[$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
if(getenv('DVM_FINANCE_RECEIPT_BROWSER')==='1'){
 $results=json_decode(file_get_contents($fixture['input']['root'].'/finance-receipt-frame-results.json'),true,64,JSON_THROW_ON_ERROR);
 $check(count($results)===(getenv('DVM_FINANCE_RECEIPT_CASE')?1:8),'all selected complete receipt frame browser profiles finished');
}
if(getenv('DVM_FINANCE_CATALOGUE_INTERFACE_BROWSER')==='1'){
 $catBrowser=json_decode(file_get_contents($fixture['input']['root'].'/finance-catalogue-interface-results.json'),true,64,JSON_THROW_ON_ERROR);
 $check($catBrowser['complete_profiles']===8&&$catBrowser['business_posts']===0,'all eight real authenticated catalogue interfaces finish without a business POST');
}
$check(ownOriginalDatabaseHash($db,$protected)===$before,'actual receipt and catalogue reads retain every complete schema business row accounting position audit source and private financial first');
foreach($private as$name=>$hash)$check(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')===$hash,'actual receipt reads preserve the exact private '.$name.' key');
if(isset($catBrowser))echo json_encode(['suite'=>'finance-catalogue-interface-originals','protected_tables'=>count($protected),'whole_rows_columns_primary_keys_exact'=>true,'row_filters'=>false,'private_keys_exact'=>true,'business_posts'=>0,'full_ddl_trigger_comparison'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
