<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity};
use Kulturhus\Native\Communication\{Workspace,Images,StoryHandoff,PublicationSnapshot};
use Kulturhus\Native\Installer\SchemaRegistry;
use Kulturhus\Native\Web\Manager;

$db=TestSupport::db();SchemaRegistry::install($db);TestSupport::seed($db);
$access=new Access($db,'admin');$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/story-handoff-'.TestSupport::config()['database'];mkdir($root,0700);
$media=$root.'/media';mkdir($media,0700);$n=0;
function handoffCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function handoffReject(callable $operation,string $label):void {try{$operation();}catch(DomainException){handoffCheck(true,$label);return;}throw new RuntimeException($label);}
try {
    $identity=new Identity($db,$access);$identity->save(['name'=>'Synthetischer Kulturverein','origin'=>'https://example.invalid','address'=>'Testweg 1'],0);
    $work=new Workspace($db,$access,'social');$images=new Images($db,$access,$media);$service=new StoryHandoff($db,$access,$media);
    $story=$work->save(['title'=>'Kulturabend','body'=>'Unsere Story','kind'=>'story','story_link_requested'=>true,'destination_url'=>'https://example.invalid/kulturabend','channels'=>['instagram','facebook'],'story'=>[['title'=>'Kulturabend','text'=>'Gemeinsam erleben','media_ref'=>''],['title'=>'Einladung','text'=>'Komm vorbei','media_ref'=>'']]]);
    handoffReject(fn()=>$service->prepare($story['id'],$story['version']),'unapproved manual story cannot be prepared');
    $ids=$images->render($story['id']);$story=$work->approve($story['id'],$story['version']);
    $rows=$service->prepare($story['id'],$story['version']);
    handoffCheck(count($rows)===2&&array_unique(array_column($rows,'status'))===['prepared'],'one manual app handoff per approved channel');
    handoffCheck(array_column($rows[0]['snapshot']['images'],'id')===$ids&&$rows[0]['snapshot']['destination_url']==='https://example.invalid/kulturabend','handoff freezes exact approved slide sequence and desired link');
    handoffCheck(count($service->prepare($story['id'],$story['version']))===2&&(int)$db->query('SELECT COUNT(*) FROM native_social_handoff_events')->fetchColumn()===2,'repeated preparation is idempotent with no duplicate audit entry');
    handoffCheck((int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_image_capabilities')->fetchColumn()===0,'manual handoff never creates transport jobs or public image capabilities');
    handoffReject(fn()=>(new StoryHandoff($db,new Access($db,'editor'),$media))->prepare($story['id'],$story['version']),'editing rights cannot prepare a publication');
    handoffReject(fn()=>(new StoryHandoff($db,new Access($db,'outsider'),$media))->history($story['id']),'handoff history remains within the club');
    $instagram=array_values(array_filter($rows,fn($r)=>$r['channel']==='instagram'))[0];$facebook=array_values(array_filter($rows,fn($r)=>$r['channel']==='facebook'))[0];
    $checks=['images_checked'=>true,'link_added'=>true,'published'=>true];
    handoffReject(fn()=>$service->complete($instagram['id'],1,$checks+['extra'=>true],'https://instagram.com.evil.invalid/story/123'),'provider lookalike cannot be recorded as a story reference');
    handoffReject(fn()=>$service->complete($instagram['id'],1,array_replace($checks,['link_added'=>false])),'missing explicit link sticker confirmation refuses completion');
    handoffReject(fn()=>$service->complete($instagram['id'],2,$checks),'stale handoff version refuses completion');
    Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
    handoffReject(fn()=>$service->complete($instagram['id'],1,$checks),'revoked publishing rights are checked at completion');
    Db::run($db,"INSERT INTO native_grants VALUES('admin','social','publish')");
    $imagePath=$images->file($ids[0])['path'];$original=file_get_contents($imagePath);file_put_contents($imagePath,'changed');
    handoffReject(fn()=>$service->complete($instagram['id'],1,$checks),'changed actual approved image bytes prevent completion');file_put_contents($imagePath,$original);
    $manager=new Manager($db,$access,$media,'synthetic-csrf');
    handoffReject(fn()=>$manager->action('social',['operation'=>'story-handoff-complete','id'=>$story['id'],'handoff_id'=>$instagram['id'],'handoff_version'=>1,'csrf'=>'wrong'],[]),'handoff UI mutations require the existing CSRF guard');
    $html=$manager->main('social',$story['id']);$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    handoffCheck($xpath->query('//article[@data-handoff]')->length===2&&str_contains($html,'Zieladresse zum Kopieren')&&str_contains($html,'Manuelle Veröffentlichung bestätigen'),'actual social editor exposes separate guided app steps per channel');
    handoffCheck($xpath->query('//input[@name="operation" and @value="communication-schedule"]')->length===0&&$xpath->query('//form//form')->length===0,'manual link story has valid forms and no unusable API scheduling action');
    $readonly=(new Manager($db,new Access($db,'reader'),$media,'synthetic-csrf'))->main('social',$story['id']);
    handoffCheck(!str_contains($readonly,'name="operation" value="story-handoff-complete"'),'read-only UI cannot confirm a manual publication');
    $manager->action('social',['csrf'=>'synthetic-csrf','operation'=>'story-handoff-complete','id'=>$story['id'],'handoff_id'=>$instagram['id'],'handoff_version'=>1,'images_checked'=>'on','link_added'=>'on','published'=>'on','reference_url'=>'https://www.instagram.com/stories/synthetic/123/'],[]);
    $confirmed=array_values(array_filter($service->history($story['id']),fn($r)=>$r['id']===$instagram['id']))[0];
    handoffCheck($confirmed['status']==='confirmed'&&$confirmed['version']===2&&$confirmed['confirmed_at']!==null,'actual manager action records one manual confirmation');
    handoffCheck($service->events($instagram['id'])[1]['action']==='manually_confirmed'&&str_contains($manager->main('social',$story['id']),'Manuell bestätigt'),'history labels the operator assertion accurately');
    handoffReject(fn()=>$service->complete($instagram['id'],2,$checks),'confirmed publication cannot be recorded twice');
    $changed=$work->save(['id'=>$story['id'],'title'=>'Neuer Kulturabend'],$story['version']);
    handoffReject(fn()=>$service->complete($facebook['id'],1,$checks),'an edited item invalidates the old uncompleted handoff');
    $service->cancel($facebook['id'],1,'Neue Fassung wird separat vorbereitet.');
    handoffCheck($service->events($facebook['id'])[1]['action']==='cancelled','stale handoff can be explicitly cancelled with an audit reason');
    $images->render($changed['id']);$changed=$work->approve($changed['id'],$changed['version']);$new=$service->prepare($changed['id'],$changed['version']);
    handoffCheck(count($new)===4&&count(array_filter($new,fn($r)=>$r['item_version']===$changed['version']))===2,'new approval gets new handoffs while preserving old confirmations');
    handoffCheck((int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===0,'complete and cancel still create no provider sends');
    $db->exec("UPDATE native_schema_versions SET version=1 WHERE module='communication'");SchemaRegistry::install($db);SchemaRegistry::assertCurrent($db);
    handoffCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===SchemaRegistry::VERSIONS['communication']&&(int)$db->query('SELECT COUNT(*) FROM native_social_handoffs')->fetchColumn()===4,'common schema upgrade is idempotent and preserves existing handoff records');
    $db->exec("UPDATE native_schema_versions SET version=99 WHERE module='communication'");
    handoffReject(fn()=>SchemaRegistry::install($db),'future schema versions refuse downgrade');
    echo "$n native story handoff checks passed; real images and MySQL, no provider calls.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
