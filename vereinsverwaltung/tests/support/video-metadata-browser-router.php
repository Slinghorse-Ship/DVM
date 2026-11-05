<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-video-metadata-save-requests'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_VIDEO_METADATA_SAVE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(!in_array($_POST['operation']??null,['social-video-metadata-save'],true)||(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')))){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??null)==='social-video-metadata-save'&&($_SERVER['HTTP_X_OWN_METADATA_RECEIPT_FAILURE']??null)==='once'){
    $faultDb=TestSupport::db();$faultDb->exec("CREATE TRIGGER own_metadata_browser_receipt_failure BEFORE INSERT ON native_communication_save_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own video receipt failure'");
    register_shutdown_function(static function()use($faultDb):void{$faultDb->exec('DROP TRIGGER IF EXISTS own_metadata_browser_receipt_failure');});
}

// Only the owned native fixture can change its own grant for one real response.
$revokeLocale=$_SERVER['HTTP_X_OWN_METADATA_REVOKE_EDIT']??$_SERVER['HTTP_X_OWN_METADATA_REVOKE_MANAGE']??null;$revokeCap=isset($_SERVER['HTTP_X_OWN_METADATA_REVOKE_EDIT'])?'edit':'manage';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['metadata_review']??null)==='1'&&in_array($revokeLocale,['de','en'],true)){
    $grantDb=TestSupport::db();$grantActor=$f['native_actors'][$revokeLocale];
    $grant=Kulturhus\Native\Shared\Db::run($grantDb,"SELECT * FROM native_grants WHERE actor_id=? AND module='social' AND capability=?",[$grantActor,$revokeCap])->fetch();
    if(!$grant)throw new RuntimeException('Own original edit grant required.');
    Kulturhus\Native\Shared\Db::run($grantDb,"DELETE FROM native_grants WHERE actor_id=? AND module='social' AND capability=?",[$grantActor,$revokeCap]);
    register_shutdown_function(static function()use($grantDb,$grant):void{Kulturhus\Native\Shared\Db::run($grantDb,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',array_values($grant));});
}
$runtime=$f['root'];
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??null)==='social-video-metadata-save'&&!isset($_POST['metadata_review'])&&($_SERVER['HTTP_X_OWN_METADATA_HIDE_ACK']??null)==='once'){
    ob_start(static function(string$body)use($runtime):string{$data=json_decode($body,true);if(http_response_code()===200&&!is_array($data)){
        $receiptDb=TestSupport::db();$receipt=Kulturhus\Native\Shared\Db::run($receiptDb,"SELECT * FROM native_communication_save_requests WHERE module='video-metadata' AND request_key=? AND item_id=?",[$_POST['metadata_request_key'],$_POST['id']])->fetchAll();
        if(count($receipt)===1){$original=(new Kulturhus\Native\Communication\VideoMetadataSaveRequests($receiptDb,new Kulturhus\Native\Shared\Access($receiptDb,$receipt[0]['actor_id']),$runtime.'/media'))->review($_POST['id'],$_POST['source_id'],$_POST['metadata_request_key']);if($original)$data=['video_metadata_result'=>['found'=>true]+$original];}
    }if(http_response_code()===200&&($data['video_metadata_result']['found']??false)===true){$result=$data['video_metadata_result'];$path=$runtime.'/video-metadata-lost-ack.jsonl';file_put_contents($path,json_encode(['source_id'=>$result['source_id'],'item_id'=>$result['item_id'],'version'=>$result['version'],'request_key'=>$result['request_key']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($path,0600);if(($_SERVER['HTTP_X_OWN_METADATA_ACK_GATE']??null)==='once'){$release=$runtime.'/video-metadata-release-'.$result['request_key'];$deadline=microtime(true)+15;do{clearstatcache(true,$release);if(is_file($release))break;usleep(1000);}while(microtime(true)<$deadline);}return '';}return$body;});
}



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
