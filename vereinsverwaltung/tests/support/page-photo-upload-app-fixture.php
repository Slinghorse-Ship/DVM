<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';require __DIR__.'/own-website-pdf.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Media,MediaMetadata,WebsiteDocuments};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/page-photo-upload-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);$report=json_decode(file_get_contents(str_replace('.json','-results.json',$file)),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $pages=new Pages($db,$access);$media=new Media($db,$access,$runtime.'/media');$docs=new WebsiteDocuments($db,$access,$runtime.'/website-documents');$new=[];
    foreach($f['pages']as$case=>$original){
        $p=$pages->get($original['id']);$r=$report[$case];$wanted=[...$f['selected'],...$r['selected']];
        if($p['version']!==3||$p['title']!==$f['prefix'].' edited '.$case||$p['excerpt']!=='Own unsaved input '.$case||$p['media_ids']!==$wanted||array_slice($p['gallery_items'],0,3)!==$f['gallery_items']||$pages->publicPage($original['slug'])!==$original['published'])throw new RuntimeException('Exact saved original and new image associations required.');
        foreach($r['selected']as$id){$record=Db::run($db,'SELECT original_name FROM khcms_media WHERE id=? AND club_id=?',[$id,$access->club()])->fetch();if(!$record||!str_starts_with($record['original_name'],$f['prefix'].'-new-'))throw new RuntimeException('Only exact new own photos may be associated.');$new[$id]=true;}
        if(count($p['document_links']??[])!==2)throw new RuntimeException('Exactly two associated new PDFs required.');
        foreach($p['document_links']as$i=>$link)if(file_get_contents($docs->file($link['id'])['path'])!==file_get_contents($f['files'][$i])||$docs->publicFile($link['id'])!==null)throw new RuntimeException('Exact private PDF bytes required.');
        foreach($r['unconfirmed']??[]as$id){$new[$id]=true;if(in_array($id,$p['media_ids'],true))throw new RuntimeException('Unconfirmed upload cannot silently join selection.');}
    }
    $rows=Db::run($db,'SELECT id,original_name FROM khcms_media WHERE club_id=? AND original_name LIKE ?',[$access->club(),$f['prefix'].'-new-%'])->fetchAll();if(count($rows)!==10||count($new)!==10)throw new RuntimeException('Exactly ten new own photos required.');
    foreach($rows as$row){if(!isset($new[$row['id']]))throw new RuntimeException('Unexpected or duplicate new photo.');$metadata=(new MediaMetadata($db,$access))->get($row['id']);if($metadata['version']!==0||$metadata['rights_state']!=='unknown'||$metadata['has_proof'])throw new RuntimeException('Rights cannot be manufactured.');$asset=$media->variant($row['id'],'original');$size=getimagesize($asset['path']);$expected=str_contains($row['original_name'],'HEIC')?[96,64]: (str_contains($row['original_name'],'RAW')?[64,48]:[32,24]);if([$size[0],$size[1]]!==$expected||$media->publicVariant($row['id'],'thumb')!==null)throw new RuntimeException('Actual private stored pixels required.');}
    foreach($rows as$row){$asset=$media->variant($row['id'],'original');$image=imagecreatefromstring(file_get_contents($asset['path']));$pixel=imagecolorsforindex($image,imagecolorat($image,intdiv(imagesx($image),2),intdiv(imagesy($image),2)));unset($image);$expected=str_contains($row['original_name'],'HEIC')?[35,140,210]:(str_contains($row['original_name'],'RAW')?[255,55,95]:[35,100,180]);foreach(['red','green','blue']as$i=>$key)if(abs($pixel[$key]-$expected[$i])>12)throw new RuntimeException('Actual own decoded source colors required.');}
    echo "Ten new private photos and ten associated original PDFs verified; five saved pages retain original order/captions/tags/publications, all previous rows and bytes unchanged.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-page-photos-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'pages'=>[],'files'=>[],'original_keys'=>[],'pixels'=>[]];
$f['png']=$runtime.'/'.$prefix.'-new-png.png';$image=imagecreatetruecolor(32,24);imagefill($image,0,0,imagecolorallocate($image,35,100,180));imagepng($image,$f['png']);unset($image);
$f['bad_image']=$runtime.'/'.$prefix.'-new-invalid.png';file_put_contents($f['bad_image'],'Own invalid new photo');$f['bad_pdf']=$runtime.'/'.$prefix.'-invalid.pdf';file_put_contents($f['bad_pdf'],'Own invalid new PDF');
$media=new Media($db,$access,$runtime.'/media');$ids=[];foreach(['A','B','C']as$name)$ids[]=$media->upload($f['png'],$prefix.'-original-'.$name.'.png','Own original '.$name)['id'];$f['selected']=[$ids[2],$ids[0],$ids[1]];$f['gallery_items']=array_map(fn($id,$i)=>['id'=>$id,'caption'=>'Own exact caption '.$i,'tags'=>['Own exact tag '.$i]],$f['selected'],array_keys($f['selected']));
foreach(['A','B']as$letter){$path=$runtime.'/'.$prefix.'-'.$letter.'.pdf';file_put_contents($path,ownWebsitePdf('Own photo upload PDF '.$prefix.' '.$letter));$f['files'][]=$path;}
$pages=new Pages($db,$access);foreach(['320-js','390-js','820-js','844-js','390-nojs']as$case){$p=$pages->save(['title'=>$prefix.' original '.$case,'slug'=>$prefix.'-'.$case,'excerpt'=>'Own original excerpt','body_html'=>'<h2>Own original heading</h2>','media_ids'=>$f['selected'],'gallery_items'=>$f['gallery_items']]);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$case]=['id'=>$p['id'],'slug'=>$p['slug'],'published'=>$pages->publicPage($p['slug'])];}
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$values=Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM);if($table==='khcms_pages')$values=array_values(array_filter($values,fn($row)=>!in_array($row[0],array_column($f['pages'],'id'),true)));$f['original_keys'][$table]=['columns'=>$keys,'values'=>$values];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Three new own original images, five own published pages and new single/multiple photo/PDF sources prepared; all earlier original rows/revisions/files recorded.\n";
