<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\{Pages,Media,ContentSections};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic crop fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();$access=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');$pages=new Pages($db,$access);
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];$media=new Media($db,$access,$runtime.'/media');$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/section-crop-'.$mode.'.json';
if(($argv[1]??'')==='check'){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 foreach($f['pages']as $width=>$old){$current=$pages->get($old['id']);foreach(['body','body_html','title','slug','excerpt','media_ids','custom_fields','template_definition','field_definitions','nav_visible']as $key)if($current[$key]!==$old[$key])throw new RuntimeException('Unrelated page original changed.');
  if($pages->publicPage($old['slug'])!==$f['public'][$width])throw new RuntimeException('Public crop original changed.');
  foreach($current['content_sections']as $i=>$row){$expected=$width===390?($i===0?['aspect'=>'portrait','focal_x'=>100,'focal_y'=>50]:['aspect'=>'landscape','focal_x'=>50,'focal_y'=>100]):['aspect'=>$i===0?'landscape':'portrait','focal_x'=>50,'focal_y'=>50];foreach($expected as $key=>$value){if($row[$key]!==$value)throw new RuntimeException('Expected actual focus/crop missing.');unset($row[$key]);}if($row!==$old['content_sections'][$i])throw new RuntimeException('Original text, identity, photo or caption changed.');}
 }
 foreach($f['photos']as $photo)if(hash_file('sha256',$media->variant($photo['id'],'original')['path'])!==$photo['hash'])throw new RuntimeException('Original crop source pixels changed.');
 if($pages->generation()!==$f['generation'])throw new RuntimeException('Private crop invalidated public generation.');echo "Actual crop/focus and exact original page/section/public/pixel bytes verified: $mode.\n";exit;
}
$photos=[];
foreach(['horizontal','vertical']as $kind){$photo=null;foreach($media->list()as $entry)if($entry['alt']==='Own crop '.$kind.' original pixels')$photo=$entry;
 if(!$photo){$horizontal=$kind==='horizontal';$w=$horizontal?200:100;$h=$horizontal?100:200;$image=imagecreatetruecolor($w,$h);imagefilledrectangle($image,0,0,$w-1,$h-1,imagecolorallocate($image,20,20,240));imagefilledrectangle($image,0,0,$horizontal?99:$w-1,$horizontal?$h-1:99,imagecolorallocate($image,240,20,20));$path=$runtime.'/crop-'.$kind.'.png';imagepng($image,$path);unset($image);$photo=$media->upload($path,'crop-'.$kind.'.png','Own crop '.$kind.' original pixels');}
 $photos[$kind]=['id'=>$photo['id'],'hash'=>hash_file('sha256',$media->variant($photo['id'],'original')['path'])];
}
$f=['photos'=>$photos,'pages'=>[],'public'=>[]];
foreach([1440,820,390]as $width){$slug='own-section-crop-'.$width;$old=$pages->search([['field'=>'slug','value'=>$slug]],1)[0]??null;if($old&&($old['title']!=='Own section crop '.$width||$old['trashed']||$old['archived']))throw new RuntimeException('Owned crop address occupied.');$sections=[];
 foreach($photos as $kind=>$photo)$sections[]=['id'=>bin2hex(random_bytes(16)),'heading'=>'Original '.$kind.' '.$width,'body_html'=>'<p>Exact original '.$kind.' section 🎶</p>','layout'=>'image-before','media_id'=>$photo['id'],'alt'=>'Own authored '.$kind.' alt','caption'=>'Own exact caption <&>','credit'=>'Own original author <&>'];
 $p=$pages->save(['id'=>$old['id']??'','title'=>'Own section crop '.$width,'slug'=>$slug,'body_html'=>'<p>Exact original main body</p>','excerpt'=>'Exact original intro','nav_visible'=>false,'content_sections'=>ContentSections::normalize($sections)],$old['version']??0);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$width]=$p;$f['public'][$width]=$pages->publicPage($slug);
}
$f['generation']=$pages->generation();file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned synthetic pixel crop browser fixture prepared: $mode.\n";
