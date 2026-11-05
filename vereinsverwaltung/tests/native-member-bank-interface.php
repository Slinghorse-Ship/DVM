<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Verein\MemberBankVault;
use Kulturhus\Verein\Web\MemberBankForm;
use Kulturhus\Native\Language\MemberBankMessages;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/bank-interface-runtime';mkdir($root,0700);foreach(['web','manager-sessions','media']as$d)mkdir($root.'/'.$d,0700);
foreach(['bank.key','auth.key','private.key']as$name){file_put_contents($root.'/'.$name,random_bytes(32));chmod($root.'/'.$name,0600);}
putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('VEREIN_MEMBER_BANK_KEY_PATH='.$root.'/bank.key');putenv('APP_SECRET='.str_repeat('owned-bank-interface-',3));putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');
$n=0;$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo'PASS '.$label.PHP_EOL;};
$deny=static function(callable$fn,string$label)use($check):void{try{$fn();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
Db::run($db,"INSERT INTO clubs(id,name) VALUES('bank-ui','Owned bank interface'),('bank-foreign','Foreign'),('bank-empty','Empty')");
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('bank-root','bank-ui','member',1),('bank-empty-root','bank-empty','member',1),('bank-foreign-root','bank-foreign','member',1)");
for($i=0;$i<14;$i++){$person=md5('bank-person-'.$i);Db::run($db,"INSERT INTO contacts(club_id,id,kind,name) VALUES('bank-ui',?,'person',?)",[$person,$i===13?'Literal %_ <name> ä🙂':'Eigene Bankperson '.str_pad((string)$i,2,'0',STR_PAD_LEFT)]);Db::run($db,"INSERT INTO memberships(club_id,id,contact_id,member_number,status,starts_on) VALUES('bank-ui',?,?,?,'aktiv','2020-01-01')",['bank-membership-'.$i,$person,'BANK-'.str_pad((string)$i,3,'0',STR_PAD_LEFT)]);}
Db::run($db,"INSERT INTO actors(id,club_id,role,contact_id) VALUES('bank-ui-member','bank-ui','member','ffef10496e835c7c97d276f4449674c9'),('bank-ui-family','bank-ui','member','115f0793bd5a6918be111f1b351de66b'),('bank-ui-editor','bank-ui','editor','ffef10496e835c7c97d276f4449674c9')");
Db::run($db,"INSERT INTO member_bank_config(club_id,bank_online_mandate_accepted,sepa_enabled,creditor_id,creditor_name,creditor_address) VALUES('bank-ui',1,1,'DE98ZZZ09999999999','Bankdaten','Eigene Straße 123')");
$vault=new MemberBankVault($db,'bank-root',$root.'/bank.key');$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
foreach([1,2,3]as$page){$rows=$vault->memberChoices('',$page);$check(count($rows['rows'])===($page===3?2:6)&&$rows['page']===$page&&$rows['pages']===3&&$rows['total']===14,'actual member picker returns bounded stable page '.$page);}
$check(count($vault->memberChoices('%_')['rows'])===1&&count($vault->memberChoices('BANK-013')['rows'])===1,'literal wildcard characters and original member number select only their actual person');
$check((new MemberBankVault($db,'bank-empty-root',$root.'/bank.key'))->memberChoices()['total']===0,'empty club returns deliberate empty choices');
foreach([fn()=>$vault->memberChoices('',4),fn()=>$vault->memberChoices('',0),fn()=>(new MemberBankVault($db,'bank-ui-member',$root.'/bank.key'))->memberChoices()]as$i=>$fn){$deny($fn,'invalid or unauthorised member choices refuse '.$i);}
$frame=['view'=>'portal','tab'=>'bank','person'=>'ffef10496e835c7c97d276f4449674c9'];$post=['action'=>'member-bank-begin','member_bank_contact'=>'ffef10496e835c7c97d276f4449674c9'];
$check(MemberBankForm::target($frame,$post,'other')==='ffef10496e835c7c97d276f4449674c9','explicit original person wins independently of list order');
foreach([[],['person'=>'115f0793bd5a6918be111f1b351de66b'],['person'=>['ffef10496e835c7c97d276f4449674c9']],['member_q'=>['bad']],['member_page'=>'0001'],['extra'=>'ffef10496e835c7c97d276f4449674c9']]as$i=>$change){$deny(fn()=>MemberBankForm::target(array_replace($frame,$change),$i===0?['action'=>'member-bank-begin']:$post,'ffef10496e835c7c97d276f4449674c9'),'missing changed or structured target cannot select another person '.$i);}
foreach(['00','-1','1e0','1.0','4294967296',[],null]as$i=>$v)$deny(fn()=>MemberBankForm::version($v),'original version rejects coercion '.$i);
$check(MemberBankForm::version('0')===0&&MemberBankForm::version('4294967295')===4294967295,'canonical unsigned original versions are retained exactly');
$en=MemberBankMessages::messages('en');$check(MemberBankMessages::text('IBAN für DE muss 22 Zeichen ohne Leerzeichen enthalten.',$en)==='An IBAN for DE must contain 22 characters without spaces.','parameterised actual IBAN validation is English');$check(MemberBankMessages::text('Eigenes Mitglied Bankdaten',$en)==='Eigenes Mitglied Bankdaten','human source values are not translated');
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all selectors form refusals and language rendering preserve every whole original');
// Arrange a narrowly bound proof in this isolated database. Real account proofs
// and MFA are exercised by native-member-bank and the actual browser below.
$payload=['holder'=>'Original <holder> ä🙂','iban'=>'DE89370400440532013000','bic'=>''];$grant=bin2hex(random_bytes(32));$session=hash('sha256','owned-bank-result');$hash=MemberBankVault::payloadHash('save',$payload);
Db::run($db,'INSERT INTO member_bank_grants(token_hash,club_id,actor_id,contact_id,action,expected_version,payload_hash,session_hash,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',[hash('sha256',$grant),'bank-ui','bank-ui-member','ffef10496e835c7c97d276f4449674c9','save',0,$hash,$session,time()+300]);
$cfg=TestSupport::config();$connection=new class('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false])extends PDO{public bool $loseCommit=true;public function commit():bool{$ok=parent::commit();if($this->loseCommit){$this->loseCommit=false;throw new PDOException('Owned bank accepted COMMIT response lost');}return$ok;}};
$own=new MemberBankVault($connection,'bank-ui-member',$root.'/bank.key');$check($own->operationResult('ffef10496e835c7c97d276f4449674c9','save',0,$hash,$grant,$session)===null,'unused original proof is never called a stored bank result');
try{$own->save('ffef10496e835c7c97d276f4449674c9',0,$payload,$grant,$session);throw new RuntimeException('Lost COMMIT was not exercised');}catch(PDOException){$check(!$connection->inTransaction(),'actual bank COMMIT completes before its response is lost');}
$stored=ownOriginalDatabaseHash($db,$tables);$check($own->operationResult('ffef10496e835c7c97d276f4449674c9','save',0,$hash,$grant,$session)===['action'=>'save','stored'=>true],'immutable encrypted original recovers only its accepted operation without account plaintext');
$deny(fn()=>$own->save('ffef10496e835c7c97d276f4449674c9',0,$payload,$grant,$session),'saved proof remains consumed after result recovery');
foreach([fn()=>$own->operationResult('ffef10496e835c7c97d276f4449674c9','save',0,$hash,$grant,hash('sha256','other session')),fn()=>$own->operationResult('ffef10496e835c7c97d276f4449674c9','save',0,str_repeat('0',64),$grant,$session),fn()=>$own->operationResult('115f0793bd5a6918be111f1b351de66b','save',0,$hash,$grant,$session),fn()=>(new MemberBankVault($db,'bank-root',$root.'/bank.key'))->operationResult('ffef10496e835c7c97d276f4449674c9','save',0,$hash,$grant,$session)]as$i=>$fn)$deny($fn,'accepted result cannot cross original proof context '.$i);
$check(ownOriginalDatabaseHash($db,$tables)===$stored,'all accepted result reads and rejected replays preserve every whole original');
// Two independent real connections: a busy original cannot consume the new proof.
$lock=TestSupport::db();$lock->beginTransaction();$q=$lock->prepare('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE');$q->execute(['bank-ui',md5('bank-person-0')]);$newGrant=bin2hex(random_bytes(32));Db::run($db,'INSERT INTO member_bank_grants(token_hash,club_id,actor_id,contact_id,action,expected_version,payload_hash,session_hash,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',[hash('sha256',$newGrant),'bank-ui','bank-ui-member',md5('bank-person-0'),'save',1,$hash,$session,time()+300]);$lockedBefore=ownOriginalDatabaseHash($db,$tables);
try{(new MemberBankVault($db,'bank-ui-member',$root.'/bank.key'))->save(md5('bank-person-0'),1,$payload,$newGrant,$session);throw new RuntimeException('Busy original was overwritten');}catch(PDOException$error){$check(in_array((int)($error->errorInfo[1]??0),[1205,3572],true)&&!$db->inTransaction(),'actual competing bank row lock refuses immediately and rolls back');}finally{$lock->rollBack();}
$check(ownOriginalDatabaseHash($db,$tables)===$lockedBefore,'busy original preserves every whole row including the unused proof');
if(getenv('PLAYWRIGHT_MODULE'))require __DIR__.'/support/member-bank-browser-fixture.php';
echo json_encode(['suite'=>'native-member-bank-interface','checks'=>$n,'schema'=>135,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
