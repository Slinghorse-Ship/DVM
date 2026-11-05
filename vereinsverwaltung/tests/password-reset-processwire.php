<?php declare(strict_types=1);
/** Native-core integration test: run only against the explicitly local ProcessWire container. */
if(PHP_SAPI!=='cli'||getenv('KULTURHUS_LOCAL_DEMO')!=='1'||!is_file('/var/www/html/index.php')){echo "SKIP password-reset-processwire: requires the local synthetic ProcessWire CLI\n";exit(0);}
require '/var/www/html/index.php';
require __DIR__.'/../src/bootstrap.php';
$wire=\ProcessWire\wire();
$wire->modules->get('ProcessForgotPassword');
require_once '/var/www/html/site/src/MailDeliveryPolicy.php';
require_once __DIR__.'/../../processwire/site/src/CentralPasswordReset.php';
final class SyntheticPasswordReset extends \Kulturhus\CentralPasswordReset
{
    public string $cachePrefix='';
    public function state(string $key):mixed{return $this->sessionGet($key);}
    public function start():string{return $this->step1_renderForm();}
    public function request():string{return $this->step2_processForm();}
    public function followLink():string{return $this->step3_processEmailClick();}
    public function allowed(\ProcessWire\User $user):bool{$reason='';return $this->allowUser($user,$reason);}
    public function rateAllowed():bool{return $this->allowResetRequest();}
    public function expire():void{$this->setupResetTable();}
    protected function getRequestCacheName($ip=null){return $this->cachePrefix;}
}
$n=0;function ok(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('synthetic','Synthetic reset test')");
$users=[];$messages=[];$prefix='kh-reset-test-'.bin2hex(random_bytes(6));
$newUser=static function(string $suffix)use($wire,$prefix,&$users):\ProcessWire\User{
    $user=$wire->wire(new \ProcessWire\User());$user->name=$prefix.$suffix;$user->email=$prefix.$suffix.'@example.invalid';$user->pass='Synthetic-previous-password-123!';$user->addRole('guest');$user->addRole('verein-member');$user->save();$users[]=(int)$user->id;return $user;
};
$reset=$wire->wire(new SyntheticPasswordReset());$reset->cachePrefix=$prefix;
$reset->configure($db,'synthetic','https://example.invalid',static function($email,$subject,$text,$html,$attachments)use(&$messages):void{$messages[]=compact('email','subject','text','html','attachments');});
$setPost=static function(array $data)use($wire):void{$_POST=$data;$wire->input->post->removeAll();foreach($data as $name=>$value)$wire->input->post->set($name,$value);};
$setGet=static function(array $data)use($wire):void{$_GET=$data;$wire->input->get->removeAll();foreach($data as $name=>$value)$wire->input->get->set($name,$value);};
$fresh=static function()use($wire,$reset,$setPost,$setGet):void{$wire->session->removeAllFor($reset);$wire->cache->delete($reset->cachePrefix);$setPost([]);$setGet([]);};
try{
    $user=$newUser('-one');$other=$newUser('-two');$other->email=$user->email;$other->save();
    $db->prepare("INSERT INTO actors(id,club_id,role,active) VALUES('synthetic-actor','synthetic','member',1)")->execute();
    $db->prepare("INSERT INTO processwire_identities VALUES(?,'synthetic-actor',1,1)")->execute([(int)$user->id]);
    $fresh();$_SERVER['REQUEST_METHOD']='GET';$reset->expire();$form=$reset->execute();
    ok(str_contains($form,'name="username"')&&str_contains($form,'name="submit_forgot"')&&str_contains($form,'TOKEN'),'request form uses native state and named CSRF token');
    $_SERVER['REQUEST_METHOD']='POST';$setPost(['username'=>$user->name,'submit_forgot'=>'1']);
    $result=$reset->execute();ok(str_contains($result,'Formular ist abgelaufen')&&!$messages,'missing CSRF stops before account lookup and mail');
    $fresh();$reset->start();$setPost(['username'=>$user->name,'submit_forgot'=>'1']);$local=$reset->request();
    $q=$wire->database->prepare('SELECT COUNT(*) FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);
    ok(!$messages&&(int)$q->fetchColumn()===0,'local mode blocks reset delivery and removes unsent native token');
    foreach(['KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','VEREIN_MODE','APP_ENV'] as $key)putenv($key);putenv('APP_URL=https://example.invalid');
    $fresh();$reset->start();$setPost(['username'=>$user->name,'submit_forgot'=>'1']);$known=$reset->request();
    ok(count($messages)===1&&$messages[0]['email']===$user->email&&$messages[0]['attachments']===[],'native request sends only to the stored address through a fake callback');
    ok(str_contains($messages[0]['html'],'Neues Passwort festlegen')&&str_contains($messages[0]['text'],'60 Minuten'),'reset email uses shared brand and time limit');
    $q=$wire->database->prepare('SELECT token FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);$token=(string)$q->fetchColumn();$verify=(string)$reset->state('verify');
    ok(strlen($token)===32&&strlen($verify)>15&&(int)$reset->state('id')===(int)$user->id,'native token and verification code bind one account and session');
    $setGet(['forgot'=>'1','u'=>(string)$user->id,'t'=>$token]);$setPost([]);$passwordForm=$reset->followLink();
    ok(str_contains($passwordForm,'name="pass"')&&str_contains($passwordForm,'name="_pass"')&&str_contains($passwordForm,'minlength="12"'),'valid native link renders modern password and repeat fields');
    $setPost(['submit_reset'=>'1','verify'=>$verify,'pass'=>'Synthetic-new-password-456!','_pass'=>'Synthetic-new-password-456!']);$done=$reset->followLink();
    $changed=$wire->pages->getFresh((int)$user->id);$untouched=$wire->pages->getFresh((int)$other->id);
    ok(str_contains($done,'Passwort wurde geändert')&&$changed->pass->matches('Synthetic-new-password-456!')&&!$changed->pass->matches('Synthetic-previous-password-123!'),'native completion updates the existing ProcessWire password');
    ok($untouched->pass->matches('Synthetic-previous-password-123!'),'reset leaves other account with same mailbox untouched');
    $q=$wire->database->prepare('SELECT COUNT(*) FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);ok((int)$q->fetchColumn()===0&&!$reset->state('id'),'successful reset consumes database token and session proof');
    $setPost([]);ok(!str_contains($reset->followLink(),'name="pass"'),'consumed token cannot open another password form');
    $fresh();$reset->start();$setPost(['username'=>$prefix.'-missing','submit_forgot'=>'1']);$unknown=$reset->request();
    ok($known===$unknown&&$local===$known&&count($messages)===1,'known unknown and locally blocked requests return identical confirmation');
    $db->exec("UPDATE processwire_identities SET enabled=0 WHERE actor_id='synthetic-actor'");ok(!$reset->allowed($changed),'disabled central binding cannot reset password');
    $db->exec("UPDATE processwire_identities SET enabled=1 WHERE actor_id='synthetic-actor';UPDATE actors SET active=0 WHERE id='synthetic-actor'");ok(!$reset->allowed($changed),'inactive actor cannot reset password');
    $db->exec("UPDATE actors SET active=1 WHERE id='synthetic-actor'");
    $fresh();$reset->start();$setPost(['username'=>$user->name,'submit_forgot'=>'1']);$reset->request();$q=$wire->database->prepare('SELECT token FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);$newToken=(string)$q->fetchColumn();$newVerify=(string)$reset->state('verify');
    $setGet(['forgot'=>'1','u'=>(string)$user->id,'t'=>$newToken]);$setPost([]);$reset->followLink();$db->exec("UPDATE actors SET active=0 WHERE id='synthetic-actor'");
    $setPost(['submit_reset'=>'1','verify'=>$newVerify,'pass'=>'Must-not-become-password-123!','_pass'=>'Must-not-become-password-123!']);$blocked=$reset->followLink();
    ok(str_contains($blocked,'nicht mehr gültig')&&$wire->pages->getFresh((int)$user->id)->pass->matches('Synthetic-new-password-456!'),'account blocked after request stays blocked at completion');
    $db->exec("UPDATE actors SET active=1 WHERE id='synthetic-actor'");$fresh();$reset->start();$setPost(['username'=>$user->name,'submit_forgot'=>'1']);$reset->request();
    $q=$wire->database->prepare('UPDATE process_forgot_password SET ts=? WHERE id=?');$q->execute([time()-3601,(int)$user->id]);$reset->expire();$q=$wire->database->prepare('SELECT COUNT(*) FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);ok((int)$q->fetchColumn()===0,'native cleanup expires reset after 60 minutes');
    // Repeated calls in this one CLI request share WireCache's memory snapshot; session quota is still enforced.
    $fresh();for($i=0;$i<4;$i++){$reset->start();$setPost(['username'=>$prefix.'-missing','submit_forgot'=>'1']);$reset->request();}
    ok(!$reset->rateAllowed(),'native session throttles repeated requests including nonexistent accounts');
    $fresh();$_SERVER['REQUEST_METHOD']='GET';$reset->execute();$csrf=$wire->session->CSRF;$csrfName=$csrf->getTokenName('central-password-reset');$csrfValue=$csrf->getTokenValue('central-password-reset');
    $_SERVER['REQUEST_METHOD']='POST';$setPost(['username'=>$prefix.'-missing','submit_forgot'=>'1',$csrfName=>$csrfValue]);
    $csrfResult=$reset->execute();ok(str_contains($csrfResult,'Wenn ein passender aktiver Zugang'),'valid named CSRF submits the native request form');
    $fresh();$reset->start();$setPost(['username'=>$user->name,'submit_forgot'=>'1']);$reset->request();$q=$wire->database->prepare('SELECT token FROM process_forgot_password WHERE id=?');$q->execute([(int)$user->id]);$ownToken=(string)$q->fetchColumn();
    $setGet(['forgot'=>'1','u'=>(string)$other->id,'t'=>$ownToken]);$setPost([]);
    ok(!str_contains($reset->followLink(),'name="pass"')&&$wire->pages->getFresh((int)$other->id)->pass->matches('Synthetic-previous-password-123!'),'swapping the account ID cannot authorize a different account reset');
    $wire->users->setCurrentUser($other);ok($reset->execute()==='','authenticated members cannot initiate resets for other accounts');$wire->users->setCurrentUser($wire->users->get($wire->config->guestUserPageID));
    echo "$n native ProcessWire password reset checks passed; temporary synthetic users and fake delivery only\n";
}finally{
    putenv('KULTURHUS_LOCAL_DEMO=1');$wire->cache->delete($prefix);$wire->session->removeAllFor($reset);
    foreach($users as $id){$user=$wire->pages->getFresh($id);if($user instanceof \ProcessWire\User&&str_starts_with((string)$user->name,$prefix)&&str_ends_with((string)$user->email,'@example.invalid')){$q=$wire->database->prepare('DELETE FROM process_forgot_password WHERE id=?');$q->execute([$id]);$wire->users->delete($user);}}
}
