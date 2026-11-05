<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';

/** Empty, separately owned CI installation: both real routers and original Native Auth sessions. */
function ownFileVersionsAppBrowser():void
{
    $cfg=\Kulturhus\Native\TestSupport::config();$previous=getenv('KH_NATIVE_TEST_CONFIG');
    $database='kh_native_'.bin2hex(random_bytes(12));$file=dirname($previous).'/file-app-isolated-'.bin2hex(random_bytes(8)).'.json';
    $server=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    try{
        $handle=fopen($file,'x');if(!$handle||!chmod($file,0600))throw new RuntimeException('Own file app configuration unavailable.');
        fwrite($handle,json_encode(array_replace($cfg,['database'=>$database,'label'=>'native-file-versions','owner'=>'isolated-native-ci']),JSON_THROW_ON_ERROR));fclose($handle);putenv('KH_NATIVE_TEST_CONFIG='.$file);
        ownFileVersionsFreshApps(\Kulturhus\Native\TestSupport::db());
    }finally{putenv('KH_NATIVE_TEST_CONFIG='.$previous);if(is_file($file))unlink($file);$server->exec('DROP DATABASE `'.$database.'`');}
}

function ownFileVersionsFreshApps(PDO$db):void
{
    require_once dirname(__DIR__,2).'/src/bootstrap.php';require_once __DIR__.'/editorial-browser-fixture.php';require_once __DIR__.'/original-database-hash.php';
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);\Kulturhus\Native\Files\Schema::install($db);\Kulturhus\Native\TestSupport::seed($db);
    $f=ownEditorialBrowserFixture($db);$admin=$f['admin'];$f['native_club']=$admin->club();
    $registry=new \Kulturhus\Verein\Registry($db,$f['accounts']['admin']['actor']);
    foreach($f['accounts']as$role=>$account){$contact=$registry->saveContact(null,0,'person','Own file app '.$role,'file-app-'.$role.'@example.invalid','Own file app fixture');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();}
    \Kulturhus\Verein\Database::ensureContributionRates($db);
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions',$runtime.'/media',$runtime.'/website-documents',$f['root'].'/website-documents']as$dir)if(!is_dir($dir))mkdir($dir,0700,true);
    copy($f['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);$f['runtime']=$runtime;$f['origins']=[];
    foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$f['origins'][$app]='http://127.0.0.1:'.$port;}
    $fileRoot=$runtime.'/files';$mediaRoot=$runtime.'/media';$versions=new \Kulturhus\Native\Files\AssetVersions($db,$admin,$fileRoot,$mediaRoot);$pages=new \Kulturhus\Native\Cms\Pages($db,$admin);
    $f['sources']=['first'=>$f['root'].'/first.txt','second'=>$f['root'].'/second.txt','image'=>$f['root'].'/own-image.png'];
    file_put_contents($f['sources']['first'],"Eigene unveränderte Originaldatei ä🙂\n");file_put_contents($f['sources']['second'],"Eigene tatsächliche Ersatzfassung\n");$image=imagecreatetruecolor(12,8);imagepng($image,$f['sources']['image']);unset($image);
    $pdf=$f['root'].'/own-file.pdf';file_put_contents($pdf,"%PDF-1.7\n1 0 obj <<>> endobj\n%%EOF\n");$document=(new \Kulturhus\Native\Cms\WebsiteDocuments($db,$admin,$runtime.'/website-documents'))->uploadMany([['path'=>$pdf,'name'=>'Unverändertes Original.pdf']])[0];
    $f['flows']=[];foreach(['native','integrated']as$app)foreach(['js','basic']as$mode){$asset=$versions->uploadMany([['path'=>$f['sources']['first'],'name'=>'Original '.$app.' '.$mode.'.txt']])[0];$freeImage=(new \Kulturhus\Native\Cms\Media($db,$admin,$mediaRoot))->upload($f['sources']['image'],'Vorhandenes Bild '.$app.' '.$mode.'.png','Vorhandenes unverändertes Original '.$app.' '.$mode);$f['flows'][$app][$mode]=['id'=>$asset['id'],'version'=>$asset['version'],'revision_id'=>$asset['revision_id'],'existing_image'=>$freeImage['id'],'existing_name'=>'Vorhandenes Bild '.$app.' '.$mode.'.png'];}
    $public=$versions->uploadMany([['path'=>$f['sources']['first'],'name'=>'Öffentliches Original.txt']])[0];$private=$versions->uploadMany([['path'=>$f['sources']['second'],'name'=>'Interne Originaldatei.txt']])[0];$f['public_file']=$public['revision_id'];$f['private_file']=$private['revision_id'];
    $model=new \Kulturhus\Native\Cms\DataModel($db,$admin);$model->saveField(['key'=>'own_general_file','label'=>'Datei','type'=>'file-reference']);$model->saveField(['key'=>'own_internal_files','label'=>'Interne Angaben','type'=>'repeater','fields'=>['own_general_file']]);$model->saveTemplate(['key'=>'own_general_files','label'=>'Eigene Dateiseite','fields'=>['own_general_file','own_internal_files']]);
    $originalPage=$pages->save(['title'=>'Unverändertes öffentliches Original','slug'=>'file-app-public-original','template'=>'own_general_files','custom_fields'=>['own_general_file'=>$public['revision_id'],'own_internal_files'=>[['version'=>0,'public'=>false,'values'=>['own_general_file'=>$private['revision_id']]]]],'nav_visible'=>false]);$originalPage=$pages->publish($originalPage['id'],$originalPage['version']);$publicOriginal=$pages->publicPage($originalPage['slug']);
    \Kulturhus\Native\Shared\Db::run($db,'UPDATE native_users SET club_id=? WHERE id=?',[$f['native_club'],'reader']);
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$fixed=array_values(array_filter($tables,static fn($table)=>!str_starts_with($table,'khcms_file_')&&!in_array($table,['khcms_assets','khcms_asset_revisions','khcms_image_group_history','khcms_state','khcms_media','khcms_media_metadata','khcms_media_metadata_revisions','native_auth_sessions'],true)));$before=ownOriginalDatabaseHash($db,$fixed);$keys=[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')];$pdfHash=hash_file('sha256',$pdf);
    unset($f['admin']);$f['result']=$f['root'].'/file-app-result.json';$path=$f['root'].'/file-app.json';file_put_contents($path,json_encode($f,JSON_THROW_ON_ERROR));chmod($path,0600);putenv('DVM_FILE_APP_FIXTURE='.$path);$servers=[];
    try{
        foreach($f['origins']as$app=>$origin){$log=fopen($f['root'].'/files-'.$app.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($origin,PHP_URL_PORT),'-t',$f['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own file actual app unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.parse_url($origin,PHP_URL_PORT),$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $process=proc_open(['node',dirname(__DIR__).'/native-file-versions-app-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual file versions apps failed.');
        if($before!==ownOriginalDatabaseHash($db,$fixed)||$keys!==[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')]||$publicOriginal!==$pages->publicPage($originalPage['slug'])||$pdfHash!==hash_file('sha256',$pdf))throw new RuntimeException('File app changed an unrelated original or key.');
        echo json_encode(['suite'=>'native-file-app-originals','original_tables'=>count($fixed),'original_keys'=>'exact','original_publication'=>'exact','original_pdf'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}putenv('DVM_FILE_APP_FIXTURE');}
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-file-versions'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $f=json_decode(file_get_contents(getenv('DVM_FILE_APP_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$f['origins'],true);if($app===false){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KH_NATIVE_MANAGER_SESSION_ROOT'=>$f['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['runtime'].'/media','KULTURHUS_DEMO_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password']]as$key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-enter'){
        $role=$_GET['role']??'admin';if(!isset($f['accounts'][$role])){http_response_code(400);exit;}$account=$f['accounts'][$role];
        if($app==='integrated'){\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];$dest='/vereinsverwaltung/?view=website';}
        else{session_save_path($f['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$f['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));$dest='/?view=website';}header('Location: '.$dest,true,303);exit;
    }
    if($app==='native'){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
    if(str_starts_with($path,'/vereinsverwaltung/')){$relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(\Kulturhus\Native\Integration\Assets::serve($relative))exit;$public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$asset||!str_starts_with($asset,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($asset);exit;}
    $db=\Kulturhus\Native\TestSupport::db();if(!\Kulturhus\Native\Integration\PublicSite::serve($db,new \Kulturhus\Native\Shared\Access($db,$f['public_actor']),$f['runtime'].'/media',$f['runtime'],$f['club'],$origin))http_response_code(404);
}
