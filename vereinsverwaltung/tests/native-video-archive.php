<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Schema,Videos,Workspace,VideoArchive,VideoApproval,VideoMetadata,VideoQuota,PublicationSnapshot,Meta};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Cms\Conflict;

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$db->exec("CREATE TABLE native_schema_versions (module VARCHAR(32) PRIMARY KEY,version INT NOT NULL,applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
Db::run($db,'INSERT INTO native_schema_versions VALUES(?,?,UTC_TIMESTAMP())',['communication',Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']]);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700,true);
file_put_contents($root.'/private.key',random_bytes(32));chmod($root.'/private.key',0600);$box=SecretBox::fromFile($root.'/private.key');
$access=new Access($db,'admin');$club=$access->club();$n=0;$server=null;$browser=getenv('DVM_VIDEO_ARCHIVE_BROWSER')==='1';
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;};
$deny=static function(callable $f,string $label)use($check):void{try{$f();}catch(DomainException|Conflict){$check(true,$label);return;}throw new RuntimeException($label);};
try{
    (new Identity($db,$access))->save(['name'=>'Own original video archive club','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    (new Accounts($db,$box))->save($access,'meta',['page_id'=>'111','instagram_id'=>'222','token'=>'synthetic-archive-never-live','api_version'=>'v24.0'],0);
    $fixture=__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4';$work=new Workspace($db,$access,'social',$root.'/media');$videos=new Videos($db,$access,$root.'/media');$archive=new VideoArchive($db,$access);
    $item=$work->save(['title'=>'Original approved video','kind'=>'reel','body'=>'Original human caption','channels'=>['facebook']]);
    $video=$videos->save($item['id'],1,1,$fixture,'Original approved sound.mp4');$source=$videos->page($item['id'])['rows'][0]['source_id'];
    (new VideoMetadata($db,$access,$box))->save($source,['alt'=>'Original video','credit'=>'Original human credit','source_name'=>'Own camera','license_name'=>'Own permission','rights_state'=>'cleared','consent_state'=>'recorded'],0,'Original private video consent');
    $work->approve($item['id'],1,$video);$renew=$work->renewVideoApproval($item['id'],2,$video);$selected=$renew['video_id'];$approved=$work->approve($item['id'],3,$selected);
    $jobs=(new Meta($db,$box))->queue($access,$item['id'],gmdate('Y-m-d H:i:s'),$root.'/media');
    $frame=$box->open('job:'.$club.':'.$jobs[0],Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobs[0]])->fetchColumn());
    $published=PublicationSnapshot::social($db,$access,$item['id'],$root.'/media');$file=$videos->file($video);
    $draft=$work->save(['title'=>'Original unapproved archive candidates','kind'=>'reel','body'=>'Actual human draft']);$candidates=[];
    for($i=0;$i<7;$i++){$id=$videos->save($draft['id'],1,1,$fixture,'Candidate '.($i+1).'.mp4');$row=Db::run($db,'SELECT source_id FROM native_social_videos WHERE id=?',[$id])->fetch();$candidates[]=['id'=>$id,'source'=>$row['source_id']];}
    $quota=(new VideoQuota($db,$access))->get();$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_video_archives','native_video_archive_history']));$filter=static fn(string$table):array=>$table==='native_communication_save_requests'?[" WHERE module<>'video-archive' OR item_id<>?",[$draft['id']]]:['',[]];$original=ownOriginalDatabaseHash($db,$tables,$filter);
    $files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$files[$f->getPathname()]=hash_file('sha256',$f->getPathname());
    $check($archive->get($source)===['version'=>0,'archived'=>false,'actor_name'=>'','changed_at'=>''],'older original source starts active without invented archive history');
    $saved=$archive->save($source,true,0);$check($saved['version']===1&&$saved['archived'],'explicit archive binds the original source version');
    $check($videos->page($item['id'])['total']===0&&$videos->page($item['id'],1,true)['total']===2,'archive hides every original version sharing the same physical source');
    $check($archive->save($source,true,1)===$saved&&$archive->history($source)['total']===1,'same desired state creates no extra history');
    $deny(fn()=>$archive->save($source,false,0),'stale original archive form cannot overwrite current state');
    $check(PublicationSnapshot::social($db,$access,$item['id'],$root.'/media')===$published,'archiving preserves exact original approved publication');
    $check(Kulturhus\Native\Communication\VideoPublication::publicFile($db,$club,basename($frame['video_url']),$root.'/media')['sha256']===$file['sha256'],'pending original provider capability still serves its exact approved bytes');
    $deny(fn()=>$work->renewVideoApproval($item['id'],4,$selected),'archived source cannot be silently selected for a new review');
    $check($videos->file($video)===$file&&(new VideoQuota($db,$access))->get()===$quota,'reversible archive keeps exact file and counts retained bytes against quota');
    $check((new VideoMetadata($db,$access,$box))->privateProof($source)==='Original private video consent','original encrypted consent survives archive unchanged');
    $archive->save($source,false,1);$check($videos->page($item['id'])['total']===2&&$videos->file($video)===$file,'return restores all original versions without file copies or new approvals');
    $candidate=$candidates[0];$archive->save($candidate['source'],true,0);
    $deny(fn()=>$work->approve($draft['id'],1,$candidate['id']),'actual approval rejects an archived unapproved original');
    $archive->save($candidate['source'],false,1);
    $proof=Db::atomic($db,fn()=>(new VideoApproval($db,$access,$root.'/media'))->capture($draft,$candidate['id']));$check($proof['id']===$candidate['id'],'returned original can be deliberately selected again');
    // An independent connection with an older RR snapshot must still see current archive authority.
    $second=TestSupport::db();$second->beginTransaction();$second->query('SELECT COUNT(*) FROM native_video_archives')->fetchColumn();
    $archive->save($candidates[1]['source'],true,0);$secondWork=new Workspace($second,new Access($second,'admin'),'social',$root.'/media');
    $deny(fn()=>$secondWork->approve($draft['id'],1,$candidates[1]['id']),'independent old snapshot cannot approve a freshly archived source');$second->rollBack();
    $second->beginTransaction();$second->query('SELECT * FROM native_video_archives')->fetchAll();$archive->save($candidates[2]['source'],true,0);
    $deny(fn()=>(new VideoArchive($second,new Access($second,'admin')))->save($candidates[2]['source'],false,0),'independent stale writer sees current source archive version');$second->rollBack();
    $active=$videos->page($draft['id']);$archived=$videos->page($draft['id'],1,true);$check($active['total']===5&&$archived['total']===2&&$active['active_total']===5&&$active['archived_total']===2,'both catalogue tabs have exact independently filtered totals');
    $archive->save($candidates[1]['source'],false,1);$archive->save($candidates[2]['source'],false,1);$first=$videos->page($draft['id']);$last=$videos->page($draft['id'],2);
    $check(count($first['rows'])===6&&count($last['rows'])===1&&count(array_unique(array_column([...$first['rows'],...$last['rows']],'id')))===7,'six-item navigation reaches every original exactly once');
    for($v=2;$v<12;$v++)$archive->save($source,$v%2===0,$v);
    $history=$archive->history($source);$older=$archive->history($source,2);$check($history['total']===12&&count($history['rows'])===10&&count($older['rows'])===2&&(int)$older['rows'][1]['version']===1,'immutable exact archive history is limited to ten originals per page');
    foreach([0,'0','01',[],false,1.5,'1000001']as$bad)$deny(fn()=>$archive->history($source,$bad),'invalid history cursor never silently becomes another page');
    foreach([-1,4294967295]as$bad)$deny(fn()=>$archive->save($source,true,$bad),'invalid archive version cannot change state');
    $deny(fn()=>(new VideoArchive($db,new Access($db,'outsider')))->get($source),'foreign club cannot read archive source');
    $deny(fn()=>(new VideoArchive($db,new Access($db,'reader')))->save($source,true,12),'read-only role cannot archive a source');
    $manager=new Kulturhus\Native\Web\Manager($db,$access,$root.'/media','own-csrf');$post=['csrf'=>'own-csrf','operation'=>'social-video-archive','id'=>$draft['id'],'source_id'=>$candidates[0]['source'],'version'=>(string)$draft['version'],'video_page'=>'1','archive_request_key'=>bin2hex(random_bytes(16)),'archive_version'=>'2','archived'=>'1'];
    $post['csrf']='forged';$deny(fn()=>$manager->action('social',$post,[]),'actual manager rejects forged archive CSRF');$post['csrf']='own-csrf';$post['id']=$item['id'];$deny(fn()=>$manager->action('social',$post,[]),'actual archive action binds the displayed content and original source');$post['id']=$draft['id'];$post['archived']='yes';$deny(fn()=>$manager->action('social',$post,[]),'actual archive action rejects ambiguous boolean');
    Db::run($db,'DELETE FROM native_grants WHERE actor_id=? AND module=? AND capability=?',['editor','social','edit']);$deny(fn()=>(new VideoArchive($db,new Access($db,'editor')))->save($source,true,12),'fresh editing revocation blocks archive mutation');Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['editor','social','edit']);
    $archive->save($candidates[0]['source'],true,2);$post['archive_version']='3';$post['archived']='0';$result=$manager->action('social',$post,[]);$route=Kulturhus\Native\Integration\Routes::result($result);
    $check($route['view']==='social'&&$route['native_item']===$draft['id']&&$route['video_archive']==='0'&&$route['video_page']===1,'production manager result retains the deliberately selected original archive tab');
    $translated=Kulturhus\Native\Integration\Routes::translate('/?'.http_build_query(['view'=>'social','item'=>$draft['id'],'video_archive'=>'1','video_page'=>2,'video_archive_source'=>$candidate['source'],'video_archive_history'=>2]).'#social-videos');parse_str(parse_url($translated,PHP_URL_QUERY),$translatedQuery);
    $check($translatedQuery['native_item']===$draft['id']&&$translatedQuery['video_archive']==='1'&&$translatedQuery['video_archive_source']===$candidate['source']&&$translatedQuery['video_archive_history']==='2'&&parse_url($translated,PHP_URL_FRAGMENT)==='social-videos','production route translation retains exact source history, archive filter and page');
    $deny(fn()=>Kulturhus\Native\Integration\Routes::result(array_replace($result,['video_archive'=>'yes'])),'production result rejects an unrecognised archive tab');
    if($browser){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);mkdir($root.'/web',0700,true);putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
        $server=proc_open([...TestSupport::phpCommand(),'-d','session.cookie_secure=0','-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/native/Web/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Owned video archive server required.');fclose($pipes[0]);
        $ready=false;for($i=0;$i<100;$i++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Archive server unavailable.');
        $data=$root.'/browser.json';file_put_contents($data,json_encode(['origin'=>'http://127.0.0.1:'.$port,'item'=>$draft['id'],'video'=>$candidate['id'],'source'=>$candidate['source']],JSON_THROW_ON_ERROR));chmod($data,0600);putenv('DVM_VIDEO_ARCHIVE_FIXTURE='.$data);
        $node=proc_open(['node',__DIR__.'/video-archive-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__,2));if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual video archive browser failed.');
        $check($archive->get($candidate['source'])['version']===8&&!$archive->get($candidate['source'])['archived'],'actual JS and no-JS forms archive and return the same source twice');
    }
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$original,'all earlier complete content, approval, provider, proof, quota and source rows remain exact');
    $check((int)Db::run($db,"SELECT COUNT(*) FROM native_communication_save_requests WHERE module='video-archive' AND item_id=?",[$draft['id']])->fetchColumn()===($browser?5:1),'only the consciously accepted manager and browser archive decisions have personal receipts');
    foreach($files as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'every earlier original file and private key byte stays intact');
    echo json_encode(['suite'=>'native-video-archive','checks'=>$n,'browser'=>$browser,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
}
