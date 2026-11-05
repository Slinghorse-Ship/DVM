<?php declare(strict_types=1);
// Explicit local opt-in. Never points at or initializes the previous demo/runtime.
ini_set('zend.exception_ignore_args','1');ini_set('display_errors','0');
$root=dirname(__DIR__,3);require $root.'/vereinsverwaltung/native/bootstrap.php';
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!in_array($_SERVER['HTTP_HOST']??'',['127.0.0.1:8111','127.0.0.1:8118'],true)){http_response_code(403);exit;}
$workflowDemo=($_SERVER['HTTP_HOST']==='127.0.0.1:8118');
$fixtureLabel=$workflowDemo?'workflow-manager':'integrated-manager';$fixtureOrigin='http://'.$_SERVER['HTTP_HOST'];
putenv('KH_NATIVE_TEST_CONFIG='.$root.'/.local/native-cms-integration/'.$fixtureLabel.'.json');
$config=Kulturhus\Native\TestSupport::config();if(($config['label']??'')!==$fixtureLabel){http_response_code(503);exit;}
$runtime=$root.'/.local/native-cms-integration/'.$fixtureLabel.'-runtime';
if(!is_file($runtime.'/auth.key')){http_response_code(503);exit('Fixture zuerst explizit einrichten.');}
ini_set('log_errors','1');ini_set('error_log',$runtime.'/php.errors.log');
$pdfPython=getenv('VEREIN_PYTHON');$pdfRoots=is_string($pdfPython)&&str_starts_with($pdfPython,'/')?[dirname($pdfPython)]:[];
ini_set('open_basedir',implode(PATH_SEPARATOR,[$root.'/vereinsverwaltung',$root.'/banking',$runtime,$root.'/.local/native-cms-integration/'.$fixtureLabel.'.json',$root.'/.local/vereinsverwaltung',...$pdfRoots,...($workflowDemo?['C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/python']:[]),sys_get_temp_dir()]));
if($workflowDemo){putenv('VEREIN_PYTHON=C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe');putenv('VEREIN_PUBLIC_URL='.$fixtureOrigin.'/vereinsverwaltung');}
putenv('SMTP_DSN');putenv('VEREIN_AUTH=native');
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');
putenv('VEREIN_PROCESSWIRE_ROOT');
putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$runtime.'/sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');
putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');
putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$config['database'].';charset=utf8mb4');
putenv('VEREIN_DATABASE_USER='.$config['username']);putenv('VEREIN_DATABASE_PASSWORD='.$config['password']);
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'; media-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$fixtureOrigin
    &&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
if(class_exists(Kulturhus\Native\Integration\PublicSite::class)){
    Kulturhus\Native\Integration\ManagerSession::start();$publicDb=Kulturhus\Native\TestSupport::db();
    if(Kulturhus\Native\Integration\PublicSite::serve($publicDb,new Kulturhus\Native\Shared\Access($publicDb,'manager-public-reader'),$runtime.'/media',$runtime,'manager-demo',$fixtureOrigin))exit;
}
if($path==='/'){header('Location: /vereinsverwaltung/',true,303);exit;}
$authPath=rtrim($path,'/');
if(in_array($authPath,['/login','/account','/invite','/reset','/forgot','/accounts'],true)){
    Kulturhus\Native\Integration\ManagerSession::start();$db=Kulturhus\Native\TestSupport::db();$auth=Kulturhus\Native\Auth\Runtime::service($db);$state=[];$error='';
    if(isset($_SESSION['auth_token']))try{$auth->resolve($_SESSION['auth_token']);}catch(DomainException){unset($_SESSION['auth_token'],$_SESSION['actor']);}
    if($authPath==='/accounts'){
        try{$bridge=Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,$auth,is_string($_SESSION['auth_token']??null)?$_SESSION['auth_token']:'');$access=$bridge->access();$accounts=new Kulturhus\Native\Web\Accounts($db,$auth,$access,$_SESSION['csrf']);
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')$state=$accounts->action($_POST);
            header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');echo $accounts->render($state,$error);
        }catch(DomainException $e){http_response_code(403);echo 'Kontoverwaltung für diesen Zugang nicht verfügbar.';}exit;
    }
    $account=Kulturhus\Native\Web\Account::forManager($db,$auth,$_SESSION['csrf'],hash('sha256',$_SERVER['REMOTE_ADDR'].'|'.($_SERVER['HTTP_USER_AGENT']??'')),'/vereinsverwaltung/');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{$state=$account->action($_POST,$_SESSION);if(isset($state['redirect'])){header('Location: '.$state['redirect'],true,303);exit;}}catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}
    header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');echo $account->render($authPath,$_GET,$_SESSION,$state,$error);exit;
}
$assets=['/assets/account.css'=>$root.'/vereinsverwaltung/native/Web/assets/account.css',
    '/assets/kulturhus-site.css'=>$root.'/vereinsverwaltung/native/Web/assets/site/site.css','/assets/kulturhus-enhancements.css'=>$root.'/vereinsverwaltung/native/Web/assets/site/enhancements.css'];
if(isset($assets[$path])){header('Content-Type: text/css; charset=utf-8');readfile($assets[$path]);exit;}
if(!str_starts_with($path,'/vereinsverwaltung/')){http_response_code(404);exit;}
$relative=rawurldecode(substr($path,strlen('/vereinsverwaltung/')));
if($relative===''||$relative==='index.php'){require $root.'/vereinsverwaltung/public/index.php';exit;}
if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
if(str_contains($relative,"\0")||str_contains($relative,'\\')||in_array('..',explode('/',$relative),true)){http_response_code(404);exit;}
$public=realpath($root.'/vereinsverwaltung/public');$file=realpath($public.DIRECTORY_SEPARATOR.$relative);
$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','webmanifest'=>'application/manifest+json'];
$extension=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
if(!$file||!str_starts_with($file,$public.DIRECTORY_SEPARATOR)||!isset($types[$extension])){http_response_code(404);exit;}
header('Content-Type: '.$types[$extension]);readfile($file);
