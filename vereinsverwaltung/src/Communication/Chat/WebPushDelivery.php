<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class WebPushDelivery
{
    public static function kick():void
    {
        if(getenv('VEREIN_MODE')!=='production'||!function_exists('exec'))return;
        try{$gate=self::runtimeGate();}catch(\DomainException){return;}
        try{
        $php=(string)(getenv('PHP_CLI_BINARY')?:'/usr/bin/php');
        $script=dirname(__DIR__, 2).'/../bin/push-worker.php';
        if(!is_file($script))return;
        @exec(escapeshellarg($php).' '.escapeshellarg($script).' >/dev/null 2>&1 </dev/null &');
        }finally{$gate->close();}
    }
    /** Every detached sender owns its deployment gate independently of the initiating request. */
    public static function runtimeGate():\Kulturhus\Native\Shared\OperationGate
    {
        require_once dirname(__DIR__,2).'/../native/bootstrap.php';
        $private=getenv('VEREIN_PRIVATE_ROOT');
        if(!is_string($private)||!str_starts_with($private,'/'))throw new DomainException('Privaten ursprünglichen Pushbetrieb konfigurieren.');
        $gate=\Kulturhus\Native\Shared\OperationGate::shared($private);
        foreach(['upgrade-hold','restore-hold','recovery-hold']as$name)if(file_exists($private.'/'.$name)||is_link($private.'/'.$name)){$gate->close();throw new DomainException('Pushbetrieb bis zur geprüften Wartungsfreigabe gesperrt.');}
        return $gate;
    }
    public static function keyPath():string
    {
        // Hosting PHP may allow the private runtime but not the backup directory.
        // This value is supplied only by the trusted server-side runtime config.
        if(getenv('VEREIN_MODE')==='production'&&($path=trim((string)getenv('VEREIN_PUSH_KEY_PATH')))!=='')return $path;
        return getenv('VEREIN_MODE')==='production'?(getenv('VEREIN_PRIVATE_ROOT')?:dirname(dirname(__DIR__, 2),2)).'/push-vapid.json':dirname(dirname(__DIR__, 2),2).'/.local/vereinsverwaltung/push-vapid.json';
    }
    public static function publicKey():string
    {
        if(getenv('VEREIN_MODE')!=='production'||!is_file(self::keyPath()))return '';
        $keys=json_decode((string)file_get_contents(self::keyPath()),true);return is_array($keys)?(string)($keys['publicKey']??''):'';
    }
    public static function run(PDO $db,int $limit=20,?string $club=null,?callable $send=null):array
    {
        if(getenv('VEREIN_MODE')!=='production'||getenv('KULTURHUS_LOCAL_DEMO')==='1')return ['status'=>'local_disabled'];
        if($send===null&&(!is_file(self::keyPath())||!is_file(dirname(__DIR__, 2).'/../push-runtime/vendor/autoload.php')))return ['status'=>'not_configured'];
        $chatPending=(bool)DocumentCatalog::query($db,"SELECT 1 FROM push_deliveries WHERE state='pending'".($club!==null?' AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=?)':'')." LIMIT 1",$club!==null?[$club]:[])->fetchColumn();
        $managerPending=ManagerNotifications::hasPending($db,$club);
        require_once dirname(__DIR__,2).'/../native/bootstrap.php';
        \Kulturhus\Native\Chat\Push::recoverInterrupted($db,$club);
        $nativeAt=\Kulturhus\Native\Chat\Push::oldest($db,$club);$nativePending=$nativeAt!==null;
        if(!$chatPending&&!$managerPending&&!$nativePending)return ['status'=>'ok','sent'=>0];
        if($send===null){
        require_once dirname(__DIR__, 2).'/../push-runtime/vendor/autoload.php';
        $keys=json_decode((string)file_get_contents(self::keyPath()),true,512,JSON_THROW_ON_ERROR);
        $webPush=new \Minishlink\WebPush\WebPush(['VAPID'=>$keys],['TTL'=>300,'urgency'=>'normal'],10,['allow_redirects'=>false,'connect_timeout'=>5,'timeout'=>10]);
        $send=static function(array $subscription,string $payload)use($webPush):string{
            $report=$webPush->sendOneNotification(\Minishlink\WebPush\Subscription::create($subscription),$payload);
            return $report->isSuccess()?'sent':($report->isSubscriptionExpired()?'expired':'failed');
        };
        }
        $limit=max(1,min(20,$limit));$sent=0;
        if($nativePending&&$limit===1){
            $oldest=[];
            if($chatPending){$value=DocumentCatalog::query($db,"SELECT MIN(updated_at) FROM push_deliveries WHERE state='pending'".($club!==null?' AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=?)':''),$club!==null?[$club]:[])->fetchColumn();if(is_string($value))$oldest[]=strtotime($value);}
            if($managerPending){$value=DocumentCatalog::query($db,"SELECT MIN(updated_at) FROM manager_push_deliveries WHERE state='pending'".($club!==null?' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=?)':''),$club!==null?[$club]:[])->fetchColumn();if(is_string($value))$oldest[]=strtotime($value);}
            if(!$oldest||$nativeAt<=min($oldest))return ['status'=>'ok','sent'=>\Kulturhus\Native\Chat\Push::dispatch($db,$send,1,$club)];
        }
        if($chatPending&&$managerPending&&$limit===1){
            // Worker requests one at a time: serve whichever queue has waited longest.
            $chatAt=(string)DocumentCatalog::query($db,"SELECT MIN(updated_at) FROM push_deliveries WHERE state='pending'".($club!==null?' AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=?)':''),$club!==null?[$club]:[])->fetchColumn();
            $managerAt=(string)DocumentCatalog::query($db,"SELECT MIN(updated_at) FROM manager_push_deliveries WHERE state='pending'".($club!==null?' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=?)':''),$club!==null?[$club]:[])->fetchColumn();
            return ['status'=>'ok','sent'=>$managerAt<$chatAt?ManagerNotifications::dispatch($db,$send,1,$club):PushNotifications::dispatch($db,$send,1,$club)];
        }
        $nativeLimit=$nativePending?(!$chatPending&&!$managerPending?$limit:($limit>1?max(1,intdiv($limit,1+(int)$chatPending+(int)$managerPending)):0)):0;
        $legacyLimit=$limit-$nativeLimit;
        $managerLimit=$managerPending?($chatPending?intdiv($legacyLimit,2):$legacyLimit):0;
        if($managerLimit)$sent+=ManagerNotifications::dispatch($db,$send,$managerLimit,$club);
        if($chatPending&&$legacyLimit>$managerLimit)$sent+=PushNotifications::dispatch($db,$send,$legacyLimit-$managerLimit,$club);
        if($nativeLimit)$sent+=\Kulturhus\Native\Chat\Push::dispatch($db,$send,$nativeLimit,$club);
        return ['status'=>'ok','sent'=>$sent];
    }
}
