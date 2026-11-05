<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Media};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic configuration required.');
$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');$file=dirname(__DIR__,3).'/.local/browser-tests/album-browsing-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$e=$f['original_keys'][$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
$mode=$argv[1]??'prepare';if(!in_array($mode,['prepare','check'],true))throw new RuntimeException('Owned fixture mode required.');
if($mode==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original row or revision changed.');
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
 if(hash_file('sha256',$f['source'])!==$f['source_sha256'])throw new RuntimeException('An own source changed.');
 $media=new Media($db,$access,$runtime.'/media');foreach([...array_column($f['items'],'id'),...$f['choices']]as$id){$original=$media->variant($id,'original');$size=getimagesize($original['path']);if([$size[0],$size[1]]!==[48,32])throw new RuntimeException('An actual original photo geometry changed.');$image=imagecreatefromstring(file_get_contents($original['path']));$color=imagecolorsforindex($image,imagecolorat($image,24,16));unset($image);if([$color['red'],$color['green'],$color['blue']]!==[25,110,175])throw new RuntimeException('Actual original PNG pixels changed.');}
 foreach(array_slice($f['choices'],11,2)as$id)if($media->publicVariant($id,'thumb')!==null)throw new RuntimeException('Private attachment cannot publish an original photo.');
 $pages=new Pages($db,$access);$album=$pages->get($f['album']);$expected=[...$f['items'],...array_map(fn($id)=>['id'=>$id,'caption'=>$f['labels'][$id],'tags'=>[]],array_slice($f['choices'],11,2))];
 $expected[6]['caption']='Own changed seventh caption Ä';$expected[6]['tags']=['Own changed tag Ä'];
 if($album['version']!==$f['version']+2||$album['gallery_items']!==$expected||$album['media_ids']!==array_column($expected,'id')||$pages->publicPage($f['slug'])!==$f['publication'])throw new RuntimeException('Exact private append and caption revisions with unchanged original public album required.');
 echo "Actual complete bounded album views and two private revisions verified; every original photo/file/row/revision/publication and source retained.\n";exit;
}
$prefix='own-album-view-'.bin2hex(random_bytes(7));$source=$runtime.'/'.$prefix.'-source.png';$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,25,110,175));imagepng($image,$source);unset($image);
$media=new Media($db,$access,$runtime.'/media');$items=[];$choices=[];$labels=[];
for($i=0;$i<31;$i++){$label=$prefix.($i<18?' original ':' choice ').sprintf('%02d',$i<18?$i:$i-18).' Ä';$asset=$media->upload($source,$label.'.png',$label);if($i<18)$items[]=['id'=>$asset['id'],'caption'=>$label,'tags'=>['Originaltag '.$i]];else{$choices[]=$asset['id'];$labels[$asset['id']]=$label;}}
$pages=new Pages($db,$access);$album=$pages->save(['title'=>$prefix.' exact album Ä','slug'=>'galerie/'.$prefix,'template'=>'gallery','body'=>'Own unchanged exact text Ä','media_ids'=>array_column($items,'id'),'gallery_items'=>$items]);$album=$pages->publish($album['id'],$album['version']);
$f=['prefix'=>$prefix,'album'=>$album['id'],'version'=>$album['version'],'slug'=>$album['slug'],'items'=>$items,'choices'=>$choices,'labels'=>$labels,'publication'=>$pages->publicPage($album['slug']),'source'=>$source,'source_sha256'=>hash_file('sha256',$source),'original_keys'=>[],'pixels'=>[]];
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original primary keys required.');$where=$table==='khcms_pages'?' WHERE id<>?':'';$args=$table==='khcms_pages'?[$album['id']]:[];$f['original_keys'][$table]=['columns'=>$keys,'values'=>Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll(PDO::FETCH_NUM)];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Own new original album and thirteen actual library choices prepared; every earlier row/revision/publication/file recorded.\n";
