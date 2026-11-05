<?php declare(strict_types=1);
namespace ProcessWire { final class Wire { public function __construct(public object $config) {} } }
namespace Kulturhus\Verein {
    // Separate process exits inside the writer: finally cannot clean up on abrupt termination.
    if (($GLOBALS['argv'][1] ?? '') === '--crash-child') {
        final class MySqlBackup { public static function export(\PDO $db,string $dsn,callable $write):void{$write('SYNTHETIC-PLAINTEXT-MUST-NOT-REMAIN');exit(77);} }
    }
}
namespace {
if (($argv[1] ?? '') === '--crash-child') {
    if (getenv('VEREIN_TEST_MYSQL') !== 'isolated-regression-only') exit(2);
    require __DIR__.'/../src/Infrastructure/Database/EncryptedMySqlBackup.php';
    \Kulturhus\Verein\EncryptedMySqlBackup::create(new class extends PDO {public function __construct(){}},'synthetic', $argv[2],str_repeat('a',64));exit(3);
}
require_once __DIR__.'/mysql-bootstrap.php';
require_once __DIR__.'/support/mysql-backup.php';
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../../processwire/site/src/BackupService.php';
use Kulturhus\BackupService;
use Kulturhus\Verein\{EncryptedMySqlBackup as Encrypted, MySqlBackup};
use Kulturhus\Verein\Tests\MySqlFixture;
use function Kulturhus\Verein\Tests\restoreArchivedMysql;
if (PHP_SAPI !== 'cli' || getenv('VEREIN_TEST_MYSQL') !== 'isolated-regression-only') throw new RuntimeException('Isolated test runner required');
$n=0;
function dailyCheck(bool $ok,string $label):void { global $n; if(!$ok)throw new RuntimeException($label); ++$n; echo "PASS $label\n"; }
function dailyReject(callable $fn,string $label):void { try{$fn();}catch(Throwable){dailyCheck(true,$label);return;}throw new RuntimeException($label); }
$root=sys_get_temp_dir().'/kh-daily-'.bin2hex(random_bytes(8));mkdir($root,0700);
$env=getenv('BACKUP_TARGET_PATH');$db=null;
try {
    mkdir($root.'/web',0700);mkdir($root.'/archives',0700);mkdir($root.'/old',0700);
    $db=MySqlFixture::connect();$dsn=MySqlFixture::dsn($db);
    $db->exec("CREATE TABLE items(id INT PRIMARY KEY,title VARCHAR(255),data LONGBLOB,amount INT NOT NULL CHECK(amount>0),doubled INT GENERATED ALWAYS AS(amount*2) STORED) ENGINE=InnoDB");
    $db->exec("CREATE TABLE changes(id INT PRIMARY KEY,item_id INT,FOREIGN KEY(item_id) REFERENCES items(id)) ENGINE=InnoDB");
    $db->exec('CREATE TRIGGER items_changed AFTER INSERT ON items FOR EACH ROW INSERT INTO changes VALUES(NEW.id,NEW.id)');
    $blob=str_repeat("Synthetic €\0\xFF'\\\r\n",20000);
    $q=$db->prepare('INSERT INTO items(id,title,data,amount) VALUES(?,?,?,?)');$q->execute([1,'Synthetic – Büro',$blob,42]);$q->execute([2,null,null,7]);
    $before=MySqlFixture::snapshot($db);
    $date=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $legacy=$root.'/old/verein-'.$date.'.sql';file_put_contents($legacy,'Synthetic prior backup – retain');
    $legacyHash=hash_file('sha256',$legacy);
    putenv('BACKUP_TARGET_PATH='.$root.'/archives');
    $service=new BackupService(new \ProcessWire\Wire((object)['paths'=>(object)['root'=>$root.'/web/']]));
    $result=$service->runVereinDaily($db,$dsn);$archive=$root.'/archives/'.$result['archive'];
    $key=trim(file_get_contents($root.'/.kulturhus-backup-key'));
    dailyCheck($result['created']&&$result['encrypted']&&$result['verified'],'daily worker boundary creates authenticated encrypted snapshot');
    dailyCheck(strlen($key)===64&&substr(file_get_contents($archive),0,8)==="KHSQL01\n",'versioned format and existing recovery-key convention');
    dailyCheck(!str_contains(file_get_contents($archive),'CREATE TABLE')&&!glob($root.'/archives/*.sql')&&!glob($root.'/archives/*.partial-*'),'no SQL or partial plaintext file created');
    dailyCheck((fileperms($archive)&0777)===0600&&(fileperms($root.'/archives')&0777)===0700&&(fileperms($root.'/.kulturhus-backup-key')&0777)===0600,'archive, directory and key permissions restricted');
    dailyCheck(MySqlFixture::snapshot($db)===$before&&hash_file('sha256',$legacy)===$legacyHash,'source database and existing plain backup unchanged');
    $archiveHash=hash_file('sha256',$archive);
    dailyCheck(!$service->runVereinDaily($db,$dsn)['created']&&hash_file('sha256',$archive)===$archiveHash,'same-day retry verifies and preserves archive bytes');
    Encrypted::decryptTo($archive,$key,$root.'/restored.sql');
    dailyCheck(hash_file('sha256',$root.'/restored.sql')===$result['sha256'],'authenticated decryption matches snapshot hash');
    $restored=restoreArchivedMysql($root.'/restored.sql');
    dailyCheck(MySqlFixture::snapshot($restored)===$before,'restored rows, Unicode, binary, nulls and generated columns match');
    $restored->exec("INSERT INTO items(id,title,amount) VALUES(3,'Restored trigger',10)");
    dailyCheck((int)$restored->query('SELECT COUNT(*) FROM changes WHERE id=3')->fetchColumn()===1,'restored trigger remains functional');
    dailyReject(fn()=>$restored->exec('INSERT INTO items(id,amount) VALUES(4,-1)'),'restored CHECK constraint remains enforced');
    dailyReject(fn()=>$restored->exec('INSERT INTO changes VALUES(5,999)'),'restored foreign key remains enforced');
    dailyReject(fn()=>Encrypted::verify($archive,str_repeat('0',64)),'wrong key rejected');
    dailyReject(fn()=>Encrypted::decryptTo($archive,str_repeat('0',64),$root.'/wrong.sql'),'wrong key publishes no plaintext');
    dailyCheck(!file_exists($root.'/wrong.sql')&&!glob($root.'/wrong.sql.partial-*'),'failed recovery temporary file removed');
    dailyReject(fn()=>Encrypted::decryptTo($archive,$key,$legacy),'recovery never overwrites an existing file');
    $raw=file_get_contents($archive);$damaged=$raw;$damaged[50]=chr(ord($damaged[50])^1);
    foreach(['truncated'=>substr($raw,0,-1),'tampered'=>$damaged,'appended'=>$raw.'x','oversized'=>substr($raw,0,32).pack('N',0xffffffff).substr($raw,36),'header'=>"BADSQL01".substr($raw,8)] as $case=>$bytes){
        $bad=$root.'/'.$case.'.enc';file_put_contents($bad,$bytes);
        dailyReject(fn()=>Encrypted::verify($bad,$key),$case.' archive rejected');
    }
    $bad=$root.'/corrupt.sql.enc';file_put_contents($bad,'corrupt');
    dailyReject(fn()=>Encrypted::create($db,$dsn,$bad,$key),'existing corrupt archive fails visibly');
    dailyCheck(file_get_contents($bad)==='corrupt','existing corrupt archive not overwritten');
    dailyReject(fn()=>Encrypted::create($db,'mysql:dbname=wrong',$root.'/failed.enc',$key),'failed snapshot rejects wrong DSN');
    dailyCheck(!file_exists($root.'/failed.enc')&&!glob($root.'/failed.enc.partial-*')&&!$db->inTransaction(),'failed snapshot cleans encrypted attempt and connection state');
    dailyReject(fn()=>MySqlBackup::export($db,$dsn,static function(){throw new RuntimeException('Synthetic interrupted sink');}),'interrupted streaming sink fails');
    dailyCheck(!$db->inTransaction()&&MySqlFixture::snapshot($db)===$before,'sink failure leaves database read-only snapshot closed');
    $crash=$root.'/crash.enc';
    $process=proc_open([PHP_BINARY,__FILE__,'--crash-child',$crash],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    stream_get_contents($pipes[1]);$childError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    dailyCheck(proc_close($process)===77&&$childError==='','writer interrupted after encrypting a snapshot chunk');
    $partials=glob($crash.'.partial-*');
    dailyCheck(!file_exists($crash)&&count($partials)===1&&str_starts_with(file_get_contents($partials[0]),"KHSQL01\n")&&!str_contains(file_get_contents($partials[0]),'SYNTHETIC-PLAINTEXT-MUST-NOT-REMAIN'),'abrupt termination leaves only encrypted partial data, no published backup');
    dailyReject(fn()=>Encrypted::verify($partials[0],str_repeat('a',64)),'incomplete encrypted stream is never accepted as a complete backup');
    $held=fopen($root.'/locked.enc.lock','c');flock($held,LOCK_EX);
    dailyReject(fn()=>Encrypted::create($db,$dsn,$root.'/locked.enc',$key),'concurrent same-target writer rejected');flock($held,LOCK_UN);fclose($held);
    MySqlBackup::create($db,$dsn,$root.'/compatible.sql');
    dailyCheck(hash_file('sha256',$root.'/compatible.sql')===$result['sha256'],'legacy raw SQL exporter remains byte-compatible');
    rename($root.'/.kulturhus-backup-key',$root.'/saved-key');
    dailyReject(fn()=>$service->runVereinDaily($db,$dsn),'missing key with daily archives is not regenerated');
    dailyCheck(!file_exists($root.'/.kulturhus-backup-key'),'missing key stays missing for operator recovery');
    file_put_contents($root.'/.kulturhus-backup-key','');
    dailyReject(fn()=>$service->runVereinDaily($db,$dsn),'empty key with existing archive is not replaced');
    dailyCheck(filesize($root.'/.kulturhus-backup-key')===0,'empty key preserved rather than silently rotated');
    file_put_contents($root.'/.kulturhus-backup-key',$key);
    $targetHash=hash_file('sha256',$archive);file_put_contents($archive,'synthetic corruption');
    dailyReject(fn()=>$service->runVereinDaily($db,$dsn),'daily entry point rejects corrupted existing archive');
    dailyCheck(file_get_contents($archive)==='synthetic corruption','daily entry point never overwrites corrupt archive');
    putenv('BACKUP_TARGET_PATH='.$root.'/web/backups');
    dailyReject(fn()=>$service->runVereinDaily($db,$dsn),'public-webroot backup destination rejected');
    dailyCheck(hash_file('sha256',$legacy)===$legacyHash,'prior backup remains intact after all failure cases');
    echo "$n encrypted daily backup checks passed; synthetic MySQL only\n";
} finally {
    putenv($env===false?'BACKUP_TARGET_PATH':'BACKUP_TARGET_PATH='.$env);
    // Only the random directory created by this test is removed.
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
}
