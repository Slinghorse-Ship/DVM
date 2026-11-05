<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__,2).'/native/bootstrap.php';
require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;

$config=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-login-aliases'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1') { http_response_code(403); exit; }
$fixture=json_decode(file_get_contents(getenv('DVM_LOGIN_ALIASES_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
$origin='http://'.($_SERVER['HTTP_HOST']??'');
$app=array_search($origin,$fixture['origins'],true);
if($app===false) { http_response_code(403); exit; }
foreach([
    'VEREIN_PRIVATE_ROOT'=>$fixture['root'],
    'KH_NATIVE_AUTH_KEY_FILE'=>$fixture['root'].'/auth.key',
    'KH_NATIVE_PRIVATE_KEY_FILE'=>$fixture['root'].'/private.key',
    'KH_NATIVE_MANAGER_SESSION_ROOT'=>$fixture['root'].'/manager-sessions',
    'KH_NATIVE_MANAGER_MEDIA_ROOT'=>$fixture['root'].'/media',
    'KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic',
    'KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1',
]as$key=>$value)putenv($key.'='.$value);
putenv('SMTP_DSN');
if($app==='native') {
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');
    putenv('KH_NATIVE_REQUIRE_LOGIN=1');
    putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($origin,PHP_URL_PORT));
    putenv('KH_NATIVE_DEMO_MANAGER_CLUB='.$fixture['club']);
    require dirname(__DIR__,2).'/native/Web/router.php';
    exit;
}
// Only the verified loopback transport supplies the deployment context.
// The real ProductionSite controller, account/session guards and actions run below.
foreach(['VEREIN_MODE'=>'production','VEREIN_ALLOWED_HOSTS'=>'own-login-aliases.example.invalid','VEREIN_CLUB'=>$fixture['club']]as$key=>$value)putenv($key.'='.$value);
putenv('KULTURHUS_LOCAL_DEMO');
$_SERVER['HTTP_HOST']='own-login-aliases.example.invalid';
$_SERVER['HTTPS']='on';
if(($_SERVER['HTTP_ORIGIN']??null)===$origin)$_SERVER['HTTP_ORIGIN']='https://own-login-aliases.example.invalid';
\Kulturhus\Native\Integration\ProductionSite::serve(TestSupport::db());
