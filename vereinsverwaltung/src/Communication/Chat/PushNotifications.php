<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class PushNotifications
{
    public function __construct(private PDO $db,private string $actor){}
    private function authority():array{$a=Permissions::actor($this->db,$this->actor);if($a['role']==='editor')throw new DomainException('Push ist für diesen Zugang nicht verfügbar.');return $a;}
    public static function validate(array $subscription):array
    {
        $endpoint=$subscription['endpoint']??'';
        if(!is_string($endpoint)||strlen($endpoint)>2048)throw new DomainException('Push-Adresse prüfen.');
        $u=parse_url($endpoint);$host=strtolower($u['host']??'');
        $allowed=$host==='fcm.googleapis.com'||str_ends_with($host,'.push.apple.com')||$host==='updates.push.services.mozilla.com'||str_ends_with($host,'.push.services.mozilla.com')||str_ends_with($host,'.notify.windows.com');
        if(!$allowed||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['fragment'])||empty($u['path']))throw new DomainException('Dieser Browser-Pushanbieter wird nicht unterstützt.');
        $keys=[];
        foreach(['p256dh'=>65,'auth'=>16] as $key=>$length){$v=$subscription['keys'][$key]??'';if(!is_string($v)||strlen($v)>100||!preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$v))throw new DomainException('Push-Schlüssel prüfen.');$bytes=base64_decode(strtr($v,'-_','+/'),true);if($bytes===false||strlen($bytes)!==$length||($key==='p256dh'&&ord($bytes[0])!==4))throw new DomainException('Push-Schlüssel prüfen.');$keys[$key]=rtrim($v,'=');}
        return ['endpoint'=>$endpoint,'keys'=>$keys,'contentEncoding'=>'aes128gcm'];
    }
    public function save(array $subscription):void
    {
        $a=$this->authority();$s=self::validate($subscription);$hash=hash('sha256',$s['endpoint']);
        $this->db->beginTransaction();
        try{
            $this->authority();
            if((int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM push_subscriptions WHERE actor_id=?',[$a['id']])->fetchColumn()>=10&&!DocumentCatalog::query($this->db,'SELECT 1 FROM push_subscriptions WHERE actor_id=? AND endpoint_hash=?',[$a['id'],$hash])->fetchColumn())throw new DomainException('Höchstens zehn Push-Geräte pro Zugang.');
            // A shared browser may change accounts: remove the old binding and queued hints.
            DocumentCatalog::query($this->db,'DELETE FROM push_subscriptions WHERE endpoint_hash=?',[$hash]);
            DocumentCatalog::query($this->db,'INSERT INTO push_subscriptions VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$a['club_id'],$a['id'],$hash,json_encode($s,JSON_THROW_ON_ERROR),gmdate('c')]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function remove(string $endpoint):void{$a=$this->authority();DocumentCatalog::query($this->db,'DELETE FROM push_subscriptions WHERE actor_id=? AND endpoint_hash=?',[$a['id'],hash('sha256',$endpoint)]);}
    public function removeAll():void{$a=$this->authority();DocumentCatalog::query($this->db,'DELETE FROM push_subscriptions WHERE actor_id=?',[$a['id']]);}
    public function count():int{$a=$this->authority();return (int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM push_subscriptions WHERE actor_id=?',[$a['id']])->fetchColumn();}
    /** Called inside the chat message transaction. No message text leaves the application. */
    public static function enqueue(PDO $db,int $seq):void
    {
        $rows=DocumentCatalog::query($db,"SELECT s.id,s.actor_id FROM club_chat_messages m JOIN club_chats c ON c.id=m.chat_id JOIN club_chat_people p ON p.chat_id=c.id JOIN push_subscriptions s ON s.actor_id=p.actor_id AND s.club_id=c.club_id JOIN actors a ON a.id=s.actor_id AND a.club_id=c.club_id WHERE m.seq=? AND m.actor_id!=p.actor_id",[$seq])->fetchAll();
        foreach($rows as $row){
            if(!ManagerNotifications::chatEnabled($db,$row['actor_id']))continue;
            DocumentCatalog::query($db,'INSERT INTO push_deliveries(subscription_id,message_seq,updated_at) VALUES(?,?,?)',[$row['id'],$seq,gmdate('c')]);
        }
    }
    public static function dispatch(PDO $db,callable $send,int $limit=20,?string $club=null):int
    {
        DocumentCatalog::query($db,"UPDATE push_deliveries SET state='uncertain',error='Unterbrochener Versand' WHERE state='sending' AND updated_at<?".($club!==null?' AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=?)':''),$club!==null?[gmdate('c',time()-900),$club]:[gmdate('c',time()-900)]);
        $rows=DocumentCatalog::query($db,"SELECT d.*,s.actor_id,s.club_id,s.subscription,m.chat_id FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id JOIN club_chat_messages m ON m.seq=d.message_seq WHERE d.state='pending'".($club!==null?' AND s.club_id=?':'')." ORDER BY d.message_seq LIMIT ".max(1,min(20,$limit)),$club!==null?[$club]:[])->fetchAll();$sent=0;
        foreach($rows as $row){
            try{$a=Permissions::actor($db,$row['actor_id']);if($a['role']==='editor'||$a['club_id']!==$row['club_id'])throw new DomainException('Zugang gesperrt.');
                if(!ManagerNotifications::chatEnabled($db,$row['actor_id']))throw new DomainException('Chat-Benachrichtigungen ausgeschaltet.');
                (new ClubChat($db,$row['actor_id']))->thread($row['chat_id']);
                $read=(int)DocumentCatalog::query($db,'SELECT last_read FROM club_chat_people WHERE chat_id=? AND actor_id=?',[$row['chat_id'],$row['actor_id']])->fetchColumn();
                if($read>=$row['message_seq'])throw new DomainException('Bereits gelesen.');
                $subscription=self::validate(json_decode($row['subscription'],true,512,JSON_THROW_ON_ERROR));
            }catch(DomainException|\JsonException){DocumentCatalog::query($db,"UPDATE push_deliveries SET state='cancelled',updated_at=? WHERE subscription_id=? AND message_seq=? AND state='pending'",[gmdate('c'),$row['subscription_id'],$row['message_seq']]);continue;}
            $claim=DocumentCatalog::query($db,"UPDATE push_deliveries SET state='sending',updated_at=? WHERE subscription_id=? AND message_seq=? AND state='pending'",[gmdate('c'),$row['subscription_id'],$row['message_seq']]);if(!$claim->rowCount())continue;
            try{$state=$send($subscription,json_encode(['title'=>'DVM','body'=>'Neue Nachricht im Vereinschat.','url'=>'/vereinsverwaltung/?view=chat','tag'=>'kulturhus-chat'],JSON_THROW_ON_ERROR));if(!in_array($state,['sent','expired','failed'],true))$state='failed';}
            catch(\Throwable){$state='uncertain';}
            if($state==='expired'){DocumentCatalog::query($db,'DELETE FROM push_subscriptions WHERE id=?',[$row['subscription_id']]);continue;}
            DocumentCatalog::query($db,'UPDATE push_deliveries SET state=?,updated_at=?,error=? WHERE subscription_id=? AND message_seq=?',[$state,gmdate('c'),$state==='sent'?'':'Push-Zustellung nicht bestätigt',$row['subscription_id'],$row['message_seq']]);if($state==='sent')++$sent;
        }
        return $sent;
    }
}
