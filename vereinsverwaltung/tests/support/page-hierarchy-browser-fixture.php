<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\{Pages, Media};
use Kulturhus\Native\Integration\ManagerBridge;

$config=TestSupport::config();
if (PHP_SAPI!=='cli' || ($config['owner']??'')!=='cloud-onboarding-synthetic'
    || !in_array($config['label']??'',['demo','integrated-manager'],true)) throw new RuntimeException('Owned synthetic hierarchy fixture required.');
$mode=$config['label']==='demo'?'standalone':'integrated';
$db=TestSupport::db();
$access=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');
$pages=new Pages($db,$access);
$directory=dirname(__DIR__,3).'/.local/browser-tests';
if(!is_dir($directory))mkdir($directory,0700,true);
$file=$directory.'/page-hierarchy-'.$mode.'.json';
if (($argv[1]??'')==='check') {
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    foreach($fixture['source'] as $key=>$original) {
        $current=$pages->get($original['id']);
        foreach(['id','slug','title','body','body_html','media_ids','gallery_items','template','custom_fields','news_channels','event_start','event_location','nav_visible'] as $field) {
            if(($original[$field]??null)!==($current[$field]??null))throw new RuntimeException('Original field changed: '.$key.' '.$field);
        }
        if($pages->publicPage($original['slug'])!==$fixture['public'][$key])throw new RuntimeException('Public original changed.');
        if($pages->get($fixture['children'][$key]['id'])!==$fixture['children'][$key])throw new RuntimeException('Descendant original changed.');
        if($current['parent_id']!==$fixture['right']['id'])throw new RuntimeException('Expected final saved placement missing.');
    }
    if($pages->generation()!==$fixture['generation'])throw new RuntimeException('Private placement invalidated public generation.');
    $media=new Media($db,$access,$fixture['media_root']);
    if(hash_file('sha256',$media->variant($fixture['photo'],'original')['path'])!==$fixture['photo_hash'])throw new RuntimeException('Original photo bytes changed.');
    echo "Saved hierarchy and exact original content, children, public projections and photo verified: $mode.\n";exit();
}
function hierarchySave(Pages $pages,array $input):array {
    $old=$pages->search([['field'=>'slug','value'=>$input['slug']]],1)[0]??null;
    if($old&&($old['title']!==$input['title']||$old['archived']))throw new RuntimeException('Owned hierarchy address occupied.');
    if($old)$input['id']=$old['id'];
    $page=$pages->save($input,$old['version']??0);
    return $pages->publish($page['id'],$page['version']);
}
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
$media=new Media($db,$access,$runtime.'/media');
$photo=null;
foreach($media->list() as $entry)if($entry['alt']==='Own hierarchy original pixels')$photo=$entry;
if(!$photo){
    $image=imagecreatetruecolor(120,80);imagefill($image,0,0,imagecolorallocate($image,19,85,60));imagepng($image,$runtime.'/hierarchy-pixels.png');imagedestroy($image);
    $photo=$media->upload($runtime.'/hierarchy-pixels.png','hierarchy.png','Own hierarchy original pixels');
}
$left=hierarchySave($pages,['title'=>'Hierarchy left original','slug'=>'own-hierarchy-left','nav_visible'=>false]);
$right=hierarchySave($pages,['title'=>'Hierarchy right original','slug'=>'own-hierarchy-right','nav_visible'=>false]);
$fixture=['left'=>$left,'right'=>$right,'source'=>[],'children'=>[],'public'=>[],'photo'=>$photo['id'],'media_root'=>$runtime.'/media','photo_hash'=>hash_file('sha256',$media->variant($photo['id'],'original')['path'])];
foreach(['website'=>'standard','news'=>'news','events'=>'event','media'=>'gallery'] as $view=>$template){
    $slug=match($view){'news'=>'aktuelles/own-hierarchy-news','media'=>'galerie/own-hierarchy-album',default=>'own-hierarchy-'.$view};
    $source=hierarchySave($pages,['title'=>'Hierarchy '.$view.' original','slug'=>$slug,'template'=>$template,'parent_id'=>$left['id'],'nav_order'=>10,
        'body_html'=>'<p>Exact <strong>hierarchy '.$view.' original 🎶</strong> &amp; 0</p>','news_date'=>$view==='news'?'2050-01-12':'',
        'event_start'=>$view==='events'?'2050-01-12T20:00':'','event_location'=>$view==='events'?'Original hall':'','nav_visible'=>false,
        'media_ids'=>[$photo['id']],'gallery_items'=>[['id'=>$photo['id'],'caption'=>'Original hierarchy caption','tags'=>['Original tag']]]]);
    $fixture['source'][$view]=$source;
    $fixture['children'][$view]=hierarchySave($pages,['title'=>'Hierarchy '.$view.' retained child','slug'=>'own-hierarchy-child-'.$view,'parent_id'=>$source['id'],'nav_visible'=>false]);
    $fixture['public'][$view]=$pages->publicPage($source['slug']);
}
$fixture['generation']=$pages->generation();
file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Own hierarchy originals prepared: $mode; no external transport.\n";
