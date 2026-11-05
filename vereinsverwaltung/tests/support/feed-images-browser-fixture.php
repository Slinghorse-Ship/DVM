<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Media,Pages};
use Kulturhus\Native\Integration\ManagerBridge;
$k=ownKBrowserFixture();$config=TestSupport::config();$standalone=$k['standalone'];

$db=TestSupport::db();$file=$k['directory'].'/feed-images-'.($standalone?'standalone':'integrated').'.json';
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','khcms_news_policy','khcms_news_policy_history','khcms_news_approvals','native_users','native_grants','native_delivery_jobs'];
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if($f['database']!==$config['database']||$f['originals']!==ownOriginalDatabaseHash($db,$tables))throw new RuntimeException('Anonymous browser changed an exact original, grant, approval or job.');
    foreach($f['files']as$path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Anonymous browser changed an original image.');
    echo "Every exact feed-image original, private draft, grant, approval, job and image byte retained.\n";exit;
}
$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=$k['runtime'];
$media=new Media($db,$access,$runtime.'/media');$pages=new Pages($db,$access);$before=ownOriginalDatabaseHash($db,$tables);$images=[];$files=[];$descriptors=[];$suffix=bin2hex(random_bytes(6));
for($i=0;$i<4;$i++){
    $pixels=imagecreatetruecolor(96+$i,48+$i);imagefill($pixels,0,0,imagecolorallocate($pixels,($i*61)%256,($i*113)%256,($i*37)%256));
    $path=$runtime.'/own-feed-'.$suffix.'-'.$i.'.png';imagepng($pixels,$path);imagedestroy($pixels);
    $image=$media->upload($path,'own-feed-'.$suffix.'-'.$i.'.png','Veröffentlichtes Originalfoto '.$i.' ä🙂 & <Kunst>');unlink($path);$images[]=$image['id'];
    foreach(['original','large','medium','thumb']as$size){$v=$media->variant($image['id'],$size);$files[$v['path']]=hash_file('sha256',$v['path']);}
    $v=$media->variant($image['id'],'large');$descriptors[]=['url'=>'/media/'.$image['id'].'/large','mime'=>'image/png','width'=>96+$i,'height'=>48+$i,'bytes'=>filesize($v['path']),'alt'=>$image['alt'],'sha256'=>$v['sha256']];
}
$p=$pages->save(['template'=>'event','slug'=>'veranstaltungen/feed-original-'.$suffix,'title'=>'Eigener Originaltermin '.$suffix.' ä🙂 & <Kunst>','body'=>'Ursprünglicher öffentlicher Termintext','event_start'=>'2035-01-12T19:00','event_timezone'=>'America/New_York','event_end'=>'2035-01-13T03:15:00Z','event_location'=>'Eigener Saal','event_address'=>'Eigene Straße 1','event_price'=>'12,50','event_status'=>'sold_out','event_ticket_url'=>'https://example.invalid/own-ticket','media_ids'=>array_slice($images,0,3)]);$p=$pages->publish($p['id'],$p['version']);$publishedVersion=$p['published_version'];$title=$p['title'];
$p=$pages->save(array_replace($p,['title'=>'PRIVATE-FEED-DRAFT-'.$suffix,'body'=>'PRIVATE-FEED-TEXT-'.$suffix,'media_ids'=>[$images[3]]]),$p['version']);
$outside=ownOriginalDatabaseHash($db,$tables,static fn($table)=>match($table){'khcms_pages'=>[' WHERE id<>?',[$p['id']]],'khcms_revisions','khcms_published_media','khcms_news_approvals'=>[' WHERE page_id<>?',[$p['id']]],'khcms_media'=>[' WHERE id NOT IN (?,?,?,?)',$images],default=>['',[]]});
if($before!==$outside)throw new RuntimeException('Preparing owned fixture changed an outside original.');
file_put_contents($file,json_encode(['database'=>$config['database'],'id'=>$p['id'],'slug'=>$p['slug'],'title'=>$title,'published_version'=>$publishedVersion,'draft_title'=>$p['title'],'private_image'=>$images[3],'images'=>array_slice($descriptors,0,3),'files'=>$files,'originals'=>ownOriginalDatabaseHash($db,$tables)],JSON_THROW_ON_ERROR),LOCK_EX);chmod($file,0600);
echo "Owned synthetic public feed original and separate private image replacement prepared; outside original bytes retained, no external publication.\n";
