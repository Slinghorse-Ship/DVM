<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Durable local intent and deduplicated queue. This class contains no network transport. */
final class Notifications
{
    public function __construct(private PDO $db, private Access $access) {}
    public function state(): array
    {
        $a=$this->access->require('chat','read');
        $row=Db::run($this->db,'SELECT enabled,version FROM native_chat_alert_preferences WHERE club_id=? AND actor_id=?',[$a['club_id'],$a['id']])->fetch();
        $devices=Db::run($this->db,'SELECT device_key,enabled,updated_at,subscription_json IS NOT NULL has_subscription FROM native_chat_devices WHERE club_id=? AND actor_id=? ORDER BY updated_at DESC',[$a['club_id'],$a['id']])->fetchAll();
        $push=Push::devices($this->db,$this->access);$configured=\Kulturhus\Verein\WebPushDelivery::publicKey()!=='';
        return ['enabled'=>(bool)($row['enabled']??false),'version'=>(int)($row['version']??0),'devices'=>$devices,'pending'=>count($this->queue()),'transport'=>$configured?'configured':'disabled','closed_browser_available'=>$configured&&$push['enabled']&&$push['count']>0,'account_devices'=>$push];
    }
    public function set(bool $enabled,int $expectedVersion): array
    {
        Db::atomic($this->db,function()use($enabled,$expectedVersion):void{
            $a=$this->access->require('chat','read');
            // User lock serializes first preference/device creation as well as changes.
            Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$a['id']]);
            $old=Db::run($this->db,'SELECT version FROM native_chat_alert_preferences WHERE club_id=? AND actor_id=? FOR UPDATE',[$a['club_id'],$a['id']])->fetchColumn();
            if((int)$old!==$expectedVersion)throw new Conflict('Benachrichtigungseinstellung inzwischen geändert.');
            Db::run($this->db,'INSERT INTO native_chat_alert_preferences(club_id,actor_id,enabled,version) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE enabled=?,version=version+1',[$a['club_id'],$a['id'],(int)$enabled,(int)$enabled]);
            if(!$enabled)Db::run($this->db,'UPDATE native_chat_outbox SET status="cancelled" WHERE club_id=? AND actor_id=? AND status="pending"',[$a['club_id'],$a['id']]);
        });
        return $this->state();
    }
    public function muted(string $id): bool
    {
        $t=(new Workspace($this->db,$this->access))->get($id);
        return (bool)Db::run($this->db,'SELECT muted FROM native_chat_mutes WHERE club_id=? AND thread_id=? AND actor_id=?',[$t['club_id'],$id,$this->access->user()['id']])->fetchColumn();
    }
    public function mute(string $id,bool $muted):void
    {
        Db::atomic($this->db,function()use($id,$muted):void{
            $t=(new Workspace($this->db,$this->access))->locked($id);$a=$this->access->user();
            Db::run($this->db,'INSERT INTO native_chat_mutes(club_id,thread_id,actor_id,muted) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE muted=?',[$t['club_id'],$id,$a['id'],(int)$muted,(int)$muted]);
            if($muted)Db::run($this->db,'UPDATE native_chat_outbox SET status="cancelled" WHERE club_id=? AND thread_id=? AND actor_id=? AND status="pending"',[$t['club_id'],$id,$a['id']]);
        });
    }
    /** Local device key is random, never a browser fingerprint. No PushManager subscription is requested. */
    public function device(string $key,bool $enabled,?array $subscription=null):void
    {
        Workspace::requestKey($key);$json=null;
        if($subscription!==null){
            $endpoint=$subscription['endpoint']??'';$keys=$subscription['keys']??[];$url=is_string($endpoint)?parse_url($endpoint):false;
            if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||strlen($endpoint)>2000||preg_match('/[\s\\\\]/',$endpoint)||!filter_var($endpoint,FILTER_VALIDATE_URL))throw new DomainException('Ungültiges Push-Abonnement.');
            foreach(['p256dh','auth'] as $name)if(!is_string($keys[$name]??null)||!preg_match('/^[A-Za-z0-9_-]{16,200}$/D',$keys[$name]))throw new DomainException('Ungültiger Abonnementschlüssel.');
            $json=json_encode(['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']]],JSON_THROW_ON_ERROR);
        }
        Db::atomic($this->db,function()use($key,$enabled,$json):void{
            $a=$this->access->require('chat','read');Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$a['id']]);
            $hash=hash('sha256',$key);
            if($enabled&&(int)Db::run($this->db,'SELECT COUNT(*) FROM native_chat_devices WHERE club_id=? AND actor_id=? AND device_key<>?',[$a['club_id'],$a['id'],$hash])->fetchColumn()>=20)throw new DomainException('Höchstens 20 lokale Geräte.');
            Db::run($this->db,'INSERT INTO native_chat_devices(club_id,actor_id,device_key,enabled,subscription_json,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=?,subscription_json=?,updated_at=UTC_TIMESTAMP(6)',[$a['club_id'],$a['id'],$hash,(int)$enabled,$enabled?$json:null,(int)$enabled,$enabled?$json:null]);
        });
    }
    public function disableDevices():void
    {
        Db::atomic($this->db,function():void{
            $a=$this->access->require('chat','read');
            Db::run($this->db,'UPDATE native_chat_devices SET enabled=0,subscription_json=NULL WHERE club_id=? AND actor_id=?',[$a['club_id'],$a['id']]);
            Db::run($this->db,'UPDATE native_chat_outbox SET status="cancelled" WHERE club_id=? AND actor_id=? AND status="pending"',[$a['club_id'],$a['id']]);
        });
    }
    public function queue():array
    {
        $a=$this->access->require('chat','read');
        return Db::run($this->db,'SELECT o.thread_id,o.message_seq,o.status,o.created_at FROM native_chat_outbox o JOIN native_chat_people p ON p.club_id=o.club_id AND p.thread_id=o.thread_id AND p.actor_id=o.actor_id JOIN native_chat_alert_preferences f ON f.club_id=o.club_id AND f.actor_id=o.actor_id AND f.enabled=1 LEFT JOIN native_chat_mutes m ON m.club_id=o.club_id AND m.thread_id=o.thread_id AND m.actor_id=o.actor_id WHERE o.club_id=? AND o.actor_id=? AND o.status="pending" AND o.message_seq>p.last_read AND COALESCE(m.muted,0)=0 ORDER BY o.message_seq LIMIT 200',[$a['club_id'],$a['id']])->fetchAll();
    }
    /** Called inside the append transaction, so failed messages cannot leave notifications. */
    public static function enqueue(PDO $db,array $thread,int $seq,string $author):void
    {
        if(!$db->inTransaction())throw new \LogicException('Benachrichtigung benötigt Nachrichtentransaktion.');
        Db::run($db,'INSERT IGNORE INTO native_chat_outbox(club_id,thread_id,message_seq,actor_id,created_at)
            SELECT p.club_id,p.thread_id,?,p.actor_id,UTC_TIMESTAMP(6) FROM native_chat_people p
            JOIN native_users u ON u.id=p.actor_id AND u.club_id=p.club_id AND u.active=1
            JOIN native_grants g ON g.actor_id=u.id AND g.module="chat" AND g.capability="read"
            JOIN native_chat_alert_preferences f ON f.club_id=p.club_id AND f.actor_id=p.actor_id AND f.enabled=1
            LEFT JOIN native_chat_mutes m ON m.club_id=p.club_id AND m.thread_id=p.thread_id AND m.actor_id=p.actor_id
            WHERE p.club_id=? AND p.thread_id=? AND p.actor_id<>? AND p.last_read<? AND COALESCE(m.muted,0)=0',[$seq,$thread['club_id'],$thread['id'],$author,$seq]);
        Push::enqueue($db,$thread,$seq);
    }
}
