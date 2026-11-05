<?php declare(strict_types=1);
/** Explicit loopback-fixture worker. No HTTP entry point, transport, or production configuration. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\PublicationSchedule;
if(!in_array($argv[1]??'',['--once','--watch','--serve'],true)){fwrite(STDERR,"Aufruf: native-publication-worker.php --once | --watch [Sekunden 1-3600] | --serve\n");exit(2);}
$serve=$argv[1]==='--serve';$watch=$argv[1]==='--watch';$seconds=$watch?(int)($argv[2]??60):0;
if($watch&&($seconds<1||$seconds>3600)){fwrite(STDERR,"Lokale Beobachtungsdauer muss 1–3600 Sekunden betragen.\n");exit(2);}
$lock=null;$state=null;$statusPath=null;$pidPath=null;$stopPath=null;$exit=0;
try{
    $config=TestSupport::config();$label=$config['label']??'';$root=realpath(dirname(__DIR__,2));
    $directory=$root.'/.local/native-cms-integration';$registered=$directory.'/'.$label.'.json';
    $actual=realpath((string)getenv('KH_NATIVE_TEST_CONFIG'));
    $ciRoot=$root.'/.local';$acceptanceRoot=getenv('DVM_LOCAL_ACCEPTANCE_TMP');
    if(is_string($acceptanceRoot)&&$acceptanceRoot!==''){
        $base=realpath($acceptanceRoot);
        if($base===false||!is_dir($base)||!is_writable($base)||is_link($acceptanceRoot)||(fileperms($base)&0077)!==0)throw new RuntimeException('Eigenes privates Abnahmeverzeichnis erforderlich.');
        $ciRoot=$base;
    }
    $ci=(getenv('GITHUB_ACTIONS')==='true'||getenv('KH_NATIVE_CI')==='1')
        &&($config['owner']??'')==='isolated-native-ci'&&$actual!==false
        &&preg_match('/^native-ci-[a-f0-9]{24}$/D',basename(dirname($actual)))
        &&realpath(dirname(dirname($actual)))===realpath($ciRoot);
    if($ci){$directory=dirname($actual);$registered=$actual;}
    if(!preg_match('/^[a-z][a-z0-9-]{0,35}$/D',$label)||(!$ci&&!in_array($config['owner']??'',['cms-chat-integration-20261007','cloud-onboarding-synthetic'],true))||is_link((string)getenv('KH_NATIVE_TEST_CONFIG'))||$actual===false||strcasecmp(str_replace('\\','/',$actual),str_replace('\\','/',$registered))!==0)throw new RuntimeException('Registrierte synthetische Fixture erforderlich.');
    $runtime=$directory.'/publication-workers';
    if(!is_dir($runtime)&&!mkdir($runtime,0700,true))throw new RuntimeException('Lokales Worker-Verzeichnis fehlt.');
    if(is_link($runtime)||strcasecmp(str_replace('\\','/',realpath($runtime)),str_replace('\\','/',$runtime))!==0)throw new RuntimeException('Worker-Verzeichnis außerhalb der lokalen Ablage.');
    $lock=fopen($runtime.'/'.$label.'.lock','c+');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Für diese lokale Fixture läuft bereits ein Publikationsworker.\n");exit(3);}
    $statusPath=$runtime.'/'.$label.'.status.json';$pidPath=$runtime.'/'.$label.'.pid';$stopPath=$runtime.'/'.$label.'.stop';
    $state=['worker'=>'local-publication','fixture'=>$label,'instance'=>bin2hex(random_bytes(8)),'pid'=>getmypid(),'mode'=>$argv[1],'state'=>'starting','started_at'=>time(),'heartbeat_at'=>time(),'processed_total'=>0];
    file_put_contents($pidPath,(string)getmypid(),LOCK_EX);file_put_contents($statusPath,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);
    echo json_encode(['worker'=>'local-publication','fixture'=>$label,'pid'=>getmypid(),'state'=>'started','mode'=>$argv[1]],JSON_THROW_ON_ERROR).PHP_EOL;
    $db=TestSupport::db();$until=microtime(true)+$seconds;$total=0;$lastHeartbeat=0;
    do{
        if(is_file($stopPath)&&trim((string)file_get_contents($stopPath))===$state['instance']){$state['reason']='stop_requested';break;}
        $results=PublicationSchedule::runDue($db);$counts=[];
        foreach($results as $result)$counts[$result['status']]=($counts[$result['status']]??0)+1;
        $total+=count($results);
        if($results||(!$watch&&!$serve))echo json_encode(['worker'=>'local-publication','processed'=>count($results),'statuses'=>$counts],JSON_THROW_ON_ERROR).PHP_EOL;
        if($results||time()-$lastHeartbeat>=5){$state['state']='running';$state['heartbeat_at']=time();$state['processed_total']=$total;$state['last_statuses']=$counts;file_put_contents($statusPath,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);$lastHeartbeat=time();}
        if(!$serve&&(!$watch||microtime(true)>=$until))break;
        usleep(500000);
    }while(true);
    $state['state']='stopped';$state['processed_total']=$total;
    if($watch||$serve)echo json_encode(['worker'=>'local-publication','completed'=>true,'processed_total'=>$total],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){if($state!==null){$state['state']='failed';$state['reason']='worker_failed';}fwrite(STDERR,"Lokaler Publikationslauf konnte nicht abgeschlossen werden; private Konfiguration und Dienststatus prüfen.\n");$exit=1;}
finally{
    if($state!==null){
        $state['heartbeat_at']=time();$state['stopped_at']=time();file_put_contents($statusPath,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);
        if(is_file($pidPath)&&trim((string)file_get_contents($pidPath))===(string)getmypid())unlink($pidPath);
        if(is_file($stopPath)&&trim((string)file_get_contents($stopPath))===$state['instance'])unlink($stopPath);
    }
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
}
exit($exit);
