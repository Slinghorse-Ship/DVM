<?php declare(strict_types=1);
/** HTTP integration fixture: available only inside the isolated synthetic CI process. */
if(getenv('KH_NATIVE_CI')!=='1'||PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/native/bootstrap.php';
require_once dirname(__DIR__,2).'/native/TestSupport.php';
use Kulturhus\Native\Cms\{Pages,ReferenceCatalog};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{BootstrapAssets,ContentModel,Html};
use Kulturhus\Native\TestSupport;
$configFile=getenv('KH_TYPED_BROWSER_FIXTURE');
if(!is_string($configFile)||!is_file($configFile)||!str_contains($configFile,'/.local/native-typed-tests/')){http_response_code(404);exit;}
$fixture=json_decode(file_get_contents($configFile),true,32,JSON_THROW_ON_ERROR);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$assets=BootstrapAssets::sources();foreach(['native.css','field-groups.css','richtext.css','typed-fields.css','richtext.js','reference-picker.js','field-conditions.js','typed-fields.js']as$name)$assets[$name]=dirname(__DIR__,2).'/native/Web/assets/'.$name;
if(str_starts_with($path,'/assets/')){
    $name=substr($path,8);if(!isset($assets[$name])){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($name,'.js')?'text/javascript':'text/css'));readfile($assets[$name]);exit;
}
$db=TestSupport::db();$access=new Access($db,($_GET['role']??'')==='editor'?'editor':'admin');$pages=new Pages($db,$access);
if($path==='/reference-options'){header('Content-Type: application/json');echo json_encode((new ReferenceCatalog($db,$access))->query($_GET),JSON_THROW_ON_ERROR);exit;}
if($path!=='/editor'){http_response_code(404);exit;}
$sessionRoot=dirname($configFile).'/sessions';if(!is_dir($sessionRoot))mkdir($sessionRoot,0700);session_save_path($sessionRoot);
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict']);$_SESSION['typed_csrf']??=bin2hex(random_bytes(24));$csrf=$_SESSION['typed_csrf'];
$page=$pages->get($fixture['id']);$model=new ContentModel($db,$access,$csrf);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){http_response_code(403);echo 'Formular abgelaufen.';exit;}
    try{$data=$page;$data['custom_fields']=$model->values($page['template'],$_POST['custom_fields']??[]);$page=$pages->save($data,(int)($_POST['version']??-1));header('Location: /editor?'.http_build_query(['saved'=>'1','role'=>$_GET['role']??'']));http_response_code(303);exit;}
    catch(\Throwable$problem){http_response_code(422);$error=$problem->getMessage();}
}
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.BootstrapAssets::styles().'<link rel="stylesheet" href="/assets/native.css"><link rel="stylesheet" href="/assets/field-groups.css"><link rel="stylesheet" href="/assets/richtext.css"><link rel="stylesheet" href="/assets/typed-fields.css">';
foreach(['richtext.js','reference-picker.js','field-conditions.js','typed-fields.js']as$asset)echo '<script defer src="/assets/'.$asset.'"></script>';
echo '</head><body><main class="container py-3"><h1>Inhalt bearbeiten</h1>'.($error!==''?'<p role="alert">'.Html::e($error).'</p>':'').(isset($_GET['saved'])?'<p role="status">Gespeichert</p>':'').'<form method="post">'.Html::hidden('csrf',$csrf).Html::hidden('version',$page['version']).$model->fields($page['template'],$page['custom_fields']).'<button class="btn btn-primary" id="save">Änderungen speichern</button></form></main></body></html>';
