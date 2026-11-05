<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Notification choices only. Never sends mail, grants access, or posts a payment. */
final class ManagerNotifications
{
    // Chat was enabled on subscribed devices before topic preferences existed.
    // Absence of "chat" in an older preference must therefore not disable it.
    private const CHAT_DISABLED='_chat_disabled';
    public const TOPICS=[
        'chat'=>'Neue Chat-Nachrichten',
        'invoices'=>'Neue Rechnungen im Rechnungseingang',
        'payments'=>'Neu erfasste Zahlungseingänge',
        'events'=>'Neu veröffentlichte Veranstaltungen',
        'meetings'=>'Einladungen zu Versammlungen',
        'documents'=>'Neue Dokumente in meinem Account',
        'contributions'=>'Mein Mitgliedsbeitrag wurde vollständig bezahlt',
        'minutes'=>'Neu veröffentlichte Protokolle',
    ];
    public function __construct(private PDO $db,private string $actor){}
    private static function q(PDO $db,string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($db,$sql,$args);}
    private static function atomic(PDO $db,callable $run):mixed
    {
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{$result=$run();if($own)$db->commit();return $result;}
        catch(\Throwable $error){if($own)$db->rollBack();throw $error;}
    }
    private static function allowed(PDO $db,array $a,string $topic):bool
    {
        if($a['role']==='editor')return false;
        if($topic==='chat')return true; // Same authority as ClubChat; room access is checked by its sender.
        if($topic==='meetings'&&!(new Registry($db,$a['id']))->canReadCalendar())return false;
        if($topic==='minutes')return (new Minutes($db,$a['id']))->canRead();
        if(in_array($topic,['invoices','payments'],true))return in_array($a['finance_access'],['read','write'],true);
        $contact=$a['contact_id']??'';
        $person=$contact!==''&&self::q($db,'SELECT 1 FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL',[$a['club_id'],$contact])->fetchColumn();
        if(in_array($topic,['documents','contributions'],true))return (bool)$person;
        if(in_array($topic,['events','meetings'],true))return BoardAccess::allowed($db,$a['id'])||($person&&(bool)self::q($db,"SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv') AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?)",[$a['club_id'],$contact,date('Y-m-d'),date('Y-m-d')])->fetchColumn());
        return false;
    }
    private static function selected(PDO $db,array $a):array
    {
        $raw=self::q($db,'SELECT topics FROM notification_preferences WHERE club_id=? AND actor_id=?',[$a['club_id'],$a['id']])->fetchColumn();
        $stored=$raw?json_decode($raw,true):[];$stored=is_array($stored)?$stored:[];
        $topics=array_values(array_filter($stored,static fn($v)=>is_string($v)&&$v!=='chat'&&isset(self::TOPICS[$v])));
        if(!in_array(self::CHAT_DISABLED,$stored,true))array_unshift($topics,'chat');
        return array_values(array_unique($topics));
    }
    /** Existing chat delivery remains the only sender; it checks this at enqueue and dispatch. */
    public static function chatEnabled(PDO $db,string $actor):bool
    {
        try{$a=Permissions::actor($db,$actor);return self::allowed($db,$a,'chat')&&in_array('chat',self::selected($db,$a),true);}
        catch(DomainException){return false;}
    }
    public function state():array
    {
        $a=Permissions::actor($this->db,$this->actor);$available=[];
        foreach(self::TOPICS as $topic=>$label)if(self::allowed($this->db,$a,$topic))$available[$topic]=$label;
        return ['version'=>(int)self::q($this->db,'SELECT version FROM notification_preferences WHERE club_id=? AND actor_id=?',[$a['club_id'],$a['id']])->fetchColumn(),'available'=>$available,'selected'=>array_values(array_intersect(self::selected($this->db,$a),array_keys($available)))];
    }
    public function save(array $topics,int $version):void
    {
        self::atomic($this->db,function()use($topics,$version){
            $a=Permissions::actor($this->db,$this->actor);$state=$this->state();
            if($a['role']==='editor')throw new DomainException('Benachrichtigungen sind für diesen Zugang nicht verfügbar.');
            if($version!==$state['version'])throw new DomainException('Benachrichtigungseinstellungen inzwischen geändert. Bitte neu laden.');
            foreach($topics as $topic)if(!is_string($topic)||!isset($state['available'][$topic]))throw new DomainException('Bitte nur die für deinen Zugang angebotenen Benachrichtigungen auswählen.');
            $topics=array_values(array_unique($topics));sort($topics);
            $stored=$topics;if(!in_array('chat',$topics,true))$stored[]=self::CHAT_DISABLED;
            self::q($this->db,'INSERT INTO notification_preferences(club_id,actor_id,topics,version,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE topics=VALUES(topics),version=VALUES(version),updated_at=VALUES(updated_at)',[$a['club_id'],$a['id'],json_encode($stored,JSON_THROW_ON_ERROR),$version+1,gmdate('c')]);
            self::q($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'notifications.preferences.saved',$a['id'],$version+1,gmdate('c')]);
            // A later opt-in never replays old events or restores cancelled deliveries.
            foreach(array_diff(array_keys(self::TOPICS),$topics) as $off){
                if($off==='chat')self::q($this->db,"UPDATE push_deliveries SET state='cancelled',updated_at=? WHERE state='pending' AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=? AND actor_id=?)",[gmdate('c'),$a['club_id'],$a['id']]);
                else self::q($this->db,"UPDATE manager_push_deliveries SET state='cancelled',updated_at=? WHERE state='pending' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=? AND topic=?) AND subscription_id IN(SELECT id FROM push_subscriptions WHERE club_id=? AND actor_id=?)",[gmdate('c'),$a['club_id'],$off,$a['club_id'],$a['id']]);
            }
        });
    }
    private static function enqueue(PDO $db,string $club,string $topic,string $source,int $version=1,string $contact='',string $starts='',string $fingerprint=''):void
    {
        self::atomic($db,function()use($db,$club,$topic,$source,$version,$contact,$starts,$fingerprint){
            $id=bin2hex(random_bytes(16));$now=gmdate('c');
            $insert=self::q($db,'INSERT IGNORE INTO manager_notifications(id,club_id,topic,source_id,source_version,contact_id,starts_at,source_fingerprint,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$club,$topic,$source,$version,$contact,$starts,$fingerprint,$now]);
            if(!$insert->rowCount())return;
            $rows=self::q($db,'SELECT s.id,s.actor_id FROM push_subscriptions s JOIN notification_preferences p ON p.club_id=s.club_id AND p.actor_id=s.actor_id WHERE s.club_id=?',[$club])->fetchAll();
            foreach($rows as $row){
                try{$a=Permissions::actor($db,$row['actor_id']);if(!self::allowed($db,$a,$topic)||!in_array($topic,self::selected($db,$a),true)||($contact!==''&&($a['contact_id']??'')!==$contact))continue;if($topic==='invoices'&&!self::sourceVisible($db,$a,['topic'=>$topic,'source_id'=>$source,'contact_id'=>$contact]))continue;}
                catch(DomainException){continue;}
                self::q($db,'INSERT INTO manager_push_deliveries(notification_id,subscription_id,updated_at) VALUES(?,?,?)',[$id,$row['id'],$now]);
            }
        });
    }
    public static function invoiceReceived(PDO $db,string $club,string $document):void
    {
        $d=self::q($db,"SELECT * FROM documents WHERE club_id=? AND id=? AND kind='receipt' AND state='active'",[$club,$document])->fetch();
        if($d&&in_array('finance',json_decode($d['scopes'],true)?:[],true))self::enqueue($db,$club,'invoices',$document);
    }
    public static function paymentReceived(PDO $db,string $club,string $transaction):void
    {
        if(self::q($db,'SELECT 1 FROM finance_transactions WHERE club_id=? AND id=? AND cents>0 AND reversal_of IS NULL',[$club,$transaction])->fetchColumn())self::enqueue($db,$club,'payments',$transaction);
    }
    public static function eventPublished(PDO $db,string $club,string $event,string $startsAtIso):void
    {
        try{$date=new \DateTimeImmutable($startsAtIso);}catch(\Exception){return;}
        if($event===''||strlen($event)>160||$date->getTimestamp()<=time())return;
        $native=self::nativeEventId($event);
        if($native!==null&&!self::nativeEventVisible($db,$club,$native))return;
        self::enqueue($db,$club,'events',$event,1,'',$date->format('c'));
    }
    public static function eventRemoved(PDO $db,string $club,string $event):void
    {
        self::q($db,"UPDATE manager_push_deliveries SET state='cancelled',updated_at=? WHERE state='pending' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=? AND topic='events' AND source_id=?)",[gmdate('c'),$club,$event]);
    }
    public static function minutesAvailable(PDO $db,string $club,string $id,int $revision=1):void
    {
        if(self::q($db,"SELECT 1 FROM minutes WHERE club_id=? AND id=? AND state='published'",[$club,$id])->fetchColumn())self::enqueue($db,$club,'minutes',$id,1);
    }
    public static function minutesRemoved(PDO $db,string $club,string $id):void
    {
        self::q($db,"UPDATE manager_push_deliveries SET state='cancelled',updated_at=? WHERE state='pending' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=? AND topic='minutes' AND source_id=?)",[gmdate('c'),$club,$id]);
    }
    public static function eventRescheduled(PDO $db,string $club,string $event,string $startsAtIso):void
    {
        try{$date=new \DateTimeImmutable($startsAtIso);}catch(\Exception){return;}
        if($event==='')return;
        self::q($db,"UPDATE manager_notifications SET starts_at=? WHERE club_id=? AND topic='events' AND source_id=?",[$date->format('c'),$club,$event]);
    }
    public static function documentAvailable(PDO $db,string $club,string $document,string $contact,int $revision=1):void
    {
        if($contact!=='')self::enqueue($db,$club,'documents',$document,$revision,$contact);
    }
    /** Read-only count for an explicit invitation preview, including people not yet invited. */
    public static function meetingPreview(PDO $db,string $club,string $eventId,array $contacts):array
    {
        $event=self::meeting($db,$club,$eventId);if(!$event)return ['contacts'=>[],'devices'=>0];
        $eligible=array_column(CalendarInvitations::meetingEligibleContacts($db,$club,$event['activity_kind']),'id');
        $contacts=array_values(array_intersect(array_filter($contacts,'is_string'),$eligible));$found=[];$devices=0;
        $rows=self::q($db,'SELECT s.actor_id,s.subscription FROM push_subscriptions s JOIN notification_preferences p ON p.club_id=s.club_id AND p.actor_id=s.actor_id WHERE s.club_id=?',[$club])->fetchAll();
        foreach($rows as $row){
            try{$a=Permissions::actor($db,$row['actor_id']);$contact=$a['contact_id']??'';
                if($a['club_id']!==$club||!in_array($contact,$contacts,true)||!self::allowed($db,$a,'meetings')||!in_array('meetings',self::selected($db,$a),true))continue;
                $subscription=json_decode($row['subscription'],true,512,JSON_THROW_ON_ERROR);if(!is_array($subscription))continue;PushNotifications::validate($subscription);
                $found[$contact]=true;++$devices;
            }catch(DomainException|\JsonException){continue;}
        }
        $found=array_keys($found);sort($found,SORT_STRING);
        return ['contacts'=>$found,'devices'=>$devices];
    }
    /** Called only after an explicit invitation request has persisted the selected attendees. */
    public static function meetingAvailable(PDO $db,string $club,string $eventId,array $contacts,int $version=1):void
    {
        self::atomic($db,function()use($db,$club,$eventId,$contacts,$version){
            $event=self::meeting($db,$club,$eventId);if(!$event||(int)$event['version']!==$version)return;
            $preview=self::meetingPreview($db,$club,$eventId,$contacts);
            foreach($preview['contacts'] as $contact){
                if(!self::q($db,'SELECT 1 FROM appointment_attendees WHERE club_id=? AND appointment_id=? AND contact_id=?',[$club,$eventId,$contact])->fetchColumn())continue;
                self::enqueue($db,$club,'meetings',$eventId,$version,$contact,'',CalendarInvitations::eventFingerprint($event));
            }
        });
    }
    private static function meeting(PDO $db,string $club,string $id):array|false
    {
        $event=self::q($db,"SELECT * FROM appointments WHERE club_id=? AND id=? AND activity_kind IN ('assembly','board') AND activity_state='planned'",[$club,$id])->fetch();
        return $event&&strtotime($event['starts_at'])>time()?$event:false;
    }
    public static function contributionPaid(PDO $db,string $club,string $entry):void
    {
        $row=self::q($db,"SELECT i.recipient_contact_id,e.version FROM contribution_invoices i JOIN finance_entries e ON e.club_id=i.club_id AND e.id=i.entry_id WHERE i.club_id=? AND i.entry_id=? AND i.state='issued' AND e.kind='contribution' AND e.status='paid'",[$club,$entry])->fetch();
        if($row)self::enqueue($db,$club,'contributions',$entry,(int)$row['version'],$row['recipient_contact_id']);
    }
    private static function documentVisible(PDO $db,array $a,string $document):bool
    {
        return (bool)self::q($db,"SELECT 1 FROM documents d WHERE d.club_id=? AND d.id=? AND d.state='active' AND d.admission_only=0 AND (EXISTS(SELECT 1 FROM portal_document_shares s WHERE s.club_id=d.club_id AND s.document_id=d.id AND s.contact_id=?) OR EXISTS(SELECT 1 FROM contribution_invoices i WHERE i.club_id=d.club_id AND i.document_id=d.id AND i.recipient_contact_id=? AND i.state='issued'))",[$a['club_id'],$document,$a['contact_id'],$a['contact_id']])->fetchColumn();
    }
    private static function sourceVisible(PDO $db,array $a,array $event):bool
    {
        $club=$a['club_id'];$id=$event['source_id'];
        if($event['contact_id']!==''&&($a['contact_id']??'')!==$event['contact_id'])return false;
        if($event['topic']==='meetings'){
            $meeting=self::meeting($db,$club,$id);if(!$meeting||!hash_equals((string)$event['source_fingerprint'],CalendarInvitations::eventFingerprint($meeting)))return false;
            if(!in_array($a['contact_id']??'',array_column(CalendarInvitations::meetingEligibleContacts($db,$club,$meeting['activity_kind']),'id'),true))return false;
            if(!self::q($db,'SELECT 1 FROM appointment_attendees WHERE club_id=? AND appointment_id=? AND contact_id=?',[$club,$id,$a['contact_id']])->fetchColumn())return false;
            try{(new Registry($db,$a['id']))->appointment($id);return true;}catch(DomainException){return false;}
        }
        if($event['topic']==='minutes'){
            try{return (new Minutes($db,$a['id']))->entry($id)['state']==='published';}
            catch(DomainException){return false;}
        }
        if($event['topic']==='events'){
            $native=self::nativeEventId($id);
            return strtotime($event['starts_at'])>time()&&($native===null||self::nativeEventVisible($db,$club,$native));
        }
        if($event['topic']==='documents')return self::documentVisible($db,$a,$id);
        if($event['topic']==='payments')return (bool)self::q($db,'SELECT 1 FROM finance_transactions t WHERE club_id=? AND id=? AND cents>0 AND reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id)',[$club,$id])->fetchColumn();
        if($event['topic']==='invoices'){
            try{$d=(new Documents($db,$a['id']))->document($id);}
            catch(DomainException){return false;}
            return $d['kind']==='receipt'&&$d['state']==='active'&&in_array('finance',$d['scopes'],true);
        }
        if($event['topic']==='contributions')return (bool)self::q($db,"SELECT 1 FROM contribution_invoices i JOIN finance_entries e ON e.club_id=i.club_id AND e.id=i.entry_id JOIN documents d ON d.club_id=i.club_id AND d.id=i.document_id WHERE i.club_id=? AND i.entry_id=? AND i.recipient_contact_id=? AND i.state='issued' AND d.state='active' AND e.status='paid' AND NOT EXISTS(SELECT 1 FROM finance_reversals r WHERE r.club_id=e.club_id AND r.entry_id=e.id)",[$club,$id,$a['contact_id']])->fetchColumn();
        return false;
    }
    private static function nativeEventId(string $source):?string
    {return preg_match('/^website:([a-f0-9]{32})$/D',$source,$match)?$match[1]:null;}
    private static function nativeEventVisible(PDO $db,string $club,string $id):bool
    {
        return class_exists(\Kulturhus\Native\Cms\EventPublications::class)
            &&\Kulturhus\Native\Cms\EventPublications::eligible($db,$club,$id);
    }
    public static function hasPending(PDO $db,?string $club=null):bool{return (bool)self::q($db,"SELECT 1 FROM manager_push_deliveries WHERE state='pending'".($club!==null?' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=?)':'')." LIMIT 1",$club!==null?[$club]:[])->fetchColumn();}
    public static function dispatch(PDO $db,callable $send,int $limit=20,?string $club=null):int
    {
        self::q($db,"UPDATE manager_push_deliveries SET state='uncertain',error='Unterbrochener Versand' WHERE state='sending' AND updated_at<?".($club!==null?' AND notification_id IN(SELECT id FROM manager_notifications WHERE club_id=?)':''),$club!==null?[gmdate('c',time()-900),$club]:[gmdate('c',time()-900)]);
        $rows=self::q($db,"SELECT n.*,d.subscription_id,s.actor_id,s.subscription FROM manager_push_deliveries d JOIN manager_notifications n ON n.id=d.notification_id JOIN push_subscriptions s ON s.id=d.subscription_id AND s.club_id=n.club_id WHERE d.state='pending'".($club!==null?' AND n.club_id=?':'')." ORDER BY n.created_at,n.id LIMIT ".max(1,min(20,$limit)),$club!==null?[$club]:[])->fetchAll();$sent=0;
        foreach($rows as $row){
            try{$a=Permissions::actor($db,$row['actor_id']);if($a['club_id']!==$row['club_id']||!self::allowed($db,$a,$row['topic'])||!in_array($row['topic'],self::selected($db,$a),true)||!self::sourceVisible($db,$a,$row))throw new DomainException('Hinweis nicht mehr verfügbar.');$subscription=json_decode($row['subscription'],true,512,JSON_THROW_ON_ERROR);if(!is_array($subscription))throw new DomainException('Geräteanmeldung ungültig.');$subscription=PushNotifications::validate($subscription);}
            catch(DomainException|\JsonException){self::q($db,"UPDATE manager_push_deliveries SET state='cancelled',updated_at=? WHERE notification_id=? AND subscription_id=? AND state='pending'",[gmdate('c'),$row['id'],$row['subscription_id']]);continue;}
            $claim=self::q($db,"UPDATE manager_push_deliveries SET state='sending',updated_at=? WHERE notification_id=? AND subscription_id=? AND state='pending'",[gmdate('c'),$row['id'],$row['subscription_id']]);if(!$claim->rowCount())continue;
            if($row['topic']==='events'&&self::nativeEventId($row['source_id'])!==null){
                try{
                    $current=Permissions::actor($db,$row['actor_id']);
                    $device=self::q($db,'SELECT subscription FROM push_subscriptions WHERE club_id=? AND id=? AND actor_id=?',[$row['club_id'],$row['subscription_id'],$row['actor_id']])->fetchColumn();
                    if($current['club_id']!==$row['club_id']||!self::allowed($db,$current,'events')||!in_array('events',self::selected($db,$current),true)||!self::sourceVisible($db,$current,$row)||!is_string($device)||!hash_equals($device,$row['subscription']))throw new DomainException('Veranstaltungshinweis nicht mehr verfügbar.');
                }catch(DomainException){self::q($db,"UPDATE manager_push_deliveries SET state='cancelled',updated_at=? WHERE notification_id=? AND subscription_id=? AND state='sending'",[gmdate('c'),$row['id'],$row['subscription_id']]);continue;}
            }
            try{$state=$send($subscription,json_encode(['type'=>$row['topic']],JSON_THROW_ON_ERROR));if(!in_array($state,['sent','expired','failed'],true))$state='failed';}catch(\Throwable){$state='uncertain';}
            if($state==='expired'){self::q($db,'DELETE FROM push_subscriptions WHERE id=?',[$row['subscription_id']]);continue;}
            self::q($db,'UPDATE manager_push_deliveries SET state=?,updated_at=?,error=? WHERE notification_id=? AND subscription_id=?',[$state,gmdate('c'),$state==='sent'?'':'Push-Zustellung nicht bestätigt',$row['id'],$row['subscription_id']]);if($state==='sent')++$sent;
        }
        return $sent;
    }
}
