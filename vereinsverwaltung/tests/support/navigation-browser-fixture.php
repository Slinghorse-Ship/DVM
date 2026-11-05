<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';
require_once dirname(__DIR__,2).'/src/bootstrap.php';
require_once __DIR__.'/editorial-browser-fixture.php';
require_once __DIR__.'/original-database-hash.php';

/** Genuine native/integrated sessions and routers, synthetic menu changes only. */
function ownNavigationBrowser(PDO $db):void
{
    $fixture=ownEditorialBrowserFixture($db);$fixture['native_club']=$fixture['admin']->club();
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions',$runtime.'/media']as$directory)if(!is_dir($directory))mkdir($directory,0700,true);
    copy($fixture['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
    $fixture['media_root']=$runtime.'/media';$fixture['runtime']=$runtime;$fixture['origins']=[];
    \Kulturhus\Native\Shared\Db::run($db,"UPDATE native_users SET club_id=? WHERE id='reader'",[$fixture['native_club']]);
    foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://127.0.0.1:'.$port;}
    $pages=new \Kulturhus\Native\Cms\Pages($db,$fixture['admin']);$fixture['items']=[];
    foreach(['first','second','third']as$key){$p=$pages->save(['title'=>'Own exact menu page '.$key.' ä🙂','slug'=>'own-menu-'.$key,'body_html'=>'<p>Exact original menu body '.$key.' ä🙂</p>','nav_visible'=>true]);$p=$pages->publish($p['id'],$p['version']);$fixture['items'][$key]=$p['id'];}
    $locked=$pages->save(['title'=>'Own readable privileged menu original','slug'=>'own-menu-locked','page_policy'=>['publish'=>'manage'],'nav_visible'=>true]);$locked=$pages->publish($locked['id'],$locked['version']);$fixture['locked']=$locked['id'];
    $programme=$pages->save(['title'=>'Own inaccessible programme original','slug'=>'veranstaltungen','page_policy'=>['read'=>'manage','publish'=>'manage'],'body_html'=>'<p>Own secret programme original</p>']);$programme=$pages->publish($programme['id'],$programme['version']);$fixture['programme']=$programme['id'];
    (new \Kulturhus\Native\Cms\Navigation($db,$fixture['admin']))->save([['id'=>$locked['id'],'label'=>'Locked original & ä🙂','position'=>81,'visible'=>true]],true,0);
    $all=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$tables=array_values(array_diff($all,['khcms_navigation','khcms_navigation_revisions','khcms_state','native_auth_sessions']));
    $original=ownOriginalDatabaseHash($db,$tables);$otherMenus=ownOriginalDatabaseHash($db,['khcms_navigation','khcms_navigation_revisions','khcms_state'],static fn():array=>[' WHERE club_id<>?',[$fixture['native_club']]]);
    $keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];
    $fixture['before_version']=(new \Kulturhus\Native\Cms\Navigation($db,$fixture['admin']))->state()['version'];
    $fixture['before_generation']=$pages->generation();
    $retainedMenus=ownOriginalDatabaseHash($db,['khcms_navigation_revisions'],static fn():array=>[' WHERE club_id=? AND version<=?',[$fixture['native_club'],$fixture['before_version']]]);
    $fixture['artifacts']=getenv('DVM_NAVIGATION_ARTIFACTS')?:$fixture['root'].'/navigation-proof';if(!is_dir($fixture['artifacts']))mkdir($fixture['artifacts'],0700,true);
    unset($fixture['admin']);$file=$fixture['root'].'/navigation-fixture.json';file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_NAVIGATION_FIXTURE='.$file);$servers=[];
    try{
        foreach($fixture['origins']as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($fixture['artifacts'].'/http-'.$port.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$fixture['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own menu HTTP server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        if(getenv('DVM_NAVIGATION_HTTP_ONLY')==='1'){require __DIR__.'/navigation-http.php';ownNavigationHttp($fixture);}
        else{$process=proc_open(['node',dirname(__DIR__).'/native-navigation-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual dual-application menu browser failed.');}
        $result=json_decode(file_get_contents($fixture['artifacts'].'/result.json'),true,32,JSON_THROW_ON_ERROR);$service=new \Kulturhus\Native\Cms\Navigation($db,(new \Kulturhus\Native\Integration\ManagerBridge($db,$fixture['accounts']['admin']['actor'],'native',static fn()=>null))->access());
        if($service->state()['version']!==$fixture['before_version']+$result['committed_saves'])throw new RuntimeException('Comparison/adoption performed an unexpected menu write.');
        $finalAccess=(new \Kulturhus\Native\Integration\ManagerBridge($db,$fixture['accounts']['admin']['actor'],'native',static fn()=>null))->access();
        if((new \Kulturhus\Native\Cms\Pages($db,$finalAccess))->generation()!==$fixture['before_generation']+$result['committed_saves'])throw new RuntimeException('An unsaved/rejected menu action changed the publication generation.');
        if((int)\Kulturhus\Native\Shared\Db::run($db,'SELECT COUNT(*) FROM khcms_navigation_revisions WHERE club_id=?',[$fixture['native_club']])->fetchColumn()!==$fixture['before_version']+$result['committed_saves'])throw new RuntimeException('Exactly one immutable revision per intended save required.');
        if(ownOriginalDatabaseHash($db,['khcms_navigation_revisions'],static fn():array=>[' WHERE club_id=? AND version<=?',[$fixture['native_club'],$fixture['before_version']]])!==$retainedMenus)throw new RuntimeException('Existing immutable menu revision changed.');
        echo json_encode(['suite'=>'native-navigation-browser-originals','original_tables'=>count($tables),'original_sha256'=>$original,'original_bytes'=>'exact','foreign_menus'=>'exact','original_keys'=>'exact','committed_saves'=>$result['committed_saves'],'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}
        if(ownOriginalDatabaseHash($db,$tables)!==$original)throw new RuntimeException('Menu recovery changed original business/content/model/media/role bytes.');
        if(ownOriginalDatabaseHash($db,['khcms_navigation','khcms_navigation_revisions','khcms_state'],static fn():array=>[' WHERE club_id<>?',[$fixture['native_club']]])!==$otherMenus)throw new RuntimeException('Menu recovery changed another club menu/generation.');
        if($keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Menu recovery changed original runtime keys.');
    }
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-navigation'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $fixture=json_decode(file_get_contents(getenv('DVM_NAVIGATION_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$fixture['origins'],true);if($app===false){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $requestStarted=microtime(true);$requestMethod=$_SERVER['REQUEST_METHOD']??'GET';$trace=$fixture['artifacts'].'/navigation-requests-'.$app.'.log';file_put_contents($trace,json_encode(['phase'=>'start','method'=>$requestMethod,'path'=>$path],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);register_shutdown_function(static function()use($trace,$requestStarted,$requestMethod,$path):void{file_put_contents($trace,json_encode(['phase'=>'end','method'=>$requestMethod,'path'=>$path,'status'=>http_response_code(),'ms'=>round((microtime(true)-$requestStarted)*1000,1)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);});
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_DEMO_MANAGER_CLUB'=>$fixture['club'],'KH_NATIVE_AUTH_KEY_FILE'=>$fixture['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$fixture['root'].'/private.key','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KH_NATIVE_MANAGER_SESSION_ROOT'=>$fixture['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$fixture['media_root'],'KULTURHUS_DEMO_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password']]as$key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $role=$_GET['role']??'admin';if(!is_string($role)||!isset($fixture['accounts'][$role])){http_response_code(400);exit;}$account=$fixture['accounts'][$role];
        if($app==='integrated'){\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];$target='/vereinsverwaltung/?view=website&tab=navigation';}
        else{session_save_path($fixture['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$fixture['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));$target='/?view=navigation';}
        header('Location: '.$target,true,303);exit;
    }
    if($app==='native'){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
    if(str_starts_with($path,'/vereinsverwaltung/')){$relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(\Kulturhus\Native\Integration\Assets::serve($relative))exit;$public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$asset||!str_starts_with($asset,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($asset);exit;}
    $db=\Kulturhus\Native\TestSupport::db();if(!\Kulturhus\Native\Integration\PublicSite::serve($db,new \Kulturhus\Native\Shared\Access($db,$fixture['public_actor']),$fixture['media_root'],$fixture['root'],$fixture['club'],$origin))http_response_code(404);exit;
}
