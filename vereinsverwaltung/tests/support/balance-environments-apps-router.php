<?php declare(strict_types=1);
/** Fixed private loopback deployment context; actual manager and site entry points below. */
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-balance-environments-apps'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$fixture=json_decode(file_get_contents(getenv('DVM_BALANCE_ENVIRONMENTS_APPS_FIXTURE')),true,64,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$fixture['origins'],true);if($app===false){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
foreach(['KH_NATIVE_AUTH_KEY_FILE'=>$fixture['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$fixture['root'].'/private.key','KH_NATIVE_MANAGER_SESSION_ROOT'=>$fixture['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$fixture['root'].'/media','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'production','VEREIN_BALANCE_WORKSPACE'=>'1','VEREIN_ALLOWED_HOSTS'=>'own-balance.example.invalid','VEREIN_CLUB'=>$fixture['club'],'VEREIN_PRIVATE_ROOT'=>$fixture['root'],'VEREIN_ERIC_RUNTIME'=>$fixture['runtime_file'],'VEREIN_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password'],'KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1']as$key=>$value)putenv($key.'='.$value);
putenv('KULTURHUS_LOCAL_DEMO');putenv('SMTP_DSN');$_SERVER['HTTP_HOST']='own-balance.example.invalid';$_SERVER['HTTPS']='on';if(isset($_SERVER['HTTP_ORIGIN']))$_SERVER['HTTP_ORIGIN']='https://own-balance.example.invalid';
if($path==='/own-test-enter'&&$method==='GET'){$role=$_GET['role']??'admin';if(!is_string($role)||!isset($fixture['accounts'][$role])){http_response_code(400);exit;}$account=$fixture['accounts'][$role];\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];header('Location: /vereinsverwaltung/?view=finance&tab=tax&balance=1&balance_year=2025',true,303);exit;}
if($app==='integrated'){\Kulturhus\Native\Integration\ProductionSite::serve(TestSupport::db());exit;}
if($path==='/vereinsverwaltung/'||$path==='/vereinsverwaltung/index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}
// The direct controller is normally paired with a webserver serving public assets.
// Use the real production static dispatcher here too, including the closed public MIME/path guard.
if(str_starts_with($path,'/vereinsverwaltung/')){\Kulturhus\Native\Integration\ProductionSite::serve(TestSupport::db());exit;}
http_response_code(404);
