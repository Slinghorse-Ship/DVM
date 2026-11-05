<?php declare(strict_types=1);
/** Both genuine applications, exclusively in this runner's newly created synthetic database. */
require_once dirname(__DIR__,2).'/native/bootstrap.php';

function ownPageLifecycleBrowser(PDO $db):array
{
    // The native class suite already owns populated native-only tables. The integrated installer
    // deliberately refuses such a database, so give both real applications another empty OWN schema.
    // Never reset the caller's successful domain fixtures to make a browser installation fit.
    $cfg=\Kulturhus\Native\TestSupport::config();$previous=getenv('KH_NATIVE_TEST_CONFIG');
    $database='kh_native_'.bin2hex(random_bytes(12));$file=dirname($previous).'/page-lifecycle-isolated-'.bin2hex(random_bytes(8)).'.json';
    $server=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    try{
        $private=fopen($file,'x');if(!$private||!chmod($file,0600))throw new RuntimeException('Private lifecycle configuration unavailable.');
        fwrite($private,json_encode(array_replace($cfg,['database'=>$database,'label'=>'native-tree-copy-jobs','owner'=>'isolated-native-ci']),JSON_THROW_ON_ERROR));fclose($private);putenv('KH_NATIVE_TEST_CONFIG='.$file);
        return ownPageLifecycleFreshBrowser(\Kulturhus\Native\TestSupport::db());
    }finally{putenv('KH_NATIVE_TEST_CONFIG='.$previous);if(is_file($file))unlink($file);$server->exec('DROP DATABASE `'.$database.'`');}
}

function ownPageLifecycleFreshBrowser(PDO $db):array
{
    require_once dirname(__DIR__,2).'/src/bootstrap.php';
    require_once __DIR__.'/editorial-browser-fixture.php';
    require_once __DIR__.'/original-database-hash.php';
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    \Kulturhus\Native\TestSupport::seed($db);
    $f=ownEditorialBrowserFixture($db);$f['native_club']=$f['admin']->club();$registry=new \Kulturhus\Verein\Registry($db,$f['accounts']['admin']['actor']);
    foreach($f['accounts'] as $role=>$account){$contact=$registry->saveContact(null,0,'person','Own lifecycle '.$role,'own-lifecycle-'.$role.'@example.invalid','Own lifecycle fixture');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();}
    \Kulturhus\Verein\Database::ensureContributionRates($db);
    $cfg=\Kulturhus\Native\TestSupport::config();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach([$runtime,$runtime.'/sessions'] as $directory)if(!is_dir($directory))mkdir($directory,0700,true);
    copy($f['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
    if(!is_dir($runtime.'/media'))symlink($f['root'].'/media',$runtime.'/media');
    $f['runtime']=$runtime;$f['origins']=[];
    foreach(['native','integrated'] as $app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$f['origins'][$app]='http://127.0.0.1:'.$port;}
    $pages=new \Kulturhus\Native\Cms\Pages($db,$f['admin']);$trash=new \Kulturhus\Native\Cms\PageTrash($db,$f['admin']);$f['flows']=[];$purged=[];
    $png=$f['root'].'/own-source.png';$im=imagecreatetruecolor(12,8);imagefilledrectangle($im,0,0,11,7,imagecolorallocate($im,30,90,170));imagepng($im,$png);imagedestroy($im);
    $media=(new \Kulturhus\Native\Cms\Media($db,$f['admin'],$f['root'].'/media'))->upload($png,'Own retained original.png','Own retained original');
    foreach(['native','integrated'] as $app)foreach(['js','basic'] as $mode){
        $slug='browser-'.$app.'-'.$mode;$source=$pages->save(['title'=>'Original '.$app.' '.$mode,'slug'=>$slug,'media_ids'=>[$media['id']],'body_html'=>'<p>Own source content ä🙂</p>']);
        for($i=0;$i<26;$i++)$pages->save(['title'=>'Own source child '.$i,'slug'=>$slug.'/child-'.$i,'parent_id'=>$source['id'],'body_html'=>'<p>Exact source child '.$i.'</p>']);
        $source=$pages->publish($source['id'],$source['version']);
        $remove=$pages->save(['title'=>'Remove '.$app.' '.$mode,'slug'=>$slug.'-remove','media_ids'=>[$media['id']]]);$remove=$trash->move($remove['id'],$remove['version']);$purged[]=$remove['id'];
        $f['flows'][$app][$mode]=['source'=>$source['id'],'source_version'=>$source['version'],'copy_slug'=>$slug.'-copy','purge'=>$remove['id'],'purge_version'=>$remove['version']];
    }
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $mutable=['native_auth_sessions','khcms_state','khcms_pages','khcms_revisions','khcms_trash','khcms_tree_copy_jobs','khcms_tree_copy_items','khcms_tree_copy_removals','khcms_page_tombstones','khcms_purged_paths'];
    $fixed=array_values(array_diff($tables,$mutable));$original=ownOriginalDatabaseHash($db,$fixed);
    $old=[];foreach(array_diff($mutable,['native_auth_sessions','khcms_state']) as $table){$key=$table==='khcms_pages'?'id':(in_array($table,['khcms_tree_copy_jobs','khcms_tree_copy_items','khcms_tree_copy_removals'],true)?($table==='khcms_tree_copy_items'?'job_id':'id'):'page_id');$where=!in_array($table,['khcms_tree_copy_jobs','khcms_tree_copy_items','khcms_tree_copy_removals'],true)?' WHERE `'.$key.'` NOT IN ('.implode(',',array_fill(0,count($purged),'?')).')':'';$args=$where?$purged:[];$old[$table]=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM `'.$table.'`'.$where.' ORDER BY 1,2',$args)->fetchAll();}
    $keys=[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')];
    $files=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f['root'].'/media',FilesystemIterator::SKIP_DOTS));foreach($it as $file)if($file->isFile())$files[$file->getPathname()]=hash_file('sha256',$file->getPathname());
    $f['proof_secret']=bin2hex(random_bytes(32));$f['result']=$f['root'].'/page-lifecycle-result.json';unset($f['admin']);$file=$f['root'].'/page-lifecycle.json';file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PAGE_LIFECYCLE_FIXTURE='.$file);$servers=[];
    try{
        foreach($f['origins'] as $app=>$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($f['root'].'/lifecycle-'.$app.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$f['root'].'/web',__FILE__],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own lifecycle server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $process=proc_open(['node',dirname(__DIR__).'/native-page-lifecycle-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual lifecycle browser failed.');
        if(ownOriginalDatabaseHash($db,$fixed)!==$original||$keys!==[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')])throw new RuntimeException('Lifecycle changed another business original or key.');
        foreach($old as $table=>$rows){$primary=\Kulturhus\Native\Shared\Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$identity=static fn(array $row):string=>serialize(array_map(static fn(string $key):mixed=>$row[$key],$primary));$expected=[];foreach($rows as $row)$expected[$identity($row)]=hash('sha256',serialize($row));$current=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM `'.$table.'`');while($row=$current->fetch()){$key=$identity($row);if(!isset($expected[$key]))continue;if(!hash_equals($expected[$key],hash('sha256',serialize($row))))throw new RuntimeException('Lifecycle changed a retained original row in '.$table);unset($expected[$key]);}if($expected)throw new RuntimeException('Lifecycle removed a retained original row in '.$table);}
        foreach($files as $path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Lifecycle changed original media bytes.');
        foreach($purged as $id)if(\Kulturhus\Native\Shared\Db::run($db,'SELECT 1 FROM khcms_pages WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('Confirmed actual browser purge did not remove the original.');
        foreach($f['flows'] as $flows)foreach($flows as $flow){$copies=\Kulturhus\Native\Shared\Db::run($db,"SELECT COUNT(*) AS total,SUM(published_revision IS NOT NULL OR published_slug IS NOT NULL OR JSON_EXTRACT(draft_json,'$.nav_visible')=TRUE) AS public_count FROM khcms_pages WHERE club_id=? AND (slug=? OR slug LIKE ?)",[$f['native_club'],$flow['copy_slug'],$flow['copy_slug'].'/%'])->fetch();if((int)$copies['total']!==27||(int)$copies['public_count']!==0)throw new RuntimeException('Removing job metadata changed an actual private copied tree.');}
        $result=json_decode(file_get_contents($f['result']),true,32,JSON_THROW_ON_ERROR);echo json_encode(['suite'=>'native-page-lifecycle-originals','original_tables'=>count($fixed),'retained_original_rows'=>array_sum(array_map('count',$old)),'original_bytes'=>'exact','original_keys'=>'exact','original_media'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;return $result;
    }finally{foreach($servers as [$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}}
}

if(PHP_SAPI==='cli-server'){
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-tree-copy-jobs'||($cfg['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
    $f=json_decode(file_get_contents(getenv('DVM_PAGE_LIFECYCLE_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$origin='http://'.($_SERVER['HTTP_HOST']??'');$app=array_search($origin,$f['origins'],true);if($app===false){http_response_code(403);exit;}$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit;}
    foreach(['KH_NATIVE_REQUIRE_LOGIN'=>'1','KH_NATIVE_LOCAL_DEMO'=>'synthetic','KH_NATIVE_DEMO_PORT'=>(string)parse_url($origin,PHP_URL_PORT),'KH_NATIVE_AUTH_KEY_FILE'=>$f['root'].'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$f['root'].'/private.key','KH_NATIVE_MANAGER'=>'1','VEREIN_AUTH'=>'native','VEREIN_MODE'=>'synthetic','KULTURHUS_LOCAL_DEMO'=>'php','KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KH_NATIVE_MANAGER_SESSION_ROOT'=>$f['root'].'/manager-sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$f['root'].'/media','KULTURHUS_DEMO_DATABASE'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','VEREIN_DATABASE_USER'=>$cfg['username'],'VEREIN_DATABASE_PASSWORD'=>$cfg['password']] as $key=>$value)putenv($key.'='.$value);putenv('SMTP_DSN');
    if($path==='/own-test-observe'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
        if(!hash_equals($f['proof_secret'],$_SERVER['HTTP_X_OWN_LIFECYCLE_PROOF']??'')){http_response_code(403);exit;}$id=$_GET['job']??'';if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)){http_response_code(400);exit;}$db=\Kulturhus\Native\TestSupport::db();$row=\Kulturhus\Native\Shared\Db::run($db,'SELECT COUNT(*) AS copies,COALESCE(SUM(p.published_revision IS NOT NULL OR p.published_slug IS NOT NULL OR JSON_EXTRACT(p.draft_json,\'$.nav_visible\')=TRUE),0) AS public_copies FROM khcms_tree_copy_items i JOIN khcms_pages p ON p.id=i.copy_id AND p.club_id=i.club_id WHERE i.job_id=? AND i.club_id=?',[$id,$f['native_club']])->fetch();header('Content-Type: application/json');echo json_encode($row,JSON_THROW_ON_ERROR);exit;
    }
    if($path==='/own-test-enter'&&($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $role=$_GET['role']??'admin';if(!is_string($role)||!isset($f['accounts'][$role])){http_response_code(400);exit;}$account=$f['accounts'][$role];
        if($app==='integrated'){\Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$account['token'];$_SESSION['actor']=$account['actor'];$dest='/vereinsverwaltung/?view=website';}
        else{session_save_path($f['runtime'].'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12));session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);$_SESSION['auth_token']=$account['token'];$_SESSION['native_actor']=\Kulturhus\Native\Integration\ManagerBridge::actorKey($account['actor'],$f['club']);$_SESSION['native_csrf']=bin2hex(random_bytes(32));$dest='/?view=website';}header('Location: '.$dest,true,303);exit;
    }
    if($app==='native'){require dirname(__DIR__,2).'/native/Web/router.php';exit;}
    if(str_starts_with($path,'/vereinsverwaltung/')){$relative=substr($path,strlen('/vereinsverwaltung/'));if($relative===''||$relative==='index.php'){require dirname(__DIR__,2).'/public/index.php';exit;}if(\Kulturhus\Native\Integration\Assets::serve($relative))exit;$public=realpath(dirname(__DIR__,2).'/public');$asset=realpath($public.'/'.$relative);$types=['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2','woff'=>'font/woff'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));if(!$asset||!str_starts_with($asset,$public.'/')||!isset($types[$ext])){http_response_code(404);exit;}header('Content-Type: '.$types[$ext]);readfile($asset);exit;}
    http_response_code(404);
}
