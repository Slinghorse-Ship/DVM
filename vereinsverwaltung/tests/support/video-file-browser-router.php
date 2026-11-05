<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\VideoRemovalRequests;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-video-removal-requests'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_VIDEO_FILE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}$runtime=$f['root'];$post=($_SERVER['REQUEST_METHOD']??'')==='POST';$entry=null;foreach($f['profiles']as$profile)if(($profile['item']??null)===($_POST['id']??null)&&$profile['source']===($_POST['source_id']??null))$entry=$profile;
if($post&&(!in_array($_POST['operation']??null,['social-video-remove','social-video-restore','social-video-purge','social-video-resume','social-video-retention-save'],true)||!$entry&&($_POST['operation']??null)!=='social-video-retention-save'||isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin'))){http_response_code(403);exit;}
if($post&&$entry&&in_array($_SERVER['HTTP_X_OWN_FILE_FINAL_FAILURE']??null,['2','8'],true)){
    $fault=TestSupport::db();$target=(int)$_SERVER['HTTP_X_OWN_FILE_FINAL_FAILURE'];$source=$entry['source'];if(!preg_match('/\A[a-f0-9]{32}\z/D',$source))throw new RuntimeException('Owned source required.');
    $fault->exec("CREATE TRIGGER own_file_final_failure BEFORE INSERT ON native_video_removal_history FOR EACH ROW BEGIN IF NEW.source_id='$source' AND NEW.version=$target THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own final file history failure'; END IF; END");register_shutdown_function(static function()use($fault):void{$fault->exec('DROP TRIGGER IF EXISTS own_file_final_failure');});
}
if($post&&$entry&&($_SERVER['HTTP_X_OWN_FILE_OTHER_FINISHER']??null)==='once'){
    $other=TestSupport::db();(new VideoRemovalRequests($other,new Access($other,$entry['finisher']),$runtime.'/media'))->save($entry['item'],$entry['source'],'resume',1,$_POST['removal_operation']);
}
if($post&&$entry&&($_POST['file_review']??null)==='1'&&($_SERVER['HTTP_X_OWN_FILE_REVOKE_MANAGE']??null)==='once'){
    $grants=TestSupport::db();$grant=Db::run($grants,"SELECT * FROM native_grants WHERE actor_id=? AND module='social' AND capability='manage'",[$entry['actor']])->fetch();if(!$grant)throw new RuntimeException('Owned manage grant required.');Db::run($grants,"DELETE FROM native_grants WHERE actor_id=? AND module='social' AND capability='manage'",[$entry['actor']]);register_shutdown_function(static function()use($grants,$grant):void{Db::run($grants,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',array_values($grant));});
}
if($post&&$entry&&!isset($_POST['file_review'])&&!isset($_POST['file_prepare'])&&($_SERVER['HTTP_X_OWN_FILE_HIDE_ACK']??null)==='once'){
    ob_start(static function(string$body)use($runtime,$entry):string{
        $data=json_decode($body,true);if(http_response_code()===200&&!is_array($data)){
            $db=TestSupport::db();$kind=Kulturhus\Native\Web\Communication\VideoRemovalForm::kind($_POST['operation']);$fact=(new VideoRemovalRequests($db,new Access($db,$entry['actor']),$runtime.'/media'))->review($entry['item'],$entry['source'],$kind,(int)$_POST['removal_version'],$_POST['removal_operation']);if($fact)$data=['video_file_result'=>['found'=>true]+$fact];
        }
        if(http_response_code()===200&&($data['video_file_result']['found']??false)===true){$fact=$data['video_file_result'];$journal=$runtime.'/video-file-lost-ack.jsonl';file_put_contents($journal,json_encode(['item_id'=>$fact['item_id'],'source_id'=>$fact['source_id'],'kind'=>$fact['requested_kind'],'requested_version'=>$fact['requested_version'],'operation'=>$fact['operation'],'version'=>$fact['version'],'complete'=>$fact['complete']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($journal,0600);if(($_SERVER['HTTP_X_OWN_FILE_ACK_GATE']??null)==='once'){$release=$runtime.'/video-file-release-'.$fact['operation'].'-'.$fact['requested_kind'];$until=microtime(true)+15;do{clearstatcache(true,$release);if(is_file($release))break;usleep(1000);}while(microtime(true)<$until);}return'';}
        return$body;
    });
}
putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$runtime.'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
    $profile=$f['profiles'][$_GET['profile']??'']??null;if(!$profile){http_response_code(400);exit;}
    if($profile['mode']==='integrated'){Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$profile['token'];$_SESSION['actor']=$profile['managerActor'];$url='/vereinsverwaltung/';}else{session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$profile['actor'];$_SESSION['native_csrf']=bin2hex(random_bytes(32));$url='/';}header('Location: '.$url.'?view=social&section=compose',true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'woff2','woff'=>'woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
