<?php declare(strict_types=1);
// php -d extension=pdo_mysql -d disable_functions=mail -S 127.0.0.1:<fixture-port> tests/support/manager-navigation-router.php
// The fixture is created separately; starting this router never resets a database.
ini_set('zend.exception_ignore_args','1');ini_set('display_errors','0');
require __DIR__.'/manager-navigation-fixture.php';
\Kulturhus\Verein\Tests\ManagerNavigationFixture::environment();
$root=\Kulturhus\Verein\Tests\ManagerNavigationFixture::root();
$output=\Kulturhus\Verein\Tests\ManagerNavigationFixture::directory();
ini_set('log_errors','1');ini_set('error_log',$output.'/php.errors.log');
// The existing ProcessWire key/relay is deliberately outside the readable roots.
ini_set('open_basedir',implode(PATH_SEPARATOR,[$root.'/vereinsverwaltung',$root.'/banking',$root.'/local-processwire',$root.'/processwire/site',$output,$root.'/.local/vereinsverwaltung',sys_get_temp_dir()]));
require_once $root.'/vereinsverwaltung/src/Infrastructure/Runtime/LocalRuntime.php';
$prefix='/vereinsverwaltung/';$path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);$gate=$_SERVER;
if(in_array($path,['/','/vereinsverwaltung'],true))$gate['REQUEST_URI']=$prefix;
if(!\Kulturhus\Verein\LocalRuntime::nativeServer()||!\Kulturhus\Verein\LocalRuntime::acceptsRequest(PHP_SAPI,$gate)){http_response_code(403);exit('Only the local synthetic navigation fixture is available.');}
if(in_array($path,['/','/vereinsverwaltung'],true)){header('Location: '.$prefix,true,303);exit;}
$relative=rawurldecode(substr((string)$path,strlen($prefix)));
if($relative===''||$relative==='index.php'){require $root.'/vereinsverwaltung/public/index.php';exit;}
if(str_contains($relative,"\0")||str_contains($relative,'\\')||in_array('..',explode('/',$relative),true)){http_response_code(404);exit;}
$public=realpath($root.'/vereinsverwaltung/public');$file=realpath($public.DIRECTORY_SEPARATOR.$relative);
$types=['css'=>'text/css','js'=>'application/javascript','mjs'=>'application/javascript','wasm'=>'application/wasm','bcmap'=>'application/octet-stream','pfb'=>'application/octet-stream','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','webmanifest'=>'application/manifest+json'];
$extension=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
if(!$file||!str_starts_with($file,$public.DIRECTORY_SEPARATOR)||!is_file($file)||!isset($types[$extension])){http_response_code(404);exit;}
header('Content-Type: '.$types[$extension]);header('X-Content-Type-Options: nosniff');readfile($file);
