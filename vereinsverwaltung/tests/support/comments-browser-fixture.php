<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';
require_once dirname(__DIR__,2).'/src/bootstrap.php';
require_once __DIR__.'/editorial-browser-fixture.php';
require_once __DIR__.'/original-database-hash.php';

/** Both genuine HTTP applications, four independent conversations, and every original table retained. */
function ownCommentsBrowser(PDO $db):void
{
    $fixture=ownEditorialBrowserFixture($db);$fixture['native_club']=$fixture['admin']->club();
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions']as$directory)if(!is_dir($directory))mkdir($directory,0700,true);
    copy($fixture['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
    if(!is_dir($runtime.'/media'))mkdir($runtime.'/media',0700,true);$fixture['media_root']=$runtime.'/media';$fixture['runtime']=$runtime;
    \Kulturhus\Native\Shared\Db::run($db,"UPDATE native_users SET club_id=? WHERE id='reader'",[$fixture['native_club']]);
    $fixture['origins']=[];foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://127.0.0.1:'.$port;}
    $pages=new \Kulturhus\Native\Cms\Pages($db,$fixture['admin']);$service=new \Kulturhus\Native\Comments\Streams($db,$fixture['admin'],\Kulturhus\Native\Shared\SecretBox::fromFile($fixture['root'].'/private.key'));
    $model=new \Kulturhus\Native\Cms\DataModel($db,$fixture['admin']);
    foreach(['discussion'=>'Öffentliche Diskussion','feedback'=>'Rückmeldungen','private_notes'=>'Interne Notizen']as$key=>$label)$model->saveField(['key'=>$key,'label'=>$label,'type'=>'comments']+($key==='private_notes'?['access'=>['read'=>'manage','edit'=>'manage']]:[]));
    $model->saveTemplate(['key'=>'own_comment_fields','label'=>'Eigene Kommentarfelder','fields'=>['discussion','feedback','private_notes']]);
    $fixture['flows']=[];
    foreach(['native','integrated']as$app)foreach(['js','basic']as$mode){
        $slug='own-comments-'.$app.'-'.$mode;$source=$pages->save(['title'=>'Eigene Kommentarseite '.$app.' '.$mode,'slug'=>$slug,'body_html'=>'<p>Exaktes ursprüngliches Seitenoriginal ä🙂</p>']);$source=$pages->publish($source['id'],$source['version']);
        $service->configure($source['id'],'comments',0,['enabled'=>true,'anonymous'=>true,'moderation'=>false]);$parent=null;
        for($i=1;$i<=25;$i++){$comment=$service->submit($source['id'],'comments',['name'=>'Eigener Gesprächsteilnehmer '.$i,'body'=>'Ursprünglicher echter Gesprächsbeitrag '.$i],bin2hex(random_bytes(32)),bin2hex(random_bytes(32)),'own-fixture-'.$slug.'-'.$i);$parent??=$comment['id'];}
        $service->configure($source['id'],'comments',1,['enabled'=>true,'anonymous'=>true,'moderation'=>true]);
        $pending=$service->submit($source['id'],'comments',['name'=>'Eigener zu prüfender Alias','body'=>'Nur der prüfenden Person sichtbarer Originaltext'],bin2hex(random_bytes(32)),bin2hex(random_bytes(32)),'own-fixture-pending-'.$slug);
        $typed=$pages->save(['title'=>'Eigene getrennte Kommentarfelder '.$app.' '.$mode,'slug'=>$slug.'-fields','template'=>'own_comment_fields']);$typed=$pages->publish($typed['id'],$typed['version']);
        $service->configure($typed['id'],'private_notes',0,['enabled'=>true,'anonymous'=>true,'moderation'=>false]);
        $fixture['flows'][$app][$mode]=['page'=>$source['id'],'slug'=>$slug,'parent'=>$parent,'pending'=>$pending['id'],'typed_page'=>$typed['id']];
    }
    $private=$pages->save(['title'=>'Eigenes nicht veröffentlichtes Original','slug'=>'own-private-comments']);$service->configure($private['id'],'comments',0,['enabled'=>true,'anonymous'=>true]);$fixture['private_page']=$private['id'];
    $png=$fixture['root'].'/own-comment-original.png';$image=imagecreatetruecolor(12,8);imagefilledrectangle($image,0,0,11,7,imagecolorallocate($image,20,80,140));imagepng($image,$png);imagedestroy($image);
    (new \Kulturhus\Native\Cms\Media($db,$fixture['admin'],$fixture['media_root']))->upload($png,'Own comment original.png','Eigenes unverändertes Original');
    $tables=array_values(array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),static fn(string $table):bool=>!str_starts_with($table,'native_comment')&&!in_array($table,['native_auth_sessions','khcms_state'],true)));
    $original=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];
    $retainedComments=[];foreach(['native_comments','native_comment_history','native_comment_setting_history']as$table){$primary=\Kulturhus\Native\Shared\Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$rows=[];foreach($db->query('SELECT * FROM '.$table)as$row)$rows[serialize(array_map(static fn(string $key):mixed=>$row[$key],$primary))]=hash('sha256',serialize($row));$retainedComments[$table]=[$primary,$rows];}
    $foreignSettings=ownOriginalDatabaseHash($db,['native_comment_settings'],static fn():array=>[' WHERE club_id<>?',[$fixture['native_club']]]);
    $files=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture['media_root'],FilesystemIterator::SKIP_DOTS));foreach($iterator as$file)if($file->isFile())$files[$file->getPathname()]=hash_file('sha256',$file->getPathname());
    $fixture['result']=$fixture['root'].'/comments-result.json';unset($fixture['admin']);$file=$fixture['root'].'/comments-fixture.json';file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_COMMENTS_FIXTURE='.$file);$servers=[];
    try{
        foreach($fixture['origins']as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($fixture['root'].'/comments-'.$port.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$fixture['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own comments HTTP server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $process=proc_open(['node',dirname(__DIR__).'/native-comments-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual dual-application comment browser failed.');
        if(ownOriginalDatabaseHash($db,$tables)!==$original)throw new RuntimeException('Comments changed an original business table.');
        if($keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Comments changed an original runtime key.');
        $retainedCount=0;foreach($retainedComments as$table=>[$primary,$expected]){$retainedCount+=count($expected);foreach($db->query('SELECT * FROM '.$table)as$row){$key=serialize(array_map(static fn(string $column):mixed=>$row[$column],$primary));if(!isset($expected[$key]))continue;if(!hash_equals($expected[$key],hash('sha256',serialize($row))))throw new RuntimeException('Comments changed a retained comment original or immutable history.');unset($expected[$key]);}if($expected)throw new RuntimeException('Comments removed a retained original history.');}
        if(ownOriginalDatabaseHash($db,['native_comment_settings'],static fn():array=>[' WHERE club_id<>?',[$fixture['native_club']]])!==$foreignSettings)throw new RuntimeException('Comments changed another club original configuration.');
        foreach($files as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Comments changed original media bytes.');
        echo json_encode(['suite'=>'native-comments-browser-originals','original_tables'=>count($tables),'retained_comment_rows'=>$retainedCount,'original_bytes'=>'exact','original_keys'=>'exact','original_media'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{
        foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}
        $artifacts=getenv('DVM_COMMENTS_ARTIFACTS')?:'/tmp/kulturhus-comments-browser-proof';if(!is_dir($artifacts))mkdir($artifacts,0700,true);foreach(glob($fixture['root'].'/comments-*.log')as$log)copy($log,$artifacts.'/'.basename($log));
    }
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-comments-ui'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $fixture=json_decode(file_get_contents(getenv('DVM_COMMENTS_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$fixture['origins'],true);if($app===false){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $requestStarted=microtime(true);$requestMethod=$_SERVER['REQUEST_METHOD']??'GET';$trace=$fixture['root'].'/comments-requests-'.$app.'.log';file_put_contents($trace,json_encode(['phase'=>'start','method'=>$requestMethod,'path'=>$path],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);register_shutdown_function(static function()use($trace,$requestStarted,$requestMethod,$path):void{file_put_contents($trace,json_encode(['phase'=>'end','method'=>$requestMethod,'path'=>$path,'status'=>http_response_code(),'ms'=>round((microtime(true)-$requestStarted)*1000,1)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);});
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_DEMO_MANAGER_CLUB'=>$fixture['club'],'KH_NATIVE_AUTH_KEY_FILE'=>$fixture['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$fixture['root'].'/private.key','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KH_NATIVE_MANAGER_SESSION_ROOT'=>$fixture['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$fixture['media_root'],'KULTURHUS_DEMO_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password']]as$key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $role=$_GET['role']??'admin';if(!is_string($role)||!isset($fixture['accounts'][$role])){http_response_code(400);exit;}$account=$fixture['accounts'][$role];
        if($app==='integrated'){\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];$target='/vereinsverwaltung/?view=website&tab=pages&section=comments';}
        else{session_save_path($fixture['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$fixture['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));$target='/?view=website&section=comments';}
        header('Location: '.$target,true,303);exit;
    }
    if($app==='native'){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
    if(str_starts_with($path,'/vereinsverwaltung/')){$relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(\Kulturhus\Native\Integration\Assets::serve($relative))exit;$public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$asset||!str_starts_with($asset,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($asset);exit;}
    $db=\Kulturhus\Native\TestSupport::db();if(!\Kulturhus\Native\Integration\PublicSite::serve($db,new \Kulturhus\Native\Shared\Access($db,$fixture['public_actor']),$fixture['media_root'],$fixture['root'],$fixture['club'],$origin))http_response_code(404);exit;
}
