<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Carousel,Content,Schema,Workspace,Images,ImageHistory,PublicationSnapshot,Meta};
use Kulturhus\Native\Cms\{Media,MediaUsage,MediaReferences,Pages};
use Kulturhus\Native\Integration\Accounts;

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700);
$admin=new Access($db,'admin');$mediaRoot=$root.'/media';$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label.PHP_EOL;};
$deny=static function(callable $f,string $label)use($check):void{try{$f();}catch(Exception){$check(true,$label);return;}throw new RuntimeException($label);};
try{
    $im=imagecreatetruecolor(36,24);imagefill($im,0,0,imagecolorallocate($im,40,90,130));imagepng($im,$root.'/own.png');unset($im);
    file_put_contents($root.'/private.key',random_bytes(32));chmod($root.'/private.key',0600);$box=SecretBox::fromFile($root.'/private.key');
    $photo=(new Media($db,$admin,$mediaRoot))->upload($root.'/own.png','Earlier original image.png','Earlier original');
    (new Identity($db,$admin))->save(['name'=>'Earlier exact club identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    $oldWork=new Workspace($db,$admin,'social',$mediaRoot);$old=$oldWork->save(['title'=>'Earlier exact post','body'=>'Earlier human original']);
    $oldImages=new Images($db,$admin,$mediaRoot);$oldIds=$oldImages->render($old['id']);$old=$oldWork->approve($old['id'],1);
    $oldFingerprint=Images::fingerprint($old);$oldPublic=(new Pages($db,$admin))->save(['title'=>'Earlier original website','slug'=>'earlier-carousel-protected','media_ids'=>[$photo['id']]]);$oldPublic=(new Pages($db,$admin))->publish($oldPublic['id'],1);
    $keys=[];$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$values=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');}
    $filter=static function(string $table)use($keys):array{$k=$keys[$table];if(!$k['values'])return[' WHERE 0=1',[]];$args=[];foreach($k['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$k['columns']).'`) IN ('.implode(',',array_fill(0,count($k['values']),'('.implode(',',array_fill(0,count($k['columns']),'?')).')')).')',$args];};
    $before=ownOriginalDatabaseHash($db,$tables,$filter);$files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$files[$f->getPathname()]=hash_file('sha256',$f->getPathname());

    $check(Carousel::normalize([])===[],'empty private carousel draft does not invent cards or stock answers');
    foreach([null,false,'[]',['named'=>[]],array_fill(0,11,['title'=>'Own card']),[['title'=>'Own card','url'=>'https://outside.invalid']], [['title'=>'']], [['title'=>'Own card','media_ref'=>[]]]]as$bad)$deny(fn()=>Carousel::normalize($bad),'malformed or unbounded card input is rejected before any write');
    $one=['title'=>'Own first ä🙂','text'=>'Own human first card','media_ref'=>$photo['id']];$two=['title'=>'Own second <card>','text'=>'Own human second card','media_ref'=>''];
    $deny(fn()=>Content::document('social',['title'=>'Wrong format','kind'=>'post','carousel'=>[$one,$two]]),'a normal post cannot silently acquire a carousel collection');
    $deny(fn()=>Content::document('newsletter',['title'=>'Wrong module','kind'=>'carousel','carousel'=>[$one,$two]]),'newsletter cannot silently adopt a social carousel kind');
    $check(Images::fingerprint($old+['carousel'=>[$one,$two]])===$oldFingerprint,'earlier post fingerprint remains byte-identical without an invented carousel field');
    $check(!array_key_exists('carousel',Content::document('social',['title'=>'Own normal post','body'=>'Own human text'])),'ordinary original post payload gains no automatic carousel key');

    $actor='own-carousel-author';$club='own-carousel-club';Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$actor,$club,'Own carousel author']);
    foreach(['website','social']as$module)foreach(['read','edit','publish','manage']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',[$actor,$module,$cap]);
    $access=new Access($db,$actor);$identity=new Identity($db,$access);$identity->save(['name'=>'Own original carousel identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
    $ownPhoto=(new Media($db,$access,$mediaRoot))->upload($root.'/own.png','Own card source.png','Own exact source');$one['media_ref']=$ownPhoto['id'];
    $work=new Workspace($db,$access,'social',$mediaRoot);$store=new Images($db,$access,$mediaRoot);$history=new ImageHistory($db,$access);
    $empty=$work->save(['title'=>'Own empty private draft','kind'=>'carousel']);$check($empty['carousel']===[]&&$empty['template']==='social-carousel'&&$empty['status']==='draft','empty private draft keeps its own distinct carousel template and no fabricated images');
    $deny(fn()=>$store->render($empty['id']),'empty carousel cannot be rendered as an ordinary single-image post');
    $deny(fn()=>$work->approve($empty['id'],1),'empty carousel cannot obtain publication approval');
    $deny(fn()=>$work->save(['id'=>$empty['id'],'carousel'=>[$one],'status'=>'review'],1),'one-card carousel cannot enter review');
    $check($work->get($empty['id'])===$empty,'rejected review leaves the entire saved original draft unchanged');
    $item=$work->save(['title'=>'Own two-image carousel','body'=>'','kind'=>'carousel','carousel'=>[$one,$two]]);
    $deny(fn()=>$work->approve($item['id'],1),'card text alone cannot approve missing exact private images');
    $ids=$store->render($item['id']);$check(count($ids)===2&&$ids[0]!==$ids[1],'actual native rendering creates two distinct ordered private image identities');
    foreach($ids as$slide=>$id){$row=Db::run($db,'SELECT * FROM native_social_images WHERE id=?',[$id])->fetch();$meta=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);$check((int)$row['slide']===$slide&&$row['kind']==='carousel'&&$row['content_hash']===Images::fingerprint($item),'each actual image retains exact original card index, kind and content binding');$check(getimagesize($store->file($id)['path'])[1]===1350&&getimagesize($store->jpeg($id)['path'])['mime']==='image/jpeg','each actual card has bounded 4:5 PNG and JPEG originals');}
    $check($history->page($item['id'])['total']===2&&count($history->newest($item['id'],1,Images::fingerprint($item),'carousel',2))===2,'bounded history and latest-card selection reach both original images');
    $uses=(new MediaUsage($db,$access))->listing($ownPhoto['id'],['media_usage_source'=>'social']);$check($uses['total']>=2&&count(array_filter($uses['rows'],static fn($r)=>$r['source']==='image'&&str_contains($r['title'],'Karussell · Bild 1')))===1,'global compact usage sees both the original carousel draft and its actual image source');
    $check((new MediaReferences($db,$access))->inspect($ownPhoto['id'])['referenced'],'global dependency check protects a source used only by a carousel');
    $preview=$work->preview($item['id']);$check(substr_count($preview,'communication-carousel')===2&&str_contains($preview,'Own second &lt;card&gt;'),'private preview retains ordered human titles and escapes original text');
    $jpeg=$store->jpeg($ids[1]);rename($jpeg['path'],$root.'/held-jpeg');try{$deny(fn()=>$work->approve($item['id'],1),'a missing exact second JPEG prevents approval of the complete carousel');}finally{rename($root.'/held-jpeg',$jpeg['path']);}$check($work->get($item['id'])===$item,'missing file rejection leaves the entire original private draft unchanged');
    Db::run($db,"DELETE FROM native_grants WHERE actor_id=? AND module='social' AND capability='publish'",[$actor]);try{$deny(fn()=>$work->approve($item['id'],1),'current publication right is checked after the private images already exist');}finally{Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',[$actor,'social','publish']);}
    $approved=$work->approve($item['id'],1);$check($approved['approved_image_ids']===$ids&&$approved['version']===2,'approval captures exactly all ordered original images in one own revision');
    $publication=PublicationSnapshot::social($db,$access,$item['id'],$mediaRoot);$check(array_column($publication['images'],'id')===$ids,'publication snapshot captures the whole approved carousel rather than its first card');
    $jobsBefore=(int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn();$capsBefore=(int)$db->query('SELECT COUNT(*) FROM native_image_capabilities')->fetchColumn();$calls=0;
    (new Accounts($db,$box))->save($access,'meta',['page_id'=>'123','instagram_id'=>'456','token'=>'synthetic-carousel-never-live','api_version'=>'v24.0'],0);
    $transport=new Meta($db,$box,static function()use(&$calls){++$calls;return ['status'=>200,'body'=>['id'=>'900001']];});
    $db->exec('DROP TABLE native_carousel_capabilities');$db->exec('DROP TABLE native_carousel_progress');
    $deny(fn()=>$transport->queue($access,$item['id'],gmdate('Y-m-d H:i:s'),$mediaRoot),'older installation without carousel capability cannot create a provider job or perform web DDL');
    $deny(fn()=>$transport->send($club,['channel'=>'facebook','kind'=>'carousel','image_id'=>$ids[0],'image_hash'=>$store->jpeg($ids[0])['sha256'],'image_url'=>'https://example.invalid/publication-image/'.str_repeat('a',64),'caption'=>'Own whole carousel','page_id'=>'123','instagram_id'=>'456']),'direct otherwise valid transport cannot misrepresent an unfinished carousel as a single public photo');
    $check($calls===0&&(int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===$jobsBefore&&(int)$db->query('SELECT COUNT(*) FROM native_image_capabilities')->fetchColumn()===$capsBefore,'blocked provider path creates no job, token or external request');
    Kulturhus\Native\Communication\CarouselProgress::install($db);

    $im=imagecreatetruecolor(1080,1350);imagefill($im,0,0,imagecolorallocate($im,50,80,140));imagepng($im,$root.'/canvas.png');unset($im);$doc=['schema'=>1,'width'=>1080,'height'=>1350,'background'=>'#32508c','layers'=>[]];
    $canvas=$work->save(['title'=>'Own editable carousel','kind'=>'carousel','carousel'=>[$one,$two]]);$first=$store->saveCanvas($canvas['id'],1,1,0,$doc,$root.'/canvas.png');
    $deny(fn()=>$work->approve($canvas['id'],1),'one editable canvas cannot approve a two-card original');
    $second=$store->saveCanvas($canvas['id'],1,1,1,$doc,$root.'/canvas.png');$check(count($history->documents($canvas['id'],'carousel'))===2,'editable-card history preserves both independently saved original documents');
    $canvasApproved=$work->approve($canvas['id'],1);$check($canvasApproved['approved_image_ids']===[$first,$second],'independent edited canvases are approved in their exact original order');
    $deny(fn()=>$store->saveCanvas($canvas['id'],2,1,2,$doc,$root.'/canvas.png'),'a third canvas cannot masquerade as an existing carousel card');
    $changed=$work->save(['id'=>$canvas['id'],'carousel'=>[$two,$one]],2);$check($changed['status']==='draft'&&$changed['approved_by']===null&&Images::fingerprint($changed)!==Images::fingerprint($canvasApproved),'card reorder is a new private revision that invalidates old approval and exact image binding');
    $deny(fn()=>$work->approve($canvas['id'],3),'images of the earlier order cannot approve the new card order');
    $check(is_file($store->file($first)['path'])&&is_file($store->jpeg($second)['path']),'card reorder preserves every earlier editable PNG and JPEG');

    $ten=$work->save(['title'=>'Own ten-card bound','kind'=>'carousel','carousel'=>array_fill(0,10,$two)]);$tenIds=$store->render($ten['id']);$ten=$work->approve($ten['id'],1);$check(count($tenIds)===10&&$ten['approved_image_ids']===$tenIds,'actual ten-card upper limit renders and approves all exact images without truncation');
    $check($history->page($ten['id'])['total']===10&&count($history->page($ten['id'])['rows'])===6&&count($history->page($ten['id'],2)['rows'])===4,'ten real card originals remain reachable in two bounded history pages');
    $check(array_column(PublicationSnapshot::social($db,$access,$ten['id'],$mediaRoot)['images'],'id')===$tenIds,'whole upper-bound snapshot preserves all ten exact original identities and order');
    $deny(fn()=>$work->save(['id'=>$ten['id'],'carousel'=>array_fill(0,11,$two)],2),'eleventh card cannot create a partial private revision');
    $check($work->get($ten['id'])===$ten,'rejected upper-bound edit preserves the complete approved original');
    $brand=$work->save(['title'=>'Own current brand check','kind'=>'carousel','carousel'=>[$two,$two]]);$store->render($brand['id']);
    $identity->save(['name'=>'Own changed identity','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],1);
    $deny(fn()=>$work->approve($brand['id'],1),'earlier brand images cannot obtain a fresh carousel approval');
    $deny(fn()=>PublicationSnapshot::social($db,$access,$item['id'],$mediaRoot),'changed club identity invalidates an earlier carousel publication snapshot');
    $store->render($brand['id']);$brand=$work->approve($brand['id'],1);$check(count($brand['approved_image_ids'])===2,'explicit current-brand rendering can receive its own exact new approval');
    $deny(fn()=>(new Workspace($db,new Access($db,'outsider'),'social',$mediaRoot))->get($item['id']),'foreign club cannot read a private carousel');
    $check($oldWork->get($old['id'])===$old&&Images::fingerprint($old)===$oldFingerprint&&(new Pages($db,$admin))->get($oldPublic['id'])===$oldPublic,'earlier post approval, fingerprint and website original remain exact');
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'all earlier complete rows, keys, authorities, approvals, settings and queues remain byte-identical');
    foreach($files as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'every earlier private source, public original, image variant and key remains byte-identical');
    echo json_encode(['suite'=>'native-carousels','checks'=>$n,'max_cards'=>10,'provider_calls'=>$calls,'schema_changes'=>0,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}}
