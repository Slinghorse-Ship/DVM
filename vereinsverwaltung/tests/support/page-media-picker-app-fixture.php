<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';require __DIR__.'/own-website-pdf.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Media,WebsiteDocuments};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/page-media-picker-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $pages=new Pages($db,$access);$docs=new WebsiteDocuments($db,$access,$runtime.'/website-documents');
    foreach($f['pages']as$case=>$original){
        $p=$pages->get($original['id']);$wanted=$case==='390-nojs'?array_slice($f['selected'],1):array_merge(array_slice($f['selected'],1),[$f['images'][111]]);
        if($case!=='390-nojs')[$wanted[0],$wanted[1]]=[$wanted[1],$wanted[0]];
        $captionById=array_column($f['gallery_items'],null,'id');$wantedItems=array_map(fn($id)=>$captionById[$id]??['id'=>$id,'caption'=>'','tags'=>[]],$wanted);
        if($p['version']!==3||$p['title']!==$f['prefix'].' edited '.$case||$p['excerpt']!=='Own unsaved input '.$case||$p['media_ids']!==$wanted||$p['gallery_items']!==$wantedItems||$pages->publicPage($original['slug'])!==$original['published'])throw new RuntimeException('Exact own selection, metadata and original publication required.');
        if(count($p['document_links']??[])!==2)throw new RuntimeException('Two new own uploaded PDFs required.');
        foreach($p['document_links']as$i=>$link)if(file_get_contents($docs->file($link['id'])['path'])!==file_get_contents($f['files'][$i])||$docs->publicFile($link['id'])!==null)throw new RuntimeException('Exact private PDF originals required.');
    }
    echo "Five own edited pages keep exact selected order/captions/tags, ten new private PDFs and earlier publications; every previous original row and file unchanged.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-page-media-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'images'=>[],'pages'=>[],'files'=>[],'original_keys'=>[],'pixels'=>[]];
$source=$runtime.'/'.$prefix.'.png';$image=imagecreatetruecolor(32,24);imagefill($image,0,0,imagecolorallocate($image,35,100,180));imagepng($image,$source);unset($image);
$media=new Media($db,$access,$runtime.'/media');foreach(range(0,111)as$i)$f['images'][]=$media->upload($source,$prefix.'-'.sprintf('%03d',$i).'.png',$prefix.' Bild '.sprintf('%03d',$i).($i===111?' 100%_! <marker> Ä':''))['id'];
$f['selected']=array_reverse(array_slice($f['images'],0,100));$f['gallery_items']=array_map(fn($id,$i)=>['id'=>$id,'caption'=>'Own original caption '.$i,'tags'=>['Own original tag '.$i]],$f['selected'],array_keys($f['selected']));
foreach(['A','B']as$letter){$path=$runtime.'/'.$prefix.'-'.$letter.'.pdf';file_put_contents($path,ownWebsitePdf('Own picker PDF '.$prefix.' '.$letter));$f['files'][]=$path;}
$f['bad']=$runtime.'/'.$prefix.'-invalid.pdf';file_put_contents($f['bad'],'Own invalid new PDF fixture');
$pages=new Pages($db,$access);foreach(['320-js','390-js','820-js','844-js','390-nojs']as$case){$p=$pages->save(['title'=>$prefix.' original '.$case,'slug'=>$prefix.'-'.$case,'excerpt'=>'Own original excerpt','body_html'=>'<h2>Own original heading</h2>','media_ids'=>$f['selected'],'gallery_items'=>$f['gallery_items']]);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$case]=['id'=>$p['id'],'slug'=>$p['slug'],'published'=>$pages->publicPage($p['slug'])];}
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$values=Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM);if($table==='khcms_pages')$values=array_values(array_filter($values,fn($row)=>!in_array($row[0],array_column($f['pages'],'id'),true)));$f['original_keys'][$table]=['columns'=>$keys,'values'=>$values];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "112 new own images, five own published pages with 100 original selections and two PDF fixtures prepared; all previous original rows/revisions/files recorded.\n";
