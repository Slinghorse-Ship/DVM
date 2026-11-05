<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,PageTrash,Media};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();
if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic trash fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();
$a=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');
$pages=new Pages($db,$a);$trash=new PageTrash($db,$a);
$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/page-trash-'.$mode.'.json';
if(($argv[1]??'')==='check'){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 foreach($f['source']as $original){
  $page=$pages->get($original['id']);
  if($page['trashed']||$page['published_revision']!==null)throw new RuntimeException('Expected final private restored original missing.');
  if(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$page['id']])->fetchColumn()!==$f['json'][$page['id']])throw new RuntimeException('Exact saved original content changed.');
  foreach($f['revisions'][$page['id']] as $revision=>$json)if(Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=?',[$page['id'],$revision])->fetchColumn()!==$json)throw new RuntimeException('Historical original bytes changed.');
 }
 $media=new Media($db,$a,$f['media_root']);if(hash_file('sha256',$media->variant($f['photo'],'original')['path'])!==$f['photo_hash'])throw new RuntimeException('Original photo changed.');
 if($pages->get($f['parent']['id'])!==$f['parent']||$pages->get($f['child']['id'])!==$f['child'])throw new RuntimeException('Blocked dependency original changed.');
 echo "Private restorations, exact saved/history/photo bytes and blocked dependency originals verified: $mode.\n";exit();
}
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];
$media=new Media($db,$a,$runtime.'/media');$photo=null;
foreach($media->list()as $m)if($m['alt']==='Own trash original pixels')$photo=$m;
if(!$photo){$im=imagecreatetruecolor(120,80);imagefill($im,0,0,imagecolorallocate($im,73,91,120));imagepng($im,$runtime.'/trash-pixels.png');imagedestroy($im);$photo=$media->upload($runtime.'/trash-pixels.png','trash.png','Own trash original pixels');}
function trashOriginal(Pages $pages,PageTrash $trash,PDO $db,Access $a,array $input):array{
 $id=Db::run($db,'SELECT id FROM khcms_pages WHERE club_id=? AND slug=?',[$a->club(),$input['slug']])->fetchColumn();
 $old=$id?$pages->get($id):null;
 if($old){if($old['title']!==$input['title'])throw new RuntimeException('Synthetic original address occupied.');if($old['trashed'])$old=$trash->restore($old['id'],$old['version']);$input['id']=$old['id'];}
 $p=$pages->save($input,$old['version']??0);return $pages->publish($p['id'],$p['version']);
}
$f=['source'=>[],'json'=>[],'revisions'=>[],'photo'=>$photo['id'],'media_root'=>$runtime.'/media','photo_hash'=>hash_file('sha256',$media->variant($photo['id'],'original')['path'])];
foreach(['website'=>'standard','news'=>'news','events'=>'event','media'=>'gallery'] as $view=>$template){
 $slug=match($view){'news'=>'aktuelles/own-trash-news','media'=>'galerie/own-trash-album',default=>'own-trash-'.$view};
 $p=trashOriginal($pages,$trash,$db,$a,['title'=>'Trash '.$view.' original','slug'=>$slug,'template'=>$template,'body_html'=>'<p>Exact <strong>trash '.$view.' original 🎶</strong> &amp; 0</p>','nav_visible'=>false,'media_ids'=>[$photo['id']],
 'gallery_items'=>[['id'=>$photo['id'],'caption'=>'Trash original caption','tags'=>['Original']]],'news_date'=>$view==='news'?'2051-01-12':'','event_start'=>$view==='events'?'2051-01-12T20:00':'','event_location'=>$view==='events'?'Original hall':'']);
 $f['source'][$view]=$p;$f['json'][$p['id']]=Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$p['id']])->fetchColumn();$f['revisions'][$p['id']]=Db::run($db,'SELECT revision,payload_json FROM khcms_revisions WHERE page_id=?',[$p['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
}
$f['parent']=trashOriginal($pages,$trash,$db,$a,['title'=>'Trash blocked parent original','slug'=>'own-trash-parent','nav_visible'=>false]);
$f['child']=trashOriginal($pages,$trash,$db,$a,['title'=>'Trash retained child original','slug'=>'own-trash-child','parent_id'=>$f['parent']['id'],'nav_visible'=>false]);
file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Own trash originals prepared: $mode; no external transport.\n";
