<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Verein\{ManagerNotifications,PushNotifications};
require_once dirname(__DIR__,2).'/src/autoload.php';

/** Original native messages use the existing personal account device registrations. */
final class Push
{
    public static function available(PDO $db):bool
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return false;
        return(int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN('native_chat_push_deliveries','native_manager_identities','push_subscriptions','native_auth_accounts')")->fetchColumn()===4;
    }
    public static function enqueue(PDO $db,array $thread,int $seq):void
    {
        if(!$db->inTransaction())throw new \LogicException('Push benötigt die ursprüngliche Nachrichtentransaktion.');
        if(!self::available($db))return;
        $rows=Db::run($db,'SELECT o.actor_id,i.manager_actor_id,i.manager_club_id,s.id,s.subscription FROM native_chat_outbox o JOIN native_manager_identities i ON i.native_id=o.actor_id JOIN native_auth_accounts a ON a.actor_id=o.actor_id AND a.active=1 AND a.verified=1 AND a.suspended=0 JOIN push_subscriptions s ON s.actor_id=i.manager_actor_id AND s.club_id=i.manager_club_id WHERE o.club_id=? AND o.thread_id=? AND o.message_seq=? AND o.status="pending"',[$thread['club_id'],$thread['id'],$seq])->fetchAll();
        foreach($rows as$row){
            if(!ManagerNotifications::chatEnabled($db,$row['manager_actor_id']))continue;
            try{$subscription=json_decode($row['subscription'],true,16,JSON_THROW_ON_ERROR);if(!is_array($subscription))continue;PushNotifications::validate($subscription);}catch(DomainException|\JsonException){continue;}
            $payload=['title'=>'DVM','body'=>'Neue Nachricht im Vereinschat.','url'=>'/vereinsverwaltung/?'.http_build_query(['view'=>'chat','native_item'=>$thread['id']]),'tag'=>'dvm-native-chat-'.substr(hash('sha256',$thread['club_id'].':'.$thread['id']),0,32)];
            Db::run($db,'INSERT IGNORE INTO native_chat_push_deliveries(club_id,thread_id,message_seq,actor_id,subscription_id,manager_club_id,manager_actor_id,subscription_hash,payload,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$thread['club_id'],$thread['id'],$seq,$row['actor_id'],$row['id'],$row['manager_club_id'],$row['manager_actor_id'],hash('sha256',$row['subscription']),json_encode($payload,JSON_THROW_ON_ERROR)]);
        }
    }
    public static function oldest(PDO $db,?string $club=null):?int
    {
        if(!self::available($db))return null;$value=Db::run($db,'SELECT MIN(created_at) FROM native_chat_push_deliveries WHERE state="pending"'.($club!==null?' AND manager_club_id=?':''),$club!==null?[$club]:[])->fetchColumn();return is_string($value)?(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->getTimestamp():null;
    }
    public static function recoverInterrupted(PDO $db,?string $club=null):void
    {
        if(!self::available($db))return;Db::run($db,'UPDATE native_chat_push_deliveries SET state="uncertain",updated_at=UTC_TIMESTAMP(6) WHERE state="sending" AND updated_at<UTC_TIMESTAMP(6)-INTERVAL 15 MINUTE'.($club!==null?' AND manager_club_id=?':''),$club!==null?[$club]:[]);
    }
    public static function devices(PDO $db,Access $access):array
    {
        $actor=$access->require('chat','read');if(!self::available($db))return ['linked'=>false,'count'=>0,'enabled'=>false];
        $binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?',[$actor['id']])->fetch();
        if(!$binding)return ['linked'=>false,'count'=>0,'enabled'=>false];
        $count=(int)Db::run($db,'SELECT COUNT(*) FROM push_subscriptions WHERE club_id=? AND actor_id=?',[$binding['manager_club_id'],$binding['manager_actor_id']])->fetchColumn();
        return ['linked'=>true,'count'=>$count,'enabled'=>ManagerNotifications::chatEnabled($db,$binding['manager_actor_id'])];
    }
    private static function identity(array $row):array
    {return[$row['club_id'],$row['thread_id'],$row['message_seq'],$row['actor_id'],$row['subscription_id']];}
    private static function update(PDO $db,array $row,string $state,string $from):bool
    {
        return Db::run($db,'UPDATE native_chat_push_deliveries SET state=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND thread_id=? AND message_seq=? AND actor_id=? AND subscription_id=? AND state=?',[$state,...self::identity($row),$from])->rowCount()===1;
    }
    private static function current(PDO $db,array $row):array
    {
        $binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?',[$row['actor_id']])->fetch();
        if(!$binding||$binding['manager_actor_id']!==$row['manager_actor_id']||$binding['manager_club_id']!==$row['manager_club_id'])throw new DomainException('Gerätezuordnung geändert.');
        if(!Db::run($db,'SELECT 1 FROM native_auth_accounts WHERE actor_id=? AND active=1 AND verified=1 AND suspended=0',[$row['actor_id']])->fetchColumn())throw new DomainException('Persönlicher Zugang nicht aktiv.');
        $access=(new ManagerBridge($db,$row['manager_actor_id'],'native',static fn()=>null))->access();
        if($access->club()!==$row['club_id']||$access->user()['id']!==$row['actor_id'])throw new DomainException('Vereinszuordnung geändert.');
        $access->require('chat','read');(new Workspace($db,$access))->get($row['thread_id']);
        if(!ManagerNotifications::chatEnabled($db,$row['manager_actor_id']))throw new DomainException('Benachrichtigungen ausgeschaltet.');
        $active=Db::run($db,'SELECT 1 FROM native_chat_outbox o JOIN native_chat_people p ON p.club_id=o.club_id AND p.thread_id=o.thread_id AND p.actor_id=o.actor_id JOIN native_chat_alert_preferences f ON f.club_id=o.club_id AND f.actor_id=o.actor_id AND f.enabled=1 LEFT JOIN native_chat_mutes m ON m.club_id=o.club_id AND m.thread_id=o.thread_id AND m.actor_id=o.actor_id WHERE o.club_id=? AND o.thread_id=? AND o.message_seq=? AND o.actor_id=? AND o.status="pending" AND p.last_read<o.message_seq AND COALESCE(m.muted,0)=0',array_slice(self::identity($row),0,4))->fetchColumn();
        if(!$active)throw new DomainException('Nachricht nicht mehr offen.');
        $raw=Db::run($db,'SELECT subscription FROM push_subscriptions WHERE id=? AND club_id=? AND actor_id=?',[$row['subscription_id'],$row['manager_club_id'],$row['manager_actor_id']])->fetchColumn();
        if(!is_string($raw)||!hash_equals($row['subscription_hash'],hash('sha256',$raw)))throw new DomainException('Ursprüngliches Gerät nicht mehr verfügbar.');
        return PushNotifications::validate(json_decode($raw,true,16,JSON_THROW_ON_ERROR));
    }
    public static function dispatch(PDO $db,callable $send,int $limit=20,?string $club=null):int
    {
        if(!self::available($db))return 0;$scope=$club!==null?' AND manager_club_id=?':'';$args=$club!==null?[$club]:[];
        self::recoverInterrupted($db,$club);
        $rows=Db::run($db,'SELECT * FROM native_chat_push_deliveries WHERE state="pending"'.$scope.' ORDER BY created_at,club_id,thread_id,message_seq,actor_id,subscription_id LIMIT '.max(1,min(20,$limit)),$args)->fetchAll();$sent=0;
        foreach($rows as$row){
            try{$subscription=self::current($db,$row);$payload=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);$expected=['title'=>'DVM','body'=>'Neue Nachricht im Vereinschat.','url'=>'/vereinsverwaltung/?'.http_build_query(['view'=>'chat','native_item'=>$row['thread_id']]),'tag'=>'dvm-native-chat-'.substr(hash('sha256',$row['club_id'].':'.$row['thread_id']),0,32)];if(!is_array($payload)||count($payload)!==count($expected))throw new DomainException('Ursprünglichen Hinweis prüfen.');foreach($expected as$key=>$value)if(($payload[$key]??null)!==$value)throw new DomainException('Ursprünglichen Hinweis prüfen.');}catch(DomainException|\JsonException){self::update($db,$row,'cancelled','pending');continue;}
            if(!self::update($db,$row,'sending','pending'))continue;
            try{$subscription=self::current($db,$row);}catch(DomainException|\JsonException){self::update($db,$row,'cancelled','sending');continue;}
            try{$state=$send($subscription,json_encode($payload,JSON_THROW_ON_ERROR));if(!in_array($state,['sent','expired','failed'],true))$state='uncertain';}catch(\Throwable){$state='uncertain';}
            self::update($db,$row,$state,'sending');if($state==='sent')++$sent;
            if($state==='expired')Db::run($db,'DELETE FROM push_subscriptions WHERE id=? AND club_id=? AND actor_id=? AND SHA2(subscription,256)=?',[$row['subscription_id'],$row['manager_club_id'],$row['manager_actor_id'],$row['subscription_hash']]);
        }
        return$sent;
    }
}
