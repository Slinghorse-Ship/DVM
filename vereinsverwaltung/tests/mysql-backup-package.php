<?php declare(strict_types=1);
// Unit integration: real backup classes, MySQL, filesystem and AES ZIP; no full PW bootstrap/login.
namespace ProcessWire {
    final class Wire
    {
        public function __construct(public object $config,public \PDO $database) {}
    }
}
namespace {
require_once __DIR__.'/mysql-bootstrap.php';
require_once __DIR__.'/support/mysql-backup.php';
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../../processwire/site/src/AppSecret.php';
require_once __DIR__.'/../../processwire/site/src/VereinDatabase.php';
require_once __DIR__.'/../../processwire/site/src/BackupService.php';
use Kulturhus\{AppSecret,BackupService};
use Kulturhus\Verein\{Database,Documents,MemberBankVault};
use Kulturhus\Verein\Tests\MySqlFixture;
use function Kulturhus\Verein\Tests\restoreArchivedMysql;

if(PHP_SAPI!=='cli'||getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only')throw new RuntimeException('Isolated MySQL CLI runner required');
if(!is_executable('/usr/bin/mysqldump'))throw new RuntimeException('BLOCKED: mysqldump required; full ProcessWire fallback is outside this unit integration');
$checks=0;
function packageCheck(bool $ok,string $label):void
{
    global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";
}
$root=sys_get_temp_dir().'/kh-backup-package-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700))throw new RuntimeException('Private package fixture directory unavailable');
$previousEnvironment=[];$zip=null;
$manager=$website=$restoredManager=$restoredWebsite=null;
try{
    $webRoot=$root.'/website/public';$mediaRoot=$webRoot.'/site/assets/files';
    $privateRoot=$root.'/manager-private';$target=$root.'/archives';
    $selectedBanking=$root.'/banking-selected';
    foreach([$mediaRoot.'/42',$privateRoot.'/kulturhus-banking',$selectedBanking,$privateRoot.'/member-bank-keys',$root.'/keys',$root.'/push',$root.'/tmp',$target,$root.'/kulturhus-banking'] as $directory){
        if(!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create synthetic directory');
    }
    $write=static function(string $path,string $bytes)use($root):void{
        if(!str_starts_with($path,$root.'/')||str_contains($path,'/../'))throw new RuntimeException('Fixture path escaped private root');
        if(file_put_contents($path,$bytes)!==strlen($bytes)||!chmod($path,0600))throw new RuntimeException('Cannot write private fixture');
    };
    $bankKey=random_bytes(32);$oldBankKey=random_bytes(32);$bankPath=$root.'/keys/operator-selected.secret';
    $pushPath=$root.'/push/operator-selected.json';$pushBytes='{"synthetic_only":true,"public":"fixture-public","private":"fixture-private"}';
    foreach(['VEREIN_DATABASE_USER'=>'root','VEREIN_DATABASE_PASSWORD'=>'isolated-regression-only',
        'VEREIN_MODE'=>'synthetic','KULTURHUS_DISABLE_MAIL'=>'1','APP_SECRET'=>'',
        'BACKUP_TARGET_PATH'=>$target,'VEREIN_PRIVATE_ROOT'=>$privateRoot,'VEREIN_BANKING_ROOT'=>$selectedBanking,
        'VEREIN_MEMBER_BANK_KEY_PATH'=>$bankPath,'VEREIN_MEMBER_BANK_KEY_VERSION'=>'7',
        'VEREIN_PUSH_KEY_PATH'=>$pushPath] as $name=>$value){$previousEnvironment[$name]=getenv($name);putenv($name.'='.$value);}
    $write($bankPath,$bankKey);$write($root.'/keys/v2.key',$oldBankKey);$write($pushPath,$pushBytes);
    $write($privateRoot.'/member-bank-keys/v1.key',str_repeat('d',32));
    $write($privateRoot.'/push-vapid.json','{"decoy":true}');
    $write($root.'/kulturhus-banking/fints.json','{"decoy":true}');

    $manager=MySqlFixture::connect();Database::migrate($manager);
    $website=MySqlFixture::connect();
    $website->exec('CREATE TABLE synthetic_pages (id INT PRIMARY KEY,title VARCHAR(255) NOT NULL,body LONGBLOB NOT NULL,note TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $insert=$website->prepare('INSERT INTO synthetic_pages VALUES(?,?,?,?)');
    $insert->execute([1,'Synthetic Kulturhus – Bühne',"Synthetic website bytes\0\xFF\r\n'quoted'",null]);
    $insert->execute([2,'Synthetic gallery','',"Second row\nwith a note"]);
    $manager->exec("INSERT INTO clubs VALUES('package','Synthetic backup package');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('package-admin','package','members',1);INSERT INTO contacts(id,club_id,kind,name) VALUES('package-person','package','person','Synthetic Member');INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('package-membership','package','package-person','SYN-1','aktiv','2020-01-01')");
    $documentBytes="Synthetic original document – never a real invoice.\r\nQuotes: ' \" and backslash: \\.\n";
    $document=(new Documents($manager,'package-admin'))->upload('synthetic-original.txt',$documentBytes,['title'=>'Synthetic original','scope'=>'orga','kind'=>'other'],bin2hex(random_bytes(16)));
    $bankData=['holder'=>'Synthetic Member','iban'=>'DE89370400440532013000','bic'=>''];
    $grant=bin2hex(random_bytes(32));$binding=hash('sha256','synthetic-backup-session');
    $manager->prepare('INSERT INTO member_bank_grants VALUES(?,?,?,?,?,?,?,?,?,NULL)')->execute([hash('sha256',$grant),'package','package-admin','package-person','save',0,MemberBankVault::payloadHash('save',$bankData),$binding,time()+120]);
    MemberBankVault::fromEnvironment($manager,'package-admin')->save('package-person',0,$bankData,$grant,$binding);
    packageCheck((int)$manager->query('SELECT key_version FROM member_bank_records')->fetchColumn()===7,'actual encrypted member-bank record uses explicitly selected key version');

    $wire=new \ProcessWire\Wire((object)[
        'paths'=>(object)['root'=>$webRoot.'/','files'=>$mediaRoot.'/'],
        'dbHost'=>'mysql-test','dbPort'=>3306,'dbUser'=>'root','dbPass'=>'isolated-regression-only',
        'dbName'=>(string)$website->query('SELECT DATABASE()')->fetchColumn(),
        'kulturhusVereinDatabase'=>MySqlFixture::dsn($manager),'kulturhusVereinRoot'=>dirname(__DIR__),
    ],$website);
    $secret=AppSecret::value($wire);
    packageCheck(AppSecret::storagePath($wire)===$root.'/tmp/.kulturhus-app-secret'&&strlen($secret)===64&&trim((string)file_get_contents(AppSecret::storagePath($wire)))===$secret,'actual AppSecret creates a private synthetic application secret');
    $expected=['private/app-secret'=>$secret,
        'private/verein/member-bank-keys/v7.key'=>$bankKey,'private/verein/member-bank-keys/v2.key'=>$oldBankKey,
        'private/verein/push-vapid.json'=>$pushBytes];
    $runtimeParts=['runtime.php'=>'<?php return ["synthetic_only"=>true];',
        'runtime-components.php'=>'<?php return ["synthetic_component"=>true];',
        'mysql-credentials.json'=>json_encode(['dsn'=>MySqlFixture::dsn($manager),'user'=>'root','password'=>'isolated-regression-only'],JSON_THROW_ON_ERROR),
        'cron-secret'=>'synthetic-cron-'.bin2hex(random_bytes(16)),
        'production.php'=>'<?php return ["synthetic_fixture"=>true];','config.php'=>'<?php return [];','contributions-last-day'=>'2026-09-01'];
    foreach($runtimeParts as $name=>$bytes){$write($privateRoot.'/'.$name,$bytes);$expected['private/verein/'.$name]=$bytes;}
    foreach(['fints.json'=>'{"club_id":"package","product_id":"synthetic-only"}',
        'fints-state.key'=>random_bytes(32),'enable-sandbox.key'=>'synthetic-enable-key',
        'enable-sandbox.crt'=>'synthetic-enable-certificate','enable-sandbox.json'=>'{"synthetic_only":true}',
        'connections-sandbox-'.str_repeat('a',64).'.enc'=>'synthetic-encrypted-connection'] as $name=>$bytes){
        $write($selectedBanking.'/'.$name,$bytes);$expected['private/banking/'.$name]=$bytes;
    }
    $write($privateRoot.'/kulturhus-banking/dialog-ephemeral.enc','synthetic-active-dialog-excluded');
    foreach(['.kulturhus-smtp-dsn','.kulturhus-smtp-key','.kulturhus-press-smtp-dsn','.kulturhus-press-smtp-key'] as $name){
        $bytes='synthetic-only-'.$name;$write($root.'/tmp/'.$name,$bytes);$expected['private/'.$name]=$bytes;
    }
    $configBytes='<?php /* Synthetic archived configuration; never executed. */';
    $write($webRoot.'/site/config.php',$configBytes);$expected['website/site-config.php']=$configBytes;
    $write($webRoot.'/.env','SYNTHETIC_PACKAGE=website');$expected['private/environment-0']='SYNTHETIC_PACKAGE=website';
    $write(dirname($webRoot).'/.env','SYNTHETIC_PACKAGE=parent');$expected['private/environment-1']='SYNTHETIC_PACKAGE=parent';
    $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=',true);
    $write($mediaRoot.'/42/synthetic.png',$image);$expected['site-assets-files/42/synthetic.png']=$image;
    $managerBefore=MySqlFixture::snapshot($manager);$websiteBefore=MySqlFixture::snapshot($website);
    $schema=(int)$manager->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
    packageCheck($schema===Database::SCHEMA_VERSION,'package source uses current schema version '.Database::SCHEMA_VERSION);
    // Row snapshots omit generated invisible columns and constraint enforcement metadata.
    $schemaDetails=static function(PDO $db):array{
        return [
            'checks'=>$db->query("SELECT t.TABLE_NAME,t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.TABLE_NAME,t.CONSTRAINT_NAME")->fetchAll(),
            'unique_indexes'=>$db->query('SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(),
            'generated_columns'=>$db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,GENERATION_EXPRESSION,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION<>'' ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(),
        ];
    };
    $managerSchema=$schemaDetails($manager);$installedChecks=[];$installedUnique=[];
    foreach($managerSchema['checks'] as $row)$installedChecks[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']]=$row['ENFORCED'];
    foreach($managerSchema['unique_indexes'] as $row)$installedUnique[$row['TABLE_NAME']][$row['INDEX_NAME']]=true;
    $checkRules=require __DIR__.'/../migrations/mysql/086_checks.php';
    foreach($checkRules as $table=>$rules)foreach($rules as $name=>$expression){
        if(($installedChecks[$table][$name]??null)!=='YES')throw new RuntimeException('Source native CHECK missing or disabled: '.$table.'.'.$name);
    }
    packageCheck(true,'all schema 86 native CHECK rules are enforced in backup source');
    $uniqueRules=require __DIR__.'/../migrations/mysql/086_unique.php';
    foreach($uniqueRules as $rule)if(empty($installedUnique[$rule['table']][$rule['index_name']]))throw new RuntimeException('Source unique index missing: '.$rule['index_name']);
    packageCheck(count($uniqueRules)===6,'all six schema 86 unique indexes are present in backup source');

    $service=new BackupService($wire);
    $write($root.'/keys/v7.key',random_bytes(32));$collisionRejected=false;
    try{$service->runDaily(true);}catch(RuntimeException $e){$collisionRejected=str_contains($e->getMessage(),'Widersprüchliche Sicherungsdateien');}
    packageCheck($collisionRejected,'conflicting key bytes for the same archived version fail closed');
    unlink($root.'/keys/v7.key');
    $result=$service->runDaily(true);
    packageCheck($result['status']==='ok'&&$result['created']===true&&$result['encrypted']===true&&$result['verified']===true&&$result['media_files']===1,'actual runDaily creates and verifies an encrypted two-database package with synthetic image');
    $archive=$target.'/'.$result['archive'];$password=trim((string)file_get_contents($root.'/.kulturhus-backup-key'));
    packageCheck((bool)preg_match('/^[a-f0-9]{64}$/D',$password)&&filesize($archive)===$result['bytes'],'backup key and completed archive created in fresh private fixture root');
    $verified=BackupService::verify($archive,$password);
    packageCheck($verified['verein_schema']===$schema&&$verified['files']===count($expected)+2,'actual archive verifier checks every expected private file and both SQL dumps');
    $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Cannot open generated archive');
    packageCheck($zip->getFromName('database.sql')===false&&$zip->getFromName('verein/database.sql')===false&&$zip->getFromName('private/verein/member-bank-keys/v7.key')===false,'both SQL dumps and active member-bank key unreadable without password');
    $zip->setPassword($password);
    for($i=0;$i<$zip->numFiles;$i++){
        $stat=$zip->statIndex($i);if(($stat['encryption_method']??null)!==ZipArchive::EM_AES_256)throw new RuntimeException('Archive entry is not AES-256 encrypted: '.$stat['name']);
    }
    packageCheck(true,'every ZIP entry including manifest uses AES-256');
    foreach($expected as $name=>$bytes)packageCheck($zip->getFromName($name)===$bytes,'exact packaged bytes: '.$name);
    packageCheck($zip->getFromName('private/banking/dialog-ephemeral.enc')===false&&$zip->getFromName('private/verein/member-bank-keys/v1.key')===false,'active bank dialog and decoy default member-bank key excluded');
    $manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    packageCheck(count($manifest['files'])===$zip->numFiles-1,'manifest enumerates every non-manifest archive entry');
    foreach(['database.sql'=>'website-restored.sql','verein/database.sql'=>'manager-restored.sql'] as $name=>$file){
        $sql=$zip->getFromName($name);if(!is_string($sql)||$sql==='')throw new RuntimeException('Archived SQL payload missing: '.$name);
        $write($root.'/'.$file,$sql);
    }
    $zip->close();$zip=null;
    packageCheck(MySqlFixture::snapshot($manager)===$managerBefore&&MySqlFixture::snapshot($website)===$websiteBefore,'backup creation leaves all rows in both source databases unchanged');
    $restoredWebsite=restoreArchivedMysql($root.'/website-restored.sql');
    $restoredManager=restoreArchivedMysql($root.'/manager-restored.sql');
    packageCheck(count(array_unique(array_map([MySqlFixture::class,'dsn'],[$manager,$website,$restoredManager,$restoredWebsite])))===4,'both archived SQL payloads restored into separate fresh isolated databases');
    packageCheck(MySqlFixture::snapshot($restoredWebsite)===$websiteBefore,'restored tiny website schema has exactly every original table and row including binary and null values');
    packageCheck(MySqlFixture::snapshot($restoredManager)===$managerBefore,'restored manager schema has exactly every original table and row including encrypted bank records');
    packageCheck($schemaDetails($restoredManager)===$managerSchema,'archived manager dump restores exact CHECK definitions and enforcement, unique indexes and generated invisible columns');
    $original=(new Documents($restoredManager,'package-admin'))->file($document);
    packageCheck(($original['bytes']??null)===$documentBytes,'authorized document read returns original bytes from restored manager database');
    foreach(['website'=>$restoredWebsite,'manager'=>$restoredManager] as $name=>$db)packageCheck(MySqlFixture::integrity($db)==='ok'&&MySqlFixture::foreignKeyErrors($db)===[],$name.' restore preserves table integrity and foreign keys');
    $rejected=false;try{BackupService::verify($archive,str_repeat('0',64));}catch(RuntimeException){$rejected=true;}
    packageCheck($rejected,'actual verifier rejects incorrect archive password');
    $archiveHash=hash_file('sha256',$archive);$archiveBytes=filesize($archive);$archiveMtime=filemtime($archive);
    $website->exec("UPDATE synthetic_pages SET title='Synthetic later revision' WHERE id=1");
    $write($mediaRoot.'/42/synthetic.png',$image.'synthetic-later-revision');
    $again=$service->runDaily(true);clearstatcache(true,$archive);
    packageCheck($again['status']==='ok'&&$again['created']===false&&$again['archive']===$result['archive'],'repeat forced run reports existing daily archive without creating another');
    packageCheck(hash_file('sha256',$archive)===$archiveHash&&filesize($archive)===$archiveBytes&&filemtime($archive)===$archiveMtime&&trim((string)file_get_contents($root.'/.kulturhus-backup-key'))===$password,'repeat run preserves exact archive bytes, modification time and backup key despite changed source');
    packageCheck((glob($target.'/*.part')?:[])===[]&&(glob($target.'/.*.part')?:[])===[]&&count(glob($target.'/*.zip')?:[])===1,'successful run leaves one final package and no temporary SQL or ZIP parts');
    echo "$checks MySQL backup-package unit integration checks passed; actual BackupService/AppSecret/VereinDatabase, no full ProcessWire bootstrap or native login\n";
}finally{
    if($zip instanceof ZipArchive)$zip->close();
    foreach($previousEnvironment as $name=>$value)putenv($value===false?$name:$name.'='.$value);
    // Only this freshly created private tree is removed; fixture helper owns database cleanup.
    if(realpath($root)===$root&&dirname($root)===sys_get_temp_dir()&&preg_match('/^kh-backup-package-[a-f0-9]{16}$/D',basename($root))){
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
        rmdir($root);
    }
}
}
