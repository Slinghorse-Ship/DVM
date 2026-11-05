<?php declare(strict_types=1);
namespace ProcessWire;
require_once __DIR__.'/support/mysql-backup.php';
if(getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only'||getenv('KULTURHUS_LOCAL_DEMO')!=='1'||getenv('KULTURHUS_DISABLE_MAIL')!=='1'||PHP_SAPI!=='cli')throw new \RuntimeException('BLOCKED: full synthetic ProcessWire fixture on mysql-test with disabled mail required; the MySQL suite does not provision it.');
foreach(['/var/www/html/vendor/autoload.php','/var/www/html/wire/core/ProcessWire.php','/var/www/html/site/config.php'] as $required)if(!is_file($required))throw new \RuntimeException('BLOCKED: full isolated ProcessWire fixture missing: '.$required);
require '/var/www/html/vendor/autoload.php';require '/var/www/html/wire/core/ProcessWire.php';
$nativeConfig=ProcessWire::buildConfig('/var/www/html');
if($nativeConfig->dbHost!=='mysql-test'||!preg_match('/^kh_test_[a-f0-9]{24}$/D',(string)$nativeConfig->dbName)||$nativeConfig->dbUser!=='root'||$nativeConfig->dbPass!=='isolated-regression-only'||$nativeConfig->kulturhusLocalDataOrigin!=='synthetic')throw new \RuntimeException('BLOCKED: ProcessWire must use an already provisioned isolated synthetic kh_test_* database on mysql-test.');
$previousEnvironment=[];foreach(['VEREIN_DATABASE_USER'=>'root','VEREIN_DATABASE_PASSWORD'=>'isolated-regression-only'] as $key=>$value){$previousEnvironment[$key]=getenv($key);putenv($key.'='.$value);}
require_once '/var/www/html/site/src/NativeSessionStorage.php';
$nativeConfig->kulturhusNativeSessionPath=rtrim(getenv('KULTURHUS_RECOVERY_TEST_ROOT')?:sys_get_temp_dir(),'/').'/recovery-source-sessions-'.bin2hex(random_bytes(6));
\Kulturhus\NativeSessionStorage::configure($nativeConfig);
$wire=new ProcessWire($nativeConfig);
\Kulturhus\NativeSessionStorage::assertActive($nativeConfig);
require_once '/var/www/html/site/src/BackupService.php';
require_once '/var/www/html/site/src/WorkerHealth.php';
require __DIR__.'/../src/bootstrap.php';
$wire=wire();
if($wire->config->kulturhusLocalDataOrigin!=='synthetic')throw new \RuntimeException('Fresh synthetic ProcessWire database required');
$loginName='recovery-'.bin2hex(random_bytes(8));$loginPassword=bin2hex(random_bytes(32));
$loginUser=new User();$loginUser->name=$loginName;$loginUser->email=$loginName.'@example.invalid';$loginUser->pass=$loginPassword;$loginUser->addRole('guest');$loginUser->save();
$root=rtrim(getenv('KULTURHUS_RECOVERY_TEST_ROOT')?:sys_get_temp_dir(),'/').'/kh-recovery-test-'.bin2hex(random_bytes(6));
$target=$root.'/archives';mkdir($target,0700,true);
$privateRoot=$root.'/private-runtime';mkdir($privateRoot,0700);mkdir($root.'/explicit-bank-key',0700);
foreach(['BACKUP_TARGET_PATH'=>$target,'VEREIN_PRIVATE_ROOT'=>$privateRoot,'VEREIN_MEMBER_BANK_KEY_PATH'=>$root.'/explicit-bank-key/current.key','VEREIN_MEMBER_BANK_KEY_VERSION'=>'1','VEREIN_PUSH_KEY_PATH'=>$privateRoot.'/push-vapid.json'] as $key=>$value){$previousEnvironment[$key]=getenv($key);putenv($key.'='.$value);}
$privateParts=['runtime-components.php'=>'<?php return [];','mysql-credentials.json'=>'{"synthetic_only":true}','cron-secret'=>'synthetic-cron-secret'];
foreach($privateParts as $name=>$bytes){file_put_contents($privateRoot.'/'.$name,$bytes);chmod($privateRoot.'/'.$name,0600);}
file_put_contents($root.'/explicit-bank-key/current.key',str_repeat('b',32));chmod($root.'/explicit-bank-key/current.key',0600);
mkdir($privateRoot.'/kulturhus-banking',0700);file_put_contents($privateRoot.'/kulturhus-banking/enable-sandbox.key','test-bank-private-key');
file_put_contents($privateRoot.'/kulturhus-banking/fints.json','{"club_id":"audit","product_id":"synthetic-only"}');
file_put_contents($privateRoot.'/kulturhus-banking/fints-state.key',str_repeat('x',32));
file_put_contents($privateRoot.'/kulturhus-banking/dialog-ephemeral.enc','do-not-restore-an-active-bank-dialog');
$previousPath=$wire->config->kulturhusVereinDatabase;
$source=\Kulturhus\Verein\Tests\MySqlFixture::connect();
$wire->config->kulturhusVereinDatabase=\Kulturhus\Verein\Tests\MySqlFixture::dsn($source);
\Kulturhus\Verein\Database::migrate($source);
$source->exec("INSERT INTO clubs VALUES('audit','Synthetic recovery');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('audit-admin','audit','members',1)");
$recoveryDocument=(new \Kulturhus\Verein\Documents($source,'audit-admin'))->upload('recovery-example.txt','Synthetic document bytes to restore',['title'=>'Recovery test','scope'=>'orga','kind'=>'other'],bin2hex(random_bytes(16)));
$source->prepare('INSERT INTO processwire_identities(pw_user_id,actor_id,enabled,version) VALUES(?,?,1,1)')->execute([(int)$loginUser->id,'audit-admin']);
$mailOriginal="From: recovery@example.invalid\r\nSubject: Synthetic recovery correspondence\r\n\r\nNo real person or invoice.\r\n";
$source->prepare('INSERT INTO invoice_mail_originals VALUES(?,?,?,?,?,?)')->execute(['audit','recovery-mailbox','1:1',hash('sha256',$mailOriginal),$mailOriginal,gmdate('c')]);
$source->prepare('INSERT INTO invoice_mail_only(club_id,id,mailbox_key,message_uid,sender,subject,received_at,imported_at,state,version) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(['audit','recovery-correspondence','recovery-mailbox','1:1','recovery@example.invalid','Synthetic recovery correspondence','2026-09-07',gmdate('c'),'archived',3]);
$expectedSchema=(int)$source->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
$source=null;
$mediaName='native-recovery-'.bin2hex(random_bytes(8)).'.png';
$mediaPath=rtrim($wire->config->paths->files,'/').'/'.$mediaName;
$mediaBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
file_put_contents($mediaPath,$mediaBytes);
try{
 $sourceLogin=$wire->session->login($loginName,$loginPassword);
 if(!$sourceLogin||!$sourceLogin->isLoggedin())throw new \RuntimeException('Synthetic source login failed');
 $sessionMarker='synthetic-session-not-for-backup-'.bin2hex(random_bytes(24));
 $_SESSION['recovery_test_marker']=$sessionMarker;
 $sourceSessionFile=rtrim(session_save_path(),'/').'/sess_'.session_id();
 session_write_close();
 if(!is_file($sourceSessionFile)||!str_contains((string)file_get_contents($sourceSessionFile),$sessionMarker))throw new \RuntimeException('Actual synthetic session file required');
 session_start();
 $result=(new \Kulturhus\BackupService($wire))->runDaily(true);
 $archive=$target.'/'.$result['archive'];$password=trim(file_get_contents($root.'/.kulturhus-backup-key'));
 $verified=\Kulturhus\BackupService::verify($archive,$password);
 if($verified['verein_schema']!==$expectedSchema)throw new \RuntimeException('Current schema missing');
 $zip=new \ZipArchive();$zip->open($archive);
 if($zip->getFromName('database.sql')!==false)throw new \RuntimeException('SQL readable without password');
 $zip->setPassword($password);
 for($index=0;$index<$zip->numFiles;$index++){
  $archiveName=(string)$zip->getNameIndex($index);
  if(preg_match('~(?:^|/)(?:sessions?|sess_[^/]*)(?:/|$)~i',$archiveName)||str_contains((string)$zip->getFromIndex($index),$sessionMarker))throw new \RuntimeException('Authenticated session state must not be backed up');
 }
 if($zip->getFromName('private/banking/enable-sandbox.key')!=='test-bank-private-key')throw new \RuntimeException('Banking key missing');
 foreach($privateParts as $name=>$bytes)if($zip->getFromName('private/verein/'.$name)!==$bytes)throw new \RuntimeException('Private runtime fixture missing: '.$name);
 if($zip->getFromName('private/verein/member-bank-keys/v1.key')!==str_repeat('b',32))throw new \RuntimeException('Explicit synthetic member-bank key missing');
 if($zip->getFromName('private/banking/fints-state.key')!==str_repeat('x',32)||$zip->getFromName('private/banking/fints.json')!=='{"club_id":"audit","product_id":"synthetic-only"}')throw new \RuntimeException('Private FinTS setup missing from recovery');
 if($zip->getFromName('private/banking/dialog-ephemeral.enc')!==false)throw new \RuntimeException('Ephemeral bank dialog must require fresh authentication after restore');
 $restoredMedia=$zip->getFromName('site-assets-files/'.$mediaName);
 if($restoredMedia!==$mediaBytes)throw new \RuntimeException('Website image missing or changed');
 file_put_contents($root.'/restored-image.png',$restoredMedia);
 $dbFile=$root.'/verein-restored.sql';$vereinDump=$zip->getFromName('verein/database.sql');
 if(!is_string($vereinDump)||$vereinDump==='')throw new \RuntimeException('Archived association MySQL dump missing');
 file_put_contents($dbFile,$vereinDump);chmod($dbFile,0600);unset($vereinDump);
 $sqlFile=$root.'/database.sql';file_put_contents($sqlFile,$zip->getFromName('database.sql'));chmod($sqlFile,0600);
 $savedConfig=$zip->getFromName('website/site-config.php');$savedSecret=$zip->getFromName('private/app-secret');
 if(!is_string($savedConfig)||!is_string($savedSecret)||strlen($savedSecret)<32)throw new \RuntimeException('Restored authentication configuration missing');
 file_put_contents($root.'/restored-config.php',$savedConfig);chmod($root.'/restored-config.php',0600);$zip->close();
 $db=\Kulturhus\Verein\Tests\restoreArchivedMysql($dbFile);
 if(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)!=='ok'||(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false))throw new \RuntimeException('Restored DB invalid');
 $documentCount=0;
 foreach($db->query('SELECT bytes,sha256 FROM document_files') as $file){if(!hash_equals($file['sha256'],hash('sha256',$file['bytes'])))throw new \RuntimeException('Document content changed');++$documentCount;}
 if($documentCount<1)throw new \RuntimeException('Document recovery fixture missing');
 $restoredMail=$db->query("SELECT m.state,m.version,o.bytes,o.sha256 FROM invoice_mail_only m JOIN invoice_mail_originals o USING(club_id,mailbox_key,message_uid) WHERE m.club_id='audit' AND m.id='recovery-correspondence'")->fetch(\PDO::FETCH_ASSOC);
 if(!$restoredMail||$restoredMail['state']!=='archived'||(int)$restoredMail['version']!==3||$restoredMail['bytes']!==$mailOriginal||!hash_equals($restoredMail['sha256'],hash('sha256',$mailOriginal)))throw new \RuntimeException('Schema48 correspondence/original did not restore exactly');
 // The test helper owns CREATE/DROP only for isolated kh_test_* databases.
 // Never drop or import over the source database or another existing database.
 $restored=\Kulturhus\Verein\Tests\MySqlFixture::connect();$restoreName=(string)$restored->query('SELECT DATABASE()')->fetchColumn();
 $restored->exec("SET SESSION sql_mode=''");$restored->exec('SET FOREIGN_KEY_CHECKS=0');
 $restore=new WireDatabaseBackup($root);$restore->setDatabase($restored);
 if(!$restore->restore($sqlFile,['allowDrop'=>false,'haltOnErrors'=>true,'useExec'=>false])||$restore->errors())throw new \RuntimeException('MySQL dump restore failed');
 $restored->exec('SET FOREIGN_KEY_CHECKS=1');
 $fingerprints=static function($connection):array{
  $tables=$connection->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);sort($tables);$out=[];
  foreach($tables as $table){$rows=[];foreach($connection->query('SELECT * FROM `'.str_replace('`','``',$table).'`')->fetchAll(\PDO::FETCH_ASSOC) as $row){ksort($row);$row=array_map(static fn($value)=>$value===null?null:(string)$value,$row);$rows[]=hash('sha256',serialize($row));}sort($rows);$out[$table]=['rows'=>count($rows),'digest'=>hash('sha256',implode('|',$rows))];}return $out;
 };
 $sourceState=$fingerprints($wire->database);$restoredState=$fingerprints($restored);
 if(!$sourceState||$sourceState!==$restoredState)throw new \RuntimeException('Restored MySQL tables/rows differ from source');
 $loginInput=['root'=>$root,'database'=>$restoreName,'verein_dsn'=>\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),'name'=>$loginName,'password'=>$loginPassword,'app_secret'=>$savedSecret,'user_id'=>(int)$loginUser->id,'document_id'=>$recoveryDocument];
 $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/support/recovery-native-login.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new \RuntimeException('Native restore login process unavailable');
 fwrite($pipes[0],json_encode($loginInput,JSON_THROW_ON_ERROR));fclose($pipes[0]);unset($loginInput,$loginPassword,$savedSecret);
 $loginOutput=stream_get_contents($pipes[1]);fclose($pipes[1]);$loginError=stream_get_contents($pipes[2]);fclose($pipes[2]);$loginStatus=proc_close($process);
 $loginChecks=json_decode($loginOutput,true);
 if($loginStatus!==0||!is_array($loginChecks)||empty($loginChecks['native_login'])||empty($loginChecks['document_read'])||empty($loginChecks['wrong_password_rejected'])||empty($loginChecks['disabled_mapping_rejected']))throw new \RuntimeException('Native restored authentication test failed: '.substr($loginError,0,1000));
 echo json_encode(['restored_authentication'=>$loginChecks],JSON_THROW_ON_ERROR)."\n";
 try{\Kulturhus\BackupService::verify($archive,str_repeat('0',64));throw new \RuntimeException('Wrong key accepted');}catch(\RuntimeException $e){if($e->getMessage()==='Wrong key accepted')throw $e;}
 $previous=['last_success_at'=>'2026-09-05T03:00:00Z','consecutive_failures'=>1];$now='2026-09-06T04:00:00Z';
 $health=\Kulturhus\WorkerHealth::task(['status'=>'skipped'],$previous,$now);if($health['last_success_at']!==$previous['last_success_at'])throw new \RuntimeException('Skipped task loses backup history');
 $health=\Kulturhus\WorkerHealth::task(['status'=>'ok','nested'=>['errors'=>['test']]],$previous,$now);if($health['consecutive_failures']!==2)throw new \RuntimeException('Nested error hidden');
 file_put_contents($root.'/result.json',json_encode(['encrypted'=>true,'verified_files'=>$verified['files'],'schema'=>$verified['verein_schema'],'verein_mysql_restored'=>true,'documents_verified'=>$documentCount,'mail_only_original_restored'=>true,'mail_only_state_version_restored'=>true,'website_image_restored'=>true,'mysql_tables_restored'=>count($sourceState),'mysql_rows_verified'=>array_sum(array_column($sourceState,'rows')),'wrong_key_rejected'=>true,'health_checks'=>true,'archive_bytes'=>$result['bytes']],JSON_PRETTY_PRINT));
 $report=json_decode((string)file_get_contents($root.'/result.json'),true,512,JSON_THROW_ON_ERROR);$report['restored_authentication']=$loginChecks;$report['active_session_excluded']=true;file_put_contents($root.'/result.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo file_get_contents($root.'/result.json')."\n";
}finally{
 $wire->config->kulturhusVereinDatabase=$previousPath;
 if(is_file($mediaPath))unlink($mediaPath);
 if($wire->user->isLoggedin())$wire->session->logout();
 if($loginUser->id)$wire->users->delete($loginUser);
 foreach($previousEnvironment as $key=>$value)putenv($value===false?$key:$key.'='.$value);
}
