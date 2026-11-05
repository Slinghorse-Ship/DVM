<?php declare(strict_types=1);
/** Worker process lifecycle against the dedicated fixture; never the running review demo. */
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,PublicationSchedule};
$config=TestSupport::config();if(($config['label']??'')!=='publication')throw new RuntimeException('Separate publication fixture required');
$db=TestSupport::db();$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;}
function startWorker(string $mode,array &$pipes){return proc_open([PHP_BINARY,'-d','extension=pdo_mysql',dirname(__DIR__).'/bin/native-publication-worker.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);}
$run='worker-'.bin2hex(random_bytes(6));Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$run,$run,'Synthetischer Worker']);foreach(['read','edit','publish'] as $cap)Db::run($db,"INSERT INTO native_grants VALUES(?,'website',?)",[$run,$cap]);
$access=new Access($db,$run);$pages=new Pages($db,$access);$page=$pages->save(['slug'=>$run,'title'=>'Zukunft aus permanentem lokalem Prozess']);$schedule=new PublicationSchedule($db,$access);$plan=$schedule->plan($page['id'],1,time()+3);
$directory=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/publication-workers';$statusPath=$directory.'/publication.status.json';$stopPath=$directory.'/publication.stop';
$pipes=[];$process=startWorker('--serve',$pipes);if(!is_resource($process))throw new RuntimeException('Worker process unavailable');fclose($pipes[0]);$state=null;
try{
    $deadline=microtime(true)+12;
    do{usleep(100000);$state=is_file($statusPath)?json_decode(file_get_contents($statusPath),true):null;$processState=proc_get_status($process);if(!$processState['running'])break;}while(microtime(true)<$deadline&&(($state['state']??'')!=='running'||($state['pid']??0)!==$processState['pid']));
    check(($state['state']??'')==='running'&&($state['mode']??'')==='--serve','serve confirms first successful database loop');
    check(is_file($directory.'/publication.pid')&&(int)file_get_contents($directory.'/publication.pid')===$state['pid'],'pid file identifies confirmed worker');
    $duplicatePipes=[];$duplicate=startWorker('--serve',$duplicatePipes);fclose($duplicatePipes[0]);$duplicateOutput=stream_get_contents($duplicatePipes[1]);$duplicateError=stream_get_contents($duplicatePipes[2]);fclose($duplicatePipes[1]);fclose($duplicatePipes[2]);check(proc_close($duplicate)===3&&$duplicateOutput==='','OS file lock rejects competing worker');
    do{usleep(100000);$current=$schedule->forPage($page['id']);}while(microtime(true)<$deadline&&$current['status']!=='published');
    check($current['status']==='published'&&$pages->publicPage($page['slug'])!==null,'serve publishes future synthetic content using real time');
    check(proc_get_status($process)['running'],'serve keeps running after publication');
    file_put_contents($stopPath,'stale-other-instance',LOCK_EX);usleep(650000);check(proc_get_status($process)['running'],'stale stop request cannot stop another instance');
}finally{
    if(is_array($state)&&isset($state['instance']))file_put_contents($stopPath,$state['instance'],LOCK_EX);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
}
check($exit===0&&$error==='','instance-bound stop lets worker exit cleanly');
$state=json_decode(file_get_contents($statusPath),true);check($state['state']==='stopped'&&($state['reason']??'')==='stop_requested','stopped status retained for operators');
check(!is_file($directory.'/publication.pid')&&!is_file($stopPath),'clean stop removes only own PID and stop marker');
check(str_contains($output,'"published":'),'log reports genuine publication');
echo 'Native publication worker: '.$checks." lifecycle checks passed; --serve, real deadline, deduplication and graceful stop in own fixture.\n";
