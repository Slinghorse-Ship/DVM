<?php declare(strict_types=1);
/** Isolated native PHP-cookie fixture: no real account, club database, or mail transport. */
namespace ProcessWire { final class Page { public const statusLocked=4; } }
namespace {
if(PHP_SAPI!=='cli-server'||getenv('VEREIN_MODE')!=='synthetic'||getenv('KULTURHUS_COOKIE_TEST')!=='1'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$asset=basename((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(in_array($asset,['style.css','settings.css'],true)){header('Content-Type: text/css');readfile(__DIR__.'/../../public/'.$asset);exit;}
require __DIR__.'/../../../processwire/site/src/WebsiteSessionPolicy.php';
require __DIR__.'/../../../processwire/site/src/PortalSessionDuration.php';
$directory=(string)getenv('KULTURHUS_COOKIE_DIRECTORY');
if(!is_dir($directory)||!str_contains(str_replace('\\','/',$directory),'/.local/session-cookie-test-'))throw new RuntimeException('Dedicated cookie fixture directory required.');
$legacy=($_GET['legacy']??'')==='1';
$fixtureWebRoot=$directory.'/webroot';if(!is_dir($fixtureWebRoot))mkdir($fixtureWebRoot,0700);
if(isset($_GET['gc'])){ini_set('session.gc_probability','1');ini_set('session.gc_divisor','1');}
$config=(object)['sessionExpireSeconds'=>isset($_GET['short'])?5:2592000,'sessionChallenge'=>true,
    'sessionName'=>'COOKIE_TEST_PW','sessionNameSecure'=>'COOKIE_TEST_PW','https'=>isset($_GET['secure']),
    'sessionCookieSecure'=>isset($_GET['secure']),'userAuthSalt'=>'synthetic-cookie-test-key-not-a-real-account-secret',
    'kulturhusVereinPortalEnabled'=>true,'kulturhusNativeSessionPath'=>$directory.'/private-native',
    'paths'=>(object)['root'=>$fixtureWebRoot.'/','sessions'=>$directory.'/']];
if(!$legacy)\Kulturhus\WebsiteSessionPolicy::configure($config);
$seconds=$config->sessionExpireSeconds;
ini_set('session.gc_maxlifetime',(string)$seconds);
ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
$nativeName=(string)$config->sessionName;
session_name($nativeName);if($legacy)session_save_path($directory);
session_set_cookie_params(['httponly'=>true,'secure'=>isset($_GET['secure']),'samesite'=>'Lax','path'=>'/']);
session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));
$valid=!empty($_SESSION['user'])&&is_string($_COOKIE[$nativeName.'_challenge']??null)
    &&hash_equals((string)($_SESSION['challenge']??''),$_COOKIE[$nativeName.'_challenge'])
    &&($seconds===0||(int)($_SESSION['last']??0)>=time()-$seconds);
if($valid)$_SESSION['last']=time();
final class CookieFixtureUser {
    public int $id=42;
    public function __construct(public bool $valid){}
    public function isLoggedin():bool{return $this->valid;}
    public function isGuest():bool{return !$this->valid;}
    public function isUnpublished():bool{return false;}
    public function isTrash():bool{return false;}
    public function hasStatus(int $status):bool{return false;}
}
final class CookieFixtureCsrf {
    public function getTokenName():string{return 'csrf';}
    public function getTokenValue():string{return $_SESSION['csrf'];}
    public function validate():void{if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new \DomainException('Invalid synthetic CSRF');}
}
$session=new class {
    public CookieFixtureCsrf $CSRF;
    public function __construct(){$this->CSRF=new CookieFixtureCsrf();}
    public function get(string $namespace,?string $key=null):mixed{
        if($namespace==='_user')return match($key){'challenge'=>$_SESSION['challenge']??null,'id'=>empty($_SESSION['user'])?0:42,default=>null};
        return $_SESSION[$namespace]??null;
    }
    public function set(string $key,mixed $value):void{$_SESSION[$key]=$value;}
    public function remove(string $key):void{unset($_SESSION[$key]);}
};
$wire=(object)['config'=>$config,'user'=>new CookieFixtureUser($valid),'session'=>$session];
if(!$legacy)\Kulturhus\WebsiteSessionPolicy::refresh($wire);
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(($_POST['portal_action']??'')==='session-duration'){
        \Kulturhus\PortalSessionDuration::save($wire);
        header('Location: /?device_ui=1',true,303);exit;
    }
    $session->CSRF->validate();
    if(($_POST['action']??'')==='login'){
        session_regenerate_id(true);$_SESSION['user']='synthetic-cookie-user';$_SESSION['last']=time();
        $_SESSION['challenge']=bin2hex(random_bytes(32));$_SESSION['csrf']=bin2hex(random_bytes(32));
        $wire->user->valid=true;$valid=true;
        if(!$legacy)\Kulturhus\WebsiteSessionPolicy::signedIn($wire);
        else setcookie('COOKIE_TEST_PW_challenge',$_SESSION['challenge'],['expires'=>time()+2592000,'httponly'=>true,'secure'=>isset($_GET['secure']),'samesite'=>'Lax','path'=>'/']);
    }elseif(($_POST['action']??'')==='logout'){
        $_SESSION=[];session_destroy();
        foreach([$nativeName,$nativeName.'_challenge',$nativeName.'_duration'] as $name)setcookie($name,'',['expires'=>time()-3600,'httponly'=>true,'samesite'=>'Lax','path'=>'/']);
        $valid=false;$wire->user->valid=false;
    }elseif(($_POST['action']??'')==='expire'){
        $_SESSION['last']=time()-$seconds-1;$valid=false;
    }elseif(($_POST['action']??'')==='age'){
        $_SESSION['last']=time()-max(0,min(34560001,(int)($_POST['age_seconds']??0)));
    }else{http_response_code(400);exit('Unsupported synthetic action');}
}catch(\DomainException $error){http_response_code(403);header('Content-Type: application/json');echo json_encode(['error'=>$error->getMessage()]);exit;}
$deviceSession=$legacy?null:\Kulturhus\WebsiteSessionPolicy::snapshot($wire);
if(isset($_GET['device_ui'])){
    require __DIR__.'/../../src/Web/View.php';
    $pwMode=true;$pwIdentity=['device_session'=>$deviceSession];
    header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic device selection</title><link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/settings.css"><main class="content settings-content">';
    require __DIR__.'/../../views/Auth/session-device-settings.php';
    echo '</main></html>';exit;
}
header('Cache-Control: no-store');header('Content-Type: application/json');
echo json_encode(['authenticated'=>$valid,'csrf'=>$_SESSION['csrf']??null,'cookie_params'=>session_get_cookie_params(),
    'idle_seconds'=>$config->sessionExpireSeconds,'gc_probability'=>(int)ini_get('session.gc_probability'),
    'private_storage'=>!$legacy&&session_save_path()===$config->kulturhusNativeSessionStoragePath,
    'mode'=>$config->kulturhusDeviceSessionMode??null,'device_session'=>$deviceSession]);
}
