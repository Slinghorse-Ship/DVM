<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-api-files'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$file=getenv('DVM_API_FILES_HTTP_FIXTURE');if(!$file||is_link($file)||!is_file($file)||fileperms($file)&0077){http_response_code(403);exit;}$f=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$native=$origin===$f['native_origin'];if(!$native&&$origin!==$f['origin']){http_response_code(403);exit;}
foreach(['VEREIN_PRIVATE_ROOT'=>$f['root'],'KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER_SESSION_ROOT'=>$f['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['root'].'/media','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1']as$key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
if($native){putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($origin,PHP_URL_PORT));putenv('KH_NATIVE_REQUIRE_LOGIN=1');putenv('KH_NATIVE_DEMO_MANAGER_CLUB='.$f['club']);}
if($path==='/own-api-files-enter'&&$method==='GET'){
    if($native){session_save_path($f['native_runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['native_csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['native_csrf'];}
    else{\Kulturhus\Native\Integration\ManagerSession::start();$csrf=$_SESSION['csrf'];}
    $_SESSION['auth_token']=$f['account']['token'];$_SESSION['actor']=$f['account']['actor'];header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['csrf'=>$csrf],JSON_THROW_ON_ERROR);exit;
}
if($native){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
// The same actual public application adapter owns the API before public slug resolution.
$db=TestSupport::db();if(!\Kulturhus\Native\Integration\PublicSite::serve($db,new \Kulturhus\Native\Shared\Access($db,$f['public_actor']),$f['root'].'/media',$f['root'],$f['club'],$f['origin']))http_response_code(404);
