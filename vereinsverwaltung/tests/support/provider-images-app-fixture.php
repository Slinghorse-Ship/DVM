<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Media,Pages};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/provider-images-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $asset=(new Media($db,$access,$runtime.'/media'))->variant($f['id'],'original');if([$asset['width'],$asset['height']]!==[48,32])throw new RuntimeException('Exact received native source image required.');
    echo "Original event, all prior database rows, publications and photo bytes unchanged after real UI browsing; no transport.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-provider-images-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'original_keys'=>[],'pixels'=>[]];
$source=$runtime.'/'.$prefix.'.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,40,130,180));imagepng($image,$source);unset($image);
$events=new \Kulturhus\Native\Events\Eventbrite($db,$access);$external='900'.random_int(100000000000,999999999999);
$raw=['id'=>$external,'name'=>['text'=>$prefix],'description'=>['text'=>'Eigener ursprünglicher Anbietertext'],'start'=>['utc'=>'2035-01-13T12:00:00Z','timezone'=>'Europe/Berlin'],'end'=>['utc'=>'2035-01-13T14:00:00Z'],'venue'=>['name'=>'Eigener Testsaal'],'status'=>'live','url'=>'https://www.eventbrite.de/e/'.$external,'logo'=>['url'=>'https://img.evbuc.com/own-synthetic/'.$external.'.png']];
$f['page']=$events->import([\Kulturhus\Native\Events\Eventbrite::normalize($raw)])[0]['id'];$pages=new Pages($db,$access);$page=$pages->get($f['page']);$pages->publish($f['page'],$page['version']);$f['slug']=$page['slug'];
(new \Kulturhus\Native\Events\EventbriteImages($db,$access,$runtime.'/media',static fn($url,$path)=>copy($source,$path)))->receive($f['page']);$f['id']=$pages->get($f['page'])['media_ids'][0];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "One own received provider photo and its original local event prepared; all original rows and stored pixels recorded.\n";
