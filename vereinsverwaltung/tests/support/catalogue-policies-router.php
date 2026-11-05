<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-catalogue-policies'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$file=getenv('DVM_CATALOGUE_POLICY_FIXTURE');if(!$file||!is_file($file)){http_response_code(403);exit;}
$f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$requestStarted=microtime(true);$requestKind=['method'=>$_SERVER['REQUEST_METHOD']??'GET','path'=>$path,'operation'=>is_string($_POST['operation']??null)?$_POST['operation']:null];error_log('OWN_CATALOGUE_BEGIN '.json_encode($requestKind,JSON_THROW_ON_ERROR));register_shutdown_function(static function()use($requestStarted,$requestKind):void{error_log('OWN_CATALOGUE_END '.json_encode($requestKind+['status'=>http_response_code(),'ms'=>round((microtime(true)-$requestStarted)*1000,1)],JSON_THROW_ON_ERROR));});
putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['root'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['root'].'/private.key');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');putenv('VEREIN_MODE=synthetic');
if($f['mode']==='native'){
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_REQUIRE_LOGIN=1');
    if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $role=$_GET['role']??'';if(!is_string($role)||!isset($f['accounts'][$role])){http_response_code(400);exit;}
        session_save_path($f['native_runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);
        $_SESSION['auth_token']=$f['accounts'][$role]['token'];$_SESSION['native_actor']=$f['accounts'][$role]['actor'];$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=media',true,303);exit;
    }
    require dirname(__DIR__,2).'/native/Web/router.php';exit;
}
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$f['root'].'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$f['root'].'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
    $role=$_GET['role']??'';if(!is_string($role)||!isset($f['accounts'][$role])){http_response_code(400);exit;}
    Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$f['accounts'][$role]['token'];$_SESSION['actor']=$f['accounts'][$role]['actor'];header('Location: /vereinsverwaltung/?view=website&tab=gallery',true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}
    if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);exit;}
    $allowed=$f['public_assets'];
    $public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$type=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','webmanifest'=>'application/manifest+json'][strtolower(pathinfo($relative,PATHINFO_EXTENSION))]??null;
    if(!isset($allowed[$relative])||!$public||!$asset||!is_file($asset)||!str_starts_with($asset,$public.'/')||!$type){http_response_code(404);exit;}
    if(!hash_equals($allowed[$relative],hash_file('sha256',$asset))){http_response_code(503);exit;}
    header('Content-Type: '.$type);header('X-Content-Type-Options: nosniff');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($asset);exit;
}
http_response_code(404);
