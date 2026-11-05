<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity};
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Communication\{Workspace,Images,Schema,CanvasDocument};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic retouch fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();Schema::install($db);$access=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');$work=new Workspace($db,$access,'social');
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];$media=new Media($db,$access,$runtime.'/media');$images=new Images($db,$access,$runtime.'/media');$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/photo-clones-'.$mode.'.json';
if(($argv[1]??'')==='check'){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 foreach($f['items']as $width=>$original){$current=$work->get($original['id']);if($current!==$original)throw new RuntimeException('Retouch changed its original editorial content or approval.');$history=$images->history($current['id']);$found=false;
  foreach($history as $record){$meta=json_decode($record['metadata_json'],true,32,JSON_THROW_ON_ERROR);if(!empty($meta['document']['layers'][0]['clone_stamps'])){if(CanvasDocument::validate($meta['document'],'post')!==CanvasDocument::validate($f['expected'],'post'))throw new RuntimeException('Retouch does not match exact editable original and stamp.');$found=true;}}
  if(!$found)throw new RuntimeException('Saved manual clone missing.');if(hash_file('sha256',$images->file($f['original_images'][$width]['id'])['path'])!==$f['original_images'][$width]['hash'])throw new RuntimeException('Earlier approved artwork pixels changed.');
 }
 foreach($f['photo_hashes']as $variant=>$hash)if(hash_file('sha256',$media->variant($f['photo'],$variant)['path'])!==$hash)throw new RuntimeException('Original photo variant changed.');
 if((int)Db::run($db,'SELECT COUNT(*) FROM native_delivery_jobs WHERE club_id=?',[$access->club()])->fetchColumn()!==$f['jobs'])throw new RuntimeException('Retouch created a delivery job.');echo "Exact saved retouch, original editorial approvals, earlier artwork and every photo variant verified: $mode.\n";exit;
}
$photo=null;foreach($media->list()as $entry)if($entry['alt']==='Own manual clone original red-blue pixels')$photo=$entry;
if(!$photo){$source=imagecreatetruecolor(200,100);imagefilledrectangle($source,0,0,99,99,imagecolorallocate($source,240,20,20));imagefilledrectangle($source,100,0,199,99,imagecolorallocate($source,20,20,240));imagepng($source,$runtime.'/clone-original.png');unset($source);$photo=$media->upload($runtime.'/clone-original.png','clone-original.png','Own manual clone original red-blue pixels');}
$identityService=new Identity($db,$access);$identity=$identityService->get();
if($mode==='standalone'&&$identity['version']===0)$identity=$identityService->save(['name'=>'Owned synthetic retouch club','origin'=>'https://example.invalid','logo_id'=>'','address'=>''],0);
if($identity['name']===''||$identity['origin']==='')throw new RuntimeException('Existing owned synthetic club identity required.');
$document=CanvasDocument::validate(['schema'=>1,'width'=>1080,'height'=>1350,'background'=>'#163f33','layers'=>[['id'=>'own-clone-photo','type'=>'image','x'=>50,'y'=>100,'width'=>800,'height'=>400,'media_ref'=>$photo['id'],'fit'=>'contain']]],'post');$expected=$document;$expected['layers'][0]['clone_stamps']=[['source_x'=>25,'source_y'=>50,'target_x'=>75,'target_y'=>50,'radius'=>20,'softness'=>50]];$f=['photo'=>$photo['id'],'photo_hashes'=>[],'items'=>[],'original_images'=>[],'expected'=>json_decode(json_encode($expected),true)];
foreach(['original','large','medium','thumb']as $variant)$f['photo_hashes'][$variant]=hash_file('sha256',$media->variant($photo['id'],$variant)['path']);
$fresh='own-clone-'.bin2hex(random_bytes(7)).' ';
foreach([1440,820,390]as $width){$title=$fresh.'Own manual clone composition '.$width;$old=null;foreach($work->list()as $entry)if($entry['title']===$title)$old=$entry;$item=$work->save(['id'=>$old['id']??'','title'=>$title,'body'=>'Exact original social content 🎶','summary'=>'Exact original summary','kind'=>'post','channels'=>['instagram'],'destination_url'=>'https://example.invalid/own-clone'], $old['version']??0);
 $canvas=imagecreatetruecolor(1080,1350);imagefill($canvas,0,0,imagecolorallocate($canvas,22,63,51));$source=imagecreatefromstring(file_get_contents($media->variant($photo['id'],'large')['path']));imagecopyresampled($canvas,$source,50,100,0,0,800,400,imagesx($source),imagesy($source));imagepng($canvas,$runtime.'/clone-base.png');unset($canvas,$source);$id=$images->saveCanvas($item['id'],$item['version'],$identity['version'],0,$document,$runtime.'/clone-base.png');$item=$work->approve($item['id'],$item['version']);$f['items'][$width]=$item;$f['original_images'][$width]=['id'=>$id,'hash'=>hash_file('sha256',$images->file($id)['path'])];
}
$f['jobs']=(int)Db::run($db,'SELECT COUNT(*) FROM native_delivery_jobs WHERE club_id=?',[$access->club()])->fetchColumn();file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned synthetic original-bound clone browser fixture prepared: $mode.\n";
