<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require_once dirname(__DIR__).'/src/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Database/Database.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Schema,PublicationSchedule,Conflict,DataModel};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Verein\Database;

function connection(string $class=PDO::class):PDO{
    $c=TestSupport::config();$db=new $class('mysql:host=127.0.0.1;port=13371;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");return $db;
}
final class InterruptPublicationPDO extends PDO{
    public int $commits=0;public int $stopAfter=0;
    public function commit():bool{$result=parent::commit();if(++$this->commits===$this->stopAfter)exit(76);return $result;}
}
final class BeforePublicationPDO extends PDO{
    public int $begins=0;public ?Closure $beforePublish=null;
    public function beginTransaction():bool{if(++$this->begins===2&&$this->beforePublish)($this->beforePublish)();return parent::beginTransaction();}
}
if(($argv[1]??'')==='--child'){
    $data=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);while(microtime(true)<($data['start']??0))usleep(1000);
    if($data['mode']==='crash'){$db=connection(InterruptPublicationPDO::class);$db->stopAfter=$data['after'];PublicationSchedule::runDue($db,null,50,$data['now']);exit(2);}
    if($data['mode']==='run'){echo count(PublicationSchedule::runDue(TestSupport::db(),null,50,$data['now']));exit;}
    $db=TestSupport::db();$service=new PublicationSchedule($db,new Access($db,$data['actor']),static fn()=>$data['now']);
    try{$service->plan($data['page'],1,$data['now']+10);echo 'ok';}catch(Conflict){echo 'conflict';}exit;
}
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;}
function rejects(callable $operation,string $label,string $class=DomainException::class):void{try{$operation();}catch(Throwable $error){check($error instanceof $class,$label);return;}check(false,$label);}
/** MySQL may release a dead client's advisory lock after proc_close(); busy ticks intentionally skip it. */
function resumeInterrupted(PDO $db,string $job,int $now):void{
    $deadline=microtime(true)+2;
    do{
        PublicationSchedule::runDue($db,null,50,$now);
        if(Db::run($db,'SELECT status FROM khcms_publication_schedule WHERE id=?',[$job])->fetchColumn()!=='processing')return;
        usleep(10000);
    }while(microtime(true)<$deadline);
}
function children(array $inputs):array{
    $processes=[];$start=microtime(true)+0.5;
    foreach($inputs as $input){$process=proc_open([...TestSupport::phpCommand(),__FILE__,'--child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Child unavailable');fwrite($pipes[0],json_encode($input+['start'=>$start],JSON_THROW_ON_ERROR));fclose($pipes[0]);$processes[]=[$process,$pipes];}
    $results=[];foreach($processes as [$process,$pipes]){$output=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($error!=='')throw new RuntimeException('Child diagnostics withheld');$results[]=['status'=>$status,'output'=>$output];}return $results;
}
$config=TestSupport::config();if(($config['label']??'')!=='publication')throw new RuntimeException('Own publication fixture required');
$db=TestSupport::db();if(!Database::tableExists($db,'schema_migrations'))Database::migrate($db);
TestSupport::seed($db);Schema::install($db);PublicationSchedule::install($db);PublicationSchedule::install($db);
\Kulturhus\Native\Auth\Schema::install($db);\Kulturhus\Native\Integration\Schema::install($db);
$run='publication-'.bin2hex(random_bytes(6));$club=$run.'-club';$actors=[];
foreach(['admin','editor','reader','outsider','publisher'] as $role){$id=$run.'-'.$role;$actors[$role]=$id;Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$id,$role==='outsider'?$run.'-foreign':$club,'Synthetische Planung '.$role]);$caps=match($role){'reader'=>['read'],'editor'=>['read','edit'],'publisher'=>['read','publish'],default=>['read','edit','publish','manage']};foreach($caps as $cap)Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,\'website\',?)',[$id,$cap]);}
$access=new Access($db,$actors['admin']);$pages=new Pages($db,$access);$now=time()+600;$clock=static function()use(&$now):int{return $now;};$schedule=new PublicationSchedule($db,$access,$clock);$counter=0;
$make=static function(string $title='Geplanter Inhalt')use($pages,$run,&$counter):array{return $pages->save(['slug'=>$run.'-'.++$counter,'title'=>$title,'body'=>'Eigener synthetischer Text']);};
$status=static function(string $id)use($db):string{return Db::run($db,'SELECT status FROM khcms_publication_schedule WHERE id=?',[$id])->fetchColumn();};
check(gmdate('c',PublicationSchedule::parseBerlin('2026-01-15T12:00'))==='2026-01-15T11:00:00+00:00','Berlin winter UTC offset');
check(gmdate('c',PublicationSchedule::parseBerlin('2026-07-15T12:00'))==='2026-07-15T10:00:00+00:00','Berlin summer UTC offset');
foreach(['2026-02-30T12:00','2026-03-29T02:30','2026-10-25T02:30','2026-01-15 12:00','2026-01-15T25:00'] as $invalid)rejects(fn()=>PublicationSchedule::parseBerlin($invalid),'invalid or ambiguous wall clock rejected');
$page=$make();check($schedule->forPage($page['id'])===null,'no schedule initially');
rejects(fn()=>$schedule->plan($page['id'],1,$now),'past publication rejected');
rejects(fn()=>$schedule->plan($page['id'],1,$now+6*366*86400),'unbounded future rejected');
rejects(fn()=>$schedule->plan($page['id'],8,$now+10),'stale page version rejected',Conflict::class);
foreach(['reader','editor','outsider'] as $role)rejects(fn()=>(new PublicationSchedule($db,new Access($db,$actors[$role]),$clock))->plan($page['id'],1,$now+10),'scope/publish authority '.$role);
rejects(fn()=>(new PublicationSchedule($db,new Access($db,$actors['outsider']),$clock))->forPage($page['id']),'foreign schedule lookup rejected');
$job=$schedule->plan($page['id'],1,$now+10);
check($job['status']==='pending'&&$job['page_version']===1&&$job['due_at']===$now+10,'immutable version scheduled');
check(!isset($job['snapshot_json'])&&!isset($job['snapshot_hash']),'snapshot internals omitted from UI');
check($pages->get($page['id'])['version']===1&&$pages->publicPage($page['slug'])===null,'planning does not publish or edit page');
check(PublicationSchedule::runDue($db,null,50,$now+9)===[],'not published before deadline');
$result=PublicationSchedule::runDue($db,null,50,$now+10);
check(count($result)===1&&$result[0]['status']==='published','due job executes');
check($pages->publicPage($page['slug'])['version']===2,'exact planned revision published');
check(PublicationSchedule::runDue($db,null,50,$now+11)===[]&&$pages->get($page['id'])['version']===2,'repeat worker does not republish');
check($schedule->forPage($page['id'])['published_revision']===2,'completion stores publication revision');

// Club selection precedes the limit, claims, authority resolution and crash reconciliation.
$ownScoped=$make('Eigene Laufgrenze');$ownScopedJob=$schedule->plan($ownScoped['id'],1,$now+10);
$outsideAccess=new Access($db,$actors['outsider']);$outsidePages=new Pages($db,$outsideAccess);
$outsidePage=$outsidePages->save(['slug'=>$run.'-foreign-plan','title'=>'Fremder geplanter Inhalt']);
$outsideSchedule=new PublicationSchedule($db,$outsideAccess,$clock);
$outsideJob=$outsideSchedule->plan($outsidePage['id'],1,$now+1);
Db::run($db,"UPDATE khcms_publication_schedule SET status='processing' WHERE id=?",[$outsideJob['id']]);
$outsideBefore=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$outsideJob['id']])->fetch();
$holder=connection();$busyLock='khcms-plan:'.substr(hash('sha256',$config['database'].'|'.$ownScoped['id']),0,48);
check((int)Db::run($holder,'SELECT GET_LOCK(?,0)',[$busyLock])->fetchColumn()===1,'another actual connection holds publication lock');
check(PublicationSchedule::runDue($db,null,1,$now+11,$club)===[]&&$status($ownScopedJob['id'])==='pending','busy publication tick leaves the approved original pending');
Db::run($holder,'SELECT RELEASE_LOCK(?)',[$busyLock]);$holder=null;
$scoped=PublicationSchedule::runDue($db,null,1,$now+11,$club);
check(count($scoped)===1&&$scoped[0]['id']===$ownScopedJob['id']&&$scoped[0]['status']==='published','club filter applies before worker limit');
check($outsideBefore===Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$outsideJob['id']])->fetch()&&$outsidePages->publicPage($outsidePage['slug'])===null,'foreign processing original is untouched by scoped reconciliation');
rejects(fn()=>PublicationSchedule::runDue($db,null,1,$now,''),'empty scope cannot silently run all clubs');
$outsideSchedule->cancel($outsideJob['id'],1);

$page=$make();$job=$schedule->plan($page['id'],1,$now+10);
rejects(fn()=>$schedule->plan($page['id'],1,$now+20),'replacement requires current plan version',Conflict::class);
$replacement=$schedule->plan($page['id'],1,$now+20,$job['version']);
check($replacement['id']!==$job['id']&&$status($job['id'])==='cancelled','replacement preserves old plan history');
rejects(fn()=>$schedule->cancel($replacement['id'],0),'cancel checks schedule version',Conflict::class);
rejects(fn()=>(new PublicationSchedule($db,new Access($db,$actors['outsider']),$clock))->cancel($replacement['id'],1),'foreign cancellation denied');
check($schedule->cancel($replacement['id'],1)['status']==='cancelled','pending plan cancellable');
check(PublicationSchedule::runDue($db,null,50,$now+21)===[],'cancelled plan never runs');
$publisher=new PublicationSchedule($db,new Access($db,$actors['publisher']),$clock);$job=$publisher->plan($page['id'],1,$now+10);check($job['created_by']===$actors['publisher'],'publisher does not need edit capability');$publisher->cancel($job['id'],1);

$page=$make('Schon öffentlich');$page=$pages->publish($page['id'],1);$page=$pages->save(array_replace($page,['body'=>'Geplanter Text']),2);$job=$schedule->plan($page['id'],3,$now+10);$newer=$pages->save(array_replace($page,['body'=>'Neuerer privater Entwurf']),3);
$result=PublicationSchedule::runDue($db,null,50,$now+11);
check($status($job['id'])==='conflicted','edited draft creates conflict');
check($pages->get($page['id'])['body']==='Neuerer privater Entwurf'&&$pages->get($page['id'])['version']===4,'newer draft untouched');
check($pages->publicPage($page['slug'])['body']==='Eigener synthetischer Text','previous public snapshot stays unchanged');
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$payload=json_decode(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$page['id']])->fetchColumn(),true);$payload['body']='Synthetische Manipulation ohne Versionsanstieg';Db::run($db,'UPDATE khcms_pages SET draft_json=? WHERE id=?',[json_encode($payload,JSON_THROW_ON_ERROR),$page['id']]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='conflicted','snapshot hash detects unversioned change');
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$pages->archive($page['id'],1);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='conflicted','archive invalidates plan');rejects(fn()=>$schedule->plan($page['id'],2,$now+10),'archived content cannot be planned',Conflict::class);
$model=new DataModel($db,$access);$definition=$model->saveTemplate(['key'=>'planned_template','label'=>'Geplant','renderer'=>'standard','fields'=>[]]);$page=$pages->save(['slug'=>$run.'-model','title'=>'Modellbindung','template'=>'planned_template']);$job=$schedule->plan($page['id'],1,$now+10);$model->saveTemplate(['key'=>'planned_template','label'=>'Geändert','renderer'=>'standard','fields'=>[]],$definition['version']);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='conflicted'&&$pages->publicPage($page['slug'])===null,'schema change fails closed');

$page=$make();$job=$schedule->plan($page['id'],1,$now+10);Db::run($db,"DELETE FROM native_grants WHERE actor_id=? AND module='website' AND capability='publish'",[$actors['admin']]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='blocked','revoked publisher permission blocks job');Db::run($db,"INSERT INTO native_grants VALUES(?,'website','publish')",[$actors['admin']]);
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);Db::run($db,'UPDATE native_users SET active=0 WHERE id=?',[$actors['admin']]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='blocked','inactive actor blocks job');Db::run($db,'UPDATE native_users SET active=1 WHERE id=?',[$actors['admin']]);
$accountId=bin2hex(random_bytes(16));Db::run($db,'INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)',[$accountId,$actors['admin'],$run.'@example.invalid',$now]);
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);Db::run($db,'UPDATE native_auth_accounts SET active=0 WHERE id=?',[$accountId]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='blocked','disabled login account blocks persistent job');Db::run($db,'UPDATE native_auth_accounts SET active=1 WHERE id=?',[$accountId]);
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);PublicationSchedule::runDue($db,fn()=>new Access($db,$actors['outsider']),50,$now+11);check($status($job['id'])==='blocked','resolver cannot change planned identity or tenant');

// Another connection changes the page AFTER the worker claim, BEFORE Pages begins its own transaction.
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$interleaving=connection(BeforePublicationPDO::class);$interleaving->beforePublish=fn()=>$pages->save(array_replace($page,['body'=>'Zwischen Claim und Freigabe geändert']),1);PublicationSchedule::runDue($interleaving,null,50,$now+11);check($status($job['id'])==='conflicted','publish transaction rechecks version after claim');check($pages->publicPage($page['slug'])===null,'race never publishes newer draft');
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$interleaving=connection(BeforePublicationPDO::class);$interleaving->beforePublish=fn()=>Db::run($db,"DELETE FROM native_grants WHERE actor_id=? AND module='website' AND capability='publish'",[$actors['admin']]);PublicationSchedule::runDue($interleaving,null,50,$now+11);check($status($job['id'])==='blocked','publish transaction rechecks rights after claim');Db::run($db,"INSERT INTO native_grants VALUES(?,'website','publish')",[$actors['admin']]);

$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$race=children([['mode'=>'run','now'=>$now+11],['mode'=>'run','now'=>$now+11]]);check(array_sum(array_column($race,'status'))===0,'parallel workers exit cleanly');check(array_sum(array_map('intval',array_column($race,'output')))===1,'two real workers complete one publication');check($pages->get($page['id'])['version']===2&&count($pages->revisions($page['id']))===2,'concurrent publication creates exactly one revision');
$page=$make();$input=['mode'=>'plan','actor'=>$actors['admin'],'page'=>$page['id'],'now'=>$now];$race=children([$input,$input]);$outputs=array_column($race,'output');sort($outputs);check($outputs===['conflict','ok'],'two simultaneous first plans cannot silently replace');$pending=$schedule->forPage($page['id']);$schedule->cancel($pending['id'],$pending['version']);

$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$crash=children([['mode'=>'crash','after'=>1,'now'=>$now+11]]);check($crash[0]['status']===76&&$status($job['id'])==='processing','real process interruption after durable claim');check($pages->publicPage($page['slug'])===null,'claimed interrupted job not yet public');resumeInterrupted($db,$job['id'],$now+11);check($status($job['id'])==='published'&&$pages->get($page['id'])['version']===2,'claim interruption resumes once: '.json_encode($schedule->forPage($page['id']),JSON_THROW_ON_ERROR));
$page=$make();$job=$schedule->plan($page['id'],1,$now+10);$crash=children([['mode'=>'crash','after'=>2,'now'=>$now+11]]);check($crash[0]['status']===76&&$status($job['id'])==='processing','real process interruption after page commit');check($pages->publicPage($page['slug'])['version']===2,'committed publication remains visible');$changed=$pages->save(array_replace($pages->get($page['id']),['body'=>'Späterer Entwurf bleibt privat']),2);$generation=$pages->generation();resumeInterrupted($db,$job['id'],$now+11);check($status($job['id'])==='published'&&$pages->get($page['id'])['version']===3,'postcommit reconciliation preserves newer draft');check($pages->generation()===$generation&&count($pages->revisions($page['id']))===3,'reconciliation creates no extra publication/cache invalidation');

// Real manager role authority: the worker must not trust the native_grants projection.
$managerClub=$run.'-manager';$managerActor=$run.'-manager-author';Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$managerClub,'Synthetischer Planungsverein']);$matrix=json_encode(array_fill_keys(['texts','events','gallery','newsletter','social','press'],'write'),JSON_THROW_ON_ERROR);
Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version,website_access) VALUES(?,'writer','Planung','Synthetisch','none','none',0,1,?)",[$managerClub,$matrix]);
Db::run($db,"INSERT INTO actors(id,club_id,role,active) VALUES(?,?,'member',1)",[$managerActor,$managerClub]);Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES(?,?,'writer',1)",[$managerActor,$managerClub]);
$manager=(new ManagerBridge($db,$managerActor))->access();$nativeActor=$manager->user()['id'];$nativeAccount=bin2hex(random_bytes(16));Db::run($db,'INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)',[$nativeAccount,$nativeActor,$run.'-manager@example.invalid',$now]);
$authorityJob=['created_by'=>$nativeActor,'authority'=>'manager','club_id'=>$manager->club()];
$authority=function()use($db,$authorityJob){return Db::atomic($db,fn()=>\Kulturhus\Native\Shared\Authority::resolve($db,$authorityJob)->require('social','publish'));};
check($authority()['id']===$nativeActor,'existing manager authority resolves inside the actual job transaction');
Db::run($db,"UPDATE settings_roles SET website_access='{}' WHERE club_id=? AND id='writer'",[$managerClub]);
try{$authority();check(false,'manager transaction role revocation');}catch(DomainException){check(true,'transaction authority rejects revoked manager role despite native projection');}
Db::run($db,"UPDATE settings_roles SET website_access=? WHERE club_id=? AND id='writer'",[$matrix,$managerClub]);
Db::run($db,'UPDATE native_auth_accounts SET active=0 WHERE id=?',[$nativeAccount]);
try{$authority();check(false,'manager transaction account suspension');}catch(DomainException){check(true,'transaction authority rejects a suspended manager account');}
Db::run($db,'UPDATE native_auth_accounts SET active=1 WHERE id=?',[$nativeAccount]);
$managerPages=new Pages($db,$manager);$managerSchedule=new PublicationSchedule($db,$manager,$clock);$page=$managerPages->save(['slug'=>$run.'-manager-1','title'=>'Rollenprüfung']);$job=$managerSchedule->plan($page['id'],1,$now+10);check($job['authority']==='manager','manager plans retain live authority kind');
Db::run($db,"UPDATE settings_roles SET website_access='{}' WHERE club_id=? AND id='writer'",[$managerClub]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='blocked','manager role revocation blocks despite projected grant');Db::run($db,"UPDATE settings_roles SET website_access=? WHERE club_id=? AND id='writer'",[$matrix,$managerClub]);
$page=$managerPages->save(['slug'=>$run.'-manager-2','title'=>'Ohne Browsersitzung']);$job=$managerSchedule->plan($page['id'],1,$now+10);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='published','manager job uses active account and current roles without browser session');
$page=$managerPages->save(['slug'=>$run.'-manager-3','title'=>'Kontosperre']);$job=$managerSchedule->plan($page['id'],1,$now+10);Db::run($db,'UPDATE native_auth_accounts SET active=0 WHERE id=?',[$nativeAccount]);PublicationSchedule::runDue($db,null,50,$now+11);check($status($job['id'])==='blocked','manager account suspension blocks worker');Db::run($db,'UPDATE native_auth_accounts SET active=1 WHERE id=?',[$nativeAccount]);

// Start an actual short-lived timer only against this dedicated synthetic fixture.
require_once __DIR__.'/support/original-database-hash.php';$workerTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$workerOriginals=ownOriginalDatabaseHash($db,$workerTables);
$workerProbe=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/worker-path-probe';mkdir($workerProbe,0700);
foreach(['writable','linked','mismatch']as$kind){
    $base=$workerProbe.'/'.$kind;mkdir($base,0700);$privateCi=$base.'/native-ci-'.bin2hex(random_bytes(12));mkdir($privateCi,0700);$privateConfig=$privateCi.'/native-publication.json';copy((string)getenv('KH_NATIVE_TEST_CONFIG'),$privateConfig);chmod($privateConfig,0600);
    $environment=getenv();$environment['KH_NATIVE_TEST_CONFIG']=$privateConfig;$environment['DVM_LOCAL_ACCEPTANCE_TMP']=$base;
    if($kind==='writable')chmod($base,0777);
    elseif($kind==='linked'){symlink($base,$base.'-link');$environment['DVM_LOCAL_ACCEPTANCE_TMP']=$base.'-link';}
    else $environment['DVM_LOCAL_ACCEPTANCE_TMP']=$workerProbe;
    $probe=proc_open([...TestSupport::phpCommand(),dirname(__DIR__).'/bin/native-publication-worker.php','--once'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$probePipes,null,$environment);if(!is_resource($probe))throw new RuntimeException('Own worker guard probe unavailable.');fclose($probePipes[0]);$probeOutput=stream_get_contents($probePipes[1]);$probeError=stream_get_contents($probePipes[2]);fclose($probePipes[1]);fclose($probePipes[2]);check(proc_close($probe)===1&&$probeOutput===''&&$probeError!=='','actual worker refuses unsafe private fixture root '.$kind);
}
check(ownOriginalDatabaseHash($db,$workerTables)===$workerOriginals,'refused worker roots preserve every whole original row and schema');
$page=$make('Echter lokaler Zeitlauf');$live=new PublicationSchedule($db,$access);$job=$live->plan($page['id'],1,time()+2);
$process=proc_open([...TestSupport::phpCommand(),dirname(__DIR__).'/bin/native-publication-worker.php','--watch','4'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$error==='','explicit local timer exits cleanly');check($status($job['id'])==='published'&&$pages->publicPage($page['slug'])!==null,'actual wall-clock timer publishes future fixture content');check(str_contains($output,'"published":1'),'worker reports actual publication only');
echo 'Native publication: '.$checks." checks passed; isolated MySQL, real concurrency/crash recovery and local timer, no external actions.\n";
