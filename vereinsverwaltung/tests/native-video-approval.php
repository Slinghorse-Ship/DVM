<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Schema,Videos,Workspace,Images,VideoApproval,PublicationSnapshot,Meta};
use Kulturhus\Native\Cms\{Media,MediaMetadata,Pages,Conflict};

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
mkdir($root,0700,true);file_put_contents($root.'/private.key',random_bytes(32));chmod($root.'/private.key',0600);
$admin=new Access($db,'admin');$n=0;$server=null;$browser=getenv('DVM_VIDEO_APPROVAL_BROWSER')==='1';
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;};
$denied=static function(callable $fn,string $label)use($check):void{try{$fn();}catch(DomainException|Conflict){$check(true,$label);return;}throw new RuntimeException($label);};
$fixture=__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4';$silent=__DIR__.'/fixtures/reel-profile/portrait-avc-silent.mp4';
try {
    $image=imagecreatetruecolor(32,32);imagepng($image,$root.'/original.png');unset($image);
    $photo=(new Media($db,$admin,$root.'/media'))->upload($root.'/original.png','Earlier untouched original.png','Earlier original');
    (new MediaMetadata($db,$admin,SecretBox::fromFile($root.'/private.key')))->save($photo['id'],['alt'=>'Earlier exact source','rights_state'=>'cleared','consent_state'=>'recorded'],0,'Earlier untouched encrypted proof');
    $pages=new Pages($db,$admin);$page=$pages->save(['title'=>'Earlier original publication','slug'=>'earlier-reel-protected','media_ids'=>[$photo['id']]]);$pages->publish($page['id'],1);
    $identity=(new Identity($db,$admin))->save(['name'=>'Earlier original identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    $originalWork=new Workspace($db,$admin,'social',$root.'/media');$old=$originalWork->save(['title'=>'Earlier exact post','body'=>'Earlier human text']);
    (new Images($db,$admin,$root.'/media'))->render($old['id']);$old=$originalWork->approve($old['id'],1);
    $originalStore=new Videos($db,$admin,$root.'/media');$oldVideo=$originalStore->save($old['id'],$old['version'],$identity['version'],$fixture,'Earlier original sound.mp4');$oldFile=$originalStore->file($oldVideo);
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$keys=$counts=[];
    foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$values=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');$counts[$table]=count($values);}
    $filter=static function(string $table)use($keys):array{$e=$keys[$table];if(!$e['values'])return [' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
    $before=ownOriginalDatabaseHash($db,$tables,$filter);$files=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile())$files[$file->getPathname()]=hash_file('sha256',$file->getPathname());

    Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-reel-author','own-reel-club','Own new reel author']);
    foreach(['read','edit','publish']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['own-reel-author','social',$cap]);
    foreach(['read','manage']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['own-reel-author','website',$cap]);
    $access=new Access($db,'own-reel-author');$work=new Workspace($db,$access,'social',$root.'/media');$store=new Videos($db,$access,$root.'/media');
    $ownIdentity=(new Identity($db,$access))->save(['name'=>'Own reel identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    $item=$work->save(['title'=>'Own exact reel','kind'=>'reel','body'=>'']);
    $check($item['template']==='social-reel'&&$item['kind']==='reel'&&$item['body']==='','new explicit reel template permits an original video without fabricated body text');
    $a=$store->save($item['id'],1,1,$fixture,'Own selected sound.mp4');$b=$store->save($item['id'],1,1,$silent,'Own later silent alternative.mp4');
    $state=$work->get($item['id']);$revisionCount=(int)$db->query('SELECT COUNT(*) FROM khn_social_revisions')->fetchColumn();
    $denied(fn()=>$work->approve($item['id'],1),'reel approval requires an explicitly selected exact video');
    $denied(fn()=>$work->approve($item['id'],1,$oldVideo),'foreign earlier video cannot be approved for a new own reel');
    $other=$work->save(['title'=>'Own separate reel','kind'=>'reel']);$otherVideo=$store->save($other['id'],1,1,$fixture,'Own other item.mp4');
    $denied(fn()=>$work->approve($item['id'],1,$otherVideo),'video belonging to another own item is rejected');
    $check($work->get($item['id'])===$state&&(int)$db->query('SELECT COUNT(*) FROM khn_social_revisions')->fetchColumn()===$revisionCount+1,'rejected approvals add no revision or state change');
    $denied(fn()=>(new Images($db,$access,$root.'/media'))->render($item['id']),'reel cannot accidentally become a still-image approval');
    $approved=$work->approve($item['id'],1,$a);$proof=$approved['approved_video'];
    $check($approved['version']===2&&$proof['id']===$a&&$proof['id']!==$b&&$approved['approved_image_ids']===[],'approval binds the chosen sound version rather than silently selecting the newest video');
    $check($proof['sha256']===hash_file('sha256',$fixture)&&$proof['bytes']===filesize($fixture)&&$proof['item_version']===1&&$proof['identity_version']===1&&$proof['profile']['audio']['sample_rate']===48000,'approval records exact original byte, content, identity and AAC facts');
    $late=$store->save($item['id'],2,1,$silent,'Own upload after approval.mp4');
    $check($work->get($item['id'])['approved_video']===$proof&&$late!==$proof['id'],'later upload cannot rebind the current approved original');
    $selectedRow=Db::run($db,'SELECT source_id FROM native_social_videos WHERE id=?',[$a])->fetch();$sourceProfile=Db::run($db,'SELECT profile_json FROM native_social_video_sources WHERE id=?',[$selectedRow['source_id']])->fetchColumn();
    Db::run($db,'UPDATE native_social_video_sources SET profile_json=? WHERE id=?',[json_encode(['mime'=>'video/mp4','codec'=>'h264','width'=>1080,'height'=>1920,'duration_ms'=>3000,'frames'=>90,'fps'=>30,'audio'=>null,'bytes'=>60207]),$selectedRow['source_id']]);
    try{$denied(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$root.'/media'),'forged stored silent profile cannot replace the actual original AAC track');}finally{Db::run($db,'UPDATE native_social_video_sources SET profile_json=? WHERE id=?',[$sourceProfile,$selectedRow['source_id']]);}
    $originalPayload=Db::run($db,'SELECT payload FROM khn_social_items WHERE club_id=? AND id=?',[$access->club(),$item['id']])->fetchColumn();
    foreach(['id'=>$late,'source_id'=>str_repeat('f',32),'sha256'=>str_repeat('0',64),'bytes'=>60208,'item_version'=>2,'identity_version'=>2]as$field=>$value){
        $forged=json_decode($originalPayload,true);$forged['approved_video'][$field]=$value;Db::run($db,'UPDATE khn_social_items SET payload=? WHERE club_id=? AND id=?',[json_encode($forged,JSON_THROW_ON_ERROR),$access->club(),$item['id']]);
        try{$denied(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$root.'/media'),'changed original approval evidence is rejected: '.$field);}finally{Db::run($db,'UPDATE khn_social_items SET payload=? WHERE club_id=? AND id=?',[$originalPayload,$access->club(),$item['id']]);}
    }
    $check($work->get($item['id'])===$approved,'all newly owned tamper cases restore exact selected approval bytes and version');
    $snapshot=PublicationSnapshot::social($db,$access,$item['id'],$root.'/media');
    $check($snapshot['video']==$proof&&$snapshot['images']===[],'common publication snapshot returns precisely the approved original video');
    $denied(fn()=>(new Workspace($db,$access,'social'))->schedule($item['id'],'2020-01-01T00:00:00Z',2),'missing private storage context cannot silently schedule a reel');
    $scheduled=$work->schedule($item['id'],'2020-01-01T00:00:00Z',2);
    $job=Db::run($db,'SELECT * FROM khn_social_jobs WHERE club_id=? AND item_id=?',[$access->club(),$item['id']])->fetch();
    $check($scheduled['version']===3&&json_decode($job['payload'],true)['snapshot']['approved_video']===$proof,'local preparation job retains exact approved video evidence');
    $ownFile=$store->file($a);$bytes=file_get_contents($ownFile['path']);file_put_contents($ownFile['path'],substr_replace($bytes,chr(ord($bytes[100])^1),100,1));
    try{$denied(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$root.'/media'),'changed actual own video bytes cannot pass the publication guard');}finally{file_put_contents($ownFile['path'],$bytes);}
    $check(hash_file('sha256',$ownFile['path'])===$proof['sha256'],'only newly created corruption fixture is restored to its exact original bytes');
    (new Identity($db,$access))->save(['name'=>'Own changed identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],1);
    $denied(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$root.'/media'),'changed current identity invalidates the earlier video approval');
    $result=$work->runDue('2020-01-02T00:00:00Z');
    $check($result['prepared']===0&&Db::run($db,'SELECT status FROM khn_social_jobs WHERE id=?',[$job['id']])->fetchColumn()==='cancelled','due local preparation cancels stale identity instead of rebinding or sending');
    $edited=$work->save(['id'=>$item['id'],'kind'=>'reel','title'=>'Own revised reel','body'=>''],3);
    $check($edited['version']===4&&!isset($edited['approved_video'])&&$edited['approved_by']===null,'content edit clears the old approval and retains earlier video artifacts');
    $denied(fn()=>$work->approve($item['id'],4,$a),'old content and identity cannot be approved as the current reel');
    $fresh=$store->save($item['id'],4,2,$fixture,'Own current revised video.mp4');$current=$work->approve($item['id'],4,$fresh);
    $check($current['approved_video']['item_version']===4&&$current['approved_video']['identity_version']===2,'new explicit approval binds the genuinely current content and identity');
    $transport=0;$meta=new Meta($db,SecretBox::fromFile($root.'/private.key'),static function()use(&$transport){$transport++;throw new RuntimeException('No live call allowed.');});
    $delivery=(int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn();$capabilities=(int)$db->query('SELECT COUNT(*) FROM native_image_capabilities')->fetchColumn();
    $denied(fn()=>$meta->queue($access,$item['id'],'2020-01-01T00:00:00Z',$root.'/media'),'unconnected Meta account cannot create a video publication or fall through to the photo publisher');
    $check($transport===0&&(int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===$delivery&&(int)$db->query('SELECT COUNT(*) FROM native_image_capabilities')->fetchColumn()===$capabilities,'private approval triggers no transport, delivery row or image capability');
    Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-reel-colleague','own-reel-club','Own current independent publisher']);foreach(['read','publish']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['own-reel-colleague','social',$cap]);$colleague=new Access($db,'own-reel-colleague');
    $check(PublicationSnapshot::social($db,$colleague,$item['id'],$root.'/media')['video']['id']===$fresh,'another current own publisher can inspect the exact selected approved original');
    Db::run($db,'DELETE FROM native_grants WHERE actor_id=? AND module=? AND capability=?',['own-reel-author','social','publish']);
    $denied(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$root.'/media'),'actual current publish revocation blocks subsequent use of an approved video');
    $denied(fn()=>PublicationSnapshot::social($db,$colleague,$item['id'],$root.'/media'),'independent current publisher cannot reuse a revoked approving actor');
    $check($work->get($item['id'])===$current,'current rights rejection preserves original approved payload and version');

    if($browser){
        $browserItem=$originalWork->save(['title'=>'Own browser reel','kind'=>'reel','body'=>'']);
        $browserA=$originalStore->save($browserItem['id'],1,$identity['version'],$fixture,'Own browser selected sound.mp4');
        $browserB=$originalStore->save($browserItem['id'],1,$identity['version'],$silent,'Own browser later silent.mp4');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
        mkdir($root.'/web',0700,true);putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);
        $server=proc_open([...TestSupport::phpCommand(),'-d','session.cookie_secure=0','-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/native/Web/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));
        if(!is_resource($server))throw new RuntimeException('Own HTTP server required.');fclose($pipes[0]);
        for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browserFixture=$root.'/browser.json';file_put_contents($browserFixture,json_encode(['origin'=>'http://127.0.0.1:'.$port,'item'=>$browserItem['id'],'selected'=>$browserA,'later'=>$browserB],JSON_THROW_ON_ERROR));chmod($browserFixture,0600);putenv('DVM_VIDEO_APPROVAL_FIXTURE='.$browserFixture);
        $process=proc_open(['node',__DIR__.'/video-approval-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual video approval browser required.');
        $after=$originalWork->get($browserItem['id']);$check($after['status']==='approved'&&$after['version']===2&&$after['approved_video']['id']===$browserA,'actual native form approves exactly the consciously selected original sound version');
    }
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'every complete earlier original row and primary key remains exact');
    $deltas=['native_users'=>2,'native_grants'=>6,'native_club_settings'=>1,'khcms_state'=>1,'khn_social_items'=>2+($browser?1:0),'khn_social_revisions'=>6+($browser?2:0),'khn_social_jobs'=>1,'native_social_video_sources'=>5+($browser?2:0),'native_social_videos'=>5+($browser?2:0)];
    foreach($counts as$table=>$count)$check((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$count+($deltas[$table]??0),'exact earlier total plus only known new own rows: '.$table);
    foreach($files as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'every earlier original image, video, encrypted proof and key byte remains exact');
    $check($originalStore->file($oldVideo)===$oldFile&&$originalWork->get($old['id'])===$old,'earlier approved post and video descriptor remain unchanged');
    $check($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$tables,'reel drafts, approval and every guard perform no DDL');
    echo json_encode(['suite'=>'native-video-approval','checks'=>$n,'browser'=>$browser,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file)$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);}
}
