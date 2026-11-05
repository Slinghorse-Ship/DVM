<?php declare(strict_types=1);
if (in_array($argv[1]??'',['--crash-copy','--crash-sql','--crash-finish'],true)) {
    if (getenv('KH_NATIVE_CI')!=='1') exit(1);
    require dirname(__DIR__).'/native/bootstrap.php';
    $fixture=\Kulturhus\Native\TestSupport::config();
    if ($fixture['label']!=='native-restore-resume') exit(1);
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    if (!preg_match('/dbname=(kh_native_[a-f0-9]{24})(?:;|$)/',$input['config']['dsn'],$match)) exit(1);
    $db=new PDO($input['config']['dsn'],$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $point=match($argv[1]){'--crash-copy'=>'copy-chunk','--crash-sql'=>'sql-statement','--crash-finish'=>'restore-completed-saved'};
    $checkpoint=static function(string $event)use($point,$db):void{
        if ($event!==$point || ($point==='sql-statement'&&(int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()<3)) return;
        echo "OWN_RESTORE_CHECKPOINT\n";flush();while(true)usleep(100000);
    };
    (new \Kulturhus\Native\Installer\Recovery(dirname(__DIR__,2),$checkpoint))->restore($input['archive'],$input['key'],$input['config'],$db);exit(1);
}
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Installer\{Install,Recovery,RestoreProgress};
use Kulturhus\Native\Shared\{SecretBox,OperationGate};
$source=dirname(__DIR__,2);$db=TestSupport::db();$fixture=TestSupport::config();
$work=sys_get_temp_dir().'/dvm-restore-resume-'.bin2hex(random_bytes(10));mkdir($work,0700);
$root=$work.'/original';$archive=$work.'/original.zip';$key=bin2hex(random_bytes(32));$n=0;$owned=[];
$admin=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function resumeCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo'PASS '.$label."\n";}
function resumeReject(callable$f,string$label):void{try{$f();}catch(DomainException|RuntimeException){resumeCheck(true,$label);return;}throw new RuntimeException($label);}
$fresh=static function(string$name)use($work,$fixture,$admin,&$owned):array{
    $database='kh_native_'.bin2hex(random_bytes(12));$owned[]=$database;$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $dsn='mysql:host=127.0.0.1;port=13371;dbname='.$database.';charset=utf8mb4';
    return [new PDO($dsn,$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]),['target'=>$work.'/'.$name,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password']]];
};
$kill=static function(string$option,array$config)use($archive,$key):void{
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,$option],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own crash subprocess unavailable.');
    fwrite($pipes[0],json_encode(['archive'=>$archive,'key'=>$key,'config'=>$config],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $line=fgets($pipes[1]);if(trim($line?:'')!=='OWN_RESTORE_CHECKPOINT'){ $error=stream_get_contents($pipes[2]);throw new RuntimeException('Actual restore did not reach checkpoint: '.$error); }
    $status=proc_get_status($process);posix_kill($status['pid'],SIGKILL);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
};
try{
    $initial=['target'=>$root,'dsn'=>'mysql:host=127.0.0.1;port=13371;dbname='.$fixture['database'].';charset=utf8mb4','username'=>$fixture['username'],'password'=>$fixture['password'],'club_id'=>'restore-resume-test','club_name'=>'Own restore resume club','hosts'=>'resume.example','admin_email'=>'resume@example.invalid','admin_password'=>'Own synthetic resume password 2026!'];
    (new Install($source))->run($initial,$db);
    $db->exec('CREATE TABLE own_restore_sources (id INT PRIMARY KEY,payload MEDIUMBLOB NOT NULL,counter INT NOT NULL) ENGINE=InnoDB');
    $large=str_repeat("Own binary restore original\0ä🙂",100000);$insert=$db->prepare('INSERT INTO own_restore_sources VALUES(1,?,7)');$insert->execute([$large]);$largeHash=hash('sha256',$large);unset($large);
    file_put_contents($root.'/private/media/own-retained-original.txt','Original untouched private media bytes');
    $auth=\Kulturhus\Native\Auth\Runtime::service($db,$root.'/private/auth.key');$login=$auth->passwordLogin($initial['admin_email'],$initial['admin_password'],'own-before-restore');
    $access=\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,$auth,$login['session_token'])->access();
    $box=SecretBox::fromFile($root.'/private/private.key');$message=['to'=>'original-resume@example.invalid','subject'=>'Original resumed encrypted mail ä🙂','html'=>'<p>Original encrypted data</p>','text'=>'Original encrypted data'];
    $job=(new \Kulturhus\Native\Delivery\Outbox($db,$box))->enqueue($access->club(),'test','own-resume-original',$message,'2050-10-25 18:30:00',$access);$query=$db->prepare("UPDATE native_delivery_jobs SET status='processing' WHERE id=?");$query->execute([$job]);
    $query=$db->prepare('SELECT ciphertext FROM native_delivery_jobs WHERE id=?');$query->execute([$job]);$cipher=$query->fetchColumn();
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$originalHash=ownOriginalDatabaseHash($db,$tables);$keyHash=hash_file('sha256',$root.'/private/private.key');
    $service=new Recovery($source);$service->create($root,$db,$initial['dsn'],$archive,$key);
    [$copyDb,$copyConfig]=$fresh('copy-interrupted');$kill('--crash-copy',$copyConfig);
    $progress=new RestoreProgress($copyConfig['target'],$key);$state=$progress->read();$prefix=$copyConfig['target'].'/private/database.restore.sql';
    resumeCheck($state['phase']==='files'&&$state['current_file']==='database.sql'&&filesize($prefix)>0&&filesize($prefix)<=65536,'actual killed restore leaves signed current member and exact bounded partial SQL prefix');
    $prefixBytes=file_get_contents($prefix);$prefixHash=hash('sha256',$prefixBytes);
    resumeReject(fn()=>$service->restore($archive,$key,$copyConfig,$copyDb),'without explicit resume existing owned root remains protected');
    resumeReject(fn()=>$service->restore($archive,str_repeat('a',64),$copyConfig,$copyDb,true),'different archive key cannot claim partial destination');
    resumeReject(fn()=>$service->restore($archive,$key,array_replace($copyConfig,['hosts'=>'other.example']),$copyDb,true),'changed original configuration cannot claim partial destination');
    [$foreignDb,$foreignConfig]=$fresh('foreign-connection');
    resumeReject(fn()=>$service->restore($archive,$key,array_replace($copyConfig,['dsn'=>$foreignConfig['dsn']]),$foreignDb,true),'foreign matching connection and DSN cannot retarget signed original intent');
    resumeCheck($foreignDb->query('SHOW TABLES')->fetchAll()===[]&&!is_dir($foreignConfig['target']),'foreign database and unrelated target remain untouched');
    $copyDb->exec("UPDATE native_restore_operation SET intent='foreign-existing-operation' WHERE id=1");
    resumeReject(fn()=>$service->restore($archive,$key,$copyConfig,$copyDb,true),'foreign database marker cannot be adopted by resume');$restoreMarker=$copyDb->prepare('UPDATE native_restore_operation SET intent=? WHERE id=1');$restoreMarker->execute([$state['intent']]);
    $changed=$prefixBytes;$changed[128]=chr(ord($changed[128])^1);file_put_contents($prefix,$changed);
    resumeReject(fn()=>$service->restore($archive,$key,$copyConfig,$copyDb,true),'changed own partial bytes cannot be appended or overwritten');
    resumeReject(fn()=>$service->restore($archive,$key,$copyConfig,$copyDb,true),'repeated refusal cannot turn changed prefix into an owned original');
    resumeCheck(file_get_contents($prefix)===$changed,'every rejected continuation leaves exact changed bytes untouched');file_put_contents($prefix,$prefixBytes);
    $result=$service->restore($archive,$key,$copyConfig,$copyDb,true);
    resumeCheck($result['restored']&&$result['background_jobs']==='held-for-review'&&!is_file($copyConfig['target'].'/restore-progress.json'),'same intent resumes actual original prefix and completes real restore with retained review hold');
    resumeCheck(hash_file('sha256',$copyConfig['target'].'/private/private.key')===$keyHash&&hash('sha256',$copyDb->query('SELECT payload FROM own_restore_sources WHERE id=1')->fetchColumn())===$largeHash,'resumed file and SQL import preserve exact original binary row and application key');
    $query=$copyDb->prepare('SELECT ciphertext,status,reason FROM native_delivery_jobs WHERE id=?');$query->execute([$job]);$row=$query->fetch();
    resumeCheck($row['ciphertext']===$cipher&&$row['status']==='uncertain'&&SecretBox::fromFile($copyConfig['target'].'/private/private.key')->open('job:'.$access->club().':'.$job,$cipher)===$message,'original processing delivery keeps exact ciphertext and becomes uncertain without transport');
    resumeReject(fn()=>\Kulturhus\Native\Auth\Runtime::service($copyDb,$copyConfig['target'].'/private/auth.key')->resolve($login['session_token']),'old authentication session is revoked after restored data is complete');
    $beforeDone=ownOriginalDatabaseHash($copyDb,$tables);$service->restore($archive,$key,$copyConfig,$copyDb,true);
    resumeCheck(ownOriginalDatabaseHash($copyDb,$tables)===$beforeDone,'accepted completed resume acknowledgement never reimports or changes original rows');
    [$sqlDb,$sqlConfig]=$fresh('sql-interrupted');$kill('--crash-sql',$sqlConfig);$partial=$sqlDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    resumeCheck(count($partial)>1&&count($partial)<count($tables),'actual killed import leaves real partial original tables plus ownership marker');
    $sqlDb->exec('CREATE TABLE own_foreign_intervention (id INT PRIMARY KEY,value VARCHAR(80)) ENGINE=InnoDB');$sqlDb->exec("INSERT INTO own_foreign_intervention VALUES(1,'Retain every foreign byte')");
    resumeReject(fn()=>$service->restore($archive,$key,$sqlConfig,$sqlDb,true),'new foreign table stops partial SQL reset before any original drop');
    resumeCheck($sqlDb->query('SELECT value FROM own_foreign_intervention WHERE id=1')->fetchColumn()==='Retain every foreign byte'&&count($sqlDb->query('SHOW TABLES')->fetchAll())===count($partial)+1,'foreign row and every earlier partial table remain unchanged after refused SQL continuation');$sqlDb->exec('DROP TABLE own_foreign_intervention');
    $result=$service->restore($archive,$key,$sqlConfig,$sqlDb,true);resumeCheck($result['restored']&&hash('sha256',$sqlDb->query('SELECT payload FROM own_restore_sources WHERE id=1')->fetchColumn())===$largeHash,'same original SQL allowlist safely resumes after actual import termination');
    [$finishDb,$finishConfig]=$fresh('finish-interrupted');$kill('--crash-finish',$finishConfig);
    resumeCheck((new RestoreProgress($finishConfig['target'],$key))->read()['phase']==='done'&&is_file($finishConfig['target'].'/private/restore-hold'),'actual accepted final receipt survives process kill before durable maintenance release');
    resumeReject(fn()=>OperationGate::shared($finishConfig['target'].'/private'),'durable restore hold rejects native requests after process gate disappears');
    resumeReject(fn()=>$service->release($finishConfig['target'],$finishDb,true),'operator review cannot prematurely release an interrupted final restore');
    $render=$work.'/render-maintenance.php';file_put_contents($render,'<?php require '.var_export($finishConfig['target'].'/web/index.php',true).';');
    $process=proc_open([...TestSupport::phpCommand(),$render],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);resumeCheck(proc_close($process)===0&&str_contains($body,'Kurze Wartung')&&$errors==='','real early entrypoint serves maintenance for accepted but not yet released restore');
    $expectedBeforeFinish=[...$tables,RestoreProgress::MARKER];sort($expectedBeforeFinish,SORT_STRING);
    resumeCheck($finishDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$expectedBeforeFinish,'accepted finish contains precisely all original tables and its own temporary marker');
    $finishState=(new RestoreProgress($finishConfig['target'],$key))->read();
    $beforeFinish=ownOriginalDatabaseHash($finishDb,$tables,null,true);$result=$service->restore($archive,$key,$finishConfig,$finishDb,true);
    resumeCheck($result['restored']&&ownOriginalDatabaseHash($finishDb,$tables,null,true)===$beforeFinish&&!is_file($finishConfig['target'].'/private/restore-hold'),'lost accepted final acknowledgement releases only own maintenance without repeating import or revocation');
    $finishedState=(new RestoreProgress($finishConfig['target'],$key))->read(true);
    resumeCheck($finishedState===$finishState&&$result['credentials']===$finishState['credentials'],'accepted final resume preserves every signed state field and original credential review report');
    resumeCheck($finishDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$tables&&is_file($finishConfig['target'].'/private/recovery-hold'),'transient provenance table is removed while original schema and operator review hold remain');
    $keyFile=$work.'/archive.key';file_put_contents($keyFile,$key);chmod($keyFile,0600);
    $process=proc_open([...TestSupport::phpCommand(),$source.'/distribution/recover-native.php','--config-stdin','--restore','--resume'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($finishConfig+['archive'=>$archive,'key_file'=>$keyFile],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);resumeCheck(proc_close($process)===0&&json_decode($output,true)['restored']&&$errors==='','actual private CLI resumes completed intent using stdin and separately protected archive key file');
    resumeCheck(ownOriginalDatabaseHash($db,$tables)===$originalHash&&hash_file('sha256',$root.'/private/private.key')===$keyHash,'every interruption, rejection, resume and private CLI leaves full original source data and key unchanged');
    echo json_encode(['checks'=>$n,'actual_process_kills'=>3,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{
    foreach($owned as$name)$admin->exec('DROP DATABASE `'.$name.'`');
    if(is_dir($work)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($work);}
}
