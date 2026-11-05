<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||!in_array(TestSupport::config()['label']??'',['native-video-storage-inventory','native-social-library','native-social-month','native-editorial-hub','native-video-recovery-ui','native-video-reuse','native-carousel-ui'],true)||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$fixtureVar=match(TestSupport::config()['label']??''){'native-social-library'=>'DVM_SOCIAL_LIBRARY_FIXTURE','native-social-month'=>'DVM_SOCIAL_MONTH_FIXTURE','native-editorial-hub'=>'DVM_EDITORIAL_HUB_FIXTURE','native-video-recovery-ui'=>'DVM_VIDEO_RECOVERY_FIXTURE','native-video-reuse'=>'DVM_VIDEO_REUSE_FIXTURE','native-carousel-ui'=>'DVM_CAROUSEL_FIXTURE',default=>'DVM_VIDEO_STORAGE_FIXTURE'};
$f=json_decode(file_get_contents(getenv($fixtureVar)),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']||(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true)&&!(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(((TestSupport::config()['label']??'')==='native-video-recovery-ui'&&($_POST['operation']??'')==='social-video-recover')||((TestSupport::config()['label']??'')==='native-video-reuse'&&($_POST['operation']??'')==='social-video-reuse')||((TestSupport::config()['label']??'')==='native-social-library'&&in_array($_POST['operation']??'',['social-sync','social-insights','social-comments','stadtlandfunk-sync','social-inbox-sync'],true))||((TestSupport::config()['label']??'')==='native-carousel-ui'&&in_array($_POST['operation']??'',['communication-save','social-render','communication-approve','communication-schedule'],true)))))){http_response_code(403);exit;}
if((TestSupport::config()['label']??'')==='native-social-library'&&isset($f['accounts']))require __DIR__.'/social-library-json-http.php';
$cfg=TestSupport::config();$runtime=$f['root'];$port=parse_url($f['origin'],PHP_URL_PORT);
putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');
putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$runtime.'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');
putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if((TestSupport::config()['label']??'')==='native-social-library'&&isset($f['accounts']))putenv('KH_NATIVE_REQUIRE_LOGIN=1');
if($path==='/own-test-enter'){
    $mode=$_GET['mode']??'';if(!in_array($mode,['native','integrated'],true)){http_response_code(400);exit;}
    $account=null;if((TestSupport::config()['label']??'')==='native-social-library'&&isset($f['accounts'])){$locale=$_GET['locale']??'de';$role=$_GET['role']??'admin';if(!is_string($locale)||!is_string($role)||!isset($f['accounts'][$role.'_'.$locale])){http_response_code(400);exit;}$account=$f['accounts'][$role.'_'.$locale];putenv('KH_NATIVE_REQUIRE_LOGIN=1');}
    if($mode==='integrated'){Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token']??$f['token'];$_SESSION['actor']=$account['manager_actor']??$f['manager_actor']??'video-storage-browser-admin';if($account)$_SESSION['csrf']=$account['csrf'];$url='/vereinsverwaltung/';}
    else{session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$account['actor']??$f['actor'];$_SESSION['native_csrf']=$account['csrf']??bin2hex(random_bytes(32));if($account)$_SESSION['auth_token']=$account['token'];$url='/';}
    $item=$f['item']??'';$section=$f['section']??'compose';if(!in_array($section,['compose','library','planning'],true)){http_response_code(400);exit;}
    $query=$mode==='integrated'?Kulturhus\Native\Integration\Routes::query('social',$item):['view'=>'social','item'=>$item];
    header('Location: '.$url.'?'.http_build_query($query+['section'=>$section]),true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));
    if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}
    if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];
    $ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}
    header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
