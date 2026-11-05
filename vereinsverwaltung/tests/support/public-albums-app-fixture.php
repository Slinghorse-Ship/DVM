<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{AlbumOrder,Pages,Media,MediaMetadata};
use Kulturhus\Native\Integration\ManagerBridge;
$k=ownKBrowserFixture();$c=TestSupport::config();$standalone=$k['standalone'];

$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=$k['runtime'];$file=$k['directory'].'/public-albums-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$e=$f['original_keys'][$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
$mode=$argv[1]??'prepare';if(!in_array($mode,['prepare','check'],true))throw new RuntimeException('Owned fixture mode required.');
if($mode==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 $currentTables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
 if($currentTables!==array_keys($f['original_keys']))throw new RuntimeException('Public browsing must not create or remove tables.');
 foreach($f['original_keys']as$table=>$keys)if((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==count($keys['values']))throw new RuntimeException('Read-only browsing inserted or removed an original database row.');
 if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original row/revision/state changed during public browsing.');
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
 if(hash_file('sha256',$f['source'])!==$f['source_sha256']||(new Pages($db,$access))->publicPage($f['album']['slug'])!==$f['album'])throw new RuntimeException('Owned source or exact earlier publication changed.');
 $media=new Media($db,$access,$runtime.'/media');foreach($f['photos']as$id){$original=$media->variant($id,'original');$size=getimagesize($original['path']);$image=imagecreatefromstring(file_get_contents($original['path']));$color=imagecolorsforindex($image,imagecolorat($image,24,16));unset($image);if([$size[0],$size[1],$color['red'],$color['green'],$color['blue']]!==[48,32,25,110,175])throw new RuntimeException('Actual original photo geometry or pixels changed.');}
 echo "Actual complete public album/photo paging verified; no database writes and every original state/page/photo/revision/publication/file/source retained.\n";exit;
}
$pages=new Pages($db,$access);if(!$pages->publicPage('galerie'))throw new RuntimeException('Existing owned published gallery root required.');
$prefix='own-public-albums-'.bin2hex(random_bytes(7));$source=$runtime.'/'.$prefix.'-source.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,25,110,175));imagepng($image,$source);unset($image);$media=new Media($db,$access,$runtime.'/media');$photos=[];
for($i=0;$i<8;$i++)$photos[]=$media->upload($source,$prefix.'-'.$i.'.png',$prefix.' original '.$i)['id'];
$details=['alt'=>$prefix.' original alternative Ä','credit'=>$prefix.' original credit Ä','source_name'=>'Own GD source','license_name'=>'Own synthetic license','rights_state'=>'cleared','consent_state'=>'not_required','channels'=>['website']];$metadata=new MediaMetadata($db,$access);$metadata->save($photos[0],$details,0);
$items=array_map(fn($id,$i)=>['id'=>$id,'caption'=>$prefix.' original caption '.$i.' Ä <marker>','tags'=>['Own original '.$i]],$photos,array_keys($photos));$new=[];
for($i=0;$i<9;$i++){$a=$pages->save(['title'=>$prefix.' '.sprintf('%02d',$i).' Ä','slug'=>'galerie/'.$prefix.'-'.$i,'template'=>'gallery','nav_order'=>99000+$i,'body'=>'Own published original text Ä','media_ids'=>$photos,'gallery_items'=>$items]);$new[]=$pages->publish($a['id'],1);}
$album=$pages->publicPage($new[0]['slug']);$pages->save(array_replace($new[0],['title'=>'PRIVATE '.$prefix,'template'=>'standard','nav_order'=>-99000,'gallery_items'=>array_replace($items,[0=>array_replace($items[0],['caption'=>'PRIVATE '.$prefix])])]),2);$metadata->save($photos[0],array_replace($details,['alt'=>'PRIVATE ALT '.$prefix,'credit'=>'PRIVATE CREDIT '.$prefix]),1);
$reference=[];foreach($pages->list()as$draft){$slug=$draft['published_slug']??'';if($slug!==''&&($p=$pages->publicPage($slug))&&($p['template_definition']['renderer']??$p['template'])==='gallery'&&$p['slug']!=='galerie')$reference[]=$p;}$reference=(new AlbumOrder($db,$access))->sort($reference);
$index=array_map(fn($p)=>['id'=>$p['id'],'slug'=>$p['slug'],'title'=>$p['title'],'preview'=>array_column(array_slice($p['gallery_items']??[],0,3),'id')],$reference);unset($reference);
// Establish only an owned anonymous read path before freezing every original row.
$url=$k['origin'].($standalone?'/site/galerie':'/galerie/');$html=file_get_contents($url);if(!$html||!str_contains($html,'Bildergalerie'))throw new RuntimeException('Actual owned public route must be ready.');
$f=['prefix'=>$prefix,'photos'=>$photos,'album'=>$album,'index'=>$index,'details'=>$details,'source'=>$source,'source_sha256'=>hash_file('sha256',$source),'original_keys'=>[],'pixels'=>[]];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original primary keys required.');$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Own nine new published albums and eight actual photos prepared; every earlier row/state/revision/publication/file recorded.\n";
