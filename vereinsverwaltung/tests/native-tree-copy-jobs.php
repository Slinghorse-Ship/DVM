<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,DataModel,TreeCopies,TreeCopyJobs,Conflict};
use Kulturhus\Native\Web\Website\TreeCopyJobPanel;

$db=TestSupport::db();
if(in_array($argv[1]??'',['advance','complete'],true)){
    $service=new TreeCopyJobs($db,new Access($db,'admin'));
    if(isset($argv[4])){file_put_contents($argv[4].'.ready','ready');$until=microtime(true)+20;while(!is_file($argv[5])){if(microtime(true)>$until)exit(3);usleep(10000);}}
    try{$job=$argv[1]==='complete'?$service->complete($argv[2],(int)$argv[3],true):$service->advance($argv[2],(int)$argv[3]);echo json_encode(['status'=>$job['status'],'next'=>$job['next_index']]);}catch(Conflict){echo '{"status":"conflict"}';}
    exit;
}
TestSupport::seed($db);Schema::install($db);TreeCopyJobs::install($db);
$access=new Access($db,'admin');$pages=new Pages($db,$access);$jobs=new TreeCopyJobs($db,$access);$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $error){check($error instanceof $class,$label.' ('.$error::class.')');return;}throw new RuntimeException($label);}
function newCount(PDO $db,string $job):int{return (int)Db::run($db,'SELECT COUNT(*) FROM khcms_pages p JOIN khcms_tree_copy_items i ON i.copy_id=p.id WHERE i.job_id=?',[$job])->fetchColumn();}
function sourcesHash(PDO $db,string $job):string{return hash('sha256',json_encode(Db::run($db,'SELECT p.*,r.revision,r.payload_json,r.actor_id,r.action,r.created_at AS revision_time FROM khcms_pages p JOIN khcms_tree_copy_items i ON i.source_id=p.id JOIN khcms_revisions r ON r.page_id=p.id WHERE i.job_id=? ORDER BY p.id,r.revision',[$job])->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));}
function prepared(TreeCopyJobs $jobs,array $job):array{while($job['next_index']<$job['total']){$job=$jobs->advance($job['id'],$job['version']);if($job['status']!=='ready')throw new RuntimeException('Unexpected preparation pause: '.$job['last_reason']);}return $job;}

$model=new DataModel($db,$access);
$model->saveField(['key'=>'related','label'=>'Related page','type'=>'page-reference']);
$model->saveTemplate(['key'=>'copy_node','label'=>'Copy node','renderer'=>'standard','fields'=>['related']]);
$outside=$pages->save(['title'=>'Outside original','slug'=>'copy-job-outside']);
$root=$pages->save(['title'=>'Large original root','slug'=>'copy-job-original','template'=>'copy_node']);
$children=[];
for($i=0;$i<500;++$i)$children[]=$pages->save(['title'=>'Original child '.$i,'slug'=>'copy-job-original/node-'.$i,'template'=>'copy_node','parent_id'=>$root['id'],'body_html'=>'<p>Own original '.$i.'</p>']);
$root=$pages->save(array_replace($root,['custom_fields'=>['related'=>$children[499]['id']]]),$root['version']);
$children[0]=$pages->save(array_replace($children[0],['custom_fields'=>['related'=>$root['id']]]),$children[0]['version']);
$children[499]=$pages->save(array_replace($children[499],['custom_fields'=>['related'=>$outside['id']]]),$children[499]['version']);
rejects(fn()=>(new TreeCopies($db,$access))->create($root['id'],$root['version'],['title'=>'Too many immediate','slug'=>'copy-job-immediate']),InvalidArgumentException::class,'immediate path retains its bounded 500-page contract');
$job=$jobs->create($root['id'],$root['version'],['title'=>'Complete private tree','slug'=>'copy-job-large']);
check($job['total']===501&&$job['next_index']===0&&newCount($db,$job['id'])===0,'501-page selection is staged without creating any editable or public pages');
$original=sourcesHash($db,$job['id']);
rejects(fn()=>(new TreeCopyJobs($db,new Access($db,'reader')))->advance($job['id'],$job['version']),DomainException::class,'reader cannot advance another editor copy');
rejects(fn()=>(new TreeCopyJobs($db,new Access($db,'outsider')))->get($job['id']),RuntimeException::class,'other club cannot read job snapshots');
rejects(fn()=>$jobs->advance($job['id'],$job['version'],26),InvalidArgumentException::class,'step size is capped before any progress');
rejects(fn()=>$jobs->complete($job['id'],$job['version'],false),InvalidArgumentException::class,'final insertion requires explicit confirmation');
rejects(fn()=>$jobs->complete($job['id'],$job['version'],true),Conflict::class,'partially prepared selection cannot be finalized');
$first=$jobs->advance($job['id'],$job['version']);
check($first['next_index']===25&&newCount($db,$job['id'])===0,'one checkpoint prepares at most 25 and exposes no incomplete tree');
rejects(fn()=>$jobs->advance($job['id'],$job['version']),Conflict::class,'lost response replay with old version cannot duplicate progress');
$job=prepared(new TreeCopyJobs($db,new Access($db,'admin')),$first);
check($job['next_index']===501&&sourcesHash($db,$job['id'])===$original&&newCount($db,$job['id'])===0,'a new service instance resumes exact original selection through every checkpoint');
$panel=(new TreeCopyJobPanel($db,$access,'own-csrf'))->render(['copy_job'=>$job['id']]);
check(strlen($panel)<12000&&str_contains($panel,'501 von 501')&&str_contains($panel,'copy_confirm')&&!str_contains($panel,'Original child 499'),'progress workspace remains bounded instead of rendering the complete tree');

// A separate reader must observe either no copy or the entire complete tree, never an intermediate prefix.
$proc=proc_open([...TestSupport::phpCommand(),__FILE__,'complete',$job['id'],(string)$job['version']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc))throw new RuntimeException('Copy completion worker unavailable');fclose($pipes[0]);$observed=[];
do{$observed[]=newCount($db,$job['id']);$running=proc_get_status($proc)['running'];if($running)usleep(5000);}while($running);
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
check(in_array($exit,[0,-1],true)&&$errors===''&&json_decode($output,true,512,JSON_THROW_ON_ERROR)['status']==='completed','actual completion worker validates and commits the entire 501-page tree');
check(!array_diff(array_unique($observed),[0,501])&&newCount($db,$job['id'])===501,'independent reader never sees partially inserted or unvalidated copies');
$job=$jobs->get($job['id']);$map=array_column(Db::run($db,'SELECT source_id,copy_id FROM khcms_tree_copy_items WHERE job_id=?',[$job['id']])->fetchAll(PDO::FETCH_ASSOC),'copy_id','source_id');
$copy=$pages->get($map[$root['id']]);$firstCopy=$pages->get($map[$children[0]['id']]);$lastCopy=$pages->get($map[$children[499]['id']]);
check($copy['custom_fields']['related']===$map[$children[499]['id']]&&$firstCopy['custom_fields']['related']===$copy['id'],'forward and backward references resolve to new private IDs');
check($lastCopy['custom_fields']['related']===$outside['id']&&$lastCopy['parent_id']===$copy['id'],'outside original reference is retained and hierarchy points to copied root');
check(!$copy['nav_visible']&&$copy['published_revision']===null&&$pages->publicPage($copy['slug'])===null,'completed tree stays private and outside public navigation');
check(sourcesHash($db,$job['id'])===$original,'all source rows and historical revision bytes remain exact after final copy');
rejects(fn()=>$jobs->complete($job['id'],$job['version'],true),Conflict::class,'completed job cannot insert a second tree');

// A late failure after preceding rows were inserted must roll the entire final tree back.
$smallRoot=$pages->save(['title'=>'Atomic rollback root','slug'=>'copy-job-small-root','template'=>'copy_node']);
$smallChild=$pages->save(['title'=>'Atomic rollback child','slug'=>'copy-job-small-child','template'=>'copy_node','parent_id'=>$smallRoot['id']]);
$failed=prepared($jobs,$jobs->create($smallRoot['id'],$smallRoot['version'],['title'=>'Late failure copy','slug'=>'copy-job-late-failure']));
$last=Db::run($db,'SELECT * FROM khcms_tree_copy_items WHERE job_id=? ORDER BY item_index DESC LIMIT 1',[$failed['id']])->fetch(PDO::FETCH_ASSOC);
// Own synthetic staging corruption emulates an interrupted/invalid retained input, not a production source edit.
$raw=json_decode($last['source_json'],true,512,JSON_THROW_ON_ERROR);$raw['parent_id']=str_repeat('f',32);
Db::run($db,'UPDATE khcms_tree_copy_items SET source_json=? WHERE job_id=? AND item_index=?',[json_encode($raw,JSON_THROW_ON_ERROR),$failed['id'],$last['item_index']]);
$failed=$jobs->complete($failed['id'],$failed['version'],true);
check($failed['status']==='paused'&&newCount($db,$failed['id'])===0,'late reference validation rolls back every preceding inserted page and records one resumable failure');
check($pages->get($smallRoot['id'])===$smallRoot&&$pages->get($smallChild['id'])===$smallChild,'failed completion leaves both source originals unchanged');
$failed=$jobs->cancel($failed['id'],$failed['version']);
check($failed['status']==='cancelled'&&newCount($db,$failed['id'])===0,'cancelling failed staging has no partial pages to clean up');

$changed=$jobs->create($smallRoot['id'],$smallRoot['version'],['title'=>'Changed source copy','slug'=>'copy-job-changed']);
$smallChild=$pages->save(array_replace($smallChild,['title'=>'Explicit later source edit']),$smallChild['version']);
$changed=$jobs->advance($changed['id'],$changed['version']);
check($changed['status']==='paused'&&$changed['next_index']===0&&newCount($db,$changed['id'])===0,'a changed selected source pauses a whole preparation checkpoint without silently accepting later edits');
$jobs->cancel($changed['id'],$changed['version']);
$changed=$jobs->create($smallRoot['id'],$smallRoot['version'],['title'=>'Changed model copy','slug'=>'copy-job-model']);
$field=$model->field('related');$model->saveField($field,$field['version']);
$changed=$jobs->advance($changed['id'],$changed['version']);
check($changed['status']==='paused'&&$changed['next_index']===0&&str_contains($changed['last_reason'],'Inhaltsmodell'),'model change pauses before prepared content is silently reinterpreted');

$plain=$pages->save(['title'=>'Concurrent checkpoint source','slug'=>'copy-job-race']);
$race=$jobs->create($plain['id'],$plain['version'],['title'=>'Race private copy','slug'=>'copy-job-race-copy']);
$dir=dirname(getenv('KH_NATIVE_TEST_CONFIG'));$barrier=$dir.'/copy-job-go';$workers=[];
foreach([0,1]as $worker){$ready=$dir.'/copy-job-'.$worker;$proc=proc_open([...TestSupport::phpCommand(),__FILE__,'advance',$race['id'],(string)$race['version'],$ready,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Checkpoint worker unavailable');fclose($pipes[0]);$workers[]=[$proc,$pipes,$ready];}
$until=microtime(true)+20;while(!is_file($workers[0][2].'.ready')||!is_file($workers[1][2].'.ready')){if(microtime(true)>$until)throw new RuntimeException('Checkpoint setup timeout');usleep(10000);}file_put_contents($barrier,'go');$outcomes=[];
foreach($workers as[$proc,$pipes,$ready]){$outcomes[]=json_decode(stream_get_contents($pipes[1]),true,512,JSON_THROW_ON_ERROR)['status'];$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$error==='','actual checkpoint worker exits cleanly');unlink($ready.'.ready');}unlink($barrier);sort($outcomes);
check($outcomes===['conflict','ready']&&$jobs->get($race['id'])['next_index']===1&&newCount($db,$race['id'])===0,'two workers with the same expected version cannot both advance or materialize a copy');

$publishedRoot=$pages->save(['title'=>'Published hierarchy original','slug'=>'copy-job-published-root']);$publishedRoot=$pages->publish($publishedRoot['id'],$publishedRoot['version']);
$publishedChild=$pages->save(['title'=>'Published hierarchy child','slug'=>'copy-job-published-child','parent_id'=>$publishedRoot['id'],'body_html'=>'<p>Actual public original</p>']);$publishedChild=$pages->publish($publishedChild['id'],$publishedChild['version']);
$publishedChild=$pages->save(array_replace($publishedChild,['parent_id'=>null,'body_html'=>'<p>Later private draft</p>']),$publishedChild['version']);
$published=$jobs->create($publishedRoot['id'],$publishedRoot['version'],['title'=>'Published hierarchy copy','slug'=>'copy-job-published-copy','basis'=>'published']);
check($published['total']===2,'published selection follows actual published hierarchy despite later draft parent changes');
$published=prepared($jobs,$published);$published=$jobs->complete($published['id'],$published['version'],true);
$publishedMap=array_column(Db::run($db,'SELECT source_id,copy_id FROM khcms_tree_copy_items WHERE job_id=?',[$published['id']])->fetchAll(PDO::FETCH_ASSOC),'copy_id','source_id');
$publishedCopy=$pages->get($publishedMap[$publishedChild['id']]);
check($published['status']==='completed'&&$publishedCopy['parent_id']===$publishedMap[$publishedRoot['id']]&&$publishedCopy['body_html']==='<p>Actual public original</p>','published snapshot copies exact public content and maps its own saved parent');
check($pages->get($publishedChild['id'])===$publishedChild&&$pages->publicPage($publishedChild['published_slug'])['body_html']==='<p>Actual public original</p>','public original and later private draft both remain unchanged');

// Stored validated fixture payloads exercise the actual upper boundary without repeating editor setup 5000 times.
$boundaryRoot=$pages->save(['title'=>'Own 5000-page boundary root','slug'=>'copy-job-boundary-root']);
$blueprint=$model->normalizePage(['title'=>'Own boundary child','slug'=>'copy-job-boundary/child','parent_id'=>$boundaryRoot['id']]);
Db::atomic($db,function()use($db,$blueprint,$access):void{
    $club=$access->club();$rows=[];$revisions=[];
    $flush=static function(array $rows,array $revisions)use($db):void{
        Db::run($db,'INSERT INTO khcms_pages(id,club_id,slug,version,draft_json,created_by,updated_by,created_at,updated_at) VALUES '.implode(',',array_fill(0,count($rows),'(?,?,?,1,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')),array_merge(...$rows));
        Db::run($db,"INSERT INTO khcms_revisions(page_id,revision,club_id,payload_json,actor_id,action,created_at) VALUES ".implode(',',array_fill(0,count($revisions),"(?,1,?,?,'admin','create',UTC_TIMESTAMP(6))")),array_merge(...$revisions));
    };
    for($index=0;$index<4999;++$index){
        $id=bin2hex(random_bytes(16));$payload=$blueprint;$payload['title']='Own boundary child '.$index;$payload['slug']='copy-job-boundary/child-'.$index;$json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $rows[]=[$id,$club,$payload['slug'],$json,'admin','admin'];$revisions[]=[$id,$club,$json];
        if(count($rows)===100){$flush($rows,$revisions);$rows=[];$revisions=[];}
    }
    if($rows)$flush($rows,$revisions);
});
$boundary=$jobs->create($boundaryRoot['id'],$boundaryRoot['version'],['title'=>'Own boundary private copy','slug'=>'copy-job-boundary-copy']);
check($boundary['total']===5000&&newCount($db,$boundary['id'])===0,'the actual 5000-node boundary stages complete selection without any visible page insertion');
$boundary=$jobs->cancel($boundary['id'],$boundary['version']);
check($boundary['status']==='cancelled'&&newCount($db,$boundary['id'])===0,'cancelling a 5000-node preparation leaves no partial tree');
$pages->save(['title'=>'Own over-boundary child','slug'=>'copy-job-boundary/over','parent_id'=>$boundaryRoot['id']]);
$beforeJobs=(int)$db->query('SELECT COUNT(*) FROM khcms_tree_copy_jobs')->fetchColumn();
rejects(fn()=>$jobs->create($boundaryRoot['id'],$boundaryRoot['version'],['title'=>'Own excessive copy','slug'=>'copy-job-boundary-over']),InvalidArgumentException::class,'an actual 5001-node selection fails before staging or page changes');
check((int)$db->query('SELECT COUNT(*) FROM khcms_tree_copy_jobs')->fetchColumn()===$beforeJobs,'over-boundary selection cannot leave an orphaned job');
if(getenv('DVM_PAGE_LIFECYCLE_BROWSER')==='1'){require __DIR__.'/support/page-lifecycle-browser-fixture.php';ownPageLifecycleBrowser($db);}
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
