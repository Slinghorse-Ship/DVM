<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
require __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Auth\{Runtime,Service,Totp};
use Kulturhus\Native\Installer\{Install,Recovery,RestoreAccounts,RestoreCredentials,RestoreProgress,SchemaRegistry,UpgradeFiles};
use Kulturhus\Native\Shared\Db;

/** The fault is injected only into the actual credential-review COMMIT, never SQL import. */
final class RestoreAccountFaultPdo extends PDO
{
    public bool $fired=false;
    public function __construct(string $dsn,string $user,string $password,private string $fault)
    {parent::__construct($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
    public function commit():bool
    {
        if(!$this->fired&&$this->inTransaction()&&(int)$this->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_restore_operation'")->fetchColumn()
            &&(bool)$this->query("SHOW COLUMNS FROM native_restore_operation LIKE 'credentials_json'")->fetch()
            &&$this->query('SELECT credentials_json FROM native_restore_operation WHERE id=1')->fetchColumn()!==null){
            $this->fired=true;if($this->fault==='declined')throw new RuntimeException('Own injected unaccepted credential COMMIT.');
            parent::commit();throw new RuntimeException('Own injected lost credential COMMIT acknowledgement.');
        }
        return parent::commit();
    }
}

/** Exercise the file-before-SQL handoff journal against both COMMIT outcomes. */
final class RestoreAccountHandoffPdo extends PDO
{
    public bool $fired=false;
    public function __construct(string $dsn,string $user,string $password,private bool $accepted)
    {parent::__construct($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
    public function commit():bool
    {
        if(!$this->fired){$this->fired=true;if($this->accepted)parent::commit();throw new RuntimeException('Own injected handoff COMMIT outcome.');}
        return parent::commit();
    }
}

// The parent sends only its own private isolated configuration over stdin.
if(in_array('--kill-worker',$argv,true)){
    ini_set('zend.exception_ignore_args','1');
    $c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$fixture=TestSupport::config();
    if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=13371;dbname=kh_native_[a-f0-9]{24};charset=utf8mb4\z/D',$c['dsn']??''))throw new RuntimeException('Own isolated worker only.');
    $db=new RestoreAccountFaultPdo($c['dsn'],$fixture['username'],$fixture['password'],'lost');
    (new Recovery(dirname(__DIR__,2),static function(string $stage)use($db):void{
        if($stage==='credentials-committed'){
            if(!$db->fired)throw new RuntimeException('Lost acknowledgement was not exercised.');
            echo "OWN_CREDENTIALS_COMMITTED\n";flush();$until=microtime(true)+30;while(microtime(true)<$until)usleep(10000);throw new RuntimeException('Parent did not stop its own worker.');
        }
    }))->restore($c['archive'],$c['key'],$c,$db,true);
    throw new RuntimeException('Own kill checkpoint not reached.');
}

$count=0;$databases=[];$source=dirname(__DIR__,2);$fixture=TestSupport::config();$db=TestSupport::db();
if((int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn())throw new RuntimeException('Fresh isolated test database required.');
$base=getenv('KH_RESTORE_TEST_TMP')?:sys_get_temp_dir();$work=$base.'/kh-restore-accounts-'.bin2hex(random_bytes(8));mkdir($work,0700);
$root=$work.'/original';$target=$work.'/restored';$archive=$work.'/original.zip';$key=bin2hex(random_bytes(32));
$dsn='mysql:host=127.0.0.1;port=13371;dbname='.$fixture['database'].';charset=utf8mb4';
$config=['target'=>$root,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'club_id'=>'own-restored-codes','club_name'=>'Own restored account club','hosts'=>'restore.example.invalid','admin_email'=>'own-restore@example.invalid','admin_password'=>'Own original restore password 2026!'];
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);++$count;echo 'PASS '.$label."\n";};
$reject=static function(callable $op,string $label)use($check):void{try{$op();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
$remove=static function(string $path)use(&$remove):void{if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new FilesystemIterator($path)as$file)$remove($file->getPathname());rmdir($path);};
$server=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$fresh=static function()use($server,$fixture,&$databases):array{$name='kh_native_'.bin2hex(random_bytes(12));$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[]=$name;$dsn='mysql:host=127.0.0.1;port=13371;dbname='.$name.';charset=utf8mb4';return[new PDO($dsn,$fixture['username'],$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]),$dsn];};
$row=static fn(PDO $db,string $id):array=>Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$id])->fetch();
$permanent=static function(array $row):array{unset($row['totp_counter']);return$row;};
$originals=static function(PDO $db):string{$omit=['native_auth_accounts','native_auth_sessions','native_auth_challenges','native_auth_recovery','native_auth_limits','native_auth_tokens','native_auth_email_codes','native_api_tokens'];$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),$omit));return ownOriginalDatabaseHash($db,$tables);};
$cli=static function(array $args,array $input)use($source):array{$process=proc_open([...TestSupport::phpCommand(),$source.'/distribution/recover-native.php','--config-stdin',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own CLI unavailable.');fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($process),$out,$err];};
$withAuthKey=static function(string $file,callable $operation):mixed{$previous=getenv('KH_NATIVE_AUTH_KEY_FILE');putenv('KH_NATIVE_AUTH_KEY_FILE='.$file);try{return$operation();}finally{putenv($previous===false?'KH_NATIVE_AUTH_KEY_FILE':'KH_NATIVE_AUTH_KEY_FILE='.$previous);}};
try{
    (new Install($source))->run($config,$db);$auth=Runtime::service($db,$root.'/private/auth.key');
    $session=$auth->passwordLogin($config['admin_email'],$config['admin_password'],'own-before-restore');$enrollment=$auth->beginTotp($session['session_token'],$config['admin_password']);
    $confirmed=$auth->confirmTotp($session['session_token'],Totp::code($enrollment['secret'],time()));$oldCodes=$confirmed['recovery_codes'];$id=$confirmed['account']['id'];$account=$row($db,$id);
    $floorAccounts=[];$encrypt=new ReflectionMethod($auth,'encrypt');$nativeClub=Db::run($db,'SELECT club_id FROM native_users WHERE id=?',[$account['actor_id']])->fetchColumn();
    foreach(['high','suspended','pending']as$kind){
        $extra=bin2hex(random_bytes(16));$actor='own-restore-'.$kind;Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$actor,$nativeClub,'Own original '.$kind]);
        $secret=$encrypt->invoke($auth,$extra,Totp::secret());$counter=$kind==='high'?intdiv(time(),30)+10000:-1;
        Db::run($db,'INSERT INTO native_auth_accounts(id,actor_id,email,password_hash,active,verified,suspended,totp_secret,totp_counter,pending_secret,created_at) VALUES(?,?,?,?,1,1,?,?,?,?,?)',[$extra,$actor,$kind.'@example.invalid',$account['password_hash'],$kind==='suspended'?1:0,$kind==='pending'?null:$secret,$counter,$kind==='pending'?$secret:null,time()]);
        $floorAccounts[$kind]=$row($db,$extra);
    }
    $auth->requestReset($config['admin_email'],'own-reset-client');
    // Real original rows cover each ephemeral capability without any provider traffic.
    $at=time();$challenge=$auth->passwordLogin($config['admin_email'],$config['admin_password'],'own-original-challenge');
    Db::run($db,'INSERT INTO native_auth_tokens(token_hash,account_id,purpose,expires_at,created_at) VALUES(?,?,?,?,?)',[str_repeat('a',64),$id,'invite',$at+600,$at]);
    foreach(['login','address']as$purpose)Db::run($db,'INSERT INTO native_auth_email_codes(id,account_id,purpose,email,account_version,session_hash,client_hash,code_hash,expires_at,cipher,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[hash('sha256','own-'.$purpose),$id,$purpose,$account['email'],$account['version'],$purpose==='login'?null:hash('sha256',$session['session_token']),hash('sha256','own-client'),hash('sha256','own-email-code'),$at+600,'own-exact-'.$purpose.'-cipher','queued',$at]);
    $addressOriginal=Db::run($db,"SELECT * FROM native_auth_email_codes WHERE purpose='address'")->fetch();
    $bearer=$withAuthKey($root.'/private/auth.key',static function()use($db,$session,$config,$oldCodes):array{$tokens=\Kulturhus\Native\Api\Factory::tokens($db);$request=$tokens->prepare($session['session_token'],bin2hex(random_bytes(16)),'Own actual original bearer',['pages.read'],1,$config['admin_password']);return$tokens->issue($session['session_token'],$request['id'],$oldCodes[8]);});
    $bearerContext=$withAuthKey($root.'/private/auth.key',static fn()=>\Kulturhus\Native\Api\Http::authenticate($db,'Bearer '.$bearer['token'],str_repeat('f',64)));$bearerContext->require('pages.read');$check($bearerContext->mode==='bearer'&&$bearerContext->accountId===$id,'actual issued bearer authenticates and reads before backup despite invalid cookie');
    $bearerOriginal=Db::run($db,'SELECT * FROM native_api_tokens WHERE id=?',[$bearer['id']])->fetch();
    Db::run($db,'UPDATE native_auth_recovery SET used_at=? WHERE account_id=? AND code_hash=?',[$at-10,$id,hash('sha256',str_replace('-','',$oldCodes[9]))]);
    $oldRecovery=Db::run($db,'SELECT account_id,code_hash,used_at FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll();
    $keyHashes=[];foreach(['auth.key','private.key','member-bank-keys/v1.key']as$file)$keyHashes[$file]=hash_file('sha256',$root.'/private/'.$file);
    $privateOriginal="Own immutable original\0\xff Ä🙂\r\n";UpgradeFiles::atomic($root.'/private/own-restore-original.bin',$privateOriginal);
    // Code0 remains unused IN THE BACKUP and is consumed only afterwards in the source.
    $beforeOriginal=$originals($db);$sourceAccount=$permanent($account);
    $recovery=new Recovery($source);$backed=$recovery->create($root,$db,$dsn,$archive,$key);$check($backed['external_actions']===0&&$backed['encrypted']===true,'actual encrypted original archive created without transport');
    $afterBackup=$auth->passwordLogin($config['admin_email'],$config['admin_password'],'own-after-backup');$auth->verifySecondFactor($afterBackup['challenge'],$oldCodes[0],'own-after-backup');
    $check(Db::run($db,'SELECT used_at FROM native_auth_recovery WHERE account_id=? AND code_hash=?',[$id,hash('sha256',str_replace('-','',$oldCodes[0]))])->fetchColumn()!==null,'original code consumed after backup really represents rollback risk');
    [$restored,$restoredDsn]=$fresh();$restoreConfig=['target'=>$target,'dsn'=>$restoredDsn,'username'=>$fixture['username'],'password'=>$fixture['password']];
    $declined=new RestoreAccountFaultPdo($restoredDsn,$fixture['username'],$fixture['password'],'declined');
    try{(new Recovery($source))->restore($archive,$key,$restoreConfig,$declined);throw new RuntimeException('Review COMMIT must decline.');}catch(RuntimeException $e){if(!$declined->fired)throw$e;}
    $state=(new RestoreProgress($target,$key))->read();$check($declined->fired&&$state['phase']==='review'&&!isset($state['credentials'])&&is_int($state['security_review_at']),'declined review keeps exact signed timestamp and resumable review phase');
    $check($restored->query('SELECT credentials_json FROM native_restore_operation WHERE id=1')->fetchColumn()===null&&(int)$restored->query('SELECT COUNT(*) FROM native_auth_recovery WHERE revoked_at IS NOT NULL')->fetchColumn()===0,'unaccepted review COMMIT rolls back report and every revocation');
    $check(Db::run($restored,'SELECT totp_counter FROM native_auth_accounts WHERE id=?',[$id])->fetchColumn()===$account['totp_counter'],'unaccepted review preserves original authenticator counter');
    // Resume in a real child; lose COMMIT ACK, then SIGKILL before the signed report save.
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--kill-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own kill worker unavailable.');
    fwrite($pipes[0],json_encode($restoreConfig+['archive'=>$archive,'key'=>$key],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$deadline=microtime(true)+90;
    try{
        while(!str_contains($out,"OWN_CREDENTIALS_COMMITTED\n")&&microtime(true)<$deadline){$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running'])break;usleep(20000);}
        if(!str_contains($out,"OWN_CREDENTIALS_COMMITTED\n"))throw new RuntimeException('Own actual credentials checkpoint missing: '.$err);
        $check(proc_terminate($process,9),'operating system accepts SIGKILL for exact own credential worker');
        $killDeadline=microtime(true)+5;do{$status=proc_get_status($process);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$killDeadline);
        $check(!$status['running']&&$status['signaled']&&$status['termsig']===9,'actual own worker terminated by signal9 rather than normal exit');
    }finally{if((proc_get_status($process))['running'])proc_terminate($process,9);foreach([1,2]as$i)fclose($pipes[$i]);proc_close($process);}
    $saved=(new RestoreProgress($target,$key))->read();$committed=json_decode($restored->query('SELECT credentials_json FROM native_restore_operation WHERE id=1')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $check($saved['security_review_at']===$state['security_review_at']&&!isset($saved['credentials'])&&$committed['recovery_codes_revoked']===10,'true kill leaves original accepted SQL report despite lost ACK before MACstate report');
    $done=(new Recovery($source))->restore($archive,$key,$restoreConfig,$restored,true);$receipt=(new RestoreProgress($target,$key))->read(true);$reviewAt=$receipt['security_review_at'];$floor=intdiv($reviewAt,30)+1;
    $check($done['restored']===true&&$done['external_actions']===0&&$done['credentials']===$committed&&$receipt['credentials']===$committed,'resume returns identical committed counts and original timestamp');
    $check(!file_exists($target.'/restore-progress.json')&&!file_exists($target.'/private/restore-hold')&&is_file($target.'/private/recovery-hold'),'finished restore opens login while separate transport hold remains');
    $counts=['sessions_revoked','challenges_consumed','api_tokens_revoked','login_email_codes_consumed','reset_tokens_consumed','invite_tokens_consumed','recovery_codes_revoked','totp_counters_advanced'];$check($committed['total']===array_sum(array_intersect_key($committed,array_flip($counts)))&&$committed['totp_counters_advanced']===2,'fixed security report contains all eight exact revocation counts');
    foreach(['native_auth_sessions'=>'revoked_at','native_auth_challenges'=>'consumed_at','native_auth_tokens'=>'consumed_at','native_api_tokens'=>'revoked_at']as$table=>$field)$check((int)$restored->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$field.' IS NULL')->fetchColumn()===0,'restored old capability invalidated '.$table);
    $check(Db::run($restored,"SELECT * FROM native_auth_email_codes WHERE purpose='address'")->fetch()===$addressOriginal,'session-bound pending address proof retains original bytes');
    $email=Db::run($restored,"SELECT consumed_at,cipher,status FROM native_auth_email_codes WHERE purpose='login'")->fetch();$check((int)$email['consumed_at']===$reviewAt&&$email['cipher']===null&&$email['status']==='blocked','old sessionless login email code consumed without mail');
    $restoredBearer=Db::run($restored,'SELECT * FROM native_api_tokens WHERE id=?',[$bearer['id']])->fetch();$expectedBearer=$bearerOriginal;$expectedBearer['revoked_at']=$reviewAt;$check($restoredBearer===$expectedBearer,'real bearer original metadata remains exact except explicit fixed revocation');
    foreach([null,str_repeat('f',64)]as$cookie){try{$withAuthKey($target.'/private/auth.key',static fn()=>\Kulturhus\Native\Api\Http::authenticate($restored,'Bearer '.$bearer['token'],$cookie));throw new RuntimeException('Restored old bearer must refuse.');}catch(\Kulturhus\Native\Api\Problem $problem){$check($problem->status===401,'actual old bearer returns401 with absent or invalid cookie');}}
    $check(Db::run($restored,'SELECT account_id,code_hash,used_at FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll()===$oldRecovery&&(int)$restored->query('SELECT MIN(revoked_at) FROM native_auth_recovery')->fetchColumn()===$reviewAt,'old code hash and original used timestamp preserved with separate revocation');
    $check($permanent($row($restored,$id))===$sourceAccount&&(int)$row($restored,$id)['totp_counter']===$floor,'password seed account identity and version original; only replay floor advances');
    foreach($floorAccounts as$kind=>$original){$actual=$row($restored,$original['id']);$expected=$kind==='suspended'?$floor:(int)$original['totp_counter'];$check($permanent($actual)===$permanent($original)&&(int)$actual['totp_counter']===$expected,'TOTP floor preserves configured high counter and pending enrollment contract '.$kind);}
    foreach($keyHashes as$file=>$hash)$check(hash_equals($hash,hash_file('sha256',$target.'/private/'.$file)),'original private key remains exact '.$file);
    $check(file_get_contents($target.'/private/own-restore-original.bin')===$privateOriginal&&$originals($restored)===$beforeOriginal,'all other original tables and private binary file survive actual restore');
    $authKey=file_get_contents($target.'/private/auth.key');$now=$reviewAt;$current=new Service($restored,$authKey,static function()use(&$now):int{return$now;});
    $reject(fn()=>$current->resolve($session['session_token']),'restored old browser session cannot resolve');
    $reject(fn()=>$current->verifySecondFactor($challenge['challenge'],$oldCodes[1],'own-original-challenge'),'restored old MFA challenge cannot finish');
    foreach([$oldCodes[0],$oldCodes[1]]as$i=>$code){$start=$current->passwordLogin($config['admin_email'],$config['admin_password'],'own-revoked-code-'.$i);$reject(fn()=>$current->verifySecondFactor($start['challenge'],$code,'own-revoked-code-'.$i),'restored recovery code rejected on fresh challenge '.$i);}
    foreach([-1,0,1]as$offset){$start=$current->passwordLogin($config['admin_email'],$config['admin_password'],'own-old-totp-'.$offset);$code=Totp::code($enrollment['secret'],(intdiv($reviewAt,30)+$offset)*30);$reject(fn()=>$current->verifySecondFactor($start['challenge'],$code,'own-old-totp-'.$offset),'review drift-window TOTP step rejected '.$offset);}
    // No sleeping or weakened drift windows: use the real clock-injection contract.
    $now=($floor+1)*30;$start=$current->passwordLogin($config['admin_email'],$config['admin_password'],'own-fresh-totp');$genuine=$current->verifySecondFactor($start['challenge'],Totp::code($enrollment['secret'],$now),'own-fresh-totp');$check(isset($genuine['session_token'])&&$now-$reviewAt<=60,'unchanged authenticator accepts genuine later step within sixty seconds');
    try{$withAuthKey($target.'/private/auth.key',static fn()=>\Kulturhus\Native\Api\Http::authenticate($restored,'Bearer '.$bearer['token'],$genuine['session_token']));throw new RuntimeException('A valid cookie cannot rescue revoked bearer.');}catch(\Kulturhus\Native\Api\Problem $problem){$check($problem->status===401,'valid new session never rescues explicitly revoked bearer header');}
    $handoff=new RestoreAccounts($source);$inspection=$handoff->inspect($target,$restored,$restoredDsn,$key,$id);$check($inspection['eligible']===true&&$inspection['version']===(int)$account['version']&&!isset($inspection['codes']),'private operator inspects exact original eligible account metadata');
    $input=['account_id'=>$id,'original_alias'=>$inspection['original_alias'],'identity_reviewed'=>true,'account_version'=>$inspection['version'],'request_key'=>bin2hex(random_bytes(16))];
    foreach(['original_alias'=>'another-human','identity_reviewed'=>false,'account_version'=>$inspection['version']+1]as$field=>$value)$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,array_replace($input,[$field=>$value])),'handoff requires exact '.$field);
    $reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,bin2hex(random_bytes(32)),$input),'another backup key cannot authorize personal handoff');
    $reject(fn()=>$handoff->issue($target,$restored,str_replace('dbname=','dbname=foreign_',$restoredDsn),$key,$input),'actual connection must match signed restored database');
    $receiptFile=$target.'/private/restore-receipt.json';$receiptBytes=file_get_contents($receiptFile);$legacyReceipt=$receipt;unset($legacyReceipt['credentials']);(new RestoreProgress($target,$key))->save($legacyReceipt,true);$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'signed legacy completion without security report cannot authorize handoff');UpgradeFiles::atomic($receiptFile,$receiptBytes);
    foreach(['active'=>0,'verified'=>0,'suspended'=>1,'totp_secret'=>null]as$field=>$value){$original=$row($restored,$id)[$field];Db::run($restored,'UPDATE native_auth_accounts SET '.$field.'=? WHERE id=?',[$value,$id]);$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'current eligible account guard '.$field.' enforced');Db::run($restored,'UPDATE native_auth_accounts SET '.$field.'=? WHERE id=?',[$original,$id]);}
    $binding=Db::run($restored,'SELECT * FROM native_manager_identities WHERE native_id=?',[$account['actor_id']])->fetch();if(!$binding)throw new RuntimeException('Actual installed account binding missing.');
    Db::run($restored,'UPDATE actors SET active=0 WHERE id=?',[$binding['manager_actor_id']]);$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'native-active account cannot bypass revoked original manager identity');Db::run($restored,'UPDATE actors SET active=1 WHERE id=?',[$binding['manager_actor_id']]);
    $nativeClub=Db::run($restored,'SELECT club_id FROM native_users WHERE id=?',[$account['actor_id']])->fetchColumn();Db::run($restored,"UPDATE native_users SET club_id='own-foreign-club' WHERE id=?",[$account['actor_id']]);$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'foreign-club native account cannot receive codes from own restore');Db::run($restored,'UPDATE native_users SET club_id=? WHERE id=?',[$nativeClub,$account['actor_id']]);
    UpgradeFiles::atomic($target.'/private/restore-hold','own-test');$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'unfinished restore hold denies handoff');unlink($target.'/private/restore-hold');
    $envFile=$target.'/private/environment.json';$envBytes=file_get_contents($envFile);$env=json_decode($envBytes,true,32,JSON_THROW_ON_ERROR);$env['VEREIN_WEB_ROOT']=$target;UpgradeFiles::atomic($envFile,json_encode($env,JSON_THROW_ON_ERROR));$reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,$input),'actual installation exposing private tree as webroot cannot issue codes');UpgradeFiles::atomic($envFile,$envBytes);
    $permanentBefore=$permanent($row($restored,$id));$businessBefore=$originals($restored);
    $declinedHandoff=new RestoreAccountHandoffPdo($restoredDsn,$fixture['username'],$fixture['password'],false);try{$handoff->issue($target,$declinedHandoff,$restoredDsn,$key,$input);throw new RuntimeException('Handoff COMMIT must decline.');}catch(RuntimeException $e){if(!$declinedHandoff->fired)throw$e;}
    $check((int)$restored->query('SELECT COUNT(*) FROM native_auth_recovery WHERE revoked_at IS NULL')->fetchColumn()===0,'unaccepted handoff COMMIT leaves only encrypted prepared journal and no new database codes');
    $lostHandoff=new RestoreAccountHandoffPdo($restoredDsn,$fixture['username'],$fixture['password'],true);try{$handoff->issue($target,$lostHandoff,$restoredDsn,$key,$input);throw new RuntimeException('Handoff acknowledgement must be lost.');}catch(RuntimeException $e){if(!$lostHandoff->fired)throw$e;}
    $check((int)$restored->query('SELECT COUNT(*) FROM native_auth_recovery WHERE revoked_at IS NULL')->fetchColumn()===10&&$handoff->inspect($target,$restored,$restoredDsn,$key,$id)['handoff']['state']==='prepared','accepted handoff lost ACK keeps exact ten hashes and prepared encrypted journal');
    $result=$handoff->issue($target,$restored,$restoredDsn,$key,$input);$text=file_get_contents($result['output_file']);preg_match_all('/^[a-f0-9]{5}(?:-[a-f0-9]{5}){3}$/m',$text,$matches);$codes=$matches[0];
    $check($result['status']==='replayed'&&count($codes)===10&&count(array_unique($codes))===10&&(fileperms($result['output_file'])&0777)===0600,'operator receives same ten actual fresh codes after reconciled ACK only through private0600 file');
    $encrypted=file_get_contents(dirname(dirname($result['output_file'])).'/receipts/'.$id.'.json');$check(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),$codes[0])&&!str_contains($encrypted,$codes[0]),'handoff JSON and encrypted journal contain no plaintext codes');
    $check($permanent($row($restored,$id))===$permanentBefore&&$originals($restored)===$businessBefore,'code handoff does not alter original password factor roles financial or content rows');
    $hashSet=$restored->query('SELECT * FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll();$outputHash=hash('sha256',$text);unlink($result['output_file']);$again=$handoff->issue($target,$restored,$restoredDsn,$key,$input);$check($again['status']==='replayed'&&hash_file('sha256',$again['output_file'])===$outputHash&&$restored->query('SELECT * FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll()===$hashSet,'same request repairs lost private output without minting or rewriting original codes');
    $reject(fn()=>$handoff->issue($target,$restored,$restoredDsn,$key,array_replace($input,['request_key'=>bin2hex(random_bytes(16))])),'same account cannot silently request a different second code set');
    $keyFile=$work.'/backup.key';UpgradeFiles::atomic($keyFile,$key."\n");$cliInput=['root'=>$target,'dsn'=>$restoredDsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'key_file'=>$keyFile,'account_id'=>$id];
    [$exit,$out,$err]=$cli(['--inspect-restore-account'],$cliInput);$check($exit===0&&$err===''&&json_decode($out,true,32,JSON_THROW_ON_ERROR)['account_id']===$id&&!str_contains($out,$codes[0]),'actual private CLI inspection outputs metadata without raw codes');
    [$exit,$out,$err]=$cli(['--restore-account-codes'],$cliInput+array_diff_key($input,['account_id'=>1]));$check($exit===0&&$err===''&&json_decode($out,true,32,JSON_THROW_ON_ERROR)['status']==='replayed'&&!str_contains($out,$codes[0]),'actual private CLI repeats original handoff metadata only');
    $beforeDoneRows=$restored->query('SELECT * FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll();$beforeCounter=$row($restored,$id)['totp_counter'];$doneAgain=(new Recovery($source))->restore($archive,$key,$restoreConfig,$restored,true);
    $check($doneAgain['credentials']===$committed&&$beforeDoneRows===$restored->query('SELECT * FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll()&&$row($restored,$id)['totp_counter']===$beforeCounter,'completed restore resume never revokes new handoff codes or moves TOTP floor again');
    $newArchive=$work.'/after-handoff.zip';(new Recovery($source))->create($target,$restored,$restoredDsn,$newArchive,$key);$manifest=(new Recovery($source))->verify($newArchive,$key);$check(!array_filter(array_keys($manifest['files']),static fn(string $name):bool=>str_starts_with($name,'private/restore-account-codes/')),'actual subsequent encrypted backup excludes private handoff files and receipts');
    $start=$current->passwordLogin($config['admin_email'],$config['admin_password'],'own-new-code-login');$signed=$current->verifySecondFactor($start['challenge'],$codes[0],'own-new-code-login');$check(isset($signed['session_token']),'fresh private recovery code actually signs in with own password');
    $start=$current->passwordLogin($config['admin_email'],$config['admin_password'],'own-new-code-replay');$reject(fn()=>$current->verifySecondFactor($start['challenge'],$codes[0],'own-new-code-replay'),'same fresh recovery code cannot be used twice');
    $check($handoff->issue($target,$restored,$restoredDsn,$key,$input)['status']==='replayed','used-at history still allows original encrypted handoff receipt reconciliation');
    $current->disableTotp($signed['session_token'],$config['admin_password'],$codes[1]);$check($row($restored,$id)['totp_secret']===null,'second distinct private code enables explicit personal authenticator replacement without operator reset');
    // Explicit CLI migration of an actual old auth7 table, after all source-original proofs.
    $db->exec('ALTER TABLE native_auth_recovery DROP COLUMN revoked_at');$db->exec("UPDATE native_schema_versions SET version=7,applied_at='2020-01-02 03:04:05' WHERE module='auth'");$versions=$db->query("SELECT * FROM native_schema_versions WHERE module<>'auth' ORDER BY module")->fetchAll();$old=$db->query('SELECT account_id,code_hash,used_at FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll();
    $intent=bin2hex(random_bytes(24));$db->exec('CREATE TABLE native_restore_operation(id INT PRIMARY KEY,intent VARCHAR(48) NOT NULL) ENGINE=InnoDB');Db::run($db,'INSERT INTO native_restore_operation VALUES(1,?)',[$intent]);$oldState=['phase'=>'review','intent'=>$intent,'security_review_at'=>time(),'database'=>$fixture['database'],'server'=>$db->query('SELECT @@server_uuid')->fetchColumn()];
    foreach([6,9]as$unsupported){Db::run($db,"UPDATE native_schema_versions SET version=? WHERE module='auth'",[$unsupported]);$reject(fn()=>RestoreCredentials::review($db,$oldState),'unsupported original auth'.$unsupported.' rejected before migration');$check(!$db->query("SHOW COLUMNS FROM native_auth_recovery LIKE 'revoked_at'")->fetch()&&$old===$db->query('SELECT account_id,code_hash,used_at FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll()&&(int)$db->query("SELECT version FROM native_schema_versions WHERE module='auth'")->fetchColumn()===$unsupported,'unsupported auth'.$unsupported.' preserves original schema codes and metadata');}Db::run($db,"UPDATE native_schema_versions SET version=7 WHERE module='auth'");
    $migrated=RestoreCredentials::review($db,$oldState);$authVersion=$db->query("SELECT * FROM native_schema_versions WHERE module='auth'")->fetch();$check((int)$authVersion['version']===SchemaRegistry::VERSIONS['auth']&&$versions===$db->query("SELECT * FROM native_schema_versions WHERE module<>'auth' ORDER BY module")->fetchAll(),'private restore migrates auth7 to8 without changing any other module version or timestamp');
    $check($old===$db->query('SELECT account_id,code_hash,used_at FROM native_auth_recovery ORDER BY account_id,code_hash')->fetchAll(),'auth7 migration keeps exact existing hashes and used timestamps');
    $check(RestoreCredentials::review($db,$oldState)===$migrated&&$authVersion===$db->query("SELECT * FROM native_schema_versions WHERE module='auth'")->fetch(),'repeat review preserves exact original report and migration appliedAt');
    echo 'Native restore accounts: '.$count." conditions passed.\n";
}finally{foreach($databases as$name)$server->exec('DROP DATABASE `'.$name.'`');$remove($work);}
