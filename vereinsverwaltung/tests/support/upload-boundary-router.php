<?php declare(strict_types=1);
// Private loopback-only upload transport fixture; no database, account bootstrap or external transport.
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!hash_equals((string)getenv('DVM_UPLOAD_BOUNDARY_TOKEN'),(string)($_SERVER['HTTP_X_OWN_FIXTURE']??''))){http_response_code(403);exit;}
ini_set('display_errors','0');
// A permitted 20 MiB document batch plus the real manager bootstrap needs its own 64 MiB check.
if(($_GET['mode']??null)==='batch')ini_set('memory_limit','64M');
require dirname(__DIR__,2).'/src/bootstrap.php';require dirname(__DIR__,2).'/src/Web/View.php';require_once dirname(__DIR__,2).'/src/Infrastructure/Media/LiveUploads.php';
$root=(string)getenv('DVM_UPLOAD_BOUNDARY_ROOT');if(!is_dir($root)||is_link($root)||realpath($root)!==$root){http_response_code(503);exit;}
session_save_path($root);session_name('OWNUPLOADBOUNDARY');session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict']);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){echo json_encode(['ready'=>true]);return;}
try{
 $mode=$_GET['mode']??'stage';$action=$mode==='inventory'?'inventory-file':(in_array($mode,['multi','batch'],true)?'document-upload-many':'document-upload');
 if(in_array($mode,['document','multi','batch'],true)){require dirname(__DIR__,2).'/src/Documents/DocumentActions.php';throw new RuntimeException('Own oversize upload unexpectedly reached document persistence.');}
 elseif($mode==='inventory'){require_once dirname(__DIR__,2).'/native/bootstrap.php';$db=\Kulturhus\Native\TestSupport::db();$_SESSION['actor']='own-upload-boundary';$inventory=new \Kulturhus\Verein\Inventory($db,$_SESSION['actor']);require dirname(__DIR__,2).'/src/Inventory/InventoryActions.php';throw new RuntimeException('Own oversize upload unexpectedly reached inventory persistence.');}
 elseif($mode==='stage'){$result=\Kulturhus\Verein\LiveUploads::stage('own-fixture-actor','document-upload','document_file',$_FILES['live_file']??[],input($_POST,'upload_key'));echo json_encode(['ok'=>true,'result'=>$result,'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);}
 else throw new RuntimeException('Unknown private fixture mode.');
}catch(DomainException){http_response_code(422);echo json_encode(['ok'=>false,'rejected'=>'file_size','title'=>input($_POST,'title'),'peak_memory_bytes'=>memory_get_peak_usage(true),'memory_limit'=>ini_get('memory_limit'),'staged'=>count($_SESSION['live_uploads']??[])],JSON_THROW_ON_ERROR);}
catch(Throwable){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'own_fixture_failure']);}
