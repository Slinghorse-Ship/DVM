<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{ProductionRuntime,Database,WebPushDelivery,ManagerNotifications};
if(!ProductionRuntime::enabled())exit;
$gate=null;$lock=null;
try{
    $gate=WebPushDelivery::runtimeGate();$privateRoot=(string)getenv('VEREIN_PRIVATE_ROOT');$lockPath=$privateRoot.'/push-worker.lock';
    if(is_link($lockPath)||(file_exists($lockPath)&&(!is_file($lockPath)||(fileperms($lockPath)&0077))))throw new DomainException('Private Pushsperre prüfen.');
    $mask=umask(0077);try{$lock=fopen($lockPath,'c');}finally{umask($mask);}
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
    $path=ProductionRuntime::database();$db=Database::connect($path);$deadline=microtime(true)+45;
    while(microtime(true)<$deadline&&($db->query("SELECT 1 FROM push_deliveries WHERE state='pending' LIMIT 1")->fetchColumn()||ManagerNotifications::hasPending($db))){
        $result=WebPushDelivery::run($db,1);if(($result['status']??'')!=='ok')break;
    }
}catch(DomainException){/* An independent deployment hold or busy gate sends nothing. */}
catch(Throwable){fwrite(STDERR,"Pushlauf nicht bestätigt. Privaten ursprünglichen Betrieb prüfen.\n");exit(1);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}if($gate)$gate->close();}
