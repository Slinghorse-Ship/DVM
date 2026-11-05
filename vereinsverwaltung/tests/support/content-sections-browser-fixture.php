<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Media,ContentSections};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic sections fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();$access=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');$pages=new Pages($db,$access);
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];$media=new Media($db,$access,$runtime.'/media');$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/content-sections-'.$mode.'.json';
if(($argv[1]??'')==='check'){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 foreach($f['pages']as $width=>$original){$current=$pages->get($original['id']);foreach(['body','body_html','title','slug','excerpt','template_definition','field_definitions','custom_fields','media_ids','gallery_items','nav_visible']as $key)if($current[$key]!==$original[$key])throw new RuntimeException('Unrelated page original changed: '.$key);
  if($pages->publicPage($original['slug'])!==$f['public'][$width])throw new RuntimeException('Published section original changed.');
  if(count($current['content_sections'])!==2||array_column($current['content_sections'],'id')!==array_reverse(array_column($original['content_sections'],'id')))throw new RuntimeException('Expected original section IDs and final order missing.');
  foreach($current['content_sections']as $row){$old=array_values(array_filter($original['content_sections'],fn($item)=>$item['id']===$row['id']))[0];unset($row['heading'],$old['heading']);if($row!==$old)throw new RuntimeException('Section text, photo or authored metadata changed.');}
 }
 if($pages->generation()!==$f['generation']||hash_file('sha256',$media->variant($f['photo'],'original')['path'])!==$f['photo_hash'])throw new RuntimeException('Private sections changed public generation or photo bytes.');
 echo "Saved section order and exact page/text/metadata/public/photo originals verified: $mode.\n";exit;
}
$photo=null;foreach($media->list()as $image)if($image['alt']==='Own content sections original pixels')$photo=$image;
if(!$photo){$image=imagecreatetruecolor(100,70);imagefill($image,0,0,imagecolorallocate($image,60,120,80));imagepng($image,$runtime.'/content-sections.png');unset($image);$photo=$media->upload($runtime.'/content-sections.png','sections.png','Own content sections original pixels');}
$f=['pages'=>[],'public'=>[],'photo'=>$photo['id'],'photo_hash'=>hash_file('sha256',$media->variant($photo['id'],'original')['path'])];
foreach([1440,820,390]as $width){
 $slug='own-content-sections-'.$width;$old=$pages->search([['field'=>'slug','value'=>$slug]],1)[0]??null;if($old&&($old['title']!=='Own content sections '.$width||$old['trashed']||$old['archived']))throw new RuntimeException('Owned sections address occupied.');
 $sections=ContentSections::normalize([['id'=>bin2hex(random_bytes(16)),'heading'=>'First original '.$width,'body_html'=>'<p>Exact original <strong>formatted section 🎶</strong> &amp; 0</p>','layout'=>'text'],['id'=>bin2hex(random_bytes(16)),'heading'=>'Second original '.$width,'body_html'=>'<p>Exact second original</p>','layout'=>'image-after','media_id'=>$photo['id'],'alt'=>'Own authored alt '.$width,'caption'=>'Own exact caption <&>','credit'=>'Own photographer <&>']]);
 $p=$pages->save(['id'=>$old['id']??'','title'=>'Own content sections '.$width,'slug'=>$slug,'body_html'=>'<p>Exact main original '.$width.' &amp; 0</p>','excerpt'=>'Exact original intro','nav_visible'=>false,'content_sections'=>$sections],$old['version']??0);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$width]=$p;$f['public'][$width]=$pages->publicPage($slug);
}
$f['generation']=$pages->generation();file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned synthetic section browser fixture prepared: $mode.\n";
