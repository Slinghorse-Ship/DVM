<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Media,MediaMetadata,MediaQuota};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/photo-library-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$e=$f['original_keys'][$table];if(!$e['values'])return [' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
 if(hash_file('sha256',$f['source'])!==$f['source_sha256'])throw new RuntimeException('Own selected source changed.');
 $rows=Db::run($db,'SELECT id,original_name,variants_json FROM khcms_media WHERE club_id=? AND original_name LIKE ?',[$access->club(),$f['prefix'].'-%'])->fetchAll();
 if(count($rows)!==count($f['expected']))throw new RuntimeException('Exactly thirteen accepted new library photos required: '.count($rows));
 $media=new Media($db,$access,$runtime.'/media');$bytes=0;
 foreach($rows as$row){
  $suffix=substr($row['original_name'],strlen($f['prefix'])+1);$expected=$f['expected'][$suffix]??null;if(!$expected)throw new RuntimeException('Unexpected private upload.');
  $m=(new MediaMetadata($db,$access))->get($row['id']);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof']||$media->publicVariant($row['id'],'thumb')!==null)throw new RuntimeException('Upload invented rights or published a photo.');
  $asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);if([$size[0],$size[1]]!==$expected['size'])throw new RuntimeException('Actual source dimensions changed.');
  $image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($size[0]/2),(int)floor($size[1]/2)));unset($image);
  foreach(['red','green','blue']as$i=>$key)if(abs($pixel[$key]-$expected['color'][$i])>12)throw new RuntimeException('Actual accepted source pixels changed.');
  $variants=json_decode($row['variants_json'],true,32,JSON_THROW_ON_ERROR);if(count($variants)!==4)throw new RuntimeException('Four actual variants required.');$bytes+=array_sum(array_column($variants,'bytes'));
 }
 $quota=(new MediaQuota($db,$access))->get();if($quota['image_count']!==$f['quota']['image_count']+count($rows)||$quota['used_bytes']!==$f['quota']['used_bytes']+$bytes)throw new RuntimeException('Exact accepted library quota delta required.');
 echo "Thirteen actual private library photos and exact quota verified; every previous row/publication/file and selected source preserved.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-library-'.bin2hex(random_bytes(7));$source=$runtime.'/'.$prefix.'-source.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,25,110,175));imagepng($image,$source);unset($image);
$f=['prefix'=>$prefix,'source'=>$source,'source_sha256'=>hash_file('sha256',$source),'expected'=>[],'original_keys'=>[],'pixels'=>[],'quota'=>(new MediaQuota($db,$access))->get()];
foreach(array_merge(array_map(fn($i)=>'batch-'.$i.'.png',range(1,7)),['lost.png','late.png','nojs-1.png','nojs-2.png'])as$name)$f['expected'][$name]=['size'=>[48,32],'color'=>[25,110,175]];
$f['expected']['heic.jpg']=['size'=>[96,64],'color'=>[35,140,210]];$f['expected']['raw.jpg']=['size'=>[64,48],'color'=>[255,55,95]];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original primary keys required.');$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Own new library source prepared; all earlier original rows/publications/files recorded without exclusions.\n";
