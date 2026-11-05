<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Settings;
foreach(['writer'=>['write','write'],'reader'=>['read','read'],'finance-only'=>['none','write'],'members-only'=>['write','none']]as$name=>$rights){
 $actor='own-assignment-'.$name;$role=$actor.'-role';
 Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,'own-list-club','member',0)",[$actor]);
 Db::run($db,'INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES(?,?,?,?,?,?,0,1)',['own-list-club',$role,'Own assignment '.$name,'',$rights[0],$rights[1]]);
 Db::run($db,'INSERT INTO settings_role_assignments VALUES(?,?,?,1)',[$actor,'own-list-club',$role]);
}
$future=$input;$future['data']['start']='2027-01-01';$future['data']['end']='2027-01-01';
$check((new \Kulturhus\Verein\Settings\AssignmentRequests($db,'own-assignment-writer'))->prepare($future)['source']['contact']['id']===$newPerson,'separate current finance and member write rights allow assignment review without general settings rights');
$reject(fn()=>(new \Kulturhus\Verein\Settings\AssignmentRequests($db,'own-assignment-reader'))->prepare($future),403,'combined finance and member readers cannot prepare an assignment write');
foreach(['finance-only','members-only']as$name)$reject(fn()=>(new \Kulturhus\Verein\Settings\AssignmentRepository($db,'own-assignment-'.$name))->state($id,'services'),403,'one financial or member permission cannot substitute for the other personal assignment permission');
$reject(fn()=>(new \Kulturhus\Verein\Settings\AssignmentRequests($db,'own-assignment-writer'))->receipt($firstKey),404,'another current fully permitted writer cannot retrieve this account personal first assignment original');
$before=ownOriginalDatabaseHash($db,$tables);$failedInput=$future;$failedInput['data']['start']='2027-01-02';$failedInput['data']['end']='2027-01-02';$failedReview=$aq->prepare($failedInput);
$db->exec("CREATE TRIGGER own_assignment_original_failure BEFORE INSERT ON native_settings_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own actual assignment original failure'");
try{
 $db->beginTransaction();$db->exec("UPDATE clubs SET name='Own earlier assignment caller work' WHERE id='own-list-club'");$callerBefore=ownOriginalDatabaseHash($db,$tables);$failed=false;
 try{$aq->accept($failedInput,bin2hex(random_bytes(16)),$failedReview['hash'],true);}catch(PDOException){$failed=true;}
 $check($failed&&$db->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$callerBefore,'actual first assignment original failure rolls back its executed canonical assignment revision and audit while retaining every earlier caller row');
}finally{if($db->inTransaction())$db->rollBack();$db->exec('DROP TRIGGER own_assignment_original_failure');}
$check(ownOriginalDatabaseHash($db,$tables)===$before,'outer caller rollback restores every whole earlier row after the real assignment original failure');
$cfg=\Kulturhus\Native\TestSupport::config();
$fault=new class($cfg)extends PDO{
 public ?string $loseKey=null;
 public function __construct(array $c){parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
 public function commit():bool{$lose=$this->loseKey!==null&&\Kulturhus\Native\Shared\Db::run($this,'SELECT 1 FROM native_settings_requests WHERE request_key=?',[$this->loseKey])->fetchColumn();$result=parent::commit();if($lose){$this->loseKey=null;throw new PDOException('Own actual assignment commit acknowledgement lost.');}return$result;}
};
$lostKey=bin2hex(random_bytes(16));$lostReview=$aq->prepare($future);$fault->loseKey=$lostKey;$lost=false;
try{(new \Kulturhus\Verein\Settings\AssignmentRequests($fault,'own-list-admin'))->accept($future,$lostKey,$lostReview['hash'],true);}catch(PDOException){$lost=true;}
$lostOriginal=$aq->receipt($lostKey);$before=ownOriginalDatabaseHash($db,$tables);
$check($lost&&!$fault->inTransaction()&&$lostOriginal['result']['id']!==$id&&$aq->accept($future,$lostKey,$lostReview['hash'],true)===$lostOriginal&&ownOriginalDatabaseHash($db,$tables)===$before,'an actual accepted assignment COMMIT with lost acknowledgement resolves by its exact first original and same-key replay creates no duplicate');
$caller=new PDO('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$caller->beginTransaction();$caller->query("SELECT * FROM actors WHERE id='own-assignment-writer'")->fetch();
$db->exec("UPDATE settings_roles SET members_access='read' WHERE club_id='own-list-club' AND id='own-assignment-writer-role'");
try{
 $denied=false;try{(new \Kulturhus\Verein\Settings\AssignmentRequests($caller,'own-assignment-writer'))->prepare($failedInput);}catch(DomainException $failure){$denied=$failure->getCode()===403;}
 $check($denied&&$caller->inTransaction(),'current member write withdrawal closes assignment review behind an earlier caller snapshot without changing the caller transaction');
}finally{$caller->rollBack();$db->exec("UPDATE settings_roles SET members_access='write' WHERE club_id='own-list-club' AND id='own-assignment-writer-role'");}
$caller->beginTransaction();$q=$caller->prepare('SELECT * FROM contacts WHERE id=?');$q->execute([$newPerson]);$q->fetch();
Db::run($db,'UPDATE contacts SET archived_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?',['own-list-club',$newPerson]);
try{
 $originalDenied=false;try{(new \Kulturhus\Verein\Settings\AssignmentRequests($caller,'own-list-admin'))->receipt($firstKey);}catch(DomainException $failure){$originalDenied=$failure->getCode()===403;}
 $history=(new \Kulturhus\Verein\Settings\AssignmentRequests($caller,'own-list-admin'))->history('services');
 $check($originalDenied&&$history['total']===0&&$caller->inTransaction(),'current profile archive closes personal assignment originals and their counted history behind an earlier snapshot without exposing another profile');
 $reject(fn()=>$aq->accept($input,$firstKey,$review['hash'],true),503,'an independently held original read returns a prompt retryable conflict rather than bypassing the original row or changing any whole row');
 $caller->rollBack();
 $reject(fn()=>$aq->accept($input,$firstKey,$review['hash'],true),403,'an accepted assignment retry never bypasses current archived-profile access');
}finally{if($caller->inTransaction())$caller->rollBack();Db::run($db,'UPDATE contacts SET archived_at=NULL WHERE club_id=? AND id=?',['own-list-club',$newPerson]);}
$check($aq->receipt($firstKey)===$first&&$aq->receipt($endKey)===$ended&&$aq->receipt($lostKey)===$lostOriginal,'restored current profile access retrieves the exact earlier assignment originals rather than recreating their history');
$stale=$future;$stale['data']['start']='2027-01-03';$stale['data']['end']='2027-01-03';$staleReview=$aq->prepare($stale);
$sourceInput=['id'=>$catalogId,'kind'=>'service','version'=>'1','data'=>array_replace($catalogOriginal['result']['data'],['amount'=>'31.00'])];$sourceReview=$at->prepare($sourceInput);$sourceChanged=$at->accept($sourceInput,bin2hex(random_bytes(16)),$sourceReview['hash'],true);
$reject(fn()=>$aq->accept($stale,bin2hex(random_bytes(16)),$staleReview['hash'],true),409,'a genuinely later consciously changed tariff source makes the earlier unaccepted assignment review invalid');
$currentReview=$aq->prepare($stale);$before=ownOriginalDatabaseHash($db,$tables);
$check($currentReview['input']===$stale&&$currentReview['source']['catalog']['data']['amount']==='31.00'&&$currentReview['hash']!==$staleReview['hash']&&$aq->accept($input,$firstKey,$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$before,'renewed current assignment review retains desired whole dates while an earlier accepted key still returns its first twenty-nine euro source without a new write');
for($i=1;$i<=26;$i++){$historyInput=$input;$historyInput['data']['start']='2027-03-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);$historyInput['data']['end']=$historyInput['data']['start'];$historyReview=$aq->prepare($historyInput);$aq->accept($historyInput,bin2hex(random_bytes(16)),$historyReview['hash'],true);}
$history=$aq->history('services',['page'=>'2']);
$check($history['total']===29&&in_array($firstKey,array_column($history['rows'],'request_key'),true)&&$aq->receipt($firstKey)===$first&&$ap->revision($id,'services','1')===$firstRaw,'bounded personal assignment history reaches the genuine first accepted original beyond twenty-five and retains its actual unchanged raw first revision');
$reject(fn()=>$aq->history('services',['page'=>'999999']),422,'a whole unavailable assignment confirmation page selects no replacement original');
$reject(fn()=>$aq->history('services',['query'=>str_repeat('🙂',151)]),422,'a whole overlong Unicode assignment confirmation search is not shortened into another selection');
$searchHistory=$aq->history('services',['query'=>str_repeat('Ä',150)]);
$check($searchHistory['total']===29,'actual personal assignment original search handles complete Unicode original tariff names case-insensitively');
for($i=0;$i<7;$i++)$ar->saveContact(null,0,'person','Own assignment selector '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'selector-'.$i.'@example.invalid','');
for($i=0;$i<26;$i++)$as->saveCatalog('',0,'service',array_replace(Settings::catalogDefaults('service'),['name'=>'Own assignment source '.str_pad((string)$i,2,'0',STR_PAD_LEFT)]));
$contacts=$ap->contacts(['query'=>'Own assignment selector','page'=>'2']);$catalogs=$ap->catalogs('services',['query'=>'Own assignment source','page'=>'2']);
$check($contacts['total']===7&&count($contacts['rows'])===1&&$catalogs['total']===26&&count($catalogs['rows'])===1,'current bounded native source choices reach the genuine seventh contact and twenty-sixth active tariff without loading all records');
$reject(fn()=>$ap->contacts(['query'=>'Own assignment selector','page'=>'999999']),422,'whole invalid contact selection page cannot substitute its first six contacts');
$reject(fn()=>$ap->catalogs('services',['query'=>str_repeat('🙂',151)]),422,'whole invalid native tariff selection search cannot substitute a shortened query');
$check(ownOriginalDatabaseHash($db,$tables,$assignmentOldFilter)===$assignmentOldHash&&(new \Kulturhus\Verein\Settings\CatalogRepository($db,'own-list-admin'))->state($catalogId,'service')===$sourceChanged['result']&&$af->entry($oldEntries[0])===$oldEntry&&$af->positions('entry',$oldEntries[0])===$oldPositions&&$ag->receipt('club',$agOriginal['key'])===$agOriginal&&$at->receipt($catalogOriginal['key'])===$catalogOriginal,'all later actual assignment writes source choices rights changes failures history and retries preserve every earlier whole business row outside the explicitly verified current tariff source and retain every earlier financial general and tariff original');
