<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();$app=getenv('DVM_WEBSITE_WORKSPACE_APP');
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??null)!=='native-website-workspace'||($cfg['owner']??null)!=='isolated-native-ci'||!in_array($app,['native','integrated'],true)||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_WEBSITE_WORKSPACE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin=$f['origins'][$app];$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$method=$_SERVER['REQUEST_METHOD']??'GET';if('http://'.($_SERVER['HTTP_HOST']??'')!==$origin||(!in_array($method,['GET','HEAD'],true)&&!($app==='integrated'&&$path==='/mitglied-werden/'&&$method==='POST'))){http_response_code(403);exit;}
if($method==='POST'&&($_SERVER['HTTP_ORIGIN']??'')!==$origin){http_response_code(403);exit;}
putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['integrated_root'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['private_key']);putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');putenv('VEREIN_MODE=synthetic');
if($app==='native'){
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($origin,PHP_URL_PORT));putenv('KH_NATIVE_REQUIRE_LOGIN=1');
    if($path==='/own-workspace-enter'){
        session_save_path($f['native_root'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$f['native_token'];$_SESSION['native_csrf']=bin2hex(random_bytes(32));header('Location: /?view=overview',true,303);exit;
    }
    require dirname(__DIR__,2).'/native/Web/router.php';exit;
}
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$f['integrated_root'].'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$f['integrated_root'].'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-workspace-enter'){
    Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$f['integrated_account']['token'];$_SESSION['actor']=$f['integrated_account']['actor'];header('Location: /vereinsverwaltung/?view=website&tab=overview',true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
if(!Kulturhus\Native\Integration\PublicSite::serve(TestSupport::db(),new Kulturhus\Native\Shared\Access(TestSupport::db(),$f['public_actor']),$f['integrated_root'].'/media',$f['integrated_root'],$f['manager_club'],$origin))http_response_code(404);
