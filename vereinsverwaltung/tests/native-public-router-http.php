<?php
declare(strict_types=1);
// Dedicated local HTTP harness; neither production nor any running review fixture is accepted.
$port=(string)getenv('KH_NATIVE_PUBLIC_TEST_PORT');
if(PHP_SAPI!=='cli-server'||$port!=='8194'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8194'){http_response_code(403);exit;}
require dirname(__DIR__).'/native/bootstrap.php';
$state=json_decode(file_get_contents(dirname(__DIR__,2).'/.local/native-public-router-tests/current.json'),true,512,JSON_THROW_ON_ERROR);
$db=Kulturhus\Native\TestSupport::db();
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/vereinsverwaltung/native-manager.js'){Kulturhus\Native\Integration\Assets::serve('native-manager.js');exit;}
if(!is_dir($state['runtime'].'/sessions'))mkdir($state['runtime'].'/sessions',0700,true);
session_save_path($state['runtime'].'/sessions');session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict']);
$_SESSION['csrf']??=bin2hex(random_bytes(32));
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!=='http://127.0.0.1:8194'){http_response_code(403);exit;}
if(!Kulturhus\Native\Integration\PublicSite::serve($db,new Kulturhus\Native\Shared\Access($db,$state['reader']),$state['media_root'],$state['runtime'],$state['manager_club'],'http://127.0.0.1:8194')){http_response_code(404);echo 'No public route.';}
