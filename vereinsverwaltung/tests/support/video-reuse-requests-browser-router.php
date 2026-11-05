<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\{VideoReuseRequests,Videos,Workspace,VideoArchive};
$cfg=TestSupport::config();if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-video-reuse-requests'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_VIDEO_REUSE_REQUEST_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}$runtime=$f['root'];$post=($_SERVER['REQUEST_METHOD']??'')==='POST';$entry=null;foreach($f['profiles']as$profile)if(in_array($_POST['id']??null,[$profile['item'],$profile['second']],true)&&$profile['source']===($_POST['source_id']??null)&&in_array($_POST['reuse_video_id']??null,[$profile['video'],$profile['firstResult'],$profile['secondResult']],true))$entry=$profile;
if($post&&(!$entry||($_POST['operation']??null)!=='social-video-reuse')){http_response_code(403);exit;}
if($post&&($_SERVER['HTTP_X_OWN_REUSE_FINAL_FAILURE']??null)==='once'){
 $fault=TestSupport::db();$id=Videos::reuseIdentity($entry['club'],$_POST['id'],(int)$_POST['version'],(int)$_POST['identity_version'],$entry['source']);$fault->exec("CREATE TRIGGER own_reuse_final_failure BEFORE INSERT ON native_social_videos FOR EACH ROW BEGIN IF NEW.id='$id' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own final reuse insert failure'; END IF; END");register_shutdown_function(static function()use($fault):void{$fault->exec('DROP TRIGGER IF EXISTS own_reuse_final_failure');});
}
if($post&&($_SERVER['HTTP_X_OWN_REUSE_OTHER_REVISION']??null)==='once'){
 $other=TestSupport::db();(new Workspace($other,new Access($other,$entry['finisher']),'social',$runtime.'/media'))->save(['id'=>$_POST['id'],'title'=>'Own actual other manager revision','kind'=>'reel','body'=>'Own actual other manager text','channels'=>[]],1);
}
if($post&&($_POST['reuse_review']??null)==='1'&&($_SERVER['HTTP_X_OWN_REUSE_ARCHIVE_SOURCE']??null)==='once'){
 $owned=TestSupport::db();(new VideoArchive($owned,new Access($owned,$entry['actor'])))->save($entry['source'],true,0);
}
if($post&&($_POST['reuse_review']??null)==='1'&&($_SERVER['HTTP_X_OWN_REUSE_REVOKE_EDIT']??null)==='once'){
 $owned=TestSupport::db();$grant=Db::run($owned,"SELECT * FROM native_grants WHERE actor_id=? AND module='social' AND capability='edit'",[$entry['actor']])->fetch();if(!$grant)throw new RuntimeException('Own edit grant required.');Db::run($owned,"DELETE FROM native_grants WHERE actor_id=? AND module='social' AND capability='edit'",[$entry['actor']]);register_shutdown_function(static function()use($owned,$grant):void{Db::run($owned,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',array_values($grant));});
}
if($post&&!isset($_POST['reuse_review'])&&!isset($_POST['reuse_prepare'])&&($_SERVER['HTTP_X_OWN_REUSE_HIDE_ACK']??null)==='once'){
 ob_start(static function(string$body)use($runtime,$entry):string{
  $data=json_decode($body,true);if(http_response_code()===200&&!is_array($data)){$owned=TestSupport::db();$fact=(new VideoReuseRequests($owned,new Access($owned,$entry['actor']),$runtime.'/media'))->review($_POST['id'],$_POST['reuse_video_id'],$entry['source'],(int)$_POST['version'],(int)$_POST['identity_version']);$data=['reuse_result'=>$fact];}
  if(http_response_code()===200&&($data['reuse_result']['found']??false)===true){$fact=$data['reuse_result'];$journal=$runtime.'/reuse-lost-ack.jsonl';file_put_contents($journal,json_encode(['item'=>$_POST['id'],'source'=>$entry['source'],'requested_version'=>(int)$_POST['version'],'actual_version'=>$fact['item_version'],'video_id'=>$fact['video_id']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($journal,0600);if(($_SERVER['HTTP_X_OWN_REUSE_ACK_GATE']??null)==='once'){$release=$runtime.'/reuse-release-'.$_POST['id'];$until=microtime(true)+15;do{clearstatcache(true,$release);if(is_file($release))break;usleep(1000);}while(microtime(true)<$until);}return'';}return$body;
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
