<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Integration\ManagerBridge;
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($config['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Own synthetic public search required.');
$mode=$config['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();$access=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');$pages=new Pages($db,$access);$club=$access->club();
$file=dirname(__DIR__,3).'/.local/browser-tests/public-search-'.$mode.'.json';
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','khcms_navigation','khcms_state','native_club_settings','khcms_website_design','native_users','native_grants','native_social_objects','native_social_attention','native_social_originals','native_social_inbox','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures'];
// The standalone demo has no integrated document schema or archive upgrade.
if($mode==='standalone')$tables=array_values(array_diff($tables,['native_social_originals','documents','document_files','document_links','dwf_document_versions','dwf_signatures']));
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==ownOriginalDatabaseHash($db,$tables))throw new RuntimeException('Search changed original content, account, media, document or job.');echo "Every exact original retained after actual public search browsing.\n";exit;}
$query='Eigene Browsersuche Ä🙂 literal.*';$ids=[];
for($i=0;$i<67;++$i){
    $slug='browser-public-search-'.$i;$row=$pages->publicPage($slug);
    if(!$row){if(Db::run($db,'SELECT id FROM khcms_pages WHERE club_id=? AND slug=?',[$club,$slug])->fetchColumn())throw new RuntimeException('An existing private search fixture must not be published.');$row=$pages->save(['title'=>'Synthetisches Suchoriginal '.$i.' & <Text>','slug'=>$slug,'body'=>$query.' ursprünglicher sichtbarer Inhalt '.str_repeat('ZeilenumbruchfreieZeichen',15),'nav_visible'=>false]);$row=$pages->publish($row['id'],$row['version']);}
    $ids[]=$row['id'];
}
$list=$pages->publicSearch($query);if($list['total']!==67)throw new RuntimeException('Unexpected original public fixture corpus.');$ordered=[];
for($page=1;$page<=4;++$page)array_push($ordered,...$pages->publicSearch($query,$page)['rows']);
file_put_contents($file,json_encode(['database'=>$config['database'],'query'=>$query,'rows'=>$ordered,'originals'=>ownOriginalDatabaseHash($db,$tables)],JSON_THROW_ON_ERROR),LOCK_EX);chmod($file,0600);echo "Own synthetic public search originals prepared for ".$mode."; no external publication.\n";
