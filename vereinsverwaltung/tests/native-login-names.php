<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Auth\{Service,Schema,Conflict,Totp};
use Kulturhus\Native\Web\{Account,Accounts};
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);Schema::install($db);
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','accounts','manage')");
$now=time();$key=random_bytes(32);$auth=new Service($db,$key,static function()use(&$now){return $now;});$admin=new Access($db,'admin');$password='Personal login original 2026!';
foreach(['reader'=>'family-one','editor'=>'family-two']as $actor=>$alias){$invite=$auth->createInvitation($admin,$actor,'family@example.invalid',86400,$alias);$auth->acceptInvitation($invite['token'],$password);}
$session=$auth->passwordLogin('family-one',$password,'first')['session_token'];$other=$auth->passwordLogin('family-one',$password,'other')['session_token'];$second=$auth->passwordLogin('family-two',$password,'second')['session_token'];
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $e){check($e instanceof $class,$label);return;}throw new RuntimeException($label);}
$before=$auth->resolve($session);$accountId=$before['id'];$raw=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$accountId])->fetch();$secondBefore=$auth->resolve($second);
$grant=Db::run($db,'SELECT * FROM native_grants WHERE actor_id=? ORDER BY module,capability',[$before['actor_id']])->fetchAll();
$changed=$auth->changeLoginName($session,$password,' Personal.Name-2026 ',$before['version']);
check($changed['id']===$before['id']&&$changed['actor_id']===$before['actor_id']&&$changed['login_name']==='personal.name-2026'&&$changed['version']===$before['version']+1,'personal alias normalizes and retains exact account/person identity');
$after=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$accountId])->fetch();unset($raw['login_name'],$raw['version'],$after['login_name'],$after['version']);
check($raw===$after,'mailbox, password bytes, MFA, suspension and all other account fields remain exact');
check($auth->resolve($other)['login_name']===$changed['login_name']&&$auth->resolve($second)===$secondBefore,'existing devices stay authenticated and other family account remains exact');
check(Db::run($db,'SELECT * FROM native_grants WHERE actor_id=? ORDER BY module,capability',[$before['actor_id']])->fetchAll()===$grant,'alias cannot change club or module authority');
$changed=$auth->resolve($session);
$revision=Db::run($db,'SELECT * FROM native_auth_login_names WHERE account_id=?',[$accountId])->fetch();
check($revision['previous_name']==='family-one'&&$revision['login_name']==='personal.name-2026'&&(int)$revision['account_version']===$changed['version'],'private alias history binds old and new names to original account version');
rejects(fn()=>$auth->passwordLogin('family-one',$password,'old'),DomainException::class,'old alias cannot choose another family member');
check($auth->passwordLogin('personal.name-2026',$password,'new')['account']['id']===$accountId,'actual authentication uses new personal alias');
rejects(fn()=>$auth->passwordLogin('family@example.invalid',$password,'ambiguous'),DomainException::class,'shared mailbox still cannot choose a family identity');
rejects(fn()=>$auth->changeLoginName($session,$password,'family-two',$changed['version']),Conflict::class,'another personal alias cannot be taken');
check($auth->resolve($session)===$changed&&$auth->resolve($second)===$secondBefore,'alias collision rolls back both account and history');
rejects(fn()=>$auth->changeLoginName($session,'Incorrect password','new-name',$changed['version']),DomainException::class,'current password required for own alias change');
rejects(fn()=>$auth->changeLoginName($session,$password,'new-name',$before['version']),Conflict::class,'stale original account version cannot replace newer alias');
$now+=901;
check($auth->changeLoginName($session,$password,$changed['login_name'],$changed['version'])===$changed,'identical alias is a no-op without extra history or version');
foreach(['','@name','_name','name with spaces','müller',str_repeat('a',91),"bad\nname"]as $name)rejects(fn()=>$auth->changeLoginName($session,$password,$name,$changed['version']),DomainException::class,'unsupported or malformed personal alias rejected');
$db->beginTransaction();$auth->changeLoginName($session,$password,'rolled-back',$changed['version']);$db->rollBack();
check($auth->resolve($session)===$changed,'outer rollback preserves alias and original history');
// No password/factor secrets are ever echoed or retained in a failed form.
$view=new Account($auth,'csrf','browser');$state=['auth_token'=>$session];
rejects(fn()=>$view->action(['csrf'=>'wrong','operation'=>'change-login-name'],$state),DomainException::class,'account form retains CSRF guard');
rejects(fn()=>$view->action(['csrf'=>'csrf','operation'=>'change-login-name','version'=>[]],$state),DomainException::class,'account form rejects forged version array');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['operation'=>'change-login-name','version'=>(string)$before['version'],'login_name'=>'proposed-name','current_password'=>$password];
$html=$view->render('/account',[],$state,[],'Konto inzwischen geändert.');
check(str_contains($html,'account-login-name" open')&&str_contains($html,'value="proposed-name"')&&str_contains($html,'name="version" value="'.$before['version'].'"'),'failed alias form keeps proposal and originally submitted version');
check(!str_contains($html,$password)&&str_contains($html,'class="form-control"')&&str_contains($html,'btn btn-solid theme-primary'),'form does not echo password and uses installed Bootstrap controls');
$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
$html=(new Accounts($db,$auth,$admin,'csrf'))->render();
check(str_contains($html,'bootstrap-6.0.0-alpha.1.min.css')&&str_contains($html,'scope="col"')&&str_contains($html,'caption class="visually-hidden"'),'account administration loads local Bootstrap and accessible real table');
// An existing authenticator and its recovery originals survive a personal alias change.
$setup=$auth->beginTotp($second,$password);$auth->confirmTotp($second,Totp::code($setup['secret'],$now));
$mfaBefore=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$secondBefore['id']])->fetch();
$recovery=Db::run($db,'SELECT * FROM native_auth_recovery WHERE account_id=? ORDER BY code_hash',[$secondBefore['id']])->fetchAll();
$stamp=$auth->reauthenticationState($second)['stamp'];
$auth->changeLoginName($second,$password,'family-two-updated',(int)$mfaBefore['version']);
$mfaAfter=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$secondBefore['id']])->fetch();unset($mfaBefore['login_name'],$mfaBefore['version'],$mfaAfter['login_name'],$mfaAfter['version']);
check($mfaBefore===$mfaAfter&&Db::run($db,'SELECT * FROM native_auth_recovery WHERE account_id=? ORDER BY code_hash',[$secondBefore['id']])->fetchAll()===$recovery,'existing encrypted authenticator and all recovery originals remain exact');
check($auth->reauthenticationState($second)['stamp']!==$stamp,'account change invalidates a previously bound sensitive-operation stamp');
$login=$auth->passwordLogin('family-two-updated',$password,'mfa-login');
check($login['status']==='second_factor','actual renamed account still requires its original second factor');
// Failure budget persists beyond rollback and is shared by already signed-in devices.
$now+=901;
for($i=0;$i<5;++$i)rejects(fn()=>$auth->changeLoginName($i%2?$other:$session,'wrong','blocked-name',$changed['version']),DomainException::class,'wrong password attempt counted across devices');
rejects(fn()=>$auth->changeLoginName($other,$password,'blocked-name',$changed['version']),DomainException::class,'correct password cannot bypass exhausted persistent attempt budget');
check($auth->resolve($session)===$changed,'failed attempts preserve current original alias');
$now+=901;$disabled=$auth->setActive($admin,$accountId,false,$changed['version']);
rejects(fn()=>$auth->changeLoginName($session,$password,'disabled-alias',$disabled['version']),DomainException::class,'revoked account cannot change alias through old session');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
