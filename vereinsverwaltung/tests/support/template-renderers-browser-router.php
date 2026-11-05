<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-template-renderers'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_EDITORIAL_WORKFLOW_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');
$started=microtime(true);register_shutdown_function(static function()use($f,$started):void{file_put_contents($f['root'].'/renderer-requests.log',json_encode(['path'=>$_SERVER['REQUEST_URI'],'status'=>http_response_code(),'ms'=>round((microtime(true)-$started)*1000)],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);});
if($origin===($f['data']['native_origin']??null)){
 foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_DEMO_MANAGER_CLUB'=>$f['club'],'KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['root'].'/media','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1']as$key=>$value)putenv($key.'='.$value);
 if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){session_save_path($f['native_runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$f['accounts']['admin']['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($f['accounts']['admin']['actor'],$f['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=model',true,303);exit;}
 require dirname(__DIR__,2).'/native/Web/router.php';exit;
}
if($origin!==$f['origin']){http_response_code(403);exit;}
require __DIR__.'/editorial-browser-router.php';
