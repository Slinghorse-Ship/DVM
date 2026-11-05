<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Media,MediaMetadata,MediaQuota};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/album-upload-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string$table,array$f):array{$e=$f['original_keys'][$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
$mode=$argv[1]??'prepare';if(!in_array($mode,['prepare','check','prepare-status','check-status','prepare-compact','check-compact'],true))throw new RuntimeException('Owned fixture mode required.');
$status=str_ends_with($mode,'-status');$compact=str_ends_with($mode,'-compact');if($status||$compact)$file=str_replace('.json',$status?'-status.json':'-compact.json',$file);$count=$compact?11:($status?1:8);$revisions=$compact?12:($status?2:4);
if(str_starts_with($mode,'check')){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row or revision changed.');
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
 foreach($f['sources']as$source)if(hash_file('sha256',$source['path'])!==$source['sha256'])throw new RuntimeException('An own selected source changed.');
 $pages=new Pages($db,$access);$album=$pages->get($f['album']);
 if($album['version']!==$f['version']+$revisions||count($album['media_ids'])!==$count+($compact?1:0)||$pages->publicPage($f['slug'])!==$f['publication'])throw new RuntimeException('Exact private album revisions and exact earlier publication required.');
 if($compact&&($album['media_ids'][0]!==$f['original_item']['id']||$album['gallery_items'][0]!==$f['original_item']))throw new RuntimeException('Original photo order caption and tags changed.');
 if(($status||$compact)&&($album['title']!==$f['prefix'].' independent edit Ä'||$album['body']!=='Own independent text Ä'))throw new RuntimeException('Actual later independent album edit required.');
 $rows=Db::run($db,'SELECT id,original_name,variants_json FROM khcms_media WHERE club_id=? AND original_name LIKE ? ORDER BY created_at,id',[$access->club(),$f['prefix'].'-%'])->fetchAll();
 if(count($rows)!==$count||array_diff(array_column($rows,'id'),$album['media_ids']))throw new RuntimeException('Exact actual selected album photos required.');
 $byId=array_column($rows,null,'id');$names=[];foreach(['width-320','width-390','width-820','retry']as$suffix)foreach([0,1]as$i)$names[]=$f['prefix'].'-'.$suffix.'-'.$i.'.png';if($status)$names=[$f['prefix'].'-status-a.png'];if($compact){$names=array_map(fn($i)=>$f['prefix'].'-compact-'.$i.'.png',range(0,7));array_push($names,$f['prefix'].'-lost-current.png',$f['prefix'].'-lost-stale.png',$f['prefix'].'-late.png');}$rows=array_map(fn($id)=>$byId[$id],$compact?array_slice($album['media_ids'],1):$album['media_ids']);if(array_column($rows,'original_name')!==$names)throw new RuntimeException('Exact selected filenames and source order required.');
 $media=new Media($db,$access,$runtime.'/media');$bytes=0;
 foreach($rows as$i=>$row){$expected=$f['sources'][$i%2===0?'a':'b'];$asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);if([$size[0],$size[1]]!==$expected['size'])throw new RuntimeException('Actual selected geometry changed.');$image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($size[0]/2),(int)floor($size[1]/2)));unset($image);foreach(['red','green','blue']as$n=>$key)if($pixel[$key]!==$expected['color'][$n])throw new RuntimeException('Actual selected PNG pixels changed.');$m=(new MediaMetadata($db,$access))->get($row['id']);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof']||$media->publicVariant($row['id'],'thumb')!==null)throw new RuntimeException('Album upload invented approval proof or publication.');$variants=json_decode($row['variants_json'],true,32,JSON_THROW_ON_ERROR);if(count($variants)!==4)throw new RuntimeException('Four actual photo variants required.');$bytes+=array_sum(array_column($variants,'bytes'));}
 $q=(new MediaQuota($db,$access))->get();if($q['image_count']!==$f['quota']['image_count']+$count||$q['used_bytes']!==$f['quota']['used_bytes']+$bytes)throw new RuntimeException('Exact complete batch quota required.');
 echo "$count actual private album photos and $revisions complete revisions verified; every previous row/publication/file and selected source preserved.\n";exit;
}
$prefix='own-album-batch-'.bin2hex(random_bytes(7));$sources=[];
foreach(['a'=>[[48,32],[25,110,175]],'b'=>[[32,48],[180,65,95]]]as$key=>[$size,$color]){$path=$runtime.'/'.$prefix.'-source-'.$key.'.png';$image=imagecreatetruecolor(...$size);imagefill($image,0,0,imagecolorallocate($image,...$color));imagepng($image,$path);unset($image);$sources[$key]=['path'=>$path,'sha256'=>hash_file('sha256',$path),'size'=>$size,'color'=>$color];}
$bad=$runtime.'/'.$prefix.'-bad.png';file_put_contents($bad,'Own invalid PNG');$sources['bad']=['path'=>$bad,'sha256'=>hash_file('sha256',$bad)];
$originalItem=null;if($compact){$asset=(new Media($db,$access,$runtime.'/media'))->upload($sources['a']['path'],'Own original compact photo.png','Own original caption Ä');$originalItem=['id'=>$asset['id'],'caption'=>'Own original caption Ä','tags'=>['Originaltag']];}
$pages=new Pages($db,$access);$album=$pages->save(['title'=>$prefix.' exact album Ä','slug'=>'galerie/'.$prefix,'template'=>'gallery','body'=>'Own unchanged exact text Ä','media_ids'=>$originalItem?[$originalItem['id']]:[],'gallery_items'=>$originalItem?[$originalItem]:[]]);$album=$pages->publish($album['id'],$album['version']);
$f=['prefix'=>$prefix,'album'=>$album['id'],'version'=>$album['version'],'slug'=>$album['slug'],'publication'=>$pages->publicPage($album['slug']),'sources'=>$sources,'original_keys'=>[],'pixels'=>[],'quota'=>(new MediaQuota($db,$access))->get()];
if($compact)$f['original_item']=$originalItem;
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original primary keys required.');$where=$table==='khcms_pages'?' WHERE id<>?':'';$args=$table==='khcms_pages'?[$album['id']]:[];$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Own new album and selected sources prepared; every earlier row/revision/publication/file recorded.\n";
