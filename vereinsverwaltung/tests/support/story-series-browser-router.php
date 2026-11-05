<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-story-automation'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_STORY_BROWSER_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'';
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if($method==='POST'&&(!in_array($_POST['operation']??null,['social-canvas-save','social-canvas-review'],true)||($_POST['id']??null)!==$f['item'])){http_response_code(403);exit;}
$runtime=$f['root'];putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');
putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');
if($path==='/own-test-enter'&&$method==='GET'){
    session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$f['actor'];$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=social&section=compose&item='.$f['item'],true,303);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
