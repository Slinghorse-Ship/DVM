<?php declare(strict_types=1);
use Kulturhus\Verein\{VolunteerForm,DocumentPreview,BoardSignature,AdmissionVotes};
use Kulturhus\Native\{TestSupport,Shared\Db};

// An actual durable commit succeeds, then its acknowledgement is lost.
final class OwnVolunteerLostAck extends PDO
{
 public bool $loseNextWriteAck=false;
 public function prepare(string $query,array $options=[]):PDOStatement|false{
  if(str_starts_with($query,'INSERT INTO document_requests'))$this->loseNextWriteAck=true;
  return parent::prepare($query,$options);
 }
 public function commit():bool{
  $result=parent::commit();
  if($this->loseNextWriteAck){$this->loseNextWriteAck=false;throw new RuntimeException('Own synthetic lost volunteer commit acknowledgement');}
  return$result;
 }
}
$voData=$vfService->build($vfOwnPerson,$vfInput,false);$voHash=VolunteerForm::digest($voData);$voKey=bin2hex(random_bytes(16));
$voAckDb=new OwnVolunteerLostAck($vfDsn,$vfConfig['username'],$vfConfig['password'],$vfOptions);$voAckService=new VolunteerForm($voAckDb,'own-list-reader','general');$voLost=false;
try{$voAckService->save($vfOwnPerson,$vfInput,false,$voKey,$voHash);}catch(RuntimeException $failure){$voLost=$failure->getMessage()==='Own synthetic lost volunteer commit acknowledgement';}
$voCommitted=$vfService->confirmation($voKey);$voAfter=ownOriginalDatabaseHash($db,$tables);
$check($voLost&&$voCommitted['intent']['input']['activity']===$vfInput['activity'],'actual committed certificate has its own first original confirmation after the write acknowledgement is lost');
$check($voAckService->save($vfOwnPerson,$vfInput,false,$voKey,$voHash)===$voCommitted['document_id']&&ownOriginalDatabaseHash($db,$tables)===$voAfter,'real lost commit acknowledgement replays without a second original grant history audit or notification');

// Two independent processes start the same real save at the same barrier.
$voKey=bin2hex(random_bytes(16));$voInput=array_replace($vfInput,['effort'=>'Own actual concurrent intention']);
$voData=$vfService->build($vfOwnPerson,$voInput,false);$voPayload=['contact'=>$vfOwnPerson,'input'=>$voInput,'key'=>$voKey,'hash'=>VolunteerForm::digest($voData)];$voChildren=[];
try{
 for($voIndex=0;$voIndex<2;$voIndex++){
  $voProcess=proc_open([...TestSupport::phpCommand(),__DIR__.'/volunteer-save-child.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$voPipes);
  if(!is_resource($voProcess))throw new RuntimeException('Owned volunteer child unavailable');
  fwrite($voPipes[0],json_encode($voPayload,JSON_THROW_ON_ERROR)."\n");
  $voChildren[]=[$voProcess,$voPipes];
 }
 foreach($voChildren as[$voProcess,$voPipes])if(trim((string)fgets($voPipes[1]))!=='ready')throw new RuntimeException('Owned volunteer barrier unavailable');
 foreach($voChildren as[$voProcess,$voPipes]){fwrite($voPipes[0],"save\n");fclose($voPipes[0]);}
 $voResults=[];
 foreach($voChildren as[$voProcess,$voPipes]){
  $voOutput=stream_get_contents($voPipes[1]);$voError=stream_get_contents($voPipes[2]);fclose($voPipes[1]);fclose($voPipes[2]);$voCode=proc_close($voProcess);
  if($voCode!==0||$voError!=='')throw new RuntimeException('Actual concurrent volunteer save failed: '.$voOutput.$voError);
  $voResults[]=json_decode(trim($voOutput),true,16,JSON_THROW_ON_ERROR);
 }
 $voChildren=[];
 $check($voResults[0]['document']===$voResults[1]['document']&&$vfService->confirmation($voKey)['document_id']===$voResults[0]['document'],'two real independent concurrent saves resolve to exactly the same original certificate identifier');
 $check((int)Db::run($db,'SELECT COUNT(*) FROM document_requests WHERE request_key=?',[$voKey])->fetchColumn()===1&&(int)Db::run($db,'SELECT COUNT(*) FROM document_files WHERE document_id=?',[$voResults[0]['document']])->fetchColumn()===1,'actual concurrent request claims produce one receipt and one immutable file version');
}finally{foreach($voChildren as[$voProcess,$voPipes]){foreach($voPipes as$voPipe)if(is_resource($voPipe))fclose($voPipe);if(is_resource($voProcess)){proc_terminate($voProcess);proc_close($voProcess);}}}

// Savepoint failure may not roll back an earlier, unrelated caller write.
$db->exec("CREATE TRIGGER own_volunteer_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='document.generated.volunteer-form' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own synthetic audit failure'; END IF; END");
try{
 $vfCaller->beginTransaction();Db::run($vfCaller,'UPDATE contacts SET email=? WHERE id=?',['own-pending@example.invalid',$vfOtherPerson]);
 $voCallerState=ownOriginalDatabaseHash($vfCaller,$tables);$voFailed=false;
 try{(new VolunteerForm($vfCaller,'own-list-reader','general'))->save($vfOwnPerson,$vfInput,false,bin2hex(random_bytes(16)));}catch(PDOException){$voFailed=true;}
 $check($voFailed&&$vfCaller->inTransaction()&&ownOriginalDatabaseHash($vfCaller,$tables)===$voCallerState,'actual certificate audit failure rolls back its own request file share and notification while retaining the caller preceding write');
}finally{if($vfCaller->inTransaction())$vfCaller->rollBack();$db->exec('DROP TRIGGER own_volunteer_audit_failure');}

// Full exact fields remain separate from an automatically derived document label.
$voWideInput=array_replace($vfInput,['first_name'=>str_repeat('ä',180),'last_name'=>str_repeat('Ö',180)]);
$voWideData=$vfService->build($vfOwnPerson,$voWideInput,false);$voWideKey=bin2hex(random_bytes(16));$voWideDocument=$vfService->save($vfOwnPerson,$voWideInput,false,$voWideKey,VolunteerForm::digest($voWideData));
$check($vfService->confirmation($voWideKey)['snapshot']['volunteer_general']['values'][0]===str_repeat('Ö',180)&&$vfService->confirmation($voWideKey)['snapshot']['volunteer_general']['values'][1]===str_repeat('ä',180),'actual filing retains both full one-hundred-eighty-character human name fields in its original');

// The original authority image and its official-form overlay are deterministic too.
$voImage=imagecreatetruecolor(200,60);imagefill($voImage,0,0,imagecolorallocate($voImage,255,255,255));imageline($voImage,10,45,190,12,imagecolorallocate($voImage,15,15,15));ob_start();imagepng($voImage);$voPng=(string)ob_get_clean();imagedestroy($voImage);
(new BoardSignature($db,'own-list-admin'))->save($voPng,0,true);
$voSigned=$vfAdmin->build($vfOwnPerson,$vfInput,true);$voSignedPdf=DocumentPreview::pdf($voSigned);usleep(1100000);
$voSignedKey=bin2hex(random_bytes(16));$voSignedDocument=$vfAdmin->save($vfOwnPerson,$vfInput,true,$voSignedKey,VolunteerForm::digest($voSigned));
$check($vfAdmin->file($voSignedDocument)['bytes']===$voSignedPdf&&$vfAdmin->confirmation($voSignedKey)['snapshot']['signature_sha256']===$voSigned['signature_sha256'],'actual signed general certificate preserves the exact reviewed PDF and the original current first-chair image fingerprint');
$voSignedHygiene=(new VolunteerForm($db,'own-list-admin'))->build($vfOwnPerson,$vfHygieneInput,true);$voSignedHygienePdf=DocumentPreview::pdf($voSignedHygiene);usleep(1100000);
$check(DocumentPreview::pdf($voSignedHygiene)===$voSignedHygienePdf,'actual original official hygiene overlay preserves byte-identical reviewed signature output across timestamps');

// Busy current profiles fail promptly and leave the caller usable.
$voBlocker=new PDO($vfDsn,$vfConfig['username'],$vfConfig['password'],$vfOptions);$voBlocker->beginTransaction();Db::run($voBlocker,'SELECT id FROM contacts WHERE id=? FOR UPDATE',[$vfOwnPerson])->fetch();
$vfCaller->beginTransaction();$voStarted=microtime(true);$voBusy=false;
try{(new VolunteerForm($vfCaller,'own-list-reader','general'))->build($vfOwnPerson,$vfInput,false);}catch(DomainException $failure){$voBusy=$failure->getCode()===503;}
$check($voBusy&&microtime(true)-$voStarted<2&&$vfCaller->inTransaction()&&(int)$vfCaller->query('SELECT 1')->fetchColumn()===1,'actual competing profile lock refuses the new certificate promptly without committing or rolling back the caller');
$vfCaller->rollBack();$voBlocker->rollBack();

// A pre-existing caller snapshot must still use the actually replaced current authority image.
$voOldSignedOriginal=$vfAdmin->file($voSignedDocument);
$vfCaller->beginTransaction();$vfCaller->query('SELECT * FROM club_settings')->fetchAll();$vfCaller->query('SELECT * FROM settings_revisions')->fetchAll();$vfCaller->query('SELECT * FROM audit_events')->fetchAll();
$voImage=imagecreatefromstring($voPng);imageline($voImage,20,15,180,50,imagecolorallocate($voImage,70,30,10));ob_start();imagepng($voImage);$voNewPng=(string)ob_get_clean();imagedestroy($voImage);
(new BoardSignature($db,'own-list-admin'))->save($voNewPng,1,true);
$voCurrentSigned=$vfAdmin->build($vfOwnPerson,$vfInput,true);$voBeforeCurrentRead=ownOriginalDatabaseHash($db,$tables);$voSnapshotSigned=null;$voCallerPreserved=false;
try{$voSnapshotSigned=(new VolunteerForm($vfCaller,'own-list-admin','general'))->build($vfOwnPerson,$vfInput,true);$voCallerPreserved=$vfCaller->inTransaction();}catch(DomainException){$voCallerPreserved=$vfCaller->inTransaction();}finally{if($vfCaller->inTransaction())$vfCaller->rollBack();}
$check($voSnapshotSigned===$voCurrentSigned&&$voCallerPreserved,'actual replaced signature and its immutable owner proof are read currently even through an earlier caller paper revision and audit snapshot');
$check($vfAdmin->file($voSignedDocument)===$voOldSignedOriginal&&ownOriginalDatabaseHash($db,$tables)===$voBeforeCurrentRead,'current signature recheck preserves the complete previously signed certificate original and all current business rows');

// Active pre-upgrade session data without its new PDF proof must be reviewed again.
$voDraftData=$vfService->build($vfOwnPerson,$vfInput,false);$voDraftPdf=DocumentPreview::pdf($voDraftData);
$voDraft=['actor'=>'own-list-reader','kind'=>'general','contact'=>$vfOwnPerson,'input'=>$vfInput,'signed'=>false,'hash'=>VolunteerForm::digest($voDraftData),'bytes'=>$voDraftPdf,'pdf_sha256'=>hash('sha256',$voDraftPdf),'token'=>bin2hex(random_bytes(32)),'request_key'=>bin2hex(random_bytes(16)),'expires'=>time()+1800];
$voCurrentDraft=\Kulturhus\Verein\VolunteerFormState::prepare($db,'own-list-reader',['volunteer_kind'=>'general'],[],['volunteer_preview'=>$voDraft]);
$check($voCurrentDraft['draft']===$voDraft&&$voCurrentDraft['values']===$vfInput,'actual current server draft reopens its complete verified original input and exact PDF proof');
unset($voDraft['pdf_sha256']);
$voOldDraft=\Kulturhus\Verein\VolunteerFormState::prepare($db,'own-list-reader',['volunteer_kind'=>'general'],[],['volunteer_preview'=>$voDraft]);
$check($voOldDraft['draft']===null&&$voOldDraft['draft_error']!==''&&$voOldDraft['values']===$vfInput,'pre-upgrade draft without the new original PDF proof keeps its complete input but requires a fresh explicit review');

// A native person page must not retain ended eligibility from an earlier caller snapshot.
$vfCaller->beginTransaction();$vfCaller->query('SELECT * FROM memberships')->fetchAll();
Db::run($db,"UPDATE memberships SET status='ehemalig',ends_on='2026-10-19',version=version+1 WHERE contact_id=?",[$vfOtherPerson]);
$voEligibilityBefore=ownOriginalDatabaseHash($db,$tables);
try{$voEligiblePage=(new VolunteerForm($vfCaller,'own-list-admin','general'))->peoplePage(['query'=>'Own Different Member']);$voEligibilityCaller=$vfCaller->inTransaction();}finally{$vfCaller->rollBack();}
$check($voEligiblePage['total']===0&&$voEligibilityCaller&&ownOriginalDatabaseHash($db,$tables)===$voEligibilityBefore,'actual native person selector excludes a changed ineligible membership through an earlier caller snapshot without changing any current business row');
Db::run($db,"UPDATE memberships SET status='passiv',ends_on=NULL,version=version+1 WHERE contact_id=?",[$vfOtherPerson]);
