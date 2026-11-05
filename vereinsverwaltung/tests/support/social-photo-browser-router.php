<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-social-photo-upload'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_SOCIAL_PHOTO_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(!in_array($_POST['operation']??null,['social-photo-upload'],true)||(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')))){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??null)==='social-photo-upload'&&($_SERVER['HTTP_X_OWN_PHOTO_RECEIPT_FAILURE']??null)==='once'){
    $faultDb=TestSupport::db();$faultDb->exec("CREATE TRIGGER own_photo_browser_receipt_failure BEFORE INSERT ON native_communication_save_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own photo receipt failure'");
    register_shutdown_function(static function()use($faultDb):void{$faultDb->exec('DROP TRIGGER IF EXISTS own_photo_browser_receipt_failure');});
}

$runtime=$f['root'];
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??null)==='social-photo-upload'&&!isset($_POST['photo_review'])&&($_SERVER['HTTP_X_OWN_PHOTO_HIDE_ACK']??null)==='once'){
    ob_start(static function(string$body)use($runtime):string{$data=json_decode($body,true);if(http_response_code()===200&&($data['photo_result']['found']??false)===true){$result=$data['photo_result'];$path=$runtime.'/social-photo-lost-ack.jsonl';file_put_contents($path,json_encode(['id'=>$result['id'],'item_id'=>$result['item_id'],'item_version'=>$result['item_version']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($path,0600);return '';}return$body;});
}

if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??null)==='social-photo-upload'&&!isset($_POST['photo_review'])){$photo=$_FILES['photo']??[];$source=$photo['tmp_name']??'';error_log(json_encode(['own_photo_input'=>['error'=>$photo['error']??null,'declared_bytes'=>$photo['size']??null,'actual_bytes'=>is_file($source)?filesize($source):null,'uploaded'=>is_uploaded_file($source),'same_fixture_bytes'=>is_file($source)?hash_file('sha256',$source)===hash_file('sha256',$runtime.'/source.png'):false]],JSON_THROW_ON_ERROR));}

putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');
putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$runtime.'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
    $mode=$_GET['mode']??'';$locale=$_GET['locale']??'';if(!in_array($mode,['native','integrated'],true)||!in_array($locale,['de','en'],true)){http_response_code(400);exit;}
    if($mode==='integrated'){Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$f['tokens'][$locale];$_SESSION['actor']=$f['manager_actors'][$locale];$url='/vereinsverwaltung/';}
    else{session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$f['native_actors'][$locale];$_SESSION['native_csrf']=bin2hex(random_bytes(32));$url='/';}
    header('Location: '.$url.'?view=social&section=compose',true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));
    if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}
    if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'woff2','woff'=>'woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
