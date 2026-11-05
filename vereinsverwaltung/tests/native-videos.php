<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Schema,VideoSchema,Videos,ReelFile,Workspace,Images};
use Kulturhus\Native\Cms\{Media,MediaMetadata,Pages,Conflict};

$db=TestSupport::db(); TestSupport::seed($db); Schema::install($db);
Kulturhus\Native\Cms\Schema::install($db);
// This intentionally small fixture installs the current communication module explicitly.
// Keep its install receipt as the real installer does; browser writes must pass the same gate.
$db->exec("CREATE TABLE native_schema_versions (module VARCHAR(32) PRIMARY KEY,version INT NOT NULL,applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
Db::run($db,'INSERT INTO native_schema_versions VALUES(?,?,UTC_TIMESTAMP())',['communication',Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']]);
$cfg=TestSupport::config(); $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
mkdir($root,0700,true); file_put_contents($root.'/private.key',random_bytes(32)); chmod($root.'/private.key',0600);
$access=new Access($db,'admin'); $store=new Videos($db,$access,$root.'/media'); $n=0; $server=null; $targetLabel=TestSupport::config()['label']??'';$browser=getenv('DVM_VIDEO_BROWSER')==='1'&&$targetLabel==='native-videos';$studio=getenv('DVM_REEL_STUDIO_BROWSER')==='1'&&$targetLabel==='native-reel-studio';$audioBrowser=getenv('DVM_REEL_AUDIO_BROWSER')==='1'&&$targetLabel==='native-reel-audio';
$check=static function(bool $ok,string $label)use(&$n):void { if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL; };
$denied=static function(callable $fn,string $label)use($check):void { try{$fn();}catch(DomainException|Conflict){$check(true,$label);return;}throw new RuntimeException($label); };
$fixture=__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4';
$silent=__DIR__.'/fixtures/reel-profile/portrait-avc-silent.mp4';
try {
    $profile=ReelFile::inspect($fixture);
    $check($profile===['mime'=>'video/mp4','codec'=>'h264','width'=>1080,'height'=>1920,'duration_ms'=>3000,'frames'=>90,'fps'=>30.0,'audio'=>['codec'=>'aac','sample_rate'=>48000,'channels'=>2],'bytes'=>60207],'real independently encoded AVC/AAC reel has exact dimensions, timing, frame count and preserved sound profile');
    $check(ReelFile::inspect($silent)['audio']===null,'real silent AVC file remains explicitly without an invented sound track');
    $data=file_get_contents($fixture); $avc=strpos($data,'avcC')+4; $stts=strpos($data,'stts')+4; $stco=strpos($data,'stco')+4; $stsc=strpos($data,'stsc')+4;
    $bad=[substr($data,0,7),substr($data,0,31),substr($data,0,-1),substr_replace($data,pack('N',4),0,4),substr_replace($data,pack('N',1).'ftyp'.str_repeat("\xff",8),0,16),substr_replace($data,'html',8,4),str_replace('avc1','hvc1',$data),substr_replace($data,"\xff\xff",$avc+6,2),substr_replace($data,pack('N',10001),$stts+4,4),substr_replace($data,pack('N',strlen($data)+8),$stco+8,4),substr_replace($data,pack('N',0),$stsc+12,4)];
    foreach($bad as$i=>$bytes){$file=$root.'/invalid-'.$i.'.mp4';file_put_contents($file,$bytes);$denied(fn()=>ReelFile::inspect($file),'malformed actual container, codec, sample table or outside media offset is rejected');unlink($file);}
    $link=$root.'/linked.mp4';symlink($fixture,$link);$denied(fn()=>ReelFile::inspect($link),'video parser rejects linked files');unlink($link);
    $expectedVideoSchema=ownOriginalDatabaseHash($db,[]);
    foreach(['native_video_removal_history','native_video_removal_states','native_video_removals','native_video_retention_history','native_video_retention']as$table){$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'only a fresh empty lifecycle table is removed for isolated old schema');$db->exec('DROP TABLE '.$table);}
    foreach(['native_video_archive_history','native_video_archives']as$table){$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'only a fresh empty archive table is removed for isolated old schema');$db->exec('DROP TABLE '.$table);}
    foreach(['native_video_quota_history','native_video_quota']as$table){$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'only a new empty quota table is removed for the isolated old-schema case');$db->exec('DROP TABLE '.$table);}
    foreach(['native_video_metadata_revisions','native_video_metadata']as$table){$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'only a new empty metadata table is removed for the isolated old-schema case');$db->exec('DROP TABLE '.$table);}
    $check((int)$db->query('SELECT COUNT(*) FROM native_video_capabilities')->fetchColumn()===0,'only new empty capability table is removed for the isolated old-schema case');$db->exec('DROP TABLE native_video_capabilities');
    $db->exec('DROP TABLE native_social_videos');$db->exec('DROP TABLE native_social_video_sources');
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $check(!$store->installed(),'older communication installation reads unavailable video capability without DDL');
    $denied(fn()=>$store->page(str_repeat('1',32)),'older schema explicitly requires the installer rather than creating tables in a read');
    $check($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$tables,'older capability and rejected history read preserve every exact existing table');
    $old=ownOriginalDatabaseHash($db,$tables,null,true);VideoSchema::install($db);
    $check(ownOriginalDatabaseHash($db,[])===$expectedVideoSchema&&ownOriginalDatabaseHash($db,$tables,null,true)===$old&&$store->installed(),'explicit new empty video tables retain every old complete row');
    $check((int)$db->query('SELECT COUNT(*) FROM native_social_video_sources')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_social_videos')->fetchColumn()===0,'installer starts both video stores empty and imports no prior media');

    $im=imagecreatetruecolor(32,32);imagefill($im,0,0,imagecolorallocate($im,13,81,37));imagepng($im,$root.'/protected.png');unset($im);
    $photo=(new Media($db,$access,$root.'/media'))->upload($root.'/protected.png','Earlier protected original.png','Earlier original');
    (new MediaMetadata($db,$access,SecretBox::fromFile($root.'/private.key')))->save($photo['id'],['alt'=>'Earlier exact source','rights_state'=>'cleared','consent_state'=>'recorded'],0,'Earlier encrypted original proof');
    $pages=new Pages($db,$access);$page=$pages->save(['title'=>'Earlier exact video test publication','slug'=>'protected-video-original','media_ids'=>[$photo['id']]]);$pages->publish($page['id'],1);$publication=$pages->publicPage($page['slug']);
    $identity=(new Identity($db,$access))->save(['name'=>'Own protected video identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    $work=new Workspace($db,$access,'social');$item=$work->save(['title'=>'Earlier protected social original','body'=>'Earlier exact human text']);
    $images=(new Images($db,$access,$root.'/media'))->render($item['id']);$approved=$work->approve($item['id'],$item['version']);
    $earlier=$store->save($item['id'],$approved['version'],$identity['version'],$fixture,'Earlier exact original sound.mp4');$earlierFile=$store->file($earlier);

    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$keys=$counts=[];
    foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$values=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');$counts[$table]=count($values);}
    $filter=static function(string $table)use($keys):array{$e=$keys[$table];if(!$e['values'])return [' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
    $before=ownOriginalDatabaseHash($db,$tables,$filter);$files=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$files[$f->getPathname()]=hash_file('sha256',$f->getPathname());
    $directory=dirname($earlierFile['path']);$originalFiles=glob($directory.'/*.mp4');
    $new=[];
    for($i=0;$i<7;$i++)$new[]=$store->save($item['id'],$approved['version'],$identity['version'],$i%2?$silent:$fixture,'Own new exact version '.$i.'.mp4');
    $first=$store->page($item['id']);$second=$store->page($item['id'],'2');
    $check($first['total']===8&&$first['pages']===2&&count($first['rows'])===6&&count($second['rows'])===2,'full private video history uses an exact count and six metadata rows per page');
    $check(count(array_unique(array_column([...$first['rows'],...$second['rows']],'id')))===8,'every earlier and new exact video identity is reachable once without replacement');
    foreach([0,'0','01','-1',[],false,1.5,'1000001']as$p)$denied(fn()=>$store->page($item['id'],$p),'invalid history page never becomes an implicit default');
    $fresh=$store->file($new[0]);$check($fresh['sha256']===hash_file('sha256',$fixture)&&$fresh['bytes']===60207&&$fresh['profile']['audio']['codec']==='aac'&&$fresh['profile']['audio']['sample_rate']===48000&&$fresh['profile']['audio']['channels']===2,'stored new private source preserves exact real AAC bytes and parsed facts');
    $check($work->get($item['id'])===$approved&&$approved['approved_image_ids']===$images,'new private MP4 does not edit the old social text, approval version or approved images');
    $check($pages->publicPage($page['slug'])===$publication,'all video storage leaves the old exact public publication intact');
    $count=(int)$db->query('SELECT COUNT(*) FROM native_social_videos')->fetchColumn();$disk=glob($directory.'/*.mp4');
    $denied(fn()=>$store->save($item['id'],1,$identity['version'],$fixture),'stale content version cannot promote itself to the current approved version');
    $denied(fn()=>$store->save($item['id'],$approved['version'],0,$fixture),'stale identity version cannot save or change earlier sources');
    $badFile=$root.'/malformed.mp4';file_put_contents($badFile,'Wrong non-video original bytes here');
    $denied(fn()=>$store->save($item['id'],$approved['version'],$identity['version'],$badFile),'actual invalid uploaded bytes roll back only the new own file');unlink($badFile);
    $db->beginTransaction();try{$denied(fn()=>$store->save($item['id'],$approved['version'],$identity['version'],$fixture),'inherited transaction is rejected before staging a new file');}finally{$db->rollBack();}
    $check((int)$db->query('SELECT COUNT(*) FROM native_social_videos')->fetchColumn()===$count&&glob($directory.'/*.mp4')===$disk,'all rejected saves retain exact earlier rows and file lists without debris');
    $other=new Videos($db,new Access($db,'outsider'),$root.'/media');$denied(fn()=>$other->file($earlier),'foreign actor cannot read an earlier private video');
    Db::run($db,'INSERT INTO native_users(id,club_id,name,active) VALUES(?,?,?,1)',['own-video-late',$access->club(),'Own current video actor']);
    foreach(['read','edit']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['own-video-late','social',$cap]);
    $cfg=TestSupport::config();$writer=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $late=new class($db,'own-video-late',$writer,$directory,glob($directory.'/*.mp4')) extends Access {
        private bool $changed=false;
        public function __construct(PDO $db,string $actor,private PDO $writer,private string $directory,private array $known){parent::__construct($db,$actor);}
        public function require(string $module,string $capability):array {
            if(!$this->changed&&array_diff(glob($this->directory.'/*.mp4'),$this->known)){$this->changed=true;Db::run($this->writer,'UPDATE native_users SET active=0 WHERE id=?',['own-video-late']);}
            return parent::require($module,$capability);
        }
    };
    $denied(fn()=>(new Videos($db,$late,$root.'/media'))->save($item['id'],$approved['version'],$identity['version'],$fixture),'real independent actor revocation after byte staging blocks the database commit');
    $check((int)Db::run($db,'SELECT active FROM native_users WHERE id=?',['own-video-late'])->fetchColumn()===0,'independent database writer actually revoked the new own actor after the copied file appeared');
    $check(glob($directory.'/*.mp4')===$disk&&(int)$db->query('SELECT COUNT(*) FROM native_social_videos')->fetchColumn()===$count,'revoked staged upload cleans only its own new file and creates no source row');
    if(getenv('DVM_REEL_STUDIO_CONTRACTS')==='1'){
        $html=\Kulturhus\Native\Web\VideoEditor::render($approved,$identity,'own-current-csrf');
        $document=new DOMDocument();@$document->loadHTML($html);$xpath=new DOMXPath($document);
        $form=$xpath->query('//form[@data-native-video-form]')->item(0);
        $check($form!==null&&$form->getAttribute('method')==='post'&&$form->getAttribute('enctype')==='multipart/form-data','direct studio save uses the native original multipart boundary');
        foreach(['id'=>$approved['id'],'version'=>(string)$approved['version'],'identity_version'=>(string)$identity['version'],'csrf'=>'own-current-csrf']as$name=>$value)$check($xpath->query('//form[@data-native-video-form]/input[@name="'.$name.'"]')->item(0)?->getAttribute('value')===$value,'direct studio save retains actual original '.$name);
        $check($xpath->query('//details[@data-video-trim or @data-video-look or @data-video-caption]')->length===3&&$xpath->query('//details[@open]')->length===0,'complete tools and entire studio start collapsed without hiding or losing values');
        $check($xpath->query('//button[@data-video-save and @disabled and @type="button"]')->length===1&&$xpath->query('//input[@data-native-video-file and @required and @type="file"]')->length===1,'unsupported studio encoder stays disabled while the independent manual form requires a real MP4');
    }
    if($browser||$studio||$audioBrowser){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
        mkdir($root.'/web',0700,true);putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);
        $server=proc_open([...TestSupport::phpCommand(),'-d','session.cookie_secure=0','-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/native/Web/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));
        if(!is_resource($server))throw new RuntimeException('Own video HTTP server required.');fclose($pipes[0]);
        for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $largeUpload=$root.'/Own actual large source.mp4';$padding=12*1024*1024;file_put_contents($largeUpload,file_get_contents($fixture).pack('N',$padding+8).'free'.str_repeat("\0",$padding));$check(filesize($largeUpload)>8*1024*1024&&ReelFile::inspect($largeUpload)['audio']['sample_rate']===48000,'new own large upload retains real AVC/AAC and exceeds both earlier PHP limits');
        $browserFixture=$root.'/browser.json';file_put_contents($browserFixture,json_encode(['origin'=>'http://127.0.0.1:'.$port,'item'=>$item['id'],'version'=>$approved['version'],'identity_version'=>$identity['version'],'video'=>$earlier,'file'=>$fixture,'upload_file'=>$largeUpload,'studio_file'=>$silent,'surround_file'=>__DIR__.'/fixtures/reel-profile/portrait-avc-center-5.1.mp4','unsupported_audio_file'=>__DIR__.'/fixtures/reel-profile/portrait-avc-center-7.1.mp4'],JSON_THROW_ON_ERROR));chmod($browserFixture,0600);putenv('DVM_VIDEO_FIXTURE='.$browserFixture);
        $process=proc_open(['node',__DIR__.($audioBrowser?'/reel-studio-audio-browser.mjs':($studio?'/reel-studio-save-browser.mjs':'/videos-browser.mjs'))],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual video browser required.');
        $largeResult=$store->page($item['id'])['rows'][0];if(!$studio&&!$audioBrowser)$check((int)$largeResult['bytes']===filesize($largeUpload)&&$largeResult['sha256']===hash_file('sha256',$largeUpload),'actual multipart PHP upload stores the complete source beyond the old 2/8 MiB limits');
        $check($store->page($item['id'])['total']===9&&$work->get($item['id'])===$approved,'actual native upload saves exactly one extra private video and retains the exact earlier approval');
    }
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'every complete earlier row and primary key including private proof, source, approval, publication and video remains exact');
    foreach($counts as$table=>$amount){$delta=in_array($table,['native_social_video_sources','native_social_videos'],true)?7+(($browser||$studio||$audioBrowser)?1:0):($table==='native_communication_save_requests'?(($browser||$studio||$audioBrowser)?1:0):($table==='native_users'?1:($table==='native_grants'?2:0)));$check((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$amount+$delta,'exact original total plus only the known new own rows: '.$table);}
    $check($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$tables,'all storage and rejection paths perform no schema changes');
    foreach($files as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'every earlier original file, publication raster, private proof key and video byte remains exact');
    $check($store->file($earlier)===$earlierFile,'earlier exact video descriptor is never regenerated or silently rebound');
    echo json_encode(['suite'=>'native-videos','checks'=>$n,'new_videos'=>7+(($browser||$studio||$audioBrowser)?1:0),'browser'=>$browser||$studio||$audioBrowser,'studio'=>$studio,'audio'=>$audioBrowser,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
}
