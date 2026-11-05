<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';

/** Genuine standalone router, isolated CI schema, own auth key and exact original Native Auth sessions. */
function ownContentListsNativeStart(array $fixture,array $data):array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions',$runtime.'/media'] as $directory)if(!is_dir($directory))mkdir($directory,0700,true);
    copy($fixture['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
    $fixture['runtime']=$runtime;$fixture['data']=$data;unset($fixture['admin']);$file=$fixture['root'].'/content-lists-native.json';file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_CONTENT_LISTS_NATIVE_FIXTURE='.$file);
    $log=fopen($fixture['root'].'/native-http.log','ab');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),'-t',$fixture['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own standalone list router unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    putenv('DVM_EDITORIAL_WORKFLOW_FIXTURE='.$file);return ['process'=>$process,'log'=>$log,'fixture'=>$fixture];
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-content-lists'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $fixture=json_decode(file_get_contents(getenv('DVM_CONTENT_LISTS_NATIVE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);if('http://'.($_SERVER['HTTP_HOST']??'')!==$fixture['origin']){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$fixture['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($fixture['origin'],PHP_URL_PORT),'KH_NATIVE_AUTH_KEY_FILE'=>$fixture['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$fixture['root'].'/private.key','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1'] as $key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $role=$_GET['role']??'admin';if(!is_string($role)||!isset($fixture['accounts'][$role])){http_response_code(400);exit;}$account=$fixture['accounts'][$role];
        session_save_path($fixture['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$fixture['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=website',true,303);exit;
    }
    require dirname(__DIR__,2).'/native/Web/router.php';exit;
}
