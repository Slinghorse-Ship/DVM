<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Articles,Media,MediaMetadata};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$mode=$argv[1]??'prepare';if(!in_array($mode,['prepare','check','prepare-editor','check-editor'],true))throw new RuntimeException('Owned fixture mode required.');$editor=str_ends_with($mode,'-editor');
$file=dirname(__DIR__,3).'/.local/browser-tests/'.($editor?'photo-editor-':'photo-import-').($standalone?'standalone':'integrated').'.json';
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$filter=static function(string $table,array $f):array{
    $entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];
    $columns='(`'.implode('`,`',$entry['columns']).'`)';$arguments=[];foreach($entry['values']as$row)array_push($arguments,...$row);
    $group='('.implode(',',array_fill(0,count($entry['columns']),'?')).')';
    return [' WHERE '.$columns.' IN ('.implode(',',array_fill(0,count($entry['values']),$group)).')',$arguments];
};
if(str_starts_with($mode,'check')){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('Outside database originals changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Original photo pixels changed.');
    $rows=Db::run($db,'SELECT id,original_name FROM khcms_media WHERE original_name LIKE ?',[$f['prefix'].'%'])->fetchAll();
    if(count($rows)!==($editor?2:8))throw new RuntimeException('Expected exact own media upload count: '.count($rows));
    $media=new Media($db,$access,$runtime.'/media');
    foreach($rows as$row){$metadata=(new MediaMetadata($db,$access))->get($row['id']);if($metadata['version']!==0||$metadata['rights_state']!=='unknown'||$metadata['has_proof'])throw new RuntimeException('Source rights were manufactured.');$asset=$media->variant($row['id'],'original');if(!getimagesize($asset['path']))throw new RuntimeException('Actual converted stored pixels required.');}
    if(count((new Pages($db,$access))->get($f['album'])['media_ids'])!==($editor?0:3)||count((new Articles($db,$access))->get($f['article'])['media_ids'])!==1)throw new RuntimeException('Actual album and article photo attachments required.');
    if(!$standalone&&!$editor){$files=Db::run($db,'SELECT mime,bytes FROM document_files WHERE name LIKE ?',[$f['prefix'].'document-%'])->fetchAll();if(count($files)!==2)throw new RuntimeException('Exactly two own converted documents required.');foreach($files as$source)if($source['mime']!=='image/jpeg'||!getimagesizefromstring($source['bytes']))throw new RuntimeException('Actual stored camera document pixels required.');}
    if(!$editor)foreach($rows as$row)if(str_contains($row['original_name'],'library-jxl')){
        $asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);if([$size[0],$size[1]]!==[480,640]||!str_contains($row['original_name'],'-vorschau.jpg'))throw new RuntimeException('Actual stored oriented compressed RAW preview required.');
        $image=imagecreatefromstring(file_get_contents($asset['path']));foreach([160=>[255,55,95],480=>[45,90,220]]as$y=>$expected){$pixel=imagecolorsforindex($image,imagecolorat($image,240,$y));foreach(['red','green','blue']as$i=>$key)if(abs($pixel[$key]-$expected[$i])>12)throw new RuntimeException('Stored camera preview source pixels changed.');}unset($image);
    }
    if($editor)foreach($rows as$row){$asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);$library=str_contains($row['original_name'],'library');$expected=$library?[64,96]:[64,48];if([$size[0],$size[1]]!==$expected)throw new RuntimeException('Actual stored edited camera geometry required.');$image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($size[0]/2),(int)floor($size[1]/2)));unset($image);if($library){foreach(['red'=>35,'green'=>140,'blue'=>210]as$key=>$value)if(abs($pixel[$key]-$value)>12)throw new RuntimeException('Actual rotated HEIC colors required.');}elseif(max($pixel['red'],$pixel['green'],$pixel['blue'])-min($pixel['red'],$pixel['green'],$pixel['blue'])>2)throw new RuntimeException('Actual stored RAW grayscale transformation required.');}
    echo ($editor?'Two edited camera photo uploads':'Eight own media uploads')."; real article attachments; every outside original row and stored photo preserved.\n";exit;
}
$prefix='own-camera-'.bin2hex(random_bytes(7)).'-';$pages=new Pages($db,$access);
$album=$pages->save(['title'=>$prefix.'album','slug'=>'galerie/'.$prefix.'album','template'=>'gallery']);
$article=(new Articles($db,$access))->save(['title'=>$prefix.'article','slug'=>'aktuelles/'.$prefix.'article','body'=>'Eigener unveränderter Beitrag','news_date'=>'2026-10-11']);
$social=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social'))->save(['title'=>$prefix.'social','body'=>'Eigener unveränderter Bildtext']);
$f=['database'=>$c['database'],'prefix'=>$prefix,'album'=>$album['id'],'article'=>$article['id'],'social'=>$social['id'],'original_keys'=>[],'pixels'=>[]];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){
    $keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');
    $where='';$args=[];
    if($table==='khcms_pages'){$where=' WHERE id NOT IN (?,?)';$args=[$album['id'],$article['id']];}
    elseif(in_array($table,['khcms_revisions','khcms_page_history','khcms_page_metadata','khcms_page_dates','khcms_page_text','khcms_page_numbers','khcms_page_references'],true)){$where=' WHERE page_id NOT IN (?,?)';$args=[$album['id'],$article['id']];}
    $values=Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll(PDO::FETCH_NUM);
    $f['original_keys'][$table]=['columns'=>$keys,'values'=>$values];
}
foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Own isolated article, album and social draft prepared; every outside original recorded, no transport.\n";
