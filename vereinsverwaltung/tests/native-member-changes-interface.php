<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Verein\{Registry,MemberChangeRequests};
$cfg=TestSupport::config();$db=TestSupport::db();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-member-changes-interface'||($cfg['host']??'')!=='127.0.0.1'||($cfg['port']??0)!==13371||$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==[])throw new RuntimeException('Fresh owned member changes fixture required.');
SchemaRegistry::install($db);$f=ownEditorialBrowserFixture($db);$actor=$f['accounts']['admin']['actor'];$member=$f['accounts']['member']['actor'];$registry=new Registry($db,$actor);putenv('APP_SECRET=Own isolated member changes fixture archive 2026!');$secret=hash('sha256',(string)getenv('APP_SECRET'),true);$n=0;$key=static fn():string=>bin2hex(random_bytes(16));
$check=static function(bool $ok,string $why)use(&$n):void{if(!$ok)throw new RuntimeException($why);++$n;echo'PASS '.$why.PHP_EOL;};
$contact=$registry->saveContact(null,0,'person','0 Own original member ä🙂','own-original-member@example.invalid',"Own original address\n0 Original second line",['phone'=>'0 Own original phone','birthday'=>'1961-01-01','notes'=>'Own unrelated confidential original note']);
Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$member]);Db::run($db,"INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES(?,?,?,'OWN-1','aktiv','2020-01-01')",[$key(),$f['club'],$contact]);
$mine=new MemberChangeRequests($db,$member,$secret);$reviewer=new MemberChangeRequests($db,$actor,$secret);$check($mine->canSubmit()&&$reviewer->canReview(),'actual current member proposes and current board reviewer has distinct review authority');
final class OwnMemberChangeConnection extends PDO
{
 public ?Closure $beforeChangeWrite=null;public ?Closure $beforeProposalInsert=null;public bool $loseCommit=false;
 public function prepare(string $query,array $options=[]):PDOStatement|false{if($this->beforeChangeWrite!==null&&str_starts_with($query,'UPDATE member_change_requests SET ')){$next=$this->beforeChangeWrite;$this->beforeChangeWrite=null;$next();}if($this->beforeProposalInsert!==null&&str_starts_with($query,'INSERT INTO member_change_requests(')){$next=$this->beforeProposalInsert;$this->beforeProposalInsert=null;$next();}return parent::prepare($query,$options);}
 public function commit():bool{$ok=parent::commit();if($this->loseCommit){$this->loseCommit=false;throw new PDOException('Own member change COMMIT accepted before response loss');}return$ok;}
}
$connect=static fn():OwnMemberChangeConnection=>new OwnMemberChangeConnection('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$fault=$connect();$other=$connect();$faultReview=new MemberChangeRequests($fault,$actor,$secret);$faultOwn=new MemberChangeRequests($fault,$member,$secret);$otherReview=new MemberChangeRequests($other,$actor,$secret);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$current=$mine->ownContact();$after=array_intersect_key($current,MemberChangeRequests::FIELDS);$after['phone']='Own accepted original after lost submission response';$submissionKey=$key();$fault->loseCommit=true;$failure=null;
try{$faultOwn->submit($after,(int)$current['version'],$submissionKey);}catch(Throwable $error){$failure=$error;}
$stored=Db::run($db,'SELECT * FROM member_change_requests WHERE club_id=? AND request_key=?',[$f['club'],$submissionKey])->fetch();
echo json_encode(['member_change_commit_observation'=>['original_error_retained'=>$failure instanceof PDOException&&$failure->getMessage()==='Own member change COMMIT accepted before response loss','actual_error'=>$failure?->getMessage(),'saved_once'=>$stored!==false,'profile_unchanged'=>$mine->ownContact()===$current]],JSON_THROW_ON_ERROR).PHP_EOL;
$check($failure instanceof PDOException&&$failure->getMessage()==='Own member change COMMIT accepted before response loss'&&$stored!==false&&$stored['state']==='pending'&&$mine->ownContact()===$current,'actual accepted submission preserves its one encrypted proposal original COMMIT exception and unchanged profile');
$beforeLookup=ownOriginalDatabaseHash($db,$tables);$lookedUp=$mine->submissionResult($submissionKey);
$check($lookedUp!==null&&$lookedUp['id']===$stored['id']&&!isset($lookedUp['request_key'])&&!isset($lookedUp['request_hash'])&&!isset($lookedUp['ciphertext']),'explicit own original submission lookup reads its same protected proposal without nonce hash or ciphertext');
$check($mine->submissionResult($key())===null&&ownOriginalDatabaseHash($db,$tables)===$beforeLookup,'missing original submission lookup returns no replacement proposal or profile write');
$afterAccepted=ownOriginalDatabaseHash($db,$tables);$check($mine->submit($after,(int)$current['version'],$submissionKey)===$stored['id']&&ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'exact original submission replay reads its accepted result without another row or audit');
$mine->withdraw($stored['id'],1);
foreach(['reject','withdraw']as$mode){
 $current=$mine->ownContact();$after=array_intersect_key($current,MemberChangeRequests::FIELDS);$after['phone']='Own separately accepted phone '.$mode;$id=$mine->submit($after,(int)$current['version'],$key());$afterOther=null;
 $fault->beforeChangeWrite=static function()use($otherReview,$id,$db,$tables,&$afterOther):void{$otherReview->review($id,1,'accepted','Own separately committed accepted proposal');$afterOther=ownOriginalDatabaseHash($db,$tables);};
 $failure=null;try{if($mode==='reject')$faultReview->review($id,1,'rejected','Own displaced stale rejection');else$faultOwn->withdraw($id,1);}catch(Throwable $error){$failure=$error;}
 $saved=$reviewer->get($id,true);echo json_encode(['member_change_race_observation'=>['mode'=>$mode,'exception'=>$failure?get_class($failure):null,'code'=>$failure?->getCode(),'state'=>$saved['state'],'request_version'=>$saved['version'],'profile_has_other_accepted_phone'=>$mine->ownContact()['phone']===$after['phone']]],JSON_THROW_ON_ERROR).PHP_EOL;
 $check($failure instanceof DomainException&&$failure->getCode()===409,'stale '.$mode.' cannot overwrite a separately accepted member change or claim success');
 $check($saved['state']==='accepted'&&$saved['data']['decision_note']==='Own separately committed accepted proposal'&&ownOriginalDatabaseHash($db,$tables)===$afterOther,'all complete accepted proposal encrypted snapshots contact audit and unrelated rows stay exact after stale '.$mode);
}
$current=$mine->ownContact();$after=array_intersect_key($current,MemberChangeRequests::FIELDS);$after['address']="Own refused concurrent address\n0 Own original second line ä🙂";$id=$mine->submit($after,(int)$current['version'],$key());$afterOther=null;
$fault->beforeChangeWrite=static function()use($otherReview,$id,$db,$tables,&$afterOther):void{$otherReview->review($id,1,'rejected','Own separately committed rejection');$afterOther=ownOriginalDatabaseHash($db,$tables);};
$failure=null;try{$faultReview->review($id,1,'accepted','Own displaced stale approval');}catch(Throwable $error){$failure=$error;}
$saved=$reviewer->get($id,true);$check($failure instanceof DomainException&&$failure->getCode()===409&&$saved['state']==='rejected'&&$saved['data']['decision_note']==='Own separately committed rejection','stale approval cannot replace a separately committed rejection');
$check($mine->ownContact()===$current&&ownOriginalDatabaseHash($db,$tables)===$afterOther,'late proposal CAS refusal rolls back its earlier contact update document index and own audit without touching the committed rejection');

$current=$mine->ownContact();$after=array_intersect_key($current,MemberChangeRequests::FIELDS);$after['phone']='0';$after['address']="Own consciously accepted address\n0 Original line ä🙂";$id=$mine->submit($after,(int)$current['version'],$key());$fault->loseCommit=true;$failure=null;
try{$faultReview->review($id,1,'accepted','Own accepted board note after lost acknowledgement');}catch(Throwable $error){$failure=$error;}
$saved=$reviewer->get($id,true);$accepted=$mine->ownContact();$expected=array_replace($current,['phone'=>'0','address'=>$after['address'],'version'=>(int)$current['version']+1]);
$check($failure instanceof PDOException&&$failure->getMessage()==='Own member change COMMIT accepted before response loss'&&$accepted===$expected&&$saved['state']==='accepted'&&(int)$saved['version']===2,'actual accepted board COMMIT keeps exactly its intended contact fields one sealed decision and the original lost-response error');
$afterAccepted=ownOriginalDatabaseHash($db,$tables);$reviewer->review($id,1,'accepted','Own accepted board note after lost acknowledgement');
$check(ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'explicit identical accepted decision replay never increments the profile or appends another audit');
$failure=null;try{$reviewer->review($id,1,'rejected','Own changed intention for the already accepted proposal');}catch(DomainException $error){$failure=$error;}
$check($failure!==null&&ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'changed replay cannot replace an already committed original decision or its encrypted note');

$current=$mine->ownContact();$after=array_intersect_key($current,MemberChangeRequests::FIELDS);$after['name']='0 Own rejected acceptance SQL abort ä🙂';$id=$mine->submit($after,(int)$current['version'],$key());$beforeAbort=ownOriginalDatabaseHash($db,$tables);
$db->exec("CREATE TRIGGER own_member_change_audit_abort BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='member.change.accepted' AND NEW.object_id='".$id."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own exact accepted proposal SQL abort'; END IF; END");
$failure=null;try{$reviewer->review($id,1,'accepted','Own refused note with exact history abort');}catch(PDOException $error){$failure=$error;}finally{$db->exec('DROP TRIGGER own_member_change_audit_abort');}
$check($failure instanceof PDOException&&str_contains($failure->getMessage(),'Own exact accepted proposal SQL abort')&&ownOriginalDatabaseHash($db,$tables)===$beforeAbort&&$mine->ownContact()===$current,'real final audit SQL abort rolls back contact index encrypted proposal state and every whole original row');
$mine->withdraw($id,1);$afterWithdrawn=ownOriginalDatabaseHash($db,$tables);$mine->withdraw($id,1);
$check(ownOriginalDatabaseHash($db,$tables)===$afterWithdrawn,'explicit original withdrawal replay reads its own one withdrawn result without another write');
$current=$mine->ownContact();$rootIntent=array_intersect_key($current,MemberChangeRequests::FIELDS);$rootIntent['phone']='Own original first concurrent submission';$otherIntent=array_replace($rootIntent,['phone'=>'Own original second concurrent submission']);$otherOwn=new MemberChangeRequests($other,$member,$secret);$otherId=null;$afterOther=null;
$fault->beforeProposalInsert=static function()use($otherOwn,$current,$otherIntent,$key,$db,$tables,&$otherId,&$afterOther):void{$otherId=$otherOwn->submit($otherIntent,(int)$current['version'],$key());$afterOther=ownOriginalDatabaseHash($db,$tables);};
$failure=null;try{$faultOwn->submit($rootIntent,(int)$current['version'],$key());}catch(PDOException $error){$failure=$error;}$pending=$mine->listing(false,'pending');
echo json_encode(['member_change_pending_observation'=>['pending_count'=>count($pending),'other_saved'=>$otherId!==null,'own_unique_refusal'=>$failure instanceof PDOException&&(int)($failure->errorInfo[1]??0)===1062]],JSON_THROW_ON_ERROR).PHP_EOL;
$check(count($pending)===1&&$pending[0]['id']===$otherId&&$failure instanceof PDOException&&(int)($failure->errorInfo[1]??0)===1062,'existing real database one-pending constraint refuses a second competing proposal');
$check(ownOriginalDatabaseHash($db,$tables)===$afterOther,'refused competing submission retains every complete row after the other committed original proposal');
$mine->withdraw($otherId,1);
$current=$mine->ownContact();$logicalNoteData=array_intersect_key($current,MemberChangeRequests::FIELDS);$logicalNoteData['email']='own-logical-note@example.invalid';$logicalNoteId=$mine->submit($logicalNoteData,(int)$current['version'],$key());$logicalNote="Own accepted multi-line response\r\n0 Literal second line ä🙂";$reviewer->review($logicalNoteId,1,'accepted',$logicalNote);$beforeLogicalReplay=ownOriginalDatabaseHash($db,$tables);$failure=null;
try{$reviewer->review($logicalNoteId,1,'accepted',str_replace("\r\n","\n",$logicalNote));}catch(Throwable $error){$failure=$error;}
echo json_encode(['member_change_note_replay_observation'=>['exception'=>$failure?get_class($failure):null,'original_sealed_rows_exact'=>ownOriginalDatabaseHash($db,$tables)===$beforeLogicalReplay]],JSON_THROW_ON_ERROR).PHP_EOL;
$check($failure===null&&ownOriginalDatabaseHash($db,$tables)===$beforeLogicalReplay,'logically identical native textarea line endings replay the one original accepted decision without rewriting its encrypted bytes');
// The published note limit counts Unicode text and logical textarea newlines,
// while the sealed original and a later identical replay retain their exact bytes.
$noteProtected=array_values(array_diff($tables,['member_change_requests','audit_events']));
$noteOriginal=ownOriginalDatabaseHash($db,$noteProtected);
$noteAuditOriginal=$db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();
$noteCases=['umlauts'=>str_repeat('ä',2000),'four-byte'=>str_repeat('🙂',2000),'crlf'=>str_repeat("a\r\n",999).'ä0','cr'=>str_repeat("a\r",999).'ä0'];
foreach($noteCases as$kind=>$originalNote){
 $logicalNote=str_replace(["\r\n","\r"],"\n",$originalNote);
 $check(mb_strlen($logicalNote,'UTF-8')===2000,'own note boundary fixture contains exactly two thousand logical Unicode characters '.$kind);
 $current=$mine->ownContact();$proposal=array_intersect_key($current,MemberChangeRequests::FIELDS);$proposal['phone']='Own note boundary proposal '.$kind;$noteId=$mine->submit($proposal,(int)$current['version'],$key());
 $failure=null;try{$reviewer->review($noteId,1,'rejected',$originalNote);}catch(Throwable$error){$failure=$error;}
 $check($failure===null,'actual reviewer accepts the advertised two thousand characters rather than UTF-8 bytes '.$kind);
 $decision=$reviewer->get($noteId,true);
 $check($decision['state']==='rejected'&&(int)$decision['version']===2&&$decision['data']['decision_note']===$originalNote,'one sealed review preserves the complete original character-boundary bytes '.$kind);
 $noteAccepted=ownOriginalDatabaseHash($db,$tables);$reviewer->review($noteId,1,'rejected',$logicalNote);
 $check(ownOriginalDatabaseHash($db,$tables)===$noteAccepted,'logical character-boundary replay changes no whole original row ciphertext or audit '.$kind);
}
$current=$mine->ownContact();$proposal=array_intersect_key($current,MemberChangeRequests::FIELDS);$proposal['phone']='Own refused note boundary original';$noteId=$mine->submit($proposal,(int)$current['version'],$key());
foreach(['ascii'=>str_repeat('a',2001),'umlauts'=>str_repeat('ä',2001),'four-byte'=>str_repeat('🙂',2001),'crlf'=>str_repeat("a\r\n",1000).'0','nul'=>"Own original\0note",'invalid-utf8'=>"Own original\xffnote"]as$kind=>$invalidNote){
 $beforeNoteRefusal=ownOriginalDatabaseHash($db,$tables);$failure=null;try{$reviewer->review($noteId,1,'rejected',$invalidNote);}catch(Throwable$error){$failure=$error;}
 $check($failure instanceof DomainException,'actual review refuses oversized or invalid original note before writing '.$kind);
 $check(ownOriginalDatabaseHash($db,$tables)===$beforeNoteRefusal,'refused note keeps every whole pending proposal profile encrypted original and audit '.$kind);
}
$mine->withdraw($noteId,1);
$noteAuditAfter=$db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();
$check(ownOriginalDatabaseHash($db,$noteProtected)===$noteOriginal&&array_slice($noteAuditAfter,0,count($noteAuditOriginal))===$noteAuditOriginal,'all character-boundary decisions preserve every earlier whole profile business table and audit original');
require __DIR__.'/support/member-changes-interface-models.php';
if(ob_get_level()>0)ob_end_flush();echo json_encode(['suite'=>'native-member-changes-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
if(getenv('DVM_MEMBER_CHANGES_BROWSER')==='1')require __DIR__.'/support/member-changes-browser-fixture.php';
