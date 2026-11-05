<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class CalendarInvitations
{
    public const GROUPS=['members'=>'Alle aktuellen Mitglieder','active'=>'Aktive Mitglieder','passive'=>'Passive Mitglieder','contacts'=>'Kontakte ohne aktuelle Mitgliedschaft','all'=>'Alle Mitglieder und Kontakte','selected'=>'Einzelne Personen auswählen'];
    public function __construct(private PDO $db,private string $actorId,private Registry $registry){}
    public function canSend():bool{return BoardAccess::allowed($this->db,$this->actorId);}
    public function mailAllowed():bool{return MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL'));}
    /** Invitation validity concerns content, not later additions to the attendee list. */
    public static function eventFingerprint(array $event):string
    {
        $values=[];foreach(['title','starts_at','ends_at','location','description','category','activity_kind','activity_state','source_reference','source_url'] as $field)$values[$field]=(string)($event[$field]??'');
        $values['end_known']=(bool)($event['end_known']??true);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    /** Internal roster check shared with notification delivery; never a public endpoint. */
    public static function meetingEligibleContacts(PDO $db,string $club,string $kind):array
    {
        if($kind==='assembly')return DocumentCatalog::query($db,"SELECT c.id,c.name,c.email,c.address FROM contacts c WHERE c.club_id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN('aktiv','passiv') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?)) ORDER BY c.name,c.id",[$club,date('Y-m-d'),date('Y-m-d')])->fetchAll();
        if($kind!=='board')throw new DomainException('Mitglieder- oder Vorstandsversammlung auswählen.');
        $rows=DocumentCatalog::query($db,"SELECT c.id,c.name,c.email,c.address,b.actor_id,b.office FROM admission_board b JOIN actors a ON a.id=b.actor_id AND a.club_id=b.club_id JOIN contacts c ON c.id=a.contact_id AND c.club_id=a.club_id JOIN admission_board_checks v ON v.club_id=b.club_id AND v.actor_id=b.actor_id AND v.board_version=b.version AND v.office=CASE WHEN b.office='Schriftführer' THEN 'Schriftwart' ELSE b.office END WHERE b.club_id=? AND b.enabled=1 AND a.active=1 AND a.role!='editor' AND c.kind='person' AND c.archived_at IS NULL AND b.office IN('1. Vorstand','2. Vorstand','Schriftwart','Schriftführer','Kassenwart') ORDER BY c.name,c.id",[$club])->fetchAll();
        $people=[];foreach($rows as $person){try{Permissions::actor($db,$person['actor_id']);}catch(DomainException){continue;}$person['office']=AdmissionVotes::officeName($person['office']);$people[$person['id']]=$person;}
        return array_values($people);
    }
    public static function meetingPeople(PDO $db,string $actor,string $kind):array
    {
        return (new self($db,$actor,new Registry($db,$actor)))->contacts($kind);
    }
    public function contactsFor(string $eventId):array
    {
        $event=$this->registry->appointment($eventId);$kind=$event['activity_kind']??'';
        return $this->contacts(in_array($kind,['assembly','board'],true)?$kind:'');
    }
    /** Small original-name/email window for choosing recipients, without their addresses. */
    public function selectionPage(string $eventId,array $input=[]):array
    {
        $a=BoardAccess::require($this->db,$this->actorId);$event=$this->registry->appointment($eventId);
        $page=$this->registry->contactChoicePage($input,true,(string)($event['activity_kind']??'event'));
        $ids=array_column($page['rows'],'id');$emails=[];$family=[];
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
            $emails=DocumentCatalog::query($this->db,'SELECT id,email FROM contacts WHERE club_id=? AND archived_at IS NULL AND id IN ('.$marks.')',[$a['club_id'],...$ids])->fetchAll(\PDO::FETCH_KEY_PAIR);
            $rows=DocumentCatalog::query($this->db,'SELECT p.contact_id,c.email FROM member_family_people p JOIN member_families f ON f.club_id=p.club_id AND f.id=p.family_id JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE p.club_id=? AND c.archived_at IS NULL AND p.contact_id IN ('.$marks.')',[$a['club_id'],...$ids])->fetchAll();$family=array_column($rows,'email','contact_id');
        }
        foreach($page['rows'] as&$person){$person['email']=trim((string)($emails[$person['id']]??''));$person['email_source']='own';if(!filter_var($person['email'],FILTER_VALIDATE_EMAIL)&&filter_var($family[$person['id']]??'',FILTER_VALIDATE_EMAIL)){$person['email']=$family[$person['id']];$person['email_source']='family';}}unset($person);
        $current=BoardAccess::require($this->db,$this->actorId);if($a['id']!==$current['id']||$a['club_id']!==$current['club_id']||$event['club_id']!==$current['club_id'])throw new DomainException('Empfänger nach geänderten Zugangsrechten erneut auswählen.');
        return $page;
    }
    public function selectionGroup(string $eventId):array
    {
        $a=BoardAccess::require($this->db,$this->actorId);$event=$this->registry->appointment($eventId);
        $ids=$this->registry->calendarSelectionGroup((string)($event['activity_kind']??'event'));
        $current=BoardAccess::require($this->db,$this->actorId);if($a['id']!==$current['id']||$a['club_id']!==$current['club_id']||$event['club_id']!==$current['club_id'])throw new DomainException('Empfänger nach geänderten Zugangsrechten erneut auswählen.');
        return $ids;
    }
    public function groupsFor(string $eventId):array
    {
        $kind=$this->registry->appointment($eventId)['activity_kind']??'';
        return match($kind){'assembly'=>array_intersect_key(self::GROUPS,array_flip(['members','active','passive','selected'])),'board'=>['board'=>'Alle aktuellen Vorstandsmitglieder','selected'=>self::GROUPS['selected']],default=>self::GROUPS};
    }
    public function contacts(string $kind=''):array
    {
        $a=BoardAccess::require($this->db,$this->actorId);
        $q=DocumentCatalog::query($this->db,"SELECT c.id,c.name,c.email,c.address,COALESCE((SELECT m.status FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN ('aktiv','passiv') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?) ORDER BY m.starts_on DESC LIMIT 1),'kontakt') member_status FROM contacts c WHERE c.archived_at IS NULL AND c.club_id=? ORDER BY c.name,c.id LIMIT 10001",[date('Y-m-d'),date('Y-m-d'),$a['club_id']]);
        $rows=$q->fetchAll();
        $familyMail=DocumentCatalog::query($this->db,'SELECT p.contact_id,c.email FROM member_family_people p JOIN member_families f ON f.club_id=p.club_id AND f.id=p.family_id JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE p.club_id=? AND c.archived_at IS NULL',[$a['club_id']])->fetchAll();$familyMail=array_column($familyMail,'email','contact_id');
        foreach($rows as &$person){$person['email']=trim($person['email']);$person['email_source']='own';if(!filter_var($person['email'],FILTER_VALIDATE_EMAIL)&&filter_var($familyMail[$person['id']]??'',FILTER_VALIDATE_EMAIL)){$person['email']=$familyMail[$person['id']];$person['email_source']='family';}}unset($person);
        if(count($rows)>10000)throw new DomainException('Bitte für mehr als 10.000 Kontakte einen gesonderten Versandlauf einrichten.');
        if($kind!==''){$eligible=array_column(self::meetingEligibleContacts($this->db,$a['club_id'],$kind),null,'id');$rows=array_values(array_filter($rows,static fn($p)=>isset($eligible[$p['id']])));foreach($rows as &$person)if(isset($eligible[$person['id']]['office']))$person['office']=$eligible[$person['id']]['office'];unset($person);}
        return $rows;
    }
    public function preview(string $eventId,array $groups,array $selected,string $subject,string $message,array $channels=['mail']):array
    {
        BoardAccess::require($this->db,$this->actorId);
        if(!$groups||array_diff($groups,array_keys($this->groupsFor($eventId)))||count($selected)>10000)throw new DomainException('Bitte eine gültige Empfängergruppe für diese Versammlung wählen.');
        foreach($selected as $id)if(!is_string($id))throw new DomainException('Kontaktauswahl prüfen.');
        $event=$this->registry->appointment($eventId);if(($event['activity_state']??'')==='cancelled'||!empty($event['cancelled']))throw new DomainException('Abgesagte Termine können nicht eingeladen werden.');$subject=trim($subject);$message=trim($message);
        foreach($channels as $channel)if(!is_string($channel)||!in_array($channel,['mail','push'],true))throw new DomainException('E-Mail und/oder Gerätehinweis auswählen.');
        $channels=array_values(array_unique($channels));sort($channels);if(!$channels)throw new DomainException('E-Mail und/oder Gerätehinweis auswählen.');
        $meeting=in_array($event['activity_kind']??'',['assembly','board'],true);
        if(in_array('push',$channels,true)&&!$meeting)throw new DomainException('Gerätehinweise sind hier für Mitglieder- und Vorstandsversammlungen verfügbar.');
        if($subject===''||strlen($subject)>200||preg_match('/[\r\n]/',$subject)||strlen($message)>10000)throw new DomainException('Betreff und Nachricht prüfen.');
        $people=$this->contactsFor($eventId);if(array_diff($selected,array_column($people,'id')))throw new DomainException('Ausgewählte Person ist für diese Versammlung nicht verfügbar.');
        $recipients=[];$peopleSelected=[];$missing=0;$matched=0;
        foreach($people as $person){$status=$person['member_status'];$include=in_array('all',$groups,true)||in_array('board',$groups,true)||(in_array('members',$groups,true)&&in_array($status,['aktiv','passiv'],true))||(in_array('active',$groups,true)&&$status==='aktiv')||(in_array('passive',$groups,true)&&$status==='passiv')||(in_array('contacts',$groups,true)&&$status==='kontakt')||(in_array('selected',$groups,true)&&in_array($person['id'],$selected,true));
            if(!$include)continue;$peopleSelected[]=$person;++$matched;$email=strtolower(trim($person['email']));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){++$missing;continue;}$recipients[$email]??=['email'=>$email,'name'=>'','names'=>[]];$recipients[$email]['names'][]=$person['name'];$recipients[$email]['name']=implode(', ',$recipients[$email]['names']);
        }
        ksort($recipients);$tz=new \DateTimeZone('Europe/Berlin');$start=(new \DateTimeImmutable($event['starts_at']))->setTimezone($tz)->format('d.m.Y H:i');
        $text=($message!==''?$message."\n\n":'').$event['title']."\nBeginn: ".$start.' Uhr';
        if($event['end_known']??true)$text.="\nEnde: ".(new \DateTimeImmutable($event['ends_at']))->setTimezone($tz)->format('d.m.Y H:i').' Uhr';
        if($event['location']!=='')$text.="\nOrt: ".$event['location'];
        if(!empty($event['source_url']))$text.="\nWeitere Informationen: ".$event['source_url'];
        if(!empty($event['description']))$text.="\n\nBeschreibung / Tagesordnung\n".$event['description'];
        $text.="\n\nKulturhus Jaderberg e. V.";
        $hash=hash('sha256',json_encode([$eventId,$subject,$text],JSON_THROW_ON_ERROR));
        foreach($recipients as &$recipient){$recipient['body']='Guten Tag '.implode(', ',$recipient['names']).",\n\n".$text;$recipient['content_hash']=hash('sha256',$hash.$recipient['body']);}unset($recipient);
        $chosen=array_column($peopleSelected,'id');$push=['contacts'=>[],'devices'=>0];
        if(in_array('push',$channels,true))$push=ManagerNotifications::meetingPreview($this->db,(string)$event['club_id'],$eventId,$chosen);
        $additional=$meeting?array_values(array_diff($chosen,array_column($event['attendees']??[],'contact_id'))):[];
        $previewHash=hash('sha256',json_encode([$hash,array_keys($recipients),$peopleSelected,$channels,(int)($event['version']??0),$push,$additional],JSON_THROW_ON_ERROR));
        return ['people'=>$peopleSelected,'event'=>$event,'subject'=>$subject,'body'=>$text,'recipients'=>array_values($recipients),'missing'=>$missing,'duplicates'=>$matched-$missing-count($recipients),'content_hash'=>$hash,'preview_hash'=>$previewHash,'channels'=>$channels,'mail_count'=>in_array('mail',$channels,true)?count($recipients):0,'push_contacts'=>$push['contacts'],'push_devices'=>$push['devices'],'additional_attendees'=>$additional];
    }
    public function queue(string $eventId,array $groups,array $selected,string $subject,string $message,string $previewHash,string $requestKey,array $channels=['mail']):string
    {
        $a=BoardAccess::require($this->db,$this->actorId);
        if(in_array('mail',$channels,true)&&!$this->mailAllowed())throw new DomainException('Mailversand auf diesem PC gesperrt. Die Vorschau kann verwendet werden.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new DomainException('Versandvorschau erneut öffnen.');
        $this->db->beginTransaction();
        try{
            $old=DocumentCatalog::query($this->db,'SELECT id,event_id,preview_hash FROM calendar_invitations WHERE club_id=? AND request_key=?',[$a['club_id'],$requestKey])->fetch();
            if($old){if($old['event_id']!==$eventId||!hash_equals($old['preview_hash'],$previewHash))throw new DomainException('Diese Versandkennung wurde bereits anders verwendet.');$this->db->commit();return $old['id'];}
            $p=$this->preview($eventId,$groups,$selected,$subject,$message,$channels);
            if(!hash_equals($p['preview_hash'],$previewHash))throw new DomainException('Termin, Versandweg oder Empfänger wurden geändert. Bitte die Vorschau erneut prüfen.');
            if(!$p['mail_count']&&!$p['push_devices'])throw new DomainException('Für die ausgewählten Versandwege gibt es keine erreichbaren Empfänger. Personen ohne E-Mail können per Brief eingeladen werden.');
            $meeting=in_array($p['event']['activity_kind']??'',['assembly','board'],true);
            if($meeting&&(($p['event']['activity_state']??'')!=='planned'||strtotime($p['event']['starts_at'])<=time()))throw new DomainException('Nur bevorstehende, geplante Versammlungen können eingeladen werden.');
            $id=bin2hex(random_bytes(16));DocumentCatalog::query($this->db,'INSERT INTO calendar_invitations VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$requestKey,$a['id'],$eventId,$p['content_hash'],$previewHash,$subject,$p['body'],gmdate('c')]);
            if($meeting)foreach($p['people'] as $person)DocumentCatalog::query($this->db,'INSERT INTO calendar_invitation_targets(club_id,invitation_id,contact_id,email,name,event_fingerprint) VALUES(?,?,?,?,?,?)',[$a['club_id'],$id,$person['id'],strtolower(trim($person['email'])),$person['name'],self::eventFingerprint($p['event'])]);
            if(in_array('mail',$channels,true)){
                // Only an explicitly reviewed new formal invitation can replace a
                // skipped snapshot. Accepted or uncertain deliveries never retry.
                $sql=$meeting?"INSERT INTO calendar_invitation_deliveries(club_id,invitation_id,event_id,content_hash,email,body) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE invitation_id=IF(status='skipped',VALUES(invitation_id),invitation_id),body=IF(status='skipped',VALUES(body),body),attempted_at=IF(status='skipped',NULL,attempted_at),sent_at=IF(status='skipped',NULL,sent_at),status=IF(status='skipped','pending',status)":'INSERT IGNORE INTO calendar_invitation_deliveries(club_id,invitation_id,event_id,content_hash,email,body) VALUES(?,?,?,?,?,?)';
                foreach($p['recipients'] as $recipient)DocumentCatalog::query($this->db,$sql,[$a['club_id'],$id,$eventId,$recipient['content_hash'],$recipient['email'],$recipient['body']]);
            }
            $eventVersion=(int)$p['event']['version'];
            if($meeting&&$p['additional_attendees']){
                foreach($p['additional_attendees'] as $contact)DocumentCatalog::query($this->db,'INSERT IGNORE INTO appointment_attendees(club_id,appointment_id,contact_id) VALUES(?,?,?)',[$a['club_id'],$eventId,$contact]);
                DocumentCatalog::query($this->db,'UPDATE appointments SET version=version+1 WHERE club_id=? AND id=? AND version=?',[$a['club_id'],$eventId,$eventVersion]);++$eventVersion;
                DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'appointment.invitees.added',$eventId,$eventVersion,gmdate('c')]);
            }
            if(in_array('push',$channels,true))ManagerNotifications::meetingAvailable($this->db,$a['club_id'],$eventId,array_column($p['people'],'id'),$eventVersion);
            DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'calendar.invitation.queued',$id,$eventVersion,gmdate('c')]);
            foreach($channels as $channel)DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],'calendar.invitation.'.$channel.'.queued',$id,gmdate('c')]);
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();else $this->db->rollBack();throw $e;}
    }
    public function deliveryStatus(string $event):array{$a=BoardAccess::require($this->db,$this->actorId);return DocumentCatalog::query($this->db,'SELECT status,COUNT(*) AS total FROM calendar_invitation_deliveries WHERE club_id=? AND event_id=? GROUP BY status',[$a['club_id'],$event])->fetchAll();}
    private static function deliveryCurrent(PDO $db,array $delivery):bool
    {
        if(str_starts_with($delivery['event_id'],'website:'))return true;
        $event=DocumentCatalog::query($db,'SELECT * FROM appointments WHERE club_id=? AND id=?',[$delivery['club_id'],$delivery['event_id']])->fetch();
        if(!$event||$event['activity_state']==='cancelled')return false;
        if(!in_array($event['activity_kind'],['assembly','board'],true))return true;
        if($event['activity_state']!=='planned'||strtotime($event['starts_at'])<=time())return false;
        try{
            $service=new self($db,$delivery['actor_id'],new Registry($db,$delivery['actor_id']));
            $attendees=DocumentCatalog::query($db,'SELECT contact_id FROM appointment_attendees WHERE club_id=? AND appointment_id=?',[$delivery['club_id'],$delivery['event_id']])->fetchAll(PDO::FETCH_COLUMN);
            $current=array_column($service->contactsFor($delivery['event_id']),null,'id');
            $targets=DocumentCatalog::query($db,'SELECT contact_id,email,name,event_fingerprint FROM calendar_invitation_targets WHERE club_id=? AND invitation_id=? AND email=? ORDER BY name,contact_id',[$delivery['club_id'],$delivery['invitation_id'],$delivery['email']])->fetchAll();
            $names=[];foreach($targets as $target){
                $person=$current[$target['contact_id']]??null;
                if(!$person||!in_array($person['id'],$attendees,true)||strtolower(trim($person['email']))!==$target['email']||$person['name']!==$target['name']||!hash_equals($target['event_fingerprint'],self::eventFingerprint($event)))return false;
                $names[]=$target['name'];
            }
            // Check only the approved IDs. Other people sharing the same mailbox are
            // not silently invited, nor do they block an explicitly selected person.
            // Legacy formal invitations without a target snapshot require a new preview.
            return $names&&hash_equals('Guten Tag '.implode(', ',$names).",\n\n".$delivery['default_body'],(string)$delivery['body']);
        }catch(DomainException){return false;}
    }
    public static function dispatch(PDO $db,string $club,string $publicUrl,callable $send):int
    {
        if(!MailPolicy::allowed($publicUrl))return 0;$sent=0;
        $rows=DocumentCatalog::query($db,"SELECT d.*,i.subject,i.body AS default_body,i.actor_id FROM calendar_invitation_deliveries d JOIN calendar_invitations i ON i.club_id=d.club_id AND i.id=d.invitation_id WHERE d.club_id=? AND d.status='pending' ORDER BY i.created_at,d.email LIMIT 20",[$club])->fetchAll();
        foreach($rows as $r){$key=[$club,$r['event_id'],$r['content_hash'],$r['email']];
            if(!BoardAccess::allowed($db,$r['actor_id'])||!self::deliveryCurrent($db,$r)){DocumentCatalog::query($db,"UPDATE calendar_invitation_deliveries SET status='skipped' WHERE club_id=? AND event_id=? AND content_hash=? AND email=? AND status='pending'",$key);continue;}
            $claim=DocumentCatalog::query($db,"UPDATE calendar_invitation_deliveries SET status='sending',attempted_at=? WHERE club_id=? AND event_id=? AND content_hash=? AND email=? AND status='pending'",array_merge([gmdate('c')],$key));if($claim->rowCount()!==1)continue;
            try{if(!MailPolicy::allowed($publicUrl))throw new DomainException('Versand gesperrt.');$send($r['email'],$r['subject'],$r['body']?:$r['default_body']);DocumentCatalog::query($db,"UPDATE calendar_invitation_deliveries SET status='sent',sent_at=? WHERE club_id=? AND event_id=? AND content_hash=? AND email=?",array_merge([gmdate('c')],$key));++$sent;}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){DocumentCatalog::query($db,"UPDATE calendar_invitation_deliveries SET status=? WHERE club_id=? AND event_id=? AND content_hash=? AND email=? AND status='sending'",array_merge([$e->retryable?'pending':'skipped'],$key));}
            catch(\Throwable){if(DocumentCatalog::query($db,"SELECT 1 FROM calendar_invitation_deliveries WHERE club_id=? AND event_id=? AND content_hash=? AND email=? AND status='sent' AND sent_at IS NOT NULL",$key)->fetchColumn()){++$sent;continue;}DocumentCatalog::query($db,"UPDATE calendar_invitation_deliveries SET status='uncertain' WHERE club_id=? AND event_id=? AND content_hash=? AND email=? AND status='sending'",$key);}
        }return $sent;
    }
}
