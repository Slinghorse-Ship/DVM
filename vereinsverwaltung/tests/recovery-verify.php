<?php declare(strict_types=1);
// Real MySQL archive verification; ProcessWire runDaily/native login is separate.
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/support/mysql-backup.php';
require __DIR__.'/../../processwire/site/src/VereinDatabase.php';
require __DIR__.'/../../processwire/site/src/BackupService.php';
require __DIR__.'/../../processwire/site/src/WorkerHealth.php';
use Kulturhus\Verein\{Database,Documents};
use Kulturhus\{BackupService,WorkerHealth};
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $run,string $label):void{try{$run();}catch(RuntimeException|JsonException){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$dir=sys_get_temp_dir().'/kh-recovery-verify-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700))throw new RuntimeException('Test directory unavailable');
$files=[$dir.'/source.sql',$dir.'/restored.sql',$dir.'/good.zip',$dir.'/corrupt.zip'];
$previousEnvironment=[];foreach(['VEREIN_DATABASE_USER'=>'root','VEREIN_DATABASE_PASSWORD'=>'isolated-regression-only'] as $key=>$value){$previousEnvironment[$key]=getenv($key);putenv($key.'='.$value);}
$db=$restored=null;
try{
 $db=\Kulturhus\Verein\Tests\MySqlFixture::connect($files[0]);Database::migrate($db);
 $db->exec("INSERT INTO clubs VALUES('test','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','test','members',1)");
 (new Documents($db,'admin'))->upload('example.txt','Synthetic original content',['title'=>'Synthetic document','scope'=>'orga','kind'=>'other'],bin2hex(random_bytes(16)));
 $schema=(int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
 \Kulturhus\VereinDatabase::backup($db,\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),$files[0]);
 $parts=['database.sql'=>'-- Synthetic website SQL fixture only','private/app-secret'=>'synthetic-secret','private/banking/enable-sandbox.key'=>'synthetic-key','verein/database.sql'=>file_get_contents($files[0])];
 // These bytes test archive verification, not runDaily's selection of private files.
 $privateParts=['private/verein/runtime-components.php'=>'<?php return [];',
  'private/verein/mysql-credentials.json'=>'{"synthetic_only":true}',
  'private/verein/cron-secret'=>'synthetic-cron-secret',
  'private/verein/member-bank-keys/v1.key'=>str_repeat('b',32)];
 $parts+=$privateParts;
 $manifest=['format'=>2,'verein_schema'=>$schema,'files'=>[]];
 foreach($parts as $name=>$bytes)$manifest['files'][$name]=['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
 $password=bin2hex(random_bytes(32));
 $archive=static function(string $path,array $contents,array $index)use($password):void{
  $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create test ZIP');
  foreach($contents as $name=>$bytes)$zip->addFromString($name,$bytes);
  $zip->addFromString('manifest.json',json_encode($index,JSON_THROW_ON_ERROR));$zip->setPassword($password);
  for($i=0;$i<$zip->numFiles;++$i)if(!$zip->setEncryptionIndex($i,ZipArchive::EM_AES_256))throw new RuntimeException('AES unavailable');
  if(!$zip->close())throw new RuntimeException('Cannot close test ZIP');
 };
 $archive($files[2],$parts,$manifest);$verified=BackupService::verify($files[2],$password);
 check($verified['files']===count($parts)&&$verified['verein_schema']===$schema,'real AES archive verifies every fixture and current schema');
 $zip=new ZipArchive();$zip->open($files[2]);
 check($zip->getFromName('database.sql')===false,'SQL cannot be read without password');
 $zip->setPassword($password);check($zip->getFromName('private/banking/enable-sandbox.key')==='synthetic-key','encrypted banking fixture preserved');
 foreach($privateParts as $name=>$bytes)check($zip->getFromName($name)===$bytes,'encrypted synthetic private fixture preserved: '.$name);
 check($zip->getFromName('verein/database.sql')===$parts['verein/database.sql'],'encrypted MySQL dump preserved exactly');
 file_put_contents($files[1],$zip->getFromName('verein/database.sql'));$zip->close();
 $restored=\Kulturhus\Verein\Tests\restoreArchivedMysql($files[1]);
 check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($restored)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($restored)[0]??false),'restored MySQL integrity and foreign keys');
 check((int)$restored->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===$schema,'restored schema matches manifest');
 $count=0;foreach($restored->query('SELECT bytes,sha256 FROM document_files') as $file){check(hash_equals($file['sha256'],hash('sha256',$file['bytes'])),'restored document checksum');++$count;}
 check($count===1,'restore verification includes a real document row');
 rejects(fn()=>BackupService::verify($files[2],str_repeat('0',64)),'wrong password rejected');
 $tampered=$parts;$tampered['database.sql']='tampered';$archive($files[3],$tampered,$manifest);
 rejects(fn()=>BackupService::verify($files[3],$password),'altered archive content rejected');
 $tampered=$parts;$tampered['private/verein/member-bank-keys/v1.key']=str_repeat('c',32);$archive($files[3],$tampered,$manifest);
 rejects(fn()=>BackupService::verify($files[3],$password),'altered synthetic member-bank key rejected');
 $previous=['last_success_at'=>'2026-09-05T03:00:00Z','consecutive_failures'=>1];$now='2026-09-06T04:00:00Z';
 check(WorkerHealth::task(['status'=>'skipped'],$previous,$now)['last_success_at']===$previous['last_success_at'],'skipped backup preserves successful-run history');
 check(WorkerHealth::task(['status'=>'ok','nested'=>['errors'=>['test']]],$previous,$now)['consecutive_failures']===2,'nested backup failure remains visible');
 echo "$checks MySQL backup verification checks passed; ProcessWire runDaily/native login remains a separate integration test\n";
}finally{
 $db=$restored=null;
 foreach($files as $file)if(is_file($file))unlink($file);
 foreach($previousEnvironment as $key=>$value)putenv($value===false?$key:$key.'='.$value);
 rmdir($dir);
}
