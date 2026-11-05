<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-social-planning-interface'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_SOCIAL_PLANNING_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
$planId=$_POST['automation_id']??'';$module=$_GET['view']??'';$allowed=[];foreach($f['plans']as$locales)foreach($locales as$modules)foreach($modules as$planModule=>$enhancements)foreach($enhancements as$id)$allowed[$id]=$planModule;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&((($_POST['operation']??null)!=='communication-pause')||!is_string($planId)||($allowed[$planId]??null)!==$module||(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$f['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')))){http_response_code(403);exit;}
$runtime=$f['root'];
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    $traceDb=TestSupport::db();$traceTable=Kulturhus\Native\Communication\Schema::table($module,'automations');$traceBefore=Kulturhus\Native\Shared\Db::run($traceDb,"SELECT id,version,status FROM $traceTable WHERE id=?",[$planId])->fetch();
    register_shutdown_function(static function()use($runtime,$traceDb,$traceTable,$traceBefore,$planId,$module):void{$after=Kulturhus\Native\Shared\Db::run($traceDb,"SELECT id,version,status FROM $traceTable WHERE id=?",[$planId])->fetch();file_put_contents($runtime.'/planning-post-trace.jsonl',json_encode(['module'=>$module,'before'=>$traceBefore,'after'=>$after,'status'=>http_response_code()])."\n",FILE_APPEND);});
}

if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_SERVER['HTTP_X_OWN_PLANNING_SQL_FAILURE']??null)==='once'){
    $faultDb=TestSupport::db();$table=Kulturhus\Native\Communication\Schema::table($module,'automations');
    $faultDb->exec("CREATE TRIGGER own_planning_status_failure BEFORE UPDATE ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own planning status failure'");
    file_put_contents($runtime.'/planning-sql-errors.jsonl',json_encode(['id'=>$planId,'module'=>$module])."\n",FILE_APPEND);
    register_shutdown_function(static function()use($faultDb):void{$faultDb->exec('DROP TRIGGER IF EXISTS own_planning_status_failure');});
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_SERVER['HTTP_X_OWN_PLANNING_HIDE_ACK']??null)==='once'){
    ob_start(static function(string$body)use($runtime,$planId,$module):string{$result=json_decode($body,true);if(http_response_code()===200&&($result['planning_saved']??false)===true){$table=Kulturhus\Native\Communication\Schema::table($module,'automations');$row=Kulturhus\Native\Shared\Db::run(TestSupport::db(),"SELECT id,version,status FROM $table WHERE id=?",[$planId])->fetch();file_put_contents($runtime.'/planning-lost-ack.jsonl',json_encode(['module'=>$module,'row'=>$row])."\n",FILE_APPEND);return '';}return$body;});
}
putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($f['origin'],PHP_URL_PORT));putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$runtime.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$runtime.'/auth.key');
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');putenv('SMTP_DSN');
putenv('KH_NATIVE_MANAGER_SESSION_ROOT='.$runtime.'/manager-sessions');putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$runtime.'/media');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4');putenv('VEREIN_DATABASE_USER='.$cfg['username']);putenv('VEREIN_DATABASE_PASSWORD='.$cfg['password']);
if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
    $mode=$_GET['mode']??'';$locale=$_GET['locale']??'';if(!in_array($mode,['native','integrated'],true)||!in_array($locale,['de','en'],true)){http_response_code(400);exit;}
    if($mode==='integrated'){Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$f['tokens'][$locale];$_SESSION['actor']=$f['manager_actors'][$locale];$url='/vereinsverwaltung/';}
    else{session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start();$_SESSION['native_actor']=$f['native_actors'][$locale];$_SESSION['native_csrf']=bin2hex(random_bytes(32));$url='/';}
    header('Location: '.$url.'?view=social&section=planning&planning_view=automation',true,303);exit;
}
if(str_starts_with($path,'/vereinsverwaltung/')){
    $relative=substr($path,strlen('/vereinsverwaltung/'));
    if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}
    if(Kulturhus\Native\Integration\Assets::serve($relative))exit;
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'woff2','woff'=>'woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$file||!str_starts_with($file,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($file);exit;
}
require dirname(__DIR__,2).'/native/Web/router.php';
