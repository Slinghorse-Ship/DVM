<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{DocumentChecks,Registry};
$cbService=new DocumentChecks($db,'own-list-admin',new Registry($db,'own-list-admin'));$cbRegistry=new Registry($db,'own-list-admin');
$cbContacts=[];$cbEvents=[];$cbDocuments=[];
for($cbIndex=0;$cbIndex<18;$cbIndex++){
 $cbContacts[]=$cbRegistry->saveContact(null,0,'person','Own choice person '.sprintf('%02d',$cbIndex).' <em data-own-choice-person>0</em> ä🙂','choice-'.$cbIndex.'@example.invalid','Own address');
 $cbStart=(new DateTimeImmutable('2040-01-01'))->modify('+'.$cbIndex.' days')->format('Y-m-d');
 $cbEvents[]=$cbRegistry->saveAppointment(null,0,['title'=>'Own choice event '.sprintf('%02d',$cbIndex).' <em data-own-choice-event>0</em> ä🙂','start'=>$cbStart.'T18:00','end'=>$cbStart.'T20:00','location'=>'Own exact source venue','description'=>'Own original calendar description','category'=>'verein','activity_kind'=>'event'],[]);
 $cbDocuments[]=$docs->upload('Own choice original '.$cbIndex.'.pdf',$ccUploadBytes,['title'=>'Own choice file '.sprintf('%02d',$cbIndex).' <em data-own-choice-file>0</em> ä🙂','kind'=>'other','scope'=>'orga','document_date'=>'2026-10-19','notes'=>'Own immutable original source','links'=>[]],bin2hex(random_bytes(16)));
}
$cbTargets=[];
for($cbIndex=0;$cbIndex<28;$cbIndex++){
 $cbService->createOnce(array_replace(DocumentChecks::defaults(),['title'=>'Own earlier creation receipt '.sprintf('%02d',$cbIndex),'notes'=>'Own protected first creation']),bin2hex(random_bytes(16)));
}
for($cbIndex=0;$cbIndex<8;$cbIndex++){
 $cbInput=array_replace(DocumentChecks::defaults(),['title'=>$cbIndex===0?'Nachweis erfassen':'Own stored review '.$cbIndex.' ä🙂','notes'=>"0\r\nOwn unchanged original notes ".$cbIndex." <em data-own-check-note>0</em> ä🙂",'responsible'=>'0','document_id'=>$id,'category'=>'insurance','review_on'=>'2099-12-01']);
 $cbTarget=$cbService->save('',0,$cbInput);if($cbIndex===0)Db::run($db,'UPDATE document_checks SET valid_until=? WHERE club_id=? AND id=?',['2026-02-30','own-list-club',$cbTarget]);
 $cbTargets[]=['id'=>$cbTarget,'original'=>$cbService->get($cbTarget)];
}
require __DIR__.'/free-list-interface-browser-fixture.php';
$cbFixture=ownFreeListInterfaceBrowser($db,[]);$cbRoot=$cbFixture['input']['root'];
$cbFixture['input']['data']=['targets'=>$cbTargets,'contacts'=>$cbContacts,'events'=>$cbEvents,'documents'=>$cbDocuments,'upload_base64'=>base64_encode($ccUploadBytes),'upload_sha256'=>hash('sha256',$ccUploadBytes),'original_file'=>$id,'original_sha256'=>$docs->file($id)['sha256']];
file_put_contents($cbRoot.'/fixture.json',json_encode($cbFixture['input'],JSON_THROW_ON_ERROR));
$cbProtected=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));
$cbTargetIds=array_column($cbTargets,'id');$cbNewIds=[];$cbNewDocIds=[];$cbNewKeys=[];$cbAuditHead=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();
$cbFilter=static function(string $table)use(&$cbNewIds,&$cbNewDocIds,&$cbNewKeys,$cbTargetIds,$cbAuditHead):array{
 $marks=static fn(array $ids):string=>implode(',',array_fill(0,count($ids),'?'));
 if($table==='document_checks'){$ids=[...$cbTargetIds,...$cbNewIds];return[' WHERE id NOT IN ('.$marks($ids).')',$ids];}
 if($table==='document_check_creations'&&$cbNewKeys)return[" WHERE NOT(club_id='own-list-club' AND actor_id='own-list-admin' AND request_key IN (".$marks($cbNewKeys).'))',$cbNewKeys];
 if(in_array($table,['documents','document_files','document_history','document_requests'],true)&&$cbNewDocIds){$field=$table==='documents'?'id':'document_id';return[' WHERE '.$field.' NOT IN ('.$marks($cbNewDocIds).')',$cbNewDocIds];}
 if($table==='audit_events'){
  $conditions=["(object_id IN (".$marks($cbTargetIds).") AND action='document.check.saved' AND object_version=2)"];$params=$cbTargetIds;
  $conditions[]="(object_id=? AND action='document.check.saved' AND object_version IN (3,4))";$params[]=$cbTargetIds[7];
  if($cbNewIds){$conditions[]="(object_id IN (".$marks($cbNewIds).") AND action='document.check.saved' AND object_version=1)";array_push($params,...$cbNewIds);}
  if($cbNewDocIds){$conditions[]="(object_id IN (".$marks($cbNewDocIds).") AND action='document.uploaded' AND object_version=1)";array_push($params,...$cbNewDocIds);}
  return[" WHERE sequence<=? OR NOT(club_id='own-list-club' AND (".implode(' OR ',$conditions).'))',[$cbAuditHead,...$params]];
 }
 return['',[]];
};
$cbBefore=ownOriginalDatabaseHash($db,$cbProtected,$cbFilter);$cbCounts=[];foreach($cbProtected as$cbTable)$cbCounts[$cbTable]=(int)$db->query('SELECT COUNT(*) FROM '.chr(96).$cbTable.chr(96))->fetchColumn();
$cbOldDocIds=$db->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN);$cbKeyHashes=[];foreach(['auth','private']as$cbKey)$cbKeyHashes[$cbKey]=hash_file('sha256',$cbRoot.'/'.$cbKey.'.key');
try{$cbProcess=proc_open(['node',dirname(__DIR__).'/document-checks-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$cbPipes);fwrite($cbPipes[0],json_encode($cbFixture['input'],JSON_THROW_ON_ERROR));fclose($cbPipes[0]);if(proc_close($cbProcess)!==0)throw new RuntimeException('Actual native check interface failed.');}
finally{foreach($cbFixture['servers']as[$cbProcess,$cbLog]){proc_terminate($cbProcess);proc_close($cbProcess);fclose($cbLog);}}
$cbResults=json_decode(file_get_contents($cbRoot.'/document-check-results.json'),true,64,JSON_THROW_ON_ERROR);$check(count($cbResults)===8,'all eight complete check variants produce their two deliberate creations and one reviewed update');
foreach($cbResults as$cbResult){foreach(['no_file','file']as$cbKind){$cbNewIds[]=$cbResult[$cbKind]['id'];$cbNewKeys[]=$cbResult[$cbKind]['key'];}$cbNewDocIds[]=$cbResult['file']['document_id'];}
$check(count(array_unique($cbNewIds))===16&&count(array_unique($cbNewDocIds))===8&&array_intersect($cbNewDocIds,$cbOldDocIds)===[],'sixteen explicit new checks have distinct identities and eight new files never replace an earlier document identity');
$check(ownOriginalDatabaseHash($db,$cbProtected,$cbFilter)===$cbBefore,'every earlier complete business row source revision original receipt and unrelated audit event stays exact outside the deliberate native check changes');
foreach($cbResults as$cbIndex=>$cbResult){
 foreach(['no_file','file']as$cbKind){$cbCreated=$cbService->get($cbResult[$cbKind]['id']);foreach($cbResult[$cbKind]['data']as$cbField=>$cbValue)$check($cbCreated[$cbField]===$cbValue,'native creation stores the exact consciously submitted field '.$cbKind.' '.$cbField);$check((int)$cbCreated['version']===1&&$cbService->creationReceipt($cbResult[$cbKind]['key'])['original']===$cbCreated,'native creation receipt is exactly its complete first accepted check');}
 $cbUpdated=$cbService->get($cbTargets[$cbIndex]['id']);$cbExpected=array_replace($cbTargets[$cbIndex]['original'],$cbResult['target']['changes'],['version'=>$cbResult['target']['version'],'updated_at'=>$cbUpdated['updated_at']]);$check($cbUpdated===$cbExpected,'only explicitly reviewed check fields change while every unchanged original byte reference and zero responsibility remains exact');
 $cbDocument=$docs->document($cbResult['file']['document_id']);$check($docs->file($cbDocument['id'],1)['bytes']===$ccUploadBytes&&$cbResult['file']['download_sha256']===hash('sha256',$ccUploadBytes),'actual browser download is the exact original PDF stored in the original private source');
}
foreach($cbCounts as$cbTable=>$cbCount){$cbIncrease=match($cbTable){'document_checks','document_check_creations'=>16,'documents','document_files','document_history','document_requests'=>8,'audit_events'=>34,default=>0};$check((int)$db->query('SELECT COUNT(*) FROM '.chr(96).$cbTable.chr(96))->fetchColumn()===$cbCount+$cbIncrease,'native check actions add only their deliberate original records: '.$cbTable);}
foreach($cbKeyHashes as$cbKey=>$cbHash)$check(hash_file('sha256',$cbRoot.'/'.$cbKey.'.key')===$cbHash,'native check interface preserves the exact private '.$cbKey.' key');
