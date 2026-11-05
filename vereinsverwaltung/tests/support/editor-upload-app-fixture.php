<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\{Workspace,Schema};
use Kulturhus\Native\Cms\{Media,MediaMetadata};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/editor-upload-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('Outside original database rows changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Original stored photo pixels changed.');
    $rows=Db::run($db,'SELECT id,original_name FROM khcms_media WHERE original_name LIKE ?',[$f['prefix'].'%'])->fetchAll();
    if(count($rows)!==9)throw new RuntimeException('Exactly nine own new media uploads required: '.count($rows));
    $media=new Media($db,$access,$runtime.'/media');$sources=[];
    foreach($rows as$row){$m=(new MediaMetadata($db,$access))->get($row['id']);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof'])throw new RuntimeException('No manufactured rights allowed.');$asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);$name=$row['original_name'];$jxl=str_contains($name,'jxl');$edited=str_contains($name,'edited');$raw=str_contains($name,'raw');$expected=$jxl?[480,640]:($edited?[64,96]:($raw?[64,48]:[96,64]));if([$size[0],$size[1]]!==$expected)throw new RuntimeException('Independent stored geometry mismatch: '.$name);$image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($size[0]/2),(int)floor($size[1]/4)));unset($image);$expectedColor=$jxl||$raw?['red'=>255,'green'=>55,'blue'=>95]:['red'=>35,'green'=>140,'blue'=>210];foreach($expectedColor as$key=>$value)if(abs($pixel[$key]-$value)>13)throw new RuntimeException('Independent stored source color mismatch: '.$name);$sources[$row['id']]=true;}
    foreach($f['items']as$key=>$id){$module=$key==='story'?'social':$key;$item=(new Workspace($db,$access,$module))->get($id);$images=$key==='story'?($item['story']??[]):array_values(array_filter($item['blocks']??[],fn($b)=>$b['type']==='image'));if($item['version']!==2||$item['status']!=='draft'||$item['title']!==$f['prefix'].$key.' edited'||count($images)!==($key==='newsletter'?3:2))throw new RuntimeException('Actual draft save and exact image association required: '.$key);foreach($images as$image)if(!isset($sources[$image['media_ref']]))throw new RuntimeException('Draft must reference its exact new own upload.');}
    echo "Nine actual camera uploads, four original-bound draft saves and every outside row/photo preserved; no transport.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-editor-upload-'.bin2hex(random_bytes(7)).'-';$f=['prefix'=>$prefix,'items'=>[],'original_keys'=>[],'pixels'=>[]];
foreach(['newsletter','press','social','story']as$key){$module=$key==='story'?'social':$key;$item=(new Workspace($db,$access,$module))->save(['title'=>$prefix.$key,'body'=>'Eigener ursprünglicher Text',...$key==='story'?['kind'=>'story']:[]]);$f['items'][$key]=$item['id'];}
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$where='';$args=[];foreach(['newsletter','press','social']as$module)if($table===Schema::table($module,'items')){$ids=array_values(array_filter($f['items'],fn($id,$key)=>($key==='story'?'social':$key)===$module,ARRAY_FILTER_USE_BOTH));$where=' WHERE id NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=$ids;}$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Four own drafts prepared; every earlier original row, revision and photo recorded.\n";
