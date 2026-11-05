<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require __DIR__.'/k-browser-fixture.php';
try{$f=ownKBrowserFixture();}catch(Throwable){http_response_code(403);exit;}
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1'||'http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
$cfg=Kulturhus\Native\TestSupport::config();$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['runtime'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['runtime'].'/private.key');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');putenv('VEREIN_MODE=synthetic');
if($f['standalone']){
    if(!str_starts_with($path,'/assets/'))require dirname(__DIR__,2).'/src/bootstrap.php';
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_REQUIRE_LOGIN=1');
    require dirname(__DIR__,2).'/native/Web/router.php';exit;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$f['runtime'].'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$f['runtime'].'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
// Identical source-bound static dispatch, without the unrelated business bootstrap.
if(str_starts_with($path,'/vereinsverwaltung/')&&!in_array(substr($path,strlen('/vereinsverwaltung/')),['','index.php'],true)){
    $relative=substr($path,strlen('/vereinsverwaltung/'));
    if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$type=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'][strtolower(pathinfo($relative,PATHINFO_EXTENSION))]??null;
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)||!isset($f['asset_proofs']['vereinsverwaltung/public/'.$relative])||!$file||!str_starts_with($file,$public.'/')||!$type){http_response_code(404);exit;}
    if(!hash_equals($f['asset_proofs']['vereinsverwaltung/public/'.$relative],hash_file('sha256',$file))){http_response_code(503);exit;}header('Content-Type: '.$type);header('X-Content-Type-Options: nosniff');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file);exit;
}
require dirname(__DIR__,2).'/src/bootstrap.php';
$authPath=rtrim($path,'/');
if(in_array($authPath,['/login','/account','/invite','/reset','/forgot'],true)){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)){http_response_code(405);exit;}
    Kulturhus\Native\Integration\ManagerSession::start();$db=Kulturhus\Native\TestSupport::db();$auth=Kulturhus\Native\Auth\Runtime::service($db);$state=[];$error='';
    $account=Kulturhus\Native\Web\Account::forManager($db,$auth,$_SESSION['csrf'],hash('sha256',$f['origin'].'|'.($_SERVER['REMOTE_ADDR']??'')),'/vereinsverwaltung/');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{$state=$account->action($_POST,$_SESSION);if(isset($state['redirect'])){header('Location: '.$state['redirect'],true,303);exit;}}catch(DomainException $failure){http_response_code(422);$error=$failure->getMessage();}
    header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $account->render($authPath,$_GET,$_SESSION,$state,$error);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){require dirname(__DIR__,2).'/public/index.php';exit;}
$db=Kulturhus\Native\TestSupport::db();
if(!Kulturhus\Native\Integration\PublicSite::serve($db,new Kulturhus\Native\Shared\Access($db,$f['apps']['integrated']['public_actor']),$f['runtime'].'/media',$f['runtime'],$f['apps']['integrated']['manager_club'],$f['origin']))http_response_code(404);
