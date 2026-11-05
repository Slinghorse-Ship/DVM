<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-meta-webhooks'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_META_WEBHOOK_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$cfg=TestSupport::config();$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['root'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['root'].'/private.key');putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$f['root'].'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$f['root'].'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
 $mode=$_GET['mode']??'';if(!isset($f['accounts'][$mode])){http_response_code(400);exit;}$account=$f['accounts'][$mode];
 if($mode==='integrated'){Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['manager_actor'];$url='/vereinsverwaltung/?view=website&tab=integrations';}
 else{$sessions=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'].'/sessions';if(!is_dir($sessions))mkdir($sessions,0700,true);session_save_path($sessions);session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$account['actor'];$_SESSION['native_csrf']=bin2hex(random_bytes(32));$url='/?view=settings';}header('Location: '.$url,true,303);exit;
}
// Exercise the actual production public projection on a separate anonymous route.
if(str_starts_with($path,'/own-public-integrated/')){
    $_SERVER['REQUEST_URI']=substr($_SERVER['REQUEST_URI'],strlen('/own-public-integrated'));
    $db=TestSupport::db();
    $public=new \Kulturhus\Native\Shared\Access($db,\Kulturhus\Native\Integration\ProductionSite::publicActor($f['manager_club']));
    if(\Kulturhus\Native\Integration\PublicSite::serve($db,$public,$f['root'].'/media',$f['root'],$f['manager_club'],$f['origin']))exit;
    http_response_code(404);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
 $relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
 $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
