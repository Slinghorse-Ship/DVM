<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Articles,Media};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Own synthetic saved-news fixture required.');
$db=TestSupport::db();$file=dirname(__DIR__,3).'/.local/browser-tests/news-reading-'.($standalone?'standalone':'integrated').'.json';
$snapshot=static fn()=>ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_news_approvals','khcms_media','khcms_news_policy','khcms_news_policy_history']);
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if($f['database']!==$c['database'])throw new RuntimeException('Original fixture database changed.');
    if($standalone){
        foreach($f['items']as$item){$p=(new Articles($db,new Access($db,'reader')))->get($item['id']);if($p['version']!==2||$p['news_review']!=='approved')throw new RuntimeException('Expected only owned private review decisions.');}
        $hash=ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_news_approvals','khcms_media','khcms_news_policy','khcms_news_policy_history'],static fn($table)=>in_array($table,['khcms_pages','khcms_revisions','khcms_news_approvals'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($f['items']),'?')).')',array_column($f['items'],'id')]:['',[]]);
        if($hash!==$f['outside_originals'])throw new RuntimeException('An outside original changed.');
        foreach($f['items']as$item){$payload=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=1',[$item['id']])->fetchColumn();if(hash('sha256',$payload)!==$item['hash'])throw new RuntimeException('Original saved revision changed.');}
    }elseif($snapshot()!==$f['originals'])throw new RuntimeException('Reading changed an original.');
    foreach($f['files']as$path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Reading changed original image bytes.');
    if($standalone&&!$f['initial_publish'])Db::run($db,"DELETE FROM native_grants WHERE actor_id='reader' AND module='website' AND capability='publish'");
    echo "All saved originals and image bytes retained; owned demo grant restored.\n";exit;
}
if($standalone){$access=new Access($db,'admin');$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];$initialPublish=(bool)Db::run($db,"SELECT 1 FROM native_grants WHERE actor_id='reader' AND module='website' AND capability='publish'")->fetchColumn();Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader','website','publish')");$credentials=null;}
else{
    $access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime';$initialPublish=false;
    $suffix=bin2hex(random_bytes(6));$actor='reading-reader-'.$suffix;$email=$actor.'@example.invalid';
    Db::run($db,"INSERT INTO contacts(club_id,id,kind,name,email) VALUES('manager-demo',?,'person','Eigene lesende Beitragsprüfung',?)",[$actor,$email]);
    Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser,contact_id) VALUES(?,'manager-demo','member',0,?)",[$actor,$actor]);
    $settings=new Kulturhus\Verein\Settings($db,'manager-admin');$role=$settings->saveRole('',0,['name'=>'Eigene Beitragsleserechte '.$suffix,'description'=>'Synthetic reading fixture','members_access'=>'none','finance_access'=>'none','settings_access'=>false,'website_custom'=>true,'website_levels'=>array_fill_keys(array_keys(Kulturhus\Verein\EditorialPolicy::AREAS),'read')]);$settings->assignRole($actor,$role,0);
    $own=(new ManagerBridge($db,$actor,'native',static fn()=>null))->access();$own->require('website','read');
    foreach(['edit','publish']as$cap){try{$own->require('website',$cap);throw new RuntimeException('Reader received write rights.');}catch(DomainException){}}
    $auth=Kulturhus\Native\Auth\Runtime::service($db,$runtime.'/auth.key');$invite=$auth->createInvitation($access,ManagerBridge::actorKey($actor,'manager-demo'),$email);$password=bin2hex(random_bytes(18)).'aA9!';$auth->acceptInvitation($invite['token'],$password);$credentials=['email'=>$email,'password'=>$password];
}
$media=new Media($db,$access,$runtime.'/media');$images=[];$files=[];$suffix=bin2hex(random_bytes(5));
for($i=0;$i<12;$i++){$pixels=imagecreatetruecolor(320,200);imagefill($pixels,0,0,imagecolorallocate($pixels,($i*17)%256,($i*37)%256,($i*73)%256));$source=$runtime.'/own-reading-'.$suffix.'-'.$i.'.png';imagepng($pixels,$source);imagedestroy($pixels);$image=$media->upload($source,'own-reading-'.$suffix.'-'.$i.'.png','Gespeichertes eigenes Foto '.$i.' & <Kunst>');unlink($source);$images[]=$image['id'];foreach(['medium','large']as$size){$variant=$media->variant($image['id'],$size);$files[$variant['path']]=hash_file('sha256',$variant['path']);}}
$articles=new Articles($db,$access);$items=[];$body=str_repeat('<p>Ursprünglicher gespeicherter Absatz mit <strong>echter Formatierung</strong> für die vollständige Prüfung.</p>',80);
foreach([1440,820,390,320,390]as$i=>$width){$p=$articles->save(['title'=>'Gespeicherte Beitragsfassung '.$suffix.' & <Original>','slug'=>'aktuelles/reading-'.$suffix.'-'.$i,'news_date'=>'2050-02-03','excerpt'=>'Ursprünglicher Kurztext','body_html'=>$body,'news_channels'=>['rss'],'media_ids'=>$images,'seo_title'=>'Ursprünglicher SEO-Titel','seo_description'=>'Ursprüngliche Suchmaschinenbeschreibung','nav_visible'=>false,'nav_label'=>'Ursprüngliches Menü','noindex'=>true]);$payload=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=1',[$p['id']])->fetchColumn();$items[]=['id'=>$p['id'],'slug'=>$p['slug'],'title'=>$p['title'],'hash'=>hash('sha256',$payload),'width'=>$width,'js'=>$i!==4];}
$ids=array_column($items,'id');$outside=ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_news_approvals','khcms_media','khcms_news_policy','khcms_news_policy_history'],static fn($table)=>in_array($table,['khcms_pages','khcms_revisions','khcms_news_approvals'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids]:['',[]]);
file_put_contents($file,json_encode(['database'=>$c['database'],'items'=>$items,'files'=>$files,'images'=>$images,'credentials'=>$credentials,'initial_publish'=>$initialPublish,'originals'=>$snapshot(),'outside_originals'=>$outside],JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Owned saved long-news and private-image fixtures prepared; no public or external action.\n";
