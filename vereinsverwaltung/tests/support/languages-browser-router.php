<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;

// Only this suite's fresh database and two owned loopback servers may enter here.
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-languages'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_EDITORIAL_WORKFLOW_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
$origin='http://'.($_SERVER['HTTP_HOST']??'');$native=$origin===($f['data']['native_origin']??null);
if(!$native&&$origin!==$f['origin']){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
foreach(['KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER_SESSION_ROOT'=>$f['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['root'].'/media','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1']as$key=>$value)putenv($key.'='.$value);
putenv('SMTP_DSN');
if($path==='/own-language-originals'){
    require_once __DIR__.'/original-database-hash.php';$db=TestSupport::db();
    $tables=array_values(array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),static fn(string $table):bool=>$table!=='native_auth_sessions'));
    header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode(['original_sha256'=>ownOriginalDatabaseHash($db,$tables)],JSON_THROW_ON_ERROR);exit;
}
if($native){putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($origin,PHP_URL_PORT));require dirname(__DIR__,2).'/native/Web/router.php';exit;}
if(in_array(rtrim($path,'/'),['/account','/login','/forgot','/invite','/reset'],true)){
    // The actual ProductionSite account dispatcher uses this same service and session contract.
    \Kulturhus\Native\Integration\ManagerSession::start();$db=TestSupport::db();$auth=\Kulturhus\Native\Auth\Runtime::service($db);
    $view=\Kulturhus\Native\Web\Account::forManager($db,$auth,$_SESSION['csrf'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.($_SERVER['HTTP_USER_AGENT']??'')));$state=[];$error='';
    if($method==='POST')try{$state=$view->action($_POST,$_SESSION);if(isset($state['redirect'])){header('Location: '.$state['redirect'],true,303);exit;}}catch(DomainException $failure){http_response_code(422);$error=$failure->getMessage();}
    header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo $view->render(rtrim($path,'/'),$_GET,$_SESSION,$state,$error);exit;
}
if($path==='/assets/account.css'){header('Content-Type: text/css');readfile(dirname(__DIR__,2).'/native/Web/assets/account.css');exit;}
require __DIR__.'/editorial-browser-router.php';
