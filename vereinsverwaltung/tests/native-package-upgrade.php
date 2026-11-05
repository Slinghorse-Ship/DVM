<?php declare(strict_types=1);
if (($argv[1]??'')==='--old-install') {
    if (getenv('KH_NATIVE_CI')!=='1') exit(1);
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    require $input['source'].'/vereinsverwaltung/native/bootstrap.php';
    $db=new PDO($input['config']['dsn'],$input['config']['username'],$input['config']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    echo json_encode((new Kulturhus\Native\Installer\Install($input['source']))->run($input['config'],$db),JSON_THROW_ON_ERROR); exit;
}
if (in_array($argv[1]??'',['--kill-switch','--kill-rollback'],true)) {
    if (getenv('KH_NATIVE_CI')!=='1') exit(1);
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    require dirname(__DIR__).'/native/bootstrap.php'; require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
    $state=Kulturhus\Native\Installer\Upgrade::readState($input['root']);
    if (!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$state['database'])) exit(1);
    $db=Kulturhus\Native\TestSupport::db();
    $permit=Kulturhus\Native\Installer\UpgradePermit::authenticate($input['root'],$input['root'].'/private/upgrade-package',$input['token']);
    $point=($argv[1]==='--kill-switch')?'old-app-saved':'rollback-tables-cleared';
    $upgrade=new Kulturhus\Native\Installer\Upgrade($input['root'],$input['root'].'/private/upgrade-package',static function(string $event)use($point):void {
        if ($event!==$point) return;
        echo "OWN_CHECKPOINT_REACHED\n"; flush(); while(true)usleep(100000);
    });
    $upgrade->step($db,$permit); exit(1);
}
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
require __DIR__.'/support/original-database-hash.php';
require __DIR__.'/support/original-row-layout.php';
require __DIR__.'/support/upgrade-schema-reference.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Installer\{Upgrade,UpgradeBundle,UpgradeFiles,UpgradePermit,Install};
use Kulturhus\Native\Shared\OperationGate;
$db=TestSupport::db(); $fixture=TestSupport::config(); $source=dirname(__DIR__,2);
$prior=getenv('DVM_UPGRADE_PRIOR_SOURCE')?:'/tmp/kulturhus-upgrade-prior';
if (!is_file($prior.'/distribution/source-files.json')) throw new RuntimeException('Verified prior package fixture required; see docs/PAKET-UPGRADE.md.');
$root=sys_get_temp_dir().'/dvm-upgrade-'.bin2hex(random_bytes(10)); $bundle=$root.'-upload';
$n=0; $server=null; $cookie=''; $port=0;
$restored=null; $restoreDbName=null;
function upgradeCheck(bool $ok,string $label):void { global $n; if (!$ok) throw new RuntimeException($label); ++$n; echo 'PASS '.$label."\n"; }
function upgradeReject(callable $fn,string $label):void { try { $fn(); } catch (DomainException|RuntimeException) { upgradeCheck(true,$label); return; } throw new RuntimeException($label); }
function upgradeHttp(string $path,array $data=[],array $headers=[],string $method='GET'):array {
    global $port,$cookie;
    $handle=curl_init('http://127.0.0.1:'.$port.$path); $received=[];
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>array_merge(['Host: upgrade.example'], $cookie!==''?['Cookie: '.$cookie]:[], $headers),CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$received):int{$received[]=$line;return strlen($line);}]);
    if ($method==='POST') curl_setopt($handle,CURLOPT_POSTFIELDS,http_build_query($data));
    $body=curl_exec($handle); $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $error=curl_error($handle); curl_close($handle);
    if ($body===false) throw new RuntimeException('Own HTTP request failed: '.$error);
    foreach ($received as $line) if (preg_match('/\ASet-Cookie:\s*(DVM_UPGRADE=[^;]+)/i',$line,$match)) $cookie=$match[1];
    return [$status,$body];
}
function upgradeRemove(string $path):void {
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isDir()&&!$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($path);
}
try {
    $config=['target'=>$root,'dsn'=>'mysql:host=127.0.0.1;port=13371;dbname='.$fixture['database'].';charset=utf8mb4','username'=>$fixture['username'],'password'=>$fixture['password'],'club_id'=>'upgrade-test','club_name'=>'Own package upgrade club','hosts'=>'upgrade.example','admin_email'=>'upgrade@example.invalid','admin_password'=>'Own synthetic upgrade password 2026!'];
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--old-install'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(['source'=>$prior,'config'=>$config],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    upgradeCheck(proc_close($process)===0 && json_decode($output,true)['status']==='installed' && $errors==='','actual previously committed source package installs independently');
    $oldReceipt=file_get_contents($root.'/installation.json');
    upgradeCheck(!is_file($root.'/private/app-files.json') && !is_file($root.'/app/vereinsverwaltung/native/Installer/Upgrade.php'),'actual old CLI package lacks updater and upgrade inventory');
    file_put_contents($root.'/app/own-extra.txt','Own supplemental application byte original');
    mkdir($root.'/app/runtime',0700); file_put_contents($root.'/app/runtime/own-config.txt','Original optional runtime payload');
    file_put_contents($root.'/private/media/own-original.txt','Original private pixels');
    $db->exec("UPDATE clubs SET name='Original manually edited club' WHERE id='upgrade-test'");
    $db->exec('DROP TABLE IF EXISTS native_communication_save_requests');
    $db->exec('DROP TABLE IF EXISTS native_communication_documents');
    $db->exec('DROP TABLE native_press_messages'); $db->exec("UPDATE native_schema_versions SET version=12 WHERE module='communication'");
    $db->exec('DROP TABLE IF EXISTS native_catalog_charges');$db->exec("UPDATE native_schema_versions SET version=4 WHERE module='identity'");
    $db->exec('DROP TABLE IF EXISTS native_invoice_reminder_attempts');$db->exec('DROP TABLE IF EXISTS native_invoice_reminder_deliveries');
    $db->exec('DROP TABLE IF EXISTS native_social_human_sends');$db->exec("UPDATE native_schema_versions SET version=11 WHERE module='delivery'");
    require dirname(__DIR__).'/src/autoload.php';
    $auth=\Kulturhus\Native\Auth\Runtime::service($db,$root.'/private/auth.key');
    $originalLogin=$auth->passwordLogin($config['admin_email'],$config['admin_password'],'own-original-upgrade-session');
    $access=\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,$auth,$originalLogin['session_token'])->access();
    $subscribers=new \Kulturhus\Native\Communication\Subscribers($db,$access);
    $originalSubscriber=$subscribers->recordWrittenConsent('original-upgrade-reader@example.invalid','Original consenting reader',['events','news'],'Own original documented consent before package update');
    $originalMessage=['to'=>'original-upgrade-reader@example.invalid','subject'=>'Original queued message ä🙂','html'=>'<p>Original protected pending mail</p>','text'=>'Original protected pending mail'];
    $originalBox=\Kulturhus\Native\Shared\SecretBox::fromFile($root.'/private/private.key');
    $originalJob=(new \Kulturhus\Native\Delivery\Outbox($db,$originalBox))->enqueue($access->club(),'test','own-original-upgrade-intent',$originalMessage,'2050-10-25 18:30:00',$access);
    $query=$db->prepare('SELECT ciphertext FROM native_delivery_jobs WHERE id=?');$query->execute([$originalJob]);$originalCipher=$query->fetchColumn();
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables);
    $originalHash=ownOriginalDatabaseHash($db,$tables);
    $originalMigrations=$db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    $originalMigrationHead=(int)max($originalMigrations);
    // Preserve every earlier migration cell, while separately requiring the exact new migration entries.
    $originalVoucherVersions=$db->query('SELECT version FROM event_voucher_schema ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    $contentFilter=static function(string $table)use($originalMigrations,$originalVoucherVersions):array{
        $versions=match($table){'schema_migrations'=>$originalMigrations,'event_voucher_schema'=>$originalVoucherVersions,default=>null};
        if($versions===null)return['',[]];
        return$versions?[' WHERE version IN ('.implode(',',array_fill(0,count($versions),'?')).')',$versions]:[' WHERE 1=0',[]];
    };
    $contentTables=array_values(array_diff($tables,['native_schema_versions']));$contentLayout=ownOriginalRowLayout($db,$contentTables);$contentHash=ownOriginalRowsHash($db,$contentLayout,$contentFilter);$originalSchemaShape=ownUpgradeSchemaShape($db);$currentSchemaReference=ownFreshUpgradeSchema();
    $keyHashes=[]; foreach(['auth.key','private.key','member-bank-keys/v1.key','environment.json','runtime.php','worker.php','media/own-original.txt']as$name)$keyHashes[$name]=hash_file('sha256',$root.'/private/'.$name);
    $built=UpgradeBundle::build($source,$bundle);
    upgradeCheck($built['files']>100 && !is_dir($bundle.'/private/upgrade-package/app/vereinsverwaltung/tests') && !is_dir($bundle.'/private/upgrade-package/web'),'new separate upgrade bundle contains unpacked application, no fresh installer webroot');
    rename($bundle.'/private/upgrade-package',$root.'/private/upgrade-package'); rename($bundle.'/web/upgrade',$root.'/web/upgrade');
    $token=trim(file_get_contents($bundle.'/UPGRADE-KEY.txt')); $package=$root.'/private/upgrade-package';
    upgradeCheck(!str_contains(file_get_contents($package.'/private/upgrade-token'),$token),'only hash of upload key stored on server');
    upgradeReject(fn()=>UpgradePermit::authenticate($root,$package,str_repeat('a',64)),'wrong private key denied');
    $permit=UpgradePermit::authenticate($root,$package,$token); $upgrade=new Upgrade($root,$package);
    $originalCmsVersion=$db->query("SELECT * FROM native_schema_versions WHERE module='cms'")->fetch();
    $db->exec("DELETE FROM native_schema_versions WHERE module='cms'");
    try{upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'missing original mandatory module is still refused before maintenance');}finally{$query=$db->prepare('INSERT INTO native_schema_versions VALUES(?,?,?)');$query->execute(array_values($originalCmsVersion));}
    upgradeCheck(ownOriginalDatabaseHash($db,$tables)===$originalHash&&!is_file($root.'/private/upgrade-hold'),'refused missing-module upgrade preserves every original byte');
    $newer=json_decode($oldReceipt,true);$newer['package_generation']=\Kulturhus\Native\Installer\PackageVersion::GENERATION+1;file_put_contents($root.'/installation.json',json_encode($newer));
    upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'older package generation cannot downgrade an existing newer installation');file_put_contents($root.'/installation.json',$oldReceipt);
    $link=$root.'/app/own-link';symlink($root.'/private/auth.key',$link);upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'linked source cannot be staged as a package original');unlink($link);
    $oldSourceFile=$root.'/app/vereinsverwaltung/native/Shared/OperationGate.php';$oldSourceBytes=file_get_contents($oldSourceFile);file_put_contents($oldSourceFile,$oldSourceBytes."\n// synthetic changed original\n");
    upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'changed owned source rejected before any maintenance or schema write');file_put_contents($oldSourceFile,$oldSourceBytes);
    $workerBytes=file_get_contents($root.'/private/worker.php');file_put_contents($root.'/private/worker.php',str_replace($root.'/private/private.key','/tmp/foreign-upgrade-key',$workerBytes));
    upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'opaque original worker with foreign key cannot bypass durable maintenance');file_put_contents($root.'/private/worker.php',$workerBytes);
    upgradeReject(fn()=>$upgrade->begin($db,$permit),'legacy CLI receipt requires original verified package before mutations');
    upgradeCheck(!is_file($root.'/private/upgrade-hold') && ownOriginalDatabaseHash($db,$tables)===$originalHash,'missing original package preserves all rows and running deployment');
    $lock=OperationGate::shared($root.'/private'); upgradeReject(fn()=>$upgrade->begin($db,$permit,$prior),'active native request blocks maintenance'); $lock->close();
    $started=$upgrade->begin($db,$permit,$prior);
    upgradeCheck($started['status']==='verify-old' && is_file($root.'/private/upgrade-hold') && !is_file($root.'/private/private.key'),'durable hold and exact original key quarantine begin before app changes');
    upgradeReject(fn()=>OperationGate::shared($root.'/private'),'durable hold blocks requests after exclusive gate is released');
    upgradeReject(fn()=>(new \Kulturhus\Native\Installer\Recovery($source))->create($root,$db,$config['dsn'],$bundle.'/blocked.zip',bin2hex(random_bytes(32))),'active upgrade cannot be archived with partial program or operation marker');
    upgradeCheck($upgrade->begin($db,$permit,$prior)['status']==='verify-old','lost begin response resumes original intent');
    $proofFile=$package.'/private/app-files.json'; $proofBytes=file_get_contents($proofFile); file_put_contents($proofFile,$proofBytes.' ');
    upgradeReject(fn()=>$upgrade->step($db,$permit),'modified upload inventory cannot retarget begun operation'); file_put_contents($proofFile,$proofBytes);
    $stateFile=$root.'/private/upgrade-state.json'; $stateBytes=file_get_contents($stateFile); $forged=json_decode($stateBytes,true); $forged['state']['phase']='finish'; file_put_contents($stateFile,json_encode($forged));
    upgradeReject(fn()=>$upgrade->step($db,$permit),'modified journal without original-key MAC denied'); file_put_contents($stateFile,$stateBytes);
    $steps=0;
    do { $result=$upgrade->step($db,$permit); upgradeCheck(++$steps<200,'bounded original/new preparation makes progress'); } while($result['status']!=='switch');
    $state=Upgrade::readState($root); $dir=$root.'/private/upgrade-operations/'.$state['intent'];
    $zip=new ZipArchive(); $zip->open($dir.'/database.zip',ZipArchive::RDONLY);
    upgradeCheck($zip->statName('database.sql')['encryption_method']===ZipArchive::EM_AES_256 && $zip->getFromName('database.sql')===false,'rollback snapshot uses real AES encryption, not public SQL'); $zip->close();
    upgradeCheck(!is_file($dir.'/database.sql') && !is_file($dir.'/rollback.sql'),'no plaintext SQL remains after completed snapshot');
    $child=proc_open([...TestSupport::phpCommand(),__FILE__,'--kill-switch'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fwrite($pipes[0],json_encode(['root'=>$root,'token'=>$token])); fclose($pipes[0]);
    upgradeCheck(trim(fgets($pipes[1]))==='OWN_CHECKPOINT_REACHED','actual updater subprocess owns exclusive gate and renames old app');
    $childStatus=proc_get_status($child); posix_kill($childStatus['pid'],SIGKILL); fclose($pipes[1]); fclose($pipes[2]); proc_close($child);
    upgradeCheck(!is_dir($root.'/app') && is_file($root.'/private/upgrade-hold'),'actual SIGKILL leaves durable hold at missing-app boundary');
    $render=$root.'/private/own-render.php'; file_put_contents($render,"<?php require ".var_export($root.'/web/index.php',true).";");
    $child=proc_open([...TestSupport::phpCommand(),$render],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    upgradeCheck(proc_close($child)===0 && str_contains($body,'Kurze Wartung') && $error==='','real entrypoint serves maintenance without touching missing app'); unlink($render);
    $upgrade->step($db,$permit); $upgrade->step($db,$permit);
    upgradeCheck(is_dir($root.'/app') && (int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication'],'same intent continues real directory switch and upgrades populated communication 12');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM native_role_requests')->fetchColumn()===0,'identity nine adds an empty first-role confirmation ledger without inventing older accepted changes');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn()===0,'identity ten adds an empty general-settings original ledger without inventing earlier approvals');
    upgradeCheck(\Kulturhus\Verein\RoleAssignments::defaultsSupported($db),'current identity installation preserves nullable role identity and original role versions');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM native_assignment_prices')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_assignment_price_changes')->fetchColumn()===0,'actual legacy package transition introduces no personal price or original-price decision');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM native_catalog_automation')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_catalog_automation_runs')->fetchColumn()===0,'actual old package gains empty optional policy and run journals without automatic billing');
    upgradeCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='status'")->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM native_status_settings')->fetchColumn()===0&&is_file($root.'/app/vereinsverwaltung/bin/service-status-worker.php'),'actual previous package gains private opt-in status schema and dedicated worker without inventing checks');
    upgradeCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='extensions'")->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM native_extensions')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_extension_values')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_extension_history')->fetchColumn()===0,'actual prior package gains an inactive empty native extension registry without activating public hooks');
    upgradeCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='identity'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['identity']&&(int)$db->query('SELECT COUNT(*) FROM native_catalog_charges')->fetchColumn()===0,'actual package switch upgrades identity 4 with an empty billing ledger and no retroactive charges');
    upgradeCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query('SELECT COUNT(*) FROM native_social_human_sends')->fetchColumn()===0,'actual delivery 11 package switch installs an empty human ledger without retrospective replies');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM native_invoice_reminder_deliveries')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_invoice_reminder_attempts')->fetchColumn()===0,'actual delivery 15 package upgrade creates empty reminder approval-linked outbox and one-attempt ledger without retrospective messages or SMTP attempts');
    $upgrade->rollback($db,$permit);
    $beforeInvalidRollback=ownOriginalDatabaseHash($db,$contentTables);$snapshotBytes=file_get_contents($dir.'/database.zip');file_put_contents($dir.'/database.zip',$snapshotBytes.'x');
    upgradeReject(fn()=>$upgrade->step($db,$permit),'modified original snapshot rejected before destructive rollback');
    upgradeCheck(ownOriginalDatabaseHash($db,$contentTables)===$beforeInvalidRollback,'invalid rollback archive never drops original business rows');file_put_contents($dir.'/database.zip',$snapshotBytes);
    $child=proc_open([...TestSupport::phpCommand(),__FILE__,'--kill-rollback'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fwrite($pipes[0],json_encode(['root'=>$root,'token'=>$token])); fclose($pipes[0]);
    upgradeCheck(trim(fgets($pipes[1]))==='OWN_CHECKPOINT_REACHED','actual rollback subprocess reaches owned empty-DB boundary');
    $childStatus=proc_get_status($child);posix_kill($childStatus['pid'],SIGKILL);fclose($pipes[1]);fclose($pipes[2]);proc_close($child);
    upgradeCheck($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===['native_package_operation'] && is_file($root.'/private/upgrade-hold'),'actual killed rollback retains only original operation ownership and durable hold');
    do { $result=$upgrade->step($db,$permit); } while(!$result['complete']);
    upgradeCheck($result['status']==='rolled-back' && ownOriginalDatabaseHash($db,$tables)===$originalHash,'explicit rollback restores all complete earlier rows, original keys and schema versions');
    upgradeCheck(file_get_contents($root.'/installation.json')===$oldReceipt && !is_file($root.'/private/app-files.json') && !is_file($root.'/private/upgrade-hold'),'rollback restores exact earlier installation receipt and releases only owned hold');
    foreach($keyHashes as$name=>$hash) upgradeCheck(hash_file('sha256',$root.'/private/'.$name)===$hash,'rollback preserves exact original '.$name);
    // Browser path: actual local hosting with shell/process functions disabled and no SSH.
    $socket=stream_socket_server('tcp://127.0.0.1:0'); $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $router=$root.'/private/own-router.php'; file_put_contents($router,"<?php\n\$_SERVER['HTTPS']=isset(\$_SERVER['HTTP_X_SYNTHETIC_PLAIN'])?'off':'on';\n\$path=parse_url(\$_SERVER['REQUEST_URI'],PHP_URL_PATH);\nif(in_array(\$path,['/upgrade/upgrade.css','/upgrade/upgrade.js','/upgrade/bootstrap-6.0.0-alpha.1.min.css','/upgrade/bootstrap-dvm.css'],true))return false;\nif(str_starts_with(\$path,'/upgrade')){require dirname(__DIR__).'/web/upgrade/index.php';return;}\nrequire dirname(__DIR__).'/web/index.php';\n");
    $log=fopen($root.'/private/own-http.log','wb');
    $server=proc_open([...TestSupport::phpCommand(),'-d','disable_functions=proc_open,exec,shell_exec,system,passthru,popen','-d','open_basedir='.$root,'-S','127.0.0.1:'.$port,'-t',$root.'/web',$router],[0=>['pipe','r'],1=>$log,2=>$log],$pipes); fclose($pipes[0]);
    for($i=0;$i<50;++$i){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(100000);}
    [$status,$html]=upgradeHttp('/upgrade/'); upgradeCheck($status===200 && str_contains($html,'DVM aktualisieren'),'actual standalone upgrade UI opens on installed own HTTPS domain');
    preg_match('/name="csrf" value="([a-f0-9]{64})"/',$html,$match); $csrf=$match[1]??''; upgradeCheck(strlen($csrf)===64,'real private upgrade session issues CSRF');
    [$status]=upgradeHttp('/upgrade/',[],['X-Synthetic-Plain: 1']); upgradeCheck($status===403,'plain HTTP upgrade denied');
    [$status]=upgradeHttp('/upgrade/',['csrf'=>$csrf,'token'=>$token,'action'=>'status'],['Accept: application/json','Origin: https://foreign.example'],'POST'); upgradeCheck($status===403,'foreign origin upgrade denied');
    [$status]=upgradeHttp('/upgrade/',['csrf'=>'invalid','token'=>$token,'action'=>'status'],['Accept: application/json'],'POST'); upgradeCheck($status===403,'invalid CSRF upgrade denied');
    [$status]=upgradeHttp('/upgrade/',['csrf'=>$csrf,'token'=>str_repeat('a',64),'action'=>'status'],['Accept: application/json'],'POST'); upgradeCheck($status===422,'invalid upload key upgrade denied');
    // Legacy roots need their original source once; attach its verified inventory using the CLI before browser steps.
    $upgrade->begin($db,$permit,$prior);
    [$status,$body]=upgradeHttp('/upgrade/',['csrf'=>$csrf,'token'=>$token,'action'=>'status'],['Accept: application/json'],'POST'); upgradeCheck($status===200 && json_decode($body,true)['status']==='verify-old','browser resumes exact independently started legacy upgrade');
    [$status,$body]=upgradeHttp('/login/'); upgradeCheck($status===503 && str_contains($body,'Kurze Wartung'),'actual HTTP entrypoint stays in maintenance during upgrade');
    $browserRun=false;
    if(getenv('DVM_UPGRADE_BROWSER')==='1'){
        $process=proc_open(['node',__DIR__.'/package-upgrade-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['url'=>'http://127.0.0.1:'.$port,'token'=>$token,'active'=>true],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);echo $out;if(proc_close($process)!==0)throw new RuntimeException($err);$browserRun=true;
    }
    $count=0;
    do {
        [$status,$body]=upgradeHttp('/upgrade/',['csrf'=>$csrf,'action'=>'step'],['Accept: application/json'],'POST');
        $result=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if($status!==200 || isset($result['error']))throw new RuntimeException('Browser upgrade step failed: '.$body);
        if(++$count>200)throw new RuntimeException('Browser did not finish');
    } while(!$result['complete']);
    upgradeCheck($result['status']==='done' && ($browserRun||$count>10),'whole installed upgrade completes through bounded real HTTP calls with no shell');
    $schemaDifferences=ownUpgradeSchemaDifferences($originalSchemaShape,ownUpgradeSchemaShape($db),$currentSchemaReference);if($schemaDifferences)echo json_encode(['probe'=>'upgrade-schema-differences','differences'=>$schemaDifferences],JSON_THROW_ON_ERROR).PHP_EOL;
    $expectedMigrations=[...$originalMigrations,...($originalMigrationHead<\Kulturhus\Verein\Database::SCHEMA_VERSION?range($originalMigrationHead+1,\Kulturhus\Verein\Database::SCHEMA_VERSION):[])];
    upgradeCheck($db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)===$expectedMigrations,'upgrade adds exactly the required migration versions without removing or inventing earlier steps');
    $expectedVoucherVersions=[...$originalVoucherVersions,...(max($originalVoucherVersions?:[0])<3?[3]:[])];
    $voucherSchemaHash=hash('sha256',hash('sha256',hash_file('sha256',dirname(__DIR__).'/migrations/mysql/095_event_vouchers.sql').hash_file('sha256',dirname(__DIR__).'/migrations/mysql/096_event_voucher_tickets.sql')).hash_file('sha256',dirname(__DIR__).'/migrations/mysql/116_event_voucher_receipts.sql'));
    upgradeCheck($db->query('SELECT version FROM event_voucher_schema ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)===$expectedVoucherVersions&&$db->query('SELECT source_sha256 FROM event_voucher_schema WHERE version=3')->fetchColumn()===$voucherSchemaHash&&(int)$db->query('SELECT COUNT(*) FROM event_voucher_receipts')->fetchColumn()===0,'upgrade adds exactly the reviewed voucher component marker and empty original-input table while retaining every earlier marker cell');
    upgradeCheck($schemaDifferences===[]&&ownOriginalRowsHash($db,$contentLayout,$contentFilter)===$contentHash && (int)$db->query('SELECT COUNT(*) FROM native_press_messages')->fetchColumn()===0,'upgrade preserves every original cell and key, matches all declared additions and changes only reviewed role nullability');
    $query=$db->prepare('SELECT ciphertext,status FROM native_delivery_jobs WHERE id=?');$query->execute([$originalJob]);$retainedJob=$query->fetch();
    upgradeCheck($retainedJob['ciphertext']===$originalCipher && $retainedJob['status']==='pending' && \Kulturhus\Native\Shared\SecretBox::fromFile($root.'/private/private.key')->open('job:'.$access->club().':'.$originalJob,$retainedJob['ciphertext'])===$originalMessage,'actual pending encrypted native mail keeps original bytes, due state and restored original key');
    upgradeCheck(\Kulturhus\Native\Communication\DocumentAttachments::installed($db)&&(int)$db->query('SELECT COUNT(*) FROM native_communication_documents')->fetchColumn()===0,'actual older module upgrade adds empty private newsletter-PDF association without exposing website documents');
    upgradeCheck(\Kulturhus\Native\Communication\EditionSaveRequests::installed($db)&&(int)$db->query('SELECT COUNT(*) FROM native_communication_save_requests')->fetchColumn()===0,'actual older communication upgrade adds empty durable edition-save ledger without inventing originals');
    upgradeCheck($auth->resolve($originalLogin['session_token'])['actor_id']===$access->user()['id'] && $subscribers->get($originalSubscriber['id'])['name']==='Original consenting reader','actual original native session and written consent still work after whole package upgrade');
    foreach($keyHashes as$name=>$hash)upgradeCheck(hash_file('sha256',$root.'/private/'.$name)===$hash,'actual upgrade keeps exact original '.$name);
    upgradeCheck(file_get_contents($root.'/app/own-extra.txt')==='Own supplemental application byte original' && file_get_contents($root.'/app/runtime/own-config.txt')==='Original optional runtime payload','unknown original app supplement and optional runtime payload preserved');
    upgradeCheck((new Install($source))->check($root,$db)['status']==='installed','normal installer verification accepts upgraded whole prepared runtime and locked vendors');
    upgradeCheck(!is_file($root.'/private/upgrade-hold') && !is_dir($root.'/app/processwire') && !is_file($root.'/app/vereinsverwaltung/tests/install-production.php'),'successful upgrade releases hold and omits obsolete ProcessWire installer fixture');
    [$status,$body]=upgradeHttp('/login/'); upgradeCheck($status===200 && str_contains($body,'Anmelden'),'actual installed native login works after complete whole upgrade');
    upgradeReject(fn()=>$upgrade->rollback($db,$permit),'completed upgrade cannot discard newer live data by rollback');
    upgradeCheck($upgrade->step($db,$permit)['complete'] && $upgrade->begin($db,$permit)['complete'],'lost completion acknowledgement remains idempotent');
    foreach (['--status','--resume'] as $option) {
        $cli=proc_open([...TestSupport::phpCommand(),$package.'/app/distribution/upgrade-native.php','--config-stdin',$option],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['target'=>$root,'token'=>$token],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        upgradeCheck(proc_close($cli)===0 && json_decode($output,true)['complete'] && $errors==='','actual uploaded private CLI accepts stdin-only '.$option.' without credentials or duplicate initialization');
    }
    [$status,$html]=upgradeHttp('/upgrade/',['csrf'=>$csrf,'token'=>$token,'action'=>'status'],[],'POST'); upgradeCheck($status===200 && str_contains($html,'Aktualisierung abgeschlossen'),'non-JavaScript actual form renders completed status');
    upgradeCheck(!str_contains(file_get_contents($root.'/private/own-http.log'),'open_basedir restriction'),'actual restricted hosting avoids probing inaccessible ancestors');
    $recovery=new \Kulturhus\Native\Installer\Recovery($source);$archive=$bundle.'/completed-upgrade.zip';$archiveKey=bin2hex(random_bytes(32));$recovery->create($root,$db,$config['dsn'],$archive,$archiveKey);$manifest=$recovery->verify($archive,$archiveKey);
    $transient=array_filter(array_keys($manifest['files']),static fn($name)=>$name==='private/upgrade-state.json'||str_starts_with($name,'private/upgrade-package/')||str_starts_with($name,'private/upgrade-sessions/')||str_starts_with($name,'private/upgrade-operations/'));
    upgradeCheck(!$transient && isset($manifest['files']['private/private.key'],$manifest['files']['app/own-extra.txt']),'actual encrypted current deployment backup excludes completed operation credentials and old-root journals, retains active originals');
    $restoreDbName='kh_native_'.bin2hex(random_bytes(12));$admin=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$restoreDbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $restoreDsn='mysql:host=127.0.0.1;port=13371;dbname='.$restoreDbName.';charset=utf8mb4';$restoreDb=new PDO($restoreDsn,$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$restored=$root.'-restored';
    $restoreResult=$recovery->restore($archive,$archiveKey,['target'=>$restored,'dsn'=>$restoreDsn,'username'=>$fixture['username'],'password'=>$fixture['password']],$restoreDb);
    upgradeCheck($restoreResult['restored'] && !is_file($restored.'/private/upgrade-state.json') && is_file($restored.'/private/recovery-hold') && hash_file('sha256',$restored.'/private/private.key')===$keyHashes['private.key'],'real restored upgraded package keeps original key and independent review hold without foreign active upgrade journal');
    rename($package,$restored.'/private/upgrade-package');$restoredPermit=UpgradePermit::authenticate($restored,$restored.'/private/upgrade-package',$token);
    try {(new Upgrade($restored,$restored.'/private/upgrade-package'))->begin($restoreDb,$restoredPermit);throw new RuntimeException('Same generation accepted.');}catch(DomainException $e){upgradeCheck(str_contains($e->getMessage(),'Paketgeneration'),'restored upgraded deployment can reach new upgrade preflight and correctly reject same generation without old-root lockout');}
    echo json_encode(['checks'=>$n,'browser_steps'=>$count,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);} if(isset($log)&&is_resource($log))fclose($log);
    upgradeRemove($root);upgradeRemove($bundle);if($restored)upgradeRemove($restored);if($restoreDbName&&isset($admin))$admin->exec('DROP DATABASE `'.$restoreDbName.'`');
}
