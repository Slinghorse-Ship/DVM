<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,PageTrash,PageBatches,Conflict};
$db=TestSupport::db();
if(($argv[1]??'')==='race'){
    file_put_contents($argv[4].'.ready','ready');$until=microtime(true)+10;while(!is_file($argv[5])){if(microtime(true)>$until)exit(3);usleep(10000);}
    try{(new PageBatches($db,new Access($db,'admin')))->advance($argv[2],(int)$argv[3],1);echo 'saved';}catch(Conflict){echo 'conflict';}exit();
}
TestSupport::seed($db);Schema::install($db);PageBatches::install($db);
$a=new Access($db,'admin');$pages=new Pages($db,$a);$batches=new PageBatches($db,$a);$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $e){check($e instanceof $class,$label);return;}throw new RuntimeException($label);}
$items=[];$json=[];
for($i=0;$i<501;++$i){$p=$pages->save(['title'=>'Batch original '.$i,'slug'=>'batch-'.$i,'body'=>str_repeat('Original 🎶 0 ',100)]);$items[]=['id'=>$p['id'],'version'=>$p['version']];$json[$p['id']]=Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$p['id']])->fetchColumn();}
$job=$batches->createMatching('trash','Batch original');
check($job['total']===501&&$job['next_index']===0&&$pages->count()===501,'preparing a large batch freezes versions without changing originals');
$job=$batches->advance($job['id'],$job['version'],3);
check($job['next_index']===3&&$pages->count()===498,'bounded step commits only chosen originals and checkpoints');
$connection=TestSupport::db();$resumed=new PageBatches($connection,new Access($connection,'admin'));
$again=$resumed->get($job['id']);check($again===$job,'new connection resumes exact persisted progress after interrupted request');
rejects(fn()=>$resumed->advance($job['id'],1),Conflict::class,'old submitted checkpoint cannot repeat completed originals');
while($job['status']==='ready')$job=$resumed->advance($job['id'],$job['version']);
check($job['status']==='completed'&&$job['next_index']===501&&$pages->count()===0,'more than 500 originals finish over multiple durable bounded steps');
foreach($items as $item){$p=$pages->get($item['id']);if(!$p['trashed']||$p['version']!==2||Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$p['id']])->fetchColumn()!==$json[$p['id']])throw new RuntimeException('Original or exact-once retirement changed');}
check(true,'every large-batch original retains exact bytes and gains one lifecycle revision');
$restore=array_map(static fn($i)=>['id'=>$i['id'],'version'=>2],$items);
$job=(new PageBatches($db,new Access($db,'editor')))->create($restore,'restore');$job=$batches->advance($job['id'],$job['version'],2);
check($pages->count()===2&&$pages->get($restore[0]['id'])['published_revision']===null,'editor restoration batch returns only private originals');
$job=$batches->cancel($job['id'],$job['version']);
check($job['status']==='cancelled'&&$pages->count()===2&&$pages->get($restore[2]['id'])['trashed'],'cancel retains completed work and leaves remaining originals untouched');
rejects(fn()=>$batches->advance($job['id'],$job['version']),Conflict::class,'cancelled batch cannot resume');
$p=$pages->save(['title'=>'Stale original','slug'=>'batch-stale']);$q=$pages->save(['title'=>'Next original','slug'=>'batch-next']);
$job=$batches->create([['id'=>$p['id'],'version'=>1],['id'=>$q['id'],'version'=>1]],'trash');
$p=$pages->save(array_replace($p,['body'=>'Newer work']),1);$job=$batches->advance($job['id'],$job['version']);
check($job['status']==='paused'&&$job['next_index']===0&&!$pages->get($p['id'])['trashed']&&!$pages->get($q['id'])['trashed'],'changed source pauses before overwriting work or advancing other originals');
$job=$batches->advance($job['id'],$job['version']);check($job['status']==='paused'&&$job['next_index']===0,'retry keeps originally approved source version');
$job=$batches->skip($job['id'],$job['version']);$job=$batches->advance($job['id'],$job['version']);
check(count($job['skipped'])===1&&$job['skipped'][0]['id']===$p['id'],'explicit skip keeps a durable original-bound history');
check($job['status']==='completed'&&!$pages->get($p['id'])['trashed']&&$pages->get($q['id'])['trashed'],'explicit skip preserves changed original and resumes remaining selection');
$parent=$pages->save(['title'=>'Parent original','slug'=>'batch-parent']);$child=$pages->save(['title'=>'Child original','slug'=>'batch-child','parent_id'=>$parent['id']]);
$job=$batches->create([['id'=>$parent['id'],'version'=>1]],'trash');$job=$batches->advance($job['id'],$job['version']);
check($job['status']==='paused'&&str_contains($job['last_reason'],'verweisen'),'current dependency pauses a batch with concrete correction');
$child=(new PageTrash($db,$a))->move($child['id'],$child['version']);$job=$batches->advance($job['id'],$job['version']);
check($job['status']==='completed','removing actual dependency permits safe resume of the same original');
rejects(fn()=>(new PageBatches($db,new Access($db,'outsider')))->get($job['id']),RuntimeException::class,'batch scope cannot cross clubs');
rejects(fn()=>(new PageBatches($db,new Access($db,'reader')))->create([['id'=>$p['id'],'version'=>$p['version']]],'trash'),DomainException::class,'read-only role cannot prepare batch');
rejects(fn()=>(new PageBatches($db,new Access($db,'editor')))->create([['id'=>$p['id'],'version'=>$p['version']]],'trash'),DomainException::class,'editor cannot approve retirement batch');
foreach([[],[['id'=>$p['id'],'version'=>0]],[['id'=>$p['id'],'version'=>$p['version']],['id'=>$p['id'],'version'=>$p['version']]]]as $selection)rejects(fn()=>$batches->create($selection,'trash'),InvalidArgumentException::class,'invalid or duplicate batch originals rejected');
check(count($batches->recent())<=25,'recent batch metadata is SQL bounded');
$manager=new Kulturhus\Native\Web\Manager($db,$a,dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/media','csrf');
rejects(fn()=>$manager->action('website',['csrf'=>'csrf','operation'=>'page-batch-create','direction'=>'trash','batch_items'=>[$p['id'].':'.$p['version']]],[]),InvalidArgumentException::class,'controller requires explicit preparation confirmation');
rejects(fn()=>$manager->action('website',['csrf'=>'wrong','operation'=>'page-batch-create'],[]),DomainException::class,'batch controller retains CSRF guard');
$r=$manager->action('website',['csrf'=>'csrf','operation'=>'page-batch-create','direction'=>'trash','batch_items'=>[$p['id'].':'.$p['version']],'batch_confirm'=>'1'],[]);
check($r['section']==='bulk'&&Kulturhus\Native\Integration\Routes::result($r)['batch']===$r['batch'],'batch preparation returns its original job in both routes');
$_GET=['section'=>'bulk','batch'=>$r['batch']];$html=$manager->main('website');$_GET=[];
check(str_contains($html,'data-page-batch="'.$r['batch'].'"')&&str_contains($html,'Bis zu 25 Inhalte fortsetzen'),'actual manager shows resumable job and bounded action');
check(str_contains($html,'Alle Suchtreffer vorbereiten')&&str_contains($html,'class="check"'),'actual Bootstrap form can freeze full filtered selection');
rejects(fn()=>$manager->action('website',['csrf'=>'csrf','operation'=>'page-batch-advance','batch'=>$r['batch'],'version'=>[]],[]),InvalidArgumentException::class,'batch controller rejects forged progress array');
$job=$batches->get($r['batch']);Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='publish'");
rejects(fn()=>$batches->advance($job['id'],$job['version']),DomainException::class,'revoked authority stops an already prepared batch');
check($batches->get($job['id'])===$job&&!$pages->get($p['id'])['trashed'],'revocation leaves progress and originals untouched');
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','website','publish')");
$before=$batches->get($job['id']);$db->beginTransaction();$batches->advance($job['id'],$job['version'],1);$db->rollBack();
check($batches->get($job['id'])===$before&&!$pages->get($p['id'])['trashed'],'outer rollback rolls back both lifecycle and durable checkpoint');
$left=$pages->save(['title'=>'Concurrent batch left','slug'=>'batch-race-left']);$right=$pages->save(['title'=>'Concurrent batch right','slug'=>'batch-race-right']);
$job=$batches->create([['id'=>$left['id'],'version'=>1],['id'=>$right['id'],'version'=>1]],'trash');$dir=dirname(getenv('KH_NATIVE_TEST_CONFIG'));$barrier=$dir.'/batch-go';$workers=[];
for($i=0;$i<2;++$i){$ready=$dir.'/batch-'.$i;$proc=proc_open([...TestSupport::phpCommand(),__FILE__,'race',$job['id'],(string)$job['version'],$ready,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Batch worker unavailable');fclose($pipes[0]);$workers[]=[$proc,$pipes,$ready];}
$until=microtime(true)+10;while(!is_file($workers[0][2].'.ready')||!is_file($workers[1][2].'.ready')){if(microtime(true)>$until)throw new RuntimeException('Batch barrier timeout');usleep(10000);}file_put_contents($barrier,'go');$outcomes=[];
foreach($workers as[$proc,$pipes,$ready]){$outcomes[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$err==='','actual concurrent batch worker completes');unlink($ready.'.ready');}unlink($barrier);sort($outcomes);
check($outcomes===['conflict','saved']&&$batches->get($job['id'])['next_index']===1,'concurrent original checkpoint commits once and rejects stale second worker');
check($pages->get($left['id'])['version']===2&&$pages->get($left['id'])['trashed']&&!$pages->get($right['id'])['trashed'],'concurrent checkpoint changes exactly one intended original');
echo json_encode(['checks'=>$checks,'large_batch'=>501,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
