<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Schema,TreeCopyJobs,PageTrash,PagePurge,Conflict};
use Kulturhus\Native\Web\Manager;
$db=TestSupport::db();$admin=new Access($db,'admin');$jobs=new TreeCopyJobs($db,$admin);$checks=0;
if(($argv[1]??'')==='forget'){echo json_encode($jobs->forget($argv[2],(int)$argv[3],true),JSON_THROW_ON_ERROR);exit;}
TestSupport::seed($db);Schema::install($db);$pages=new Pages($db,$admin);
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function reject(callable $operation,string $class,string $label):void{try{$operation();}catch(Throwable $error){check($error instanceof$class,$label.' ('.$error::class.')');return;}throw new RuntimeException($label);}
function snapshot(PDO $db,array $tables):string{$all=[];foreach($tables as$table)$all[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();return hash('sha256',serialize($all));}
function ownJob(Pages $pages,TreeCopyJobs $jobs,string $suffix):array{$source=$pages->save(['title'=>'Own retained copy source '.$suffix,'slug'=>'copy-cleanup-source-'.$suffix,'body'=>'Own immutable content '.$suffix]);$job=$jobs->create($source['id'],$source['version'],['title'=>'Own cleanup target '.$suffix,'slug'=>'copy-cleanup-target-'.$suffix]);return[$source,$job];}
$originalTables=['khcms_pages','khcms_revisions','khcms_state','khcms_media','khcms_published_media','native_users','native_grants'];
[$source,$job]=ownJob($pages,$jobs,'first');$jobTables=['khcms_tree_copy_jobs','khcms_tree_copy_items','khcms_tree_copy_removals'];$before=snapshot($db,$jobTables);
reject(fn()=>$jobs->forget($job['id'],$job['version'],true),Conflict::class,'active preparation cannot be discarded through closed-job cleanup');
check(snapshot($db,$jobTables)===$before,'refused active removal retains every exact preparation byte');
$job=$jobs->advance($job['id'],$job['version']);$job=$jobs->complete($job['id'],$job['version'],true);$copy=$pages->get($job['root_copy_id']);
reject(fn()=>$jobs->forget($job['id'],$job['version'],false),InvalidArgumentException::class,'removing retained job content requires explicit separate confirmation');
reject(fn()=>$jobs->forget($job['id'],$job['version']-1,true),Conflict::class,'stale closed-job version cannot remove its retained originals');
reject(fn()=>(new TreeCopyJobs($db,new Access($db,'editor')))->forget($job['id'],$job['version'],true),DomainException::class,'ordinary editor cannot remove retained job snapshots');
reject(fn()=>(new TreeCopyJobs($db,new Access($db,'outsider')))->forget($job['id'],$job['version'],true),RuntimeException::class,'another club cannot remove retained preparation');
$trashed=(new PageTrash($db,$admin))->move($source['id'],$source['version']);
reject(fn()=>(new PagePurge($db,$admin))->purge($source['id'],$trashed['version'],true),Conflict::class,'retained completed job originally blocks permanent source removal');
$before=snapshot($db,[...$originalTables,...$jobTables]);
$db->exec('CREATE TABLE own_copy_dependency(id INT PRIMARY KEY,job_id CHAR(32),FOREIGN KEY(job_id) REFERENCES khcms_tree_copy_jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');Db::run($db,'INSERT INTO own_copy_dependency(id,job_id) VALUES(1,?)',[$job['id']]);
reject(fn()=>$jobs->forget($job['id'],$job['version'],true),Conflict::class,'unknown future cross-module cascade cannot erase a bound original during cleanup');
check((int)$db->query('SELECT COUNT(*) FROM own_copy_dependency')->fetchColumn()===1&&snapshot($db,[...$originalTables,...$jobTables])===$before,'blocked foreign dependency retains exact own and cross-module originals');$db->exec('DROP TABLE own_copy_dependency');
$db->exec("CREATE TRIGGER own_copy_cleanup_failure BEFORE DELETE ON khcms_tree_copy_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own synthetic late removal failure'");
reject(fn()=>$jobs->forget($job['id'],$job['version'],true),PDOException::class,'late storage failure rolls back already removed preparation items and tombstone');
check(snapshot($db,[...$originalTables,...$jobTables])===$before,'failed cleanup preserves all original pages and complete retryable preparation');$db->exec('DROP TRIGGER own_copy_cleanup_failure');
$originals=snapshot($db,$originalTables);$removed=$jobs->forget($job['id'],$job['version'],true);
check($removed['removed']&&!$removed['replayed']&&$removed['items']===1&&preg_match('/\A[a-f0-9]{64}\z/D',$removed['original_hash'])===1,'closed job content is replaced by exact-version content-free audit hash');
check(!Db::run($db,'SELECT 1 FROM khcms_tree_copy_jobs WHERE id=?',[$job['id']])->fetchColumn()&&!Db::run($db,'SELECT 1 FROM khcms_tree_copy_items WHERE job_id=?',[$job['id']])->fetchColumn(),'closed header and its own preparation items are actually removed');
check(snapshot($db,$originalTables)===$originals&&$pages->get($copy['id'])===$copy,'explicit cleanup changes no source, target, revision, publication, media or access byte');
$retry=$jobs->forget($job['id'],$job['version'],true);check($retry['replayed']&&$retry['original_hash']===$removed['original_hash'],'lost cleanup response safely replays its exact confirmed version');
reject(fn()=>$jobs->forget($job['id'],$job['version']+1,true),Conflict::class,'audit replay cannot authorize a different unconfirmed version');
(new PagePurge($db,$admin))->purge($source['id'],$trashed['version'],true);check(!Db::run($db,'SELECT 1 FROM khcms_pages WHERE id=?',[$source['id']])->fetchColumn()&&$pages->get($copy['id'])===$copy,'after explicit preparation cleanup permanent source removal works and retains its independent copied original');
[$source2,$job2]=ownJob($pages,$jobs,'cancelled');$job2=$jobs->cancel($job2['id'],$job2['version']);$before=snapshot($db,$originalTables);$removed=$jobs->forget($job2['id'],$job2['version'],true);check($removed['removed']&&snapshot($db,$originalTables)===$before,'cancelled preparation can be explicitly removed without deleting any original page');
[$source3,$job3]=ownJob($pages,$jobs,'concurrent');$job3=$jobs->cancel($job3['id'],$job3['version']);$before=snapshot($db,$originalTables);$workers=[];
for($index=0;$index<2;++$index){$worker=proc_open([...TestSupport::phpCommand(),__FILE__,'forget',$job3['id'],(string)$job3['version']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($worker))throw new RuntimeException('Own cleanup worker unavailable.');fclose($pipes[0]);$workers[]=[$worker,$pipes];}
$replayed=[];foreach($workers as[$worker,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($worker);check($status===0&&$errors==='','actual concurrent cleanup worker completes');$result=json_decode($output,true,32,JSON_THROW_ON_ERROR);$replayed[]=$result['replayed'];}sort($replayed);check($replayed===[false,true]&&snapshot($db,$originalTables)===$before,'concurrent exact cleanup produces one deletion and one safe replay with every original preserved');
$manager=new Manager($db,$admin,dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/cleanup-media','own-csrf');$result=$manager->action('website',['operation'=>'tree-copy-forget','copy_job'=>$job3['id'],'version'=>$job3['version'],'copy_forget_confirm'=>'1','csrf'=>'own-csrf'],[]);check(($result['section']??null)==='copy-jobs'&&!isset($result['copy_job']),'normal manager cleanup redirects to the bounded job list without a removed job focus');
reject(fn()=>$manager->action('website',['operation'=>'tree-copy-forget','copy_job'=>$job3['id'],'version'=>$job3['version'],'copy_forget_confirm'=>'1','csrf'=>'wrong'],[]),DomainException::class,'closed-job cleanup retains the existing protected manager CSRF boundary');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
