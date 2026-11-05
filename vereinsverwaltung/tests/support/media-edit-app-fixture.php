<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Media,MediaMetadata,MediaQuota,Pages};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/media-edit-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $media=new Media($db,$access,$runtime.'/media');$rows=Db::run($db,'SELECT id,variants_json FROM khcms_media WHERE original_name LIKE ?',[$f['prefix'].'%-bearbeitet.%'])->fetchAll();
    if(count($rows)!==4)throw new RuntimeException('Exactly four actual edited copies required.');$bytes=0;
    foreach($rows as$row){$variants=json_decode($row['variants_json'],true,32,JSON_THROW_ON_ERROR);$original=Db::run($db,'SELECT sha256 FROM khcms_media WHERE id=?',[$f['ids'][0]])->fetchColumn();if(($variants['original']['derived_from']??null)!==['media_id'=>$f['ids'][0],'sha256'=>$original])throw new RuntimeException('Exact original ID and hash required.');$bytes+=array_sum(array_column($variants,'bytes'));$asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);if([$size[0],$size[1]]!==[32,48])throw new RuntimeException('Independent saved rotation geometry required.');$image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,16,24));unset($image);foreach(['red'=>40,'green'=>130,'blue'=>180]as$key=>$value)if(abs($pixel[$key]-$value)>5)throw new RuntimeException('Independent saved original color required.');$m=(new MediaMetadata($db,$access))->get($row['id']);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof']||$media->publicVariant($row['id'],'medium')!==null)throw new RuntimeException('New copy must stay private without manufactured rights.');}
    $quota=(new MediaQuota($db,$access))->get();if($quota['used_bytes']!==$f['quota']['used_bytes']+$bytes||$quota['image_count']!==$f['quota']['image_count']+4)throw new RuntimeException('Exact new image quota accounting required.');
    echo "Four actual edited copies retain exact original ID/SHA/pixels; every original row, publication and stored file preserved, no transport.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-media-edit-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'ids'=>[],'original_keys'=>[],'pixels'=>[]];
$source=$runtime.'/'.$prefix.'.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,40,130,180));imagepng($image,$source);unset($image);
$media=new Media($db,$access,$runtime.'/media');
foreach(['first','second']as$name)$f['ids'][]=$media->upload($source,$prefix.'-'.$name.'.png','Eigene ursprüngliche Aufnahme')['id'];
$pages=new Pages($db,$access);$page=$pages->save(['title'=>'Eigene Archivprüfung','slug'=>$prefix,'body'=>'Eigener unveränderlicher Testinhalt','media_ids'=>[$f['ids'][0]]]);$pages->publish($page['id'],$page['version']);$f['slug']=$prefix;$f['quota']=(new MediaQuota($db,$access))->get();
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Two own photos and one own local publication prepared; all original rows and stored pixels recorded.\n";
