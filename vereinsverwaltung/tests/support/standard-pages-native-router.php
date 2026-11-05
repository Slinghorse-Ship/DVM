<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-standard-pages'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_STANDARD_NATIVE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_REQUIRE_LOGIN=1');putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['root'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['private_key']);putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
 $role=$_GET['role']??'admin';if(!is_string($role)||!isset($f['accounts'][$role])){http_response_code(400);exit;}
 session_save_path($f['root'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$f['accounts'][$role];$_SESSION['native_actor']=$role;$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=website',true,303);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
