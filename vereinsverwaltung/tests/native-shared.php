<?php declare(strict_types=1);
require __DIR__.'/../native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Installer\Plan;
$db=TestSupport::db();$ids=TestSupport::seed($db);$n=0;
function ck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $f,string $label):void {try{$f();}catch(DomainException){ck(true,$label);return;}throw new RuntimeException($label);}
ck((int)$db->query('SELECT @@SESSION.sql_log_bin')->fetchColumn()===0,'isolated fixture connection does not retain synthetic data in server binary logs');
$evidenceOne=TestSupport::evidenceDirectory();$evidenceTwo=TestSupport::evidenceDirectory();
ck($evidenceOne!==$evidenceTwo&&is_dir($evidenceOne)&&is_dir($evidenceTwo),'separate private evidence folders never replace an earlier run');
ck((fileperms($evidenceOne)&0077)===0&&(fileperms($evidenceTwo)&0077)===0,'test evidence folders deny access to other operating-system users');
$oldEvidence=getenv('DVM_BROWSER_ARTIFACT_DIR');$publicEvidence=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/own-public-evidence-'.bin2hex(random_bytes(6));mkdir($publicEvidence,0700);chmod($publicEvidence,0777);putenv('DVM_BROWSER_ARTIFACT_DIR='.$publicEvidence);
try{TestSupport::evidenceDirectory();throw new RuntimeException('Public evidence directory accepted');}catch(RuntimeException$error){ck($error->getMessage()==='Nachweise benötigen einen eigenen privaten Ordner.','public temporary root cannot become the private evidence directory');}finally{putenv($oldEvidence===false?'DVM_BROWSER_ARTIFACT_DIR':'DVM_BROWSER_ARTIFACT_DIR='.$oldEvidence);rmdir($publicEvidence);}
rmdir($evidenceOne);rmdir($evidenceTwo);
$admin=new Access($db,$ids['admin']);$reader=new Access($db,$ids['reader']);
ck($admin->require('website','publish')['club_id']==='synthetic-a','explicit publishing grant');
denied(fn()=>$reader->require('website','edit'),'reader cannot edit');
denied(fn()=>$admin->require('invalid','read'),'unknown module denied');
ck(count($admin->directory('chat'))===3,'directory excludes other tenant');
Db::run($db,'UPDATE native_users SET active=0 WHERE id=?',[$ids['editor']]);
denied(fn()=>(new Access($db,$ids['editor']))->user(),'disabled actor denied');
ck(count($admin->directory('chat'))===2,'directory reflects revocation');
Db::run($db,'UPDATE native_users SET active=1 WHERE id=?',[$ids['editor']]);
try {Db::atomic($db,function()use($db){Db::run($db,"UPDATE native_users SET name='rollback' WHERE id='admin'");throw new DomainException('rollback');});}catch(DomainException){}
ck($admin->user()['name']==='Lokale Administration'&&!$db->inTransaction(),'failed operation rolls back');
$target=sys_get_temp_dir().'/kh-native-installer-'.bin2hex(random_bytes(8));mkdir($target);
$plan=Plan::inspect(__DIR__.'/../native',$target);ck($plan['can_stage']&&count($plan['files'])>4,'empty destination plan');
mkdir($target.'/native');file_put_contents($target.'/native/bootstrap.php','Existing installation');
$db->exec('CREATE TABLE IF NOT EXISTS khn_collision_test(id INT PRIMARY KEY) ENGINE=InnoDB');
$plan=Plan::inspect(__DIR__.'/../native',$target,$db);ck(!$plan['can_stage']&&in_array('native/bootstrap.php',$plan['file_conflicts'],true),'existing file collision blocks');
ck(in_array('native_users',$plan['table_conflicts'],true),'database table collision blocks');
ck(in_array('khn_collision_test',$plan['table_conflicts'],true),'independent communication table collision blocks');
ck(file_get_contents($target.'/native/bootstrap.php')==='Existing installation','preflight preserves existing file');
unlink($target.'/native/bootstrap.php');rmdir($target.'/native');rmdir($target);
echo json_encode(['suite'=>'native-shared','passed'=>$n,'driver'=>'mysql'],JSON_THROW_ON_ERROR).PHP_EOL;
