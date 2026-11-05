<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Auth\{Schema,Service,Totp,PreservedPassword};

if(($argv[1]??'')==='--race-factor'){
    $data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $service=new Service(TestSupport::db(),base64_decode($data['key']),static fn()=>(int)$data['now']);
    while(microtime(true)<$data['start'])usleep(1000);
    try{$service->verifySecondFactor($data['challenge'],$data['code'],$data['client']);echo 'ok';}catch(DomainException){echo 'denied';}
    exit;
}
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;}
function denied(callable $call,string $label):void{try{$call();}catch(DomainException){check(true,$label);return;}check(false,$label);}
function race(array $first,array $second):array
{
    $children=[];$start=microtime(true)+0.8;
    foreach([$first,$second] as $data){$process=proc_open([...TestSupport::phpCommand(),__FILE__,'--race-factor'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start isolated auth race');fwrite($pipes[0],json_encode($data+['start'=>$start],JSON_THROW_ON_ERROR));fclose($pipes[0]);$children[]=[$process,$pipes];}
    $results=[];foreach($children as [$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0||$errors!==''){preg_match('/Uncaught ([A-Za-z\\\\]+)/',$errors,$kind);preg_match('/SQLSTATE\[[A-Za-z0-9]+\]/',$errors,$state);throw new RuntimeException('Auth race process failed: '.($kind[1]??'unexpected subprocess diagnostics').' '.($state[0]??'').'; private details withheld.');}}sort($results);return $results;
}
// Published RFC 6238 Appendix B vectors, not its reference implementation.
$vectors=[59=>['94287082','46119246','90693936'],1111111109=>['07081804','68084774','25091201'],1111111111=>['14050471','67062674','99943326'],1234567890=>['89005924','91819424','93441116'],2000000000=>['69279037','90698825','38618901'],20000000000=>['65353130','77737706','47863826']];
foreach($vectors as $time=>$expected){foreach(['sha1'=>20,'sha256'=>32,'sha512'=>64] as $algorithm=>$length){$index=array_search($algorithm,['sha1','sha256','sha512']);$secret=Totp::encode(substr(str_repeat('1234567890',7),0,$length));check(Totp::code($secret,(int)$time,8,$algorithm)===$expected[$index],'RFC6238 vector '.$algorithm.' '.$time);}}
check(Totp::encode('12345678901234567890')==='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','own Base32 encoder interoperability');
$totp=Totp::secret();check(strlen($totp)===32&&Totp::secret()!==$totp,'unique random 160-bit TOTP secret');
check(Totp::match($totp,Totp::code($totp,300),300,9)===10,'TOTP current step accepted');
check(Totp::match($totp,Totp::code($totp,300),300,10)===null,'TOTP used step rejected');
check(Totp::match($totp,Totp::code($totp,270),300,-1)===9,'TOTP previous step bounded drift');
check(Totp::match($totp,Totp::code($totp,240),300,-1)===null,'TOTP outside drift rejected');

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);Schema::install($db);
$run='auth-test-'.bin2hex(random_bytes(6));$club=$run.'-club';
foreach(['admin','member','extra','foreign','expired'] as $role)Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$run.'-'.$role,$role==='foreign'?$run.'-other':$club,'Synthetisches Konto '.$role]);
Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,"accounts","manage")',[$run.'-admin']);
$admin=new Access($db,$run.'-admin');$memberAccess=new Access($db,$run.'-member');
$now=1800000000;$clock=static function()use(&$now):int{return $now;};$encryptionKey=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$auth=new Service($db,$encryptionKey,$clock);$password='Synthetische Anmeldung 2026!';$newPassword='Anderes eigenes Passwort 2026!';$email=$run.'@example.invalid';
denied(fn()=>new Service($db,'short'),'encryption key size enforced');
denied(fn()=>$auth->createInvitation($memberAccess,$run.'-member',$email),'invitation requires explicit accounts manage');
denied(fn()=>$auth->createInvitation($admin,$run.'-foreign',$run.'-foreign@example.invalid'),'invitation tenant boundary');
$invitation=$auth->createInvitation($admin,$run.'-member',strtoupper($email));
check($invitation['account']['email']===$email&&!$invitation['account']['active']&&!$invitation['account']['verified'],'invitation creates inactive unverified account');
check(Db::run($db,'SELECT token_hash FROM native_auth_tokens WHERE account_id=?',[$invitation['account']['id']])->fetchColumn()===hash('sha256',$invitation['token']),'invitation stored only hashed');
denied(fn()=>$auth->acceptInvitation($invitation['token'],'short'),'short password rejected without consuming invite');
denied(fn()=>$auth->acceptInvitation($invitation['token'],str_repeat('x',73)),'bcrypt byte truncation disallowed');
$account=$auth->acceptInvitation($invitation['token'],$password);$accountId=$account['id'];
check($account['active']&&$account['verified']&&!$account['totp_enabled'],'invitation activation reports account state');
check((int)Db::run($db,'SELECT COUNT(*) FROM native_grants WHERE actor_id=?',[$run.'-member'])->fetchColumn()===0,'account creation grants no module permissions');
$stored=Db::run($db,'SELECT password_hash FROM native_auth_accounts WHERE id=?',[$accountId])->fetchColumn();check($stored!==$password&&password_verify($password,$stored),'PHP password hash stored');
$oldSystemSalt='synthetic-old-site-system-salt-only';
$oldBcrypt=crypt($password.$oldSystemSalt,'$2y$04$abcdefghijklmnopqrstuu');
$preserved=PreservedPassword::seal($oldBcrypt,$oldSystemSalt,$encryptionKey,$accountId);
check(strlen($preserved)<=255&&!str_contains($preserved,$oldSystemSalt)&&!str_contains($preserved,$oldBcrypt),'preserved bcrypt and original system salt encrypted inside bounded account envelope');
check($preserved!==PreservedPassword::seal($oldBcrypt,$oldSystemSalt,$encryptionKey,$accountId),'each preserved envelope uses a fresh nonce');
check(PreservedPassword::verify($password,$preserved,$encryptionKey,$accountId),'independent original crypt password interoperates without ProcessWire runtime');
check(!PreservedPassword::verify('wrong',$preserved,$encryptionKey,$accountId),'wrong original password denied');
check(!PreservedPassword::verify($password,$preserved,random_bytes(32),$accountId),'wrong installation key cannot use original password');
check(!PreservedPassword::verify($password,$preserved,$encryptionKey,str_repeat('f',32)),'copied envelope cannot authenticate a different account');
$tampered=$preserved;$tampered[strlen($tampered)-8]=$tampered[strlen($tampered)-8]==='A'?'B':'A';
check(!PreservedPassword::verify($password,$tampered,$encryptionKey,$accountId),'modified preserved credentials fail closed');
foreach(['DVM-PW1:','DVM-PW1:!','DVM-PW1:'.str_repeat('A',260)]as$invalid)check(!PreservedPassword::verify($password,$invalid,$encryptionKey,$accountId),'invalid preserved envelope rejected');
denied(fn()=>PreservedPassword::seal(md5($password),$oldSystemSalt,$encryptionKey,$accountId),'obsolete non-bcrypt hashes are not imported');
denied(fn()=>PreservedPassword::seal($oldBcrypt,str_repeat('x',81),$encryptionKey,$accountId),'oversized legacy salt cannot exceed the account hash column');
check(strlen(PreservedPassword::seal($oldBcrypt,str_repeat('x',80),$encryptionKey,$accountId))<=255,'largest supported original salt fits existing schema');
Db::run($db,'UPDATE native_auth_accounts SET password_hash=? WHERE id=?',[$preserved,$accountId]);
denied(fn()=>$auth->acceptInvitation($invitation['token'],$password),'invitation single use');
denied(fn()=>$auth->createInvitation($admin,$run.'-member',$email),'active account cannot be re-invited to replace password');
$expired=$auth->createInvitation($admin,$run.'-expired',$run.'-expired@example.invalid',60);$now+=61;denied(fn()=>$auth->acceptInvitation($expired['token'],$password),'expired invitation denied');
denied(fn()=>$auth->passwordLogin($email,'wrong-password-value','login-a'),'wrong password rejected');
check(Db::run($db,'SELECT password_hash FROM native_auth_accounts WHERE id=?',[$accountId])->fetchColumn()===$preserved,'failed original login retains the original envelope');
denied(fn()=>$auth->passwordLogin($run.'-missing@example.invalid',$password,'login-a'),'unknown account generic rejection');
$session=$auth->passwordLogin($email,$password,'login-a');$token=$session['session_token'];
$upgradedHash=Db::run($db,'SELECT password_hash FROM native_auth_accounts WHERE id=?',[$accountId])->fetchColumn();
check(password_verify($password,$upgradedHash)&&!str_starts_with($upgradedHash,'DVM-PW1:'),'first successful original login atomically replaces the envelope with a current native hash');
check($session['status']==='authenticated'&&$session['expires_at']===$now+28800,'password login issues bounded full session only without 2FA');
check($auth->resolve($token)['actor_id']===$run.'-member'&&$auth->resolve($token)['club_id']===$club,'session resolves native actor and current club');
check(!isset($auth->resolve($token)['password_hash'])&&!isset($auth->resolve($token)['totp_secret']),'resolved identity excludes credential fields');
check((bool)Db::run($db,'SELECT 1 FROM native_auth_sessions WHERE token_hash=?',[hash('sha256',$token)])->fetchColumn(),'session stored only hashed');
$second=$auth->passwordLogin($email,$password,'login-b');
denied(fn()=>$auth->beginTotp($token,'wrong-current-password'),'enrollment rechecks password');
$setup=$auth->beginTotp($token,$password);
check(str_starts_with($setup['otpauth_uri'],'otpauth://totp/')&&str_contains($setup['otpauth_uri'],'digits=6'),'authenticator provisioning parameters');
$cipher=Db::run($db,'SELECT pending_secret FROM native_auth_accounts WHERE id=?',[$accountId])->fetchColumn();check(!str_contains($cipher,$setup['secret']),'pending secret encrypted at rest');
denied(fn()=>$auth->confirmTotp($second['session_token'],Totp::code($setup['secret'],$now)),'enrollment bound to confirming session');
denied(fn()=>$auth->confirmTotp($token,'wrong-code'),'wrong setup code denied');
$recovery=$auth->confirmTotp($token,Totp::code($setup['secret'],$now));
check(count($recovery['recovery_codes'])===10&&$recovery['account']['totp_enabled'],'TOTP enables only after code and returns ten recovery codes');
check(count(array_unique($recovery['recovery_codes']))===10,'recovery codes unique');
check((int)Db::run($db,'SELECT COUNT(*) FROM native_auth_recovery WHERE account_id=?',[$accountId])->fetchColumn()===10,'only recovery hashes persisted');
check(!str_contains(json_encode(Db::run($db,'SELECT code_hash FROM native_auth_recovery WHERE account_id=?',[$accountId])->fetchAll()),str_replace('-','',$recovery['recovery_codes'][0])),'recovery plaintext not stored');
denied(fn()=>$auth->resolve($second['session_token']),'enrollment revokes other password-only sessions');
check($auth->resolve($token)['totp_enabled'],'confirming session remains valid');
Db::run($db,'UPDATE native_auth_accounts SET password_hash=? WHERE id=?',[$preserved,$accountId]);
$challenge=$auth->passwordLogin($email,$password,'factor-a');
check(password_verify($password,Db::run($db,'SELECT password_hash FROM native_auth_accounts WHERE id=?',[$accountId])->fetchColumn()),'preserved password upgrades while the original second factor stays mandatory');
check($challenge['status']==='second_factor'&&!isset($challenge['session_token'])&&!isset($challenge['account']),'password alone does not expose authenticated session with 2FA');
denied(fn()=>$auth->resolve($challenge['challenge']),'challenge cannot authenticate as full session');
denied(fn()=>$auth->verifySecondFactor($challenge['challenge'],Totp::code($setup['secret'],$now),'factor-a'),'enrollment TOTP cannot be replayed');
$now+=30;$code=Totp::code($setup['secret'],$now);
denied(fn()=>$auth->verifySecondFactor($challenge['challenge'],$code,'other-client'),'challenge bound to initial client');
$factorSession=$auth->verifySecondFactor($challenge['challenge'],$code,'factor-a');
check($factorSession['status']==='authenticated'&&$auth->resolve($factorSession['session_token'])['totp_enabled'],'valid second factor issues full session');
denied(fn()=>$auth->verifySecondFactor($challenge['challenge'],$code,'factor-a'),'challenge cannot be reused');
$otherChallenge=$auth->passwordLogin($email,$password,'factor-b');
denied(fn()=>$auth->verifySecondFactor($otherChallenge['challenge'],$code,'factor-b'),'TOTP step cannot replay across challenges');
$recovered=$auth->verifySecondFactor($otherChallenge['challenge'],strtoupper($recovery['recovery_codes'][0]),'factor-b');
check($recovered['status']==='authenticated','recovery code accepted as second factor');
$reuse=$auth->passwordLogin($email,$password,'factor-c');denied(fn()=>$auth->verifySecondFactor($reuse['challenge'],$recovery['recovery_codes'][0],'factor-c'),'recovery code single use');
$expiredChallenge=$auth->passwordLogin($email,$password,'factor-expired');$now+=301;denied(fn()=>$auth->verifySecondFactor($expiredChallenge['challenge'],Totp::code($setup['secret'],$now),'factor-expired'),'expired second-factor challenge denied');

$now+=1000;$a=$auth->passwordLogin($email,$password,'race-a');$b=$auth->passwordLogin($email,$password,'race-b');$raceCode=Totp::code($setup['secret'],$now);
$common=['key'=>base64_encode($encryptionKey),'now'=>$now,'code'=>$raceCode];
check(race($common+['challenge'=>$a['challenge'],'client'=>'race-a'],$common+['challenge'=>$b['challenge'],'client'=>'race-b'])===['denied','ok'],'parallel TOTP replay permits exactly one full session');
$now+=1000;$a=$auth->passwordLogin($email,$password,'recovery-race-a');$b=$auth->passwordLogin($email,$password,'recovery-race-b');$common=['key'=>base64_encode($encryptionKey),'now'=>$now,'code'=>$recovery['recovery_codes'][1]];
check(race($common+['challenge'=>$a['challenge'],'client'=>'recovery-race-a'],$common+['challenge'=>$b['challenge'],'client'=>'recovery-race-b'])===['denied','ok'],'parallel recovery use permits exactly one full session');

$reset=$auth->requestReset($email,'reset-a');check($reset!==null&&$reset['expires_at']===$now+1800,'trusted reset adapter gets expiring token');
check($auth->requestReset($run.'-none@example.invalid','reset-b')===null,'unknown reset request produces no token');
$auth->resetPassword($reset['token'],$newPassword);
denied(fn()=>$auth->resolve($token),'reset revokes old full session');denied(fn()=>$auth->resetPassword($reset['token'],$password),'reset token single use');
denied(fn()=>$auth->passwordLogin($email,$password,'after-reset'),'old password fails after reset');
$afterReset=$auth->passwordLogin($email,$newPassword,'after-reset');check($afterReset['status']==='second_factor','password reset preserves second-factor requirement');
$now+=30;$logged=$auth->verifySecondFactor($afterReset['challenge'],Totp::code($setup['secret'],$now),'after-reset');$token=$logged['session_token'];
// An existing repeatable-read snapshot must not keep a revoked session alive in the manager bridge.
$db2=TestSupport::db();$secondAuth=new Service($db2,$encryptionKey,$clock);$db->beginTransaction();$db->query('SELECT COUNT(*) FROM native_auth_sessions')->fetchColumn();$secondAuth->logout($token);
denied(fn()=>$auth->resolve($token),'resolve inside existing transaction sees current revocation');check($db->inTransaction(),'resolve does not commit caller transaction');$db->rollBack();
$now+=30;$next=$auth->passwordLogin($email,$newPassword,'disable-a');$logged=$auth->verifySecondFactor($next['challenge'],Totp::code($setup['secret'],$now),'disable-a');$token=$logged['session_token'];
denied(fn()=>$auth->disableTotp($token,$newPassword,'bad-factor'),'disabling requires current second factor');
$auth->disableTotp($token,$newPassword,$recovery['recovery_codes'][2]);check(!$auth->resolve($token)['totp_enabled'],'valid password and recovery code can disable TOTP');
check((int)Db::run($db,'SELECT COUNT(*) FROM native_auth_recovery WHERE account_id=?',[$accountId])->fetchColumn()===0,'disabling deletes recovery hashes');
$changed=$auth->changePassword($token,$newPassword,$password);denied(fn()=>$auth->resolve($token),'password change revokes prior session');check($auth->resolve($changed['session_token'])['id']===$accountId,'password change rotates current session');
$token=$changed['session_token'];$state=$auth->resolve($token);$inactive=$auth->setActive($admin,$accountId,false,$state['version']);check(!$inactive['active'],'administrator can disable own-club account');
denied(fn()=>$auth->resolve($token),'disabled account revokes active session');denied(fn()=>$auth->passwordLogin($email,$password,'disabled'),'disabled account cannot log in');
denied(fn()=>$auth->setActive($admin,$accountId,true,$state['version']),'account status expected version enforced');
$auth->setActive($admin,$accountId,true,$inactive['version']);$token=$auth->passwordLogin($email,$password,'active')['session_token'];
Db::run($db,'UPDATE native_users SET active=0 WHERE id=?',[$run.'-member']);denied(fn()=>$auth->resolve($token),'native actor deactivation immediately blocks auth');Db::run($db,'UPDATE native_users SET active=1 WHERE id=?',[$run.'-member']);
$now+=28801;denied(fn()=>$auth->resolve($token),'session expires at fixed deadline');

$now+=1000;
for($i=0;$i<8;$i++)denied(fn()=>$auth->passwordLogin($email,'incorrect-password-'.$i,'throttle-'.$i),'bad password attempt counted');
denied(fn()=>$auth->passwordLogin($email,$password,'throttle-correct'),'account-wide password limit spans clients');
$now+=901;$limitedSession=$auth->passwordLogin($email,$password,'throttle-correct');check($limitedSession['status']==='authenticated','password rate window expires');
$setup2=$auth->beginTotp($limitedSession['session_token'],$password);$auth->confirmTotp($limitedSession['session_token'],Totp::code($setup2['secret'],$now));$now+=30;
for($i=0;$i<8;$i++){$attempt=$auth->passwordLogin($email,$password,'brute-factor-'.$i);denied(fn()=>$auth->verifySecondFactor($attempt['challenge'],'not-a-code','brute-factor-'.$i),'bad second factor counted across challenges');}
$blocked=$auth->passwordLogin($email,$password,'brute-correct');denied(fn()=>$auth->verifySecondFactor($blocked['challenge'],Totp::code($setup2['secret'],$now),'brute-correct'),'account-wide factor limit blocks new challenges');
$now+=901;$fresh=$auth->passwordLogin($email,$password,'fresh');$good=$auth->verifySecondFactor($fresh['challenge'],Totp::code($setup2['secret'],$now),'fresh');check($good['status']==='authenticated','factor rate window expires');
$auth->revokeSessions($good['session_token']);denied(fn()=>$auth->resolve($good['session_token']),'revoke all includes current session');
$now+=1000;$wrongKeyChallenge=$auth->passwordLogin($email,$password,'wrong-key');$wrongKeyAuth=new Service($db,random_bytes(32),$clock);
denied(fn()=>$wrongKeyAuth->verifySecondFactor($wrongKeyChallenge['challenge'],Totp::code($setup2['secret'],$now),'wrong-key'),'wrong deployment encryption key cannot authenticate factor');
check($auth->verifySecondFactor($wrongKeyChallenge['challenge'],Totp::code($setup2['secret'],$now),'wrong-key')['status']==='authenticated','failed decryption does not consume valid challenge');
$rotated=$auth->createInvitation($admin,$run.'-extra',$run.'-extra@example.invalid');$replacement=$auth->createInvitation($admin,$run.'-extra',$run.'-extra@example.invalid');
denied(fn()=>$auth->acceptInvitation($rotated['token'],$password),'new invitation invalidates previous invitation');
$extraAccount=$auth->acceptInvitation($replacement['token'],$password);$extraSession=$auth->passwordLogin($run.'-extra@example.invalid',$password,'extra');
$extraSetup=$auth->beginTotp($extraSession['session_token'],$password);$now+=601;
denied(fn()=>$auth->confirmTotp($extraSession['session_token'],Totp::code($extraSetup['secret'],$now)),'expired TOTP enrollment cannot enable factor');
check(!$auth->resolve($extraSession['session_token'])['totp_enabled'],'expired enrollment leaves password login unchanged');
$extraReset=$auth->requestReset($run.'-extra@example.invalid','expired-reset');$now+=1801;denied(fn()=>$auth->resetPassword($extraReset['token'],$newPassword),'expired reset token rejected');
$foreignAdmin=new Access($db,$run.'-foreign');Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,"accounts","manage")',[$run.'-foreign']);
denied(fn()=>$auth->setActive($foreignAdmin,$extraAccount['id'],false,$extraAccount['version']),'account management remains tenant scoped');
denied(fn()=>$auth->resolve(str_repeat('g',64)),'malformed full session token rejected');
denied(fn()=>$auth->accounts($memberAccess),'account directory requires manage capability');
$directory=$auth->accounts($admin);check(count($directory)===3&&isset($directory[0]['name']),'administrator directory lists own club accounts with names');
check(!str_contains(json_encode($directory),'password_hash')&&!str_contains(json_encode($directory),'totp_secret'),'account directory exposes no credential material');
check($auth->accounts($foreignAdmin)===[],'foreign club administrator sees no accounts');
Db::run($db,'INSERT INTO native_users(id,club_id,name,active) VALUES(?,?,?,0)',[$run.'-inactive',$club,'Inactive test actor']);
denied(fn()=>$auth->createInvitation($admin,$run.'-inactive',$run.'-inactive@example.invalid'),'inactive native actor cannot be invited');
Db::run($db,'UPDATE native_users SET active=1 WHERE id=?',[$run.'-inactive']);$pending=$auth->createInvitation($admin,$run.'-inactive',$run.'-inactive@example.invalid');
$auth->setActive($admin,$pending['account']['id'],false,$pending['account']['version']);
denied(fn()=>$auth->acceptInvitation($pending['token'],$password),'administrator disabling pending account revokes invitation');
echo 'Native auth: '.$checks.' checks passed; RFC6238 vectors, isolated MySQL, two real factor races, no mail transport.'.PHP_EOL;
