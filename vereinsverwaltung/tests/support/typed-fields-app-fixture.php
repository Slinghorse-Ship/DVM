<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';

/** Empty, separately owned CI installation: both real routers and original Native Auth sessions. */
function ownTypedFieldsAppBrowser():void
{
    $cfg=\Kulturhus\Native\TestSupport::config();$previous=getenv('KH_NATIVE_TEST_CONFIG');
    $database='kh_native_'.bin2hex(random_bytes(12));$file=dirname($previous).'/typed-app-isolated-'.bin2hex(random_bytes(8)).'.json';
    $server=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    try{
        $handle=fopen($file,'x');if(!$handle||!chmod($file,0600))throw new RuntimeException('Own typed app configuration unavailable.');
        fwrite($handle,json_encode(array_replace($cfg,['database'=>$database,'label'=>'native-typed-fields','owner'=>'isolated-native-ci']),JSON_THROW_ON_ERROR));fclose($handle);putenv('KH_NATIVE_TEST_CONFIG='.$file);
        ownTypedFieldsFreshApps(\Kulturhus\Native\TestSupport::db());
    }finally{putenv('KH_NATIVE_TEST_CONFIG='.$previous);if(is_file($file))unlink($file);$server->exec('DROP DATABASE `'.$database.'`');}
}

function ownTypedFieldsFreshApps(PDO$db):void
{
    require_once dirname(__DIR__,2).'/src/bootstrap.php';require_once __DIR__.'/editorial-browser-fixture.php';require_once __DIR__.'/original-database-hash.php';
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);\Kulturhus\Native\TestSupport::seed($db);
    $f=ownEditorialBrowserFixture($db);$admin=$f['admin'];$f['native_club']=$admin->club();
    $registry=new \Kulturhus\Verein\Registry($db,$f['accounts']['admin']['actor']);
    foreach($f['accounts']as$role=>$account){$contact=$registry->saveContact(null,0,'person','Own typed app '.$role,'typed-app-'.$role.'@example.invalid','Own typed app fixture');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();}
    \Kulturhus\Verein\Database::ensureContributionRates($db);
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions',$runtime.'/media',$runtime.'/website-documents',$f['root'].'/website-documents']as$dir)if(!is_dir($dir))mkdir($dir,0700,true);
    copy($f['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);$f['runtime']=$runtime;$f['origins']=[];
    foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$f['origins'][$app]='http://127.0.0.1:'.$port;}
    $model=new \Kulturhus\Native\Cms\DataModel($db,$admin);$pages=new \Kulturhus\Native\Cms\Pages($db,$admin);$prefix='app_typed_'.bin2hex(random_bytes(4));$f['prefix']=$prefix;$key=static fn($suffix)=>$prefix.'_'.$suffix;
    foreach(['name'=>['type'=>'text','required'=>true],'tags'=>['type'=>'text','multiple'=>true,'max_items'=>5],'formatted'=>['type'=>'richtext'],'secret'=>['type'=>'password'],'file'=>['type'=>'file-reference']]as$suffix=>$definition)$model->saveField(['key'=>$key($suffix),'label'=>['name'=>'Bezeichnung','tags'=>'Merkmale','formatted'=>'Beschreibung','secret'=>'Geschütztes Passwort','file'=>'PDF-Dokument'][$suffix]]+$definition);
    $model->saveField(['key'=>$key('members'),'label'=>'Kontakte','type'=>'repeater','fields'=>[$key('name'),$key('formatted'),$key('file')],'max_items'=>5]);
    $model->saveTemplate(['key'=>$key('template'),'label'=>'Strukturierte Inhalte','fields'=>[$key('tags'),$key('members'),$key('secret')]]);
    $pdf=$f['root'].'/own-file.pdf';file_put_contents($pdf,"%PDF-1.7\n1 0 obj <<>> endobj\n%%EOF\n");
    $document=(new \Kulturhus\Native\Cms\WebsiteDocuments($db,$admin,$f['root'].'/website-documents'))->uploadMany([['path'=>$pdf,'name'=>'Eigenes Merkblatt.pdf']])[0];
    $f['document']=$document['id'];
    copy($pdf,$runtime.'/website-documents/'.$document['id'].'.pdf');chmod($runtime.'/website-documents/'.$document['id'].'.pdf',0600);
    $f['flows']=[];
    foreach(['native','integrated']as$app)foreach(['js','basic']as$mode){
        $page=$pages->save(['title'=>'Eigener strukturierter Entwurf '.$app.' '.$mode,'slug'=>'typed-app-'.$app.'-'.$mode,'template'=>$key('template'),'nav_visible'=>false,'custom_fields'=>[
            $key('tags')=>['Original A','Original B'],$key('secret')=>'Own typed application secret',
            $key('members')=>[['version'=>0,'values'=>[$key('name')=>'Geschütztes Original',$key('formatted')=>'<p>Ursprünglicher Inhalt</p>',$key('file')=>$document['id']],'editors'=>[$admin->user()['id']]]],
        ]]);$f['flows'][$app][$mode]=['id'=>$page['id'],'version'=>$page['version']];
    }
    $originalPage=$pages->save(['title'=>'Unverändertes öffentliches Original','slug'=>'typed-app-public-original','body_html'=>'<p>Originalä🙂</p>','nav_visible'=>false]);$originalPage=$pages->publish($originalPage['id'],$originalPage['version']);$publicOriginal=$pages->publicPage($originalPage['slug']);
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$fixed=array_values(array_diff($tables,['khcms_pages','khcms_revisions','khcms_state','native_auth_sessions']));$before=ownOriginalDatabaseHash($db,$fixed);$keys=[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')];$pdfHash=hash_file('sha256',$pdf);
    unset($f['admin']);$f['result']=$f['root'].'/typed-app-result.json';$path=$f['root'].'/typed-app.json';file_put_contents($path,json_encode($f,JSON_THROW_ON_ERROR));chmod($path,0600);putenv('DVM_TYPED_APP_FIXTURE='.$path);$servers=[];
    try{
        foreach($f['origins']as$app=>$origin){$log=fopen($f['root'].'/typed-'.$app.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($origin,PHP_URL_PORT),'-t',$f['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own typed actual app unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.parse_url($origin,PHP_URL_PORT),$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $process=proc_open(['node',dirname(__DIR__).'/native-typed-fields-app-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual typed fields apps failed.');
        if($before!==ownOriginalDatabaseHash($db,$fixed)||$keys!==[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')]||$publicOriginal!==$pages->publicPage($originalPage['slug'])||$pdfHash!==hash_file('sha256',$pdf))throw new RuntimeException('Typed app changed an unrelated original or key.');
        echo json_encode(['suite'=>'native-typed-app-originals','original_tables'=>count($fixed),'original_keys'=>'exact','original_publication'=>'exact','original_pdf'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}putenv('DVM_TYPED_APP_FIXTURE');}
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-typed-fields'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $f=json_decode(file_get_contents(getenv('DVM_TYPED_APP_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$f['origins'],true);if($app===false){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KH_NATIVE_MANAGER_SESSION_ROOT'=>$f['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['root'].'/media','KULTURHUS_DEMO_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password']]as$key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-enter'){
        $role=$_GET['role']??'admin';if(!isset($f['accounts'][$role])){http_response_code(400);exit;}$account=$f['accounts'][$role];
        if($app==='integrated'){\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];$dest='/vereinsverwaltung/?view=website';}
        else{session_save_path($f['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$f['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));$dest='/?view=website';}header('Location: '.$dest,true,303);exit;
    }
    if($app==='native'){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
    if(str_starts_with($path,'/vereinsverwaltung/')){$relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(\Kulturhus\Native\Integration\Assets::serve($relative))exit;$public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$asset||!str_starts_with($asset,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($asset);exit;}
    http_response_code(404);
}
