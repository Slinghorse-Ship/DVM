<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{AlbumCatalogue,AlbumOrder,Pages,Media};
use Kulturhus\Native\Integration\ManagerBridge;
$k=ownKBrowserFixture();$c=TestSupport::config();$standalone=$k['standalone'];

$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=$k['runtime'];$file=$k['directory'].'/album-catalogue-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$e=$f['original_keys'][$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
$mode=$argv[1]??'prepare';if(!in_array($mode,['prepare','check'],true))throw new RuntimeException('Owned fixture mode required.');
if($mode==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original row or revision changed.');
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
 if(hash_file('sha256',$f['source'])!==$f['source_sha256'])throw new RuntimeException('An own source changed.');
 $state=(new AlbumOrder($db,$access))->state();if($state['version']!==$f['order_version']+2||$state['ids']!==$f['global_ids'])throw new RuntimeException('Exactly two private independent order revisions must restore original complete order.');
 if((new AlbumCatalogue($db,$access))->listing(['q'=>$f['prefix']])['total']!==53)throw new RuntimeException('Complete own catalogue must remain reachable.');
 if((new Pages($db,$access))->publicPage($f['published_slug'])!==$f['publication'])throw new RuntimeException('Earlier public album changed.');
 $original=(new Media($db,$access,$runtime.'/media'))->variant($f['image'],'original');$size=getimagesize($original['path']);$image=imagecreatefromstring(file_get_contents($original['path']));$color=imagecolorsforindex($image,imagecolorat($image,24,16));unset($image);
 if([$size[0],$size[1],$color['red'],$color['green'],$color['blue']]!==[48,32,25,110,175])throw new RuntimeException('Actual original photo geometry or pixels changed.');
 echo "Actual bounded album catalogue and two independent order revisions verified; every original page/photo/row/revision/publication/file/source retained.\n";exit;
}
$catalogue=new AlbumCatalogue($db,$access);if($catalogue->listing()['all_total']+53>500)throw new RuntimeException('Fresh owned album catalogue proof must fit the existing movement limit.');
$prefix='own-album-list-'.bin2hex(random_bytes(7));$source=$runtime.'/'.$prefix.'-source.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,25,110,175));imagepng($image,$source);unset($image);
$asset=(new Media($db,$access,$runtime.'/media'))->upload($source,$prefix.'.png',$prefix.' original');$pages=new Pages($db,$access);$ids=[];$published=[];
for($i=0;$i<53;$i++){
 $album=$pages->save(['title'=>$prefix.' '.sprintf('%03d',$i).' Ä','slug'=>'galerie/'.$prefix.'-'.$i,'template'=>'gallery','nav_order'=>99900+$i,'body'=>str_repeat('own-body-only ',1000),'media_ids'=>[$asset['id']],'gallery_items'=>[['id'=>$asset['id'],'caption'=>$prefix.' caption '.$i.($i===37?' 100%_! Σ Straße':''),'tags'=>[$prefix.' tag '.$i]]]]);
 if($i%4===0){$album=$pages->publish($album['id'],$album['version']);$published[]=$album['id'];}
 if($i===0){$public=$pages->publicPage($album['slug']);$publicSlug=$album['slug'];$album=$pages->save(array_replace($album,['title'=>$album['title'].' privater langer Titel '.str_repeat('Ä',60),'nav_order'=>-99999]),$album['version']);}
 $ids[]=$album['id'];
}
$state=(new AlbumOrder($db,$access))->state();$global=$catalogue->order();$rank=array_search($ids[25],$global,true);if($rank===false||$rank<1||$global[$rank-1]!==$ids[24])throw new RuntimeException('New own adjacent albums must be in the original complete global order.');
$f=['prefix'=>$prefix,'ids'=>$ids,'published'=>$published,'image'=>$asset['id'],'order_version'=>$state['version'],'global_ids'=>$global,'published_slug'=>$publicSlug,'publication'=>$public,'source'=>$source,'source_sha256'=>hash_file('sha256',$source),'original_keys'=>[],'pixels'=>[]];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original primary keys required.');$where=$table==='khcms_album_order'?' WHERE club_id<>?':'';$args=$table==='khcms_album_order'?[$access->club()]:[];$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Own new 53 album catalogue prepared; every earlier original row/revision/publication/file and new album recorded.\n";
