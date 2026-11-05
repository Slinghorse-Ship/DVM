<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
$cfg=TestSupport::config();if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-video-recovery-requests'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_VIDEO_RECOVERY_REQUEST_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}$runtime=$f['root'];$post=($_SERVER['REQUEST_METHOD']??'')==='POST';$entry=null;foreach($f['profiles']as$profile)if($profile['item']===($_POST['id']??null)&&$profile['source']===($_POST['source_id']??null))$entry=$profile;
if($post&&(!$entry||($_POST['operation']??null)!=='social-video-recover')){http_response_code(403);exit;}
if($post&&($_SERVER['HTTP_X_OWN_RECOVERY_FINAL_FAILURE']??null)==='once'){
 $fault=TestSupport::db();$id=$entry['recoveredVideo'];if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new RuntimeException('Own target required.');$fault->exec("CREATE TRIGGER own_recovery_final_failure BEFORE INSERT ON native_social_videos FOR EACH ROW BEGIN IF NEW.id='$id' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own final recovery insert failure'; END IF; END");register_shutdown_function(static function()use($fault):void{$fault->exec('DROP TRIGGER IF EXISTS own_recovery_final_failure');});
}
if($post&&($_SERVER['HTTP_X_OWN_RECOVERY_OTHER_REVISION']??null)==='once'){
 $other=TestSupport::db();$work=new Kulturhus\Native\Communication\Workspace($other,new Access($other,$entry['finisher']),'social',$runtime.'/media');$work->save(['id'=>$entry['item'],'title'=>'Own actual other manager revision','kind'=>'reel','body'=>'Own actual other manager text','channels'=>[]],1);
}
if($post&&($_POST['recovery_review']??null)==='1'&&($_SERVER['HTTP_X_OWN_RECOVERY_PURGE_COPY']??null)==='once'){
 $owned=TestSupport::db();$a=new Access($owned,$entry['actor']);$recover=new Kulturhus\Native\Communication\VideoRecovery($owned,$a,$runtime.'/media');$plan=$recover->prepare($entry['source']);if($plan['state']!=='complete'||$plan['target_id']!==$entry['target'])throw new RuntimeException('Own completed recovery required.');(new Kulturhus\Native\Communication\VideoArchive($owned,$a))->save($entry['target'],true,0);$file=new Kulturhus\Native\Communication\VideoRemoval($owned,$a,$runtime.'/media');$op=bin2hex(random_bytes(16));$file->beginRemove($entry['target'],0,$op);$file->resume($entry['target'],$op);$file->beginPurge($entry['target'],2,$op);$file->resume($entry['target'],$op);
}
if($post&&($_POST['recovery_review']??null)==='1'&&($_SERVER['HTTP_X_OWN_RECOVERY_REVOKE_MANAGE']??null)==='once'){
 $owned=TestSupport::db();$grant=Db::run($owned,"SELECT * FROM native_grants WHERE actor_id=? AND module='social' AND capability='manage'",[$entry['actor']])->fetch();if(!$grant)throw new RuntimeException('Own manage grant required.');Db::run($owned,"DELETE FROM native_grants WHERE actor_id=? AND module='social' AND capability='manage'",[$entry['actor']]);register_shutdown_function(static function()use($owned,$grant):void{Db::run($owned,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',array_values($grant));});
}
if($post&&!isset($_POST['recovery_review'])&&!isset($_POST['recovery_prepare'])&&($_SERVER['HTTP_X_OWN_RECOVERY_HIDE_ACK']??null)==='once'){
 ob_start(static function(string$body)use($runtime,$entry):string{
  $data=json_decode($body,true);if(http_response_code()===200&&!is_array($data)){$owned=TestSupport::db();$fact=(new Kulturhus\Native\Communication\VideoRecoveryRequests($owned,new Access($owned,$entry['actor']),$runtime.'/media'))->review($entry['item'],$entry['source'],$_POST['source_fingerprint'],$_POST['recovery_name']);$data=['recovery_result'=>$fact];}
  if(http_response_code()===200&&($data['recovery_result']['found']??false)===true){$fact=$data['recovery_result'];$journal=$runtime.'/recovery-lost-ack.jsonl';file_put_contents($journal,json_encode(['source'=>$entry['source'],'state'=>$fact['state'],'requested_version'=>(int)$_POST['version'],'actual_version'=>$fact['item_version']??null],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($journal,0600);if(($_SERVER['HTTP_X_OWN_RECOVERY_ACK_GATE']??null)==='once'){$release=$runtime.'/recovery-release-'.$entry['source'];$until=microtime(true)+15;do{clearstatcache(true,$release);if(is_file($release))break;usleep(1000);}while(microtime(true)<$until);}return'';}return$body;
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
