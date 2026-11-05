<?php declare(strict_types=1);
require __DIR__.'/free-list-interface-browser-fixture.php';
$vbFixture=ownFreeListInterfaceBrowser($db,['person'=>$vfOwnPerson,'other'=>$vfOtherPerson,'old_document'=>$vfDocument,'old_key'=>$vfKey,'old_sha256'=>hash('sha256',$vfPreview),'input'=>$vfInput]);
$vbRoot=$vbFixture['input']['root'];
$vbProtected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));
$vbNewDocuments=[];
$vbFilter=static function(string $table)use(&$vbNewDocuments):array{
 if(!$vbNewDocuments)return['',[]];
 $marks=implode(',',array_fill(0,count($vbNewDocuments),'?'));
 if(in_array($table,['documents','document_files','document_links','document_history','document_requests','portal_document_shares'],true))return[' WHERE '.($table==='documents'?'id':'document_id').' NOT IN ('.$marks.')',$vbNewDocuments];
 if($table==='manager_notifications')return[" WHERE NOT(topic='documents' AND source_version=1 AND source_id IN (".$marks.'))',$vbNewDocuments];
 if($table==='audit_events')return[" WHERE NOT(object_version=1 AND action IN ('document.generated.volunteer-form','portal.document.shared') AND object_id IN (".$marks.'))',$vbNewDocuments];
 return['',[]];
};
$vbBefore=ownOriginalDatabaseHash($db,$vbProtected,$vbFilter);
$vbCounts=[];foreach($vbProtected as$vbTable)$vbCounts[$vbTable]=(int)$db->query('SELECT COUNT(*) FROM '.chr(96).$vbTable.chr(96))->fetchColumn();
$vbKeys=[];foreach(['auth','private']as$vbKey)$vbKeys[$vbKey]=hash_file('sha256',$vbRoot.'/'.$vbKey.'.key');
try{
 $vbProcess=proc_open(['node',dirname(__DIR__).'/volunteer-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$vbPipes);
 fwrite($vbPipes[0],json_encode($vbFixture['input'],JSON_THROW_ON_ERROR));fclose($vbPipes[0]);
 if(proc_close($vbProcess)!==0)throw new RuntimeException('Actual personal certificate browser failed.');
}finally{foreach($vbFixture['servers']as[$vbProcess,$vbLog]){proc_terminate($vbProcess);proc_close($vbProcess);fclose($vbLog);}}
$vbResults=json_decode(file_get_contents($vbRoot.'/volunteer-interface-results.json'),true,64,JSON_THROW_ON_ERROR);
$check(count($vbResults)===8,'eight complete personal certificate variants produce their deliberate reviewed originals');
$vbNewDocuments=array_column($vbResults,'document');
$check(count(array_unique($vbNewDocuments))===8,'each complete native personal certificate flow creates one distinct consciously reviewed document');
$check(ownOriginalDatabaseHash($db,$vbProtected,$vbFilter)===$vbBefore,'every complete earlier business row private source original file and unrelated audit remains exact outside eight deliberate certificates');
foreach($vbResults as$vbResult){
 $vbOriginal=$vfService->confirmation($vbResult['key']);
 $check($vbOriginal['document_id']===$vbResult['document']&&$vbOriginal['sha256']===$vbResult['preview_sha256']&&$vbOriginal['sha256']===$vbResult['download_sha256'],'actual reviewed preview downloaded original and durable original confirmation have the exact same PDF fingerprint');
 $check($vbOriginal['intent']['input']['activity']===$vbResult['activity']&&$vbOriginal['intent']['input']['effort']==='0','actual native certificate retains every consciously entered original activity character and human zero');
}
foreach($vbCounts as$vbTable=>$vbCount){
 $vbIncrease=match($vbTable){'documents','document_files','document_links','document_history','document_requests','portal_document_shares','manager_notifications'=>8,'audit_events'=>16,default=>0};
 $check((int)$db->query('SELECT COUNT(*) FROM '.chr(96).$vbTable.chr(96))->fetchColumn()===$vbCount+$vbIncrease,'only deliberately confirmed personal certificate rows are added: '.$vbTable);
}
foreach($vbKeys as$vbKey=>$vbHash)$check(hash_file('sha256',$vbRoot.'/'.$vbKey.'.key')===$vbHash,'actual certificate flow preserves the original '.$vbKey.' key');
