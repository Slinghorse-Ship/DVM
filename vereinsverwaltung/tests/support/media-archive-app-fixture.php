<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Media,MediaArchive,MediaMetadata,MediaQuota,Pages};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/media-archive-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $archive=new MediaArchive($db,$access);$state=$archive->get($f['ids'][0]);
    if($state['archived']||$state['version']!==8||$archive->history($f['ids'][0])['total']!==8)throw new RuntimeException('Exactly four actual archive/restore pairs required.');
    if($archive->get($f['ids'][1])['version']!==0||$archive->history($f['ids'][1])['total']!==0)throw new RuntimeException('Untouched own image must have no manufactured archive history.');
    if((new MediaQuota($db,$access))->get()!==$f['quota'])throw new RuntimeException('Original photo storage usage changed.');
    foreach($f['ids']as$id){$m=(new MediaMetadata($db,$access))->get($id);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof'])throw new RuntimeException('Original copyright state changed.');}
    echo "Eight actual archive state changes; every original row, publication, proof and stored file preserved, no transport.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-media-archive-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'ids'=>[],'original_keys'=>[],'pixels'=>[]];
$source=$runtime.'/'.$prefix.'.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,40,130,180));imagepng($image,$source);unset($image);
$media=new Media($db,$access,$runtime.'/media');
foreach(['first','second']as$name)$f['ids'][]=$media->upload($source,$prefix.'-'.$name.'.png','Eigene ursprüngliche Aufnahme')['id'];
$pages=new Pages($db,$access);$page=$pages->save(['title'=>'Eigene Archivprüfung','slug'=>$prefix,'body'=>'Eigener unveränderlicher Testinhalt','media_ids'=>[$f['ids'][0]]]);$pages->publish($page['id'],$page['version']);$f['slug']=$prefix;$f['quota']=(new MediaQuota($db,$access))->get();
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Two own photos and one own local publication prepared; all original rows and stored pixels recorded.\n";
