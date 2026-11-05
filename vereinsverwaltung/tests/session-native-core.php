<?php declare(strict_types=1);
/** Exercise the installed native Session implementation without loading accounts or a database.
 * KULTURHUS_SESSION_CORE points to the unmodified read-only copy of wire/core/Session.php.
 * The small Wire/User fixtures provide surrounding services; validation/logout are native code.
 */
namespace ProcessWire {
    class Wire {
        protected object $fixtureRuntime;
        public function wire($name=null){return $name===null?$this->fixtureRuntime:($this->fixtureRuntime->$name??null);}
        public function __call($name,$arguments){return $this->{'___'.$name}(...$arguments);}
        public function trackChange(...$arguments){}
        public function log(...$arguments){}
        public function error($text,$flags=0){}
        public function _($text){return $text;}
    }
    class User {public int $id=42;public string $name='synthetic-session-user';}
}
namespace {
$source=(string)getenv('KULTURHUS_SESSION_CORE');
if(PHP_SAPI!=='cli'||$source===''||!is_file($source)){echo "SKIP native session core: provide read-only core source\n";exit;}
ob_start();require $source;
final class NativeCookieSession extends \ProcessWire\Session {
    public function __construct(object $config,object $runtime){$this->config=$config;$this->fixtureRuntime=$runtime;$this->sessionKey='Session';$this->sessionAllow=true;$this->___init();}
    public function valid():bool{return $this->___isValidSession(42);}
}
$dir=sys_get_temp_dir().'/kulturhus-native-session-'.bin2hex(random_bytes(8));mkdir($dir,0700);
mkdir($dir.'/webroot',0700);mkdir($dir.'/legacy',0700);
ini_set('session.gc_probability','1');ini_set('session.cookie_lifetime','2592000');ini_set('session.use_strict_mode','0');session_save_path($dir.'/legacy');
$config=(object)['sessionName'=>'KH_NATIVE_TEST','sessionNameSecure'=>'','https'=>false,'sessionCookieSecure'=>true,'sessionCookieDomain'=>'','sessionCookieSameSite'=>'Lax','sessionExpireSeconds'=>2592000,'sessionChallenge'=>true,'sessionFingerprint'=>8,'guestUserPageID'=>40,'sessionForceIP'=>''];
$config->paths=(object)['root'=>$dir.'/webroot/','sessions'=>$dir.'/legacy/'];
$config->kulturhusNativeSessionPath=$dir.'/private-native';
require __DIR__.'/../../processwire/site/src/NativeSessionStorage.php';
\Kulturhus\NativeSessionStorage::configure($config);
$sessionDir=$config->kulturhusNativeSessionStoragePath;
$runtime=(object)['user'=>new \ProcessWire\User(),'languages'=>false];
$runtime->users=new class($runtime) {
    public function __construct(private object $runtime){}
    public function get($id){return $this->runtime->user;}
    public function getGuestUser(){ $guest=new \ProcessWire\User();$guest->id=40;$guest->name='guest';return $guest;}
    public function setCurrentUser($user){$this->runtime->user=$user;}
};
$_SERVER['HTTP_USER_AGENT']='Synthetic native session browser';
$s=new NativeCookieSession($config,$runtime);$n=0;
$check=static function(bool $value,string $label)use(&$n){if(!$value)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
try{
    \Kulturhus\NativeSessionStorage::assertActive($config);
    $check(session_name()==='KH_NATIVE_TEST_v2'&&session_save_path()===$sessionDir,'native PW keeps the new namespace and private file store');
    $check((int)ini_get('session.gc_probability')===0&&(int)ini_get('session.use_strict_mode')===1,'native PW retains disabled shared GC and strict session IDs');
    $check($s->get('_user','id')===null,'new private store never restores an old authenticated identity');
    $challenge=bin2hex(random_bytes(32));$s->set('_user','id',42);$s->set('_user','challenge',$challenge);$s->set('_user','fingerprint',$s->getFingerprint());$s->set('_user','ts',time()-60);
    $_COOKIE[session_name()]=session_id();$_COOKIE[session_name().'_challenge']=$challenge;
    $check($s->valid(),'native core accepts matching challenge, fingerprint and live timestamp');
    $check($s->get('_user','ts')>=time()-1,'native core advances sliding inactivity timestamp');
    $_COOKIE[session_name().'_challenge']='wrong';$check(!$s->valid(),'native core rejects mismatched challenge');
    unset($_COOKIE[session_name().'_challenge']);$check(!$s->valid(),'native core rejects absent challenge');
    $_COOKIE[session_name().'_challenge']=$challenge;$_SERVER['HTTP_USER_AGENT']='Different browser';$check(!$s->valid(),'native core still rejects changed browser fingerprint');
    $_SERVER['HTTP_USER_AGENT']='Synthetic native session browser';$s->set('_user','ts',time()-86401);$check($s->valid(),'30-day native window remains valid after more than one day');
    $s->set('_user','ts',time()-2592001);$check(!$s->valid(),'native core expires login after 30-day inactivity despite persistent cookies');
    $config->sessionExpireSeconds=0;$s->set('_user','ts',time()-34560001);$check($s->valid(),'native zero idle setting does not expire logout-only mode by inactivity');
    $_COOKIE[session_name().'_challenge']='wrong';$check(!$s->valid(),'logout-only mode still rejects an invalid native challenge');
    $_COOKIE[session_name().'_challenge']=$challenge;
    $s->set('_user','ts',time());$check($s->valid(),'current session validates before explicit logout');
    $old=session_id();session_write_close();session_id($old);session_start();$check(is_file($sessionDir.'/sess_'.$old),'native PHP has durable original session before logout');
    if(DIRECTORY_SEPARATOR!=='\\')$check((fileperms($sessionDir.'/sess_'.$old)&0077)===0,'native session bytes are owner-only');
    $s->___logout();clearstatcache(true,$sessionDir.'/sess_'.$old);$check(session_id()!==$old&&!is_file($sessionDir.'/sess_'.$old),'native logout destroys old session file and rotates identifier');
    $check($runtime->user->id===40&&$s->get('_user','challenge')===null,'native logout removes authenticated user and server-side challenge');
    $check(!$s->valid(),'old challenge cookies cannot restore a logged-out native session');
    echo "$n native Session.php checks passed; source SHA256 ".hash_file('sha256',$source)."\n";
}finally{
    if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}
    foreach(new \DirectoryIterator($sessionDir) as $file)if(!$file->isDot())unlink($file->getPathname());
    rmdir($sessionDir);rmdir($dir.'/webroot');rmdir($dir.'/legacy');rmdir($dir);
    ob_end_flush();
}
}
