<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

require_once __DIR__.'/EventVoucherMailBatch.php';
require_once __DIR__.'/EventVoucherMailPages.php';

/** One personal message per event; delivery never creates or replenishes a voucher. */
final class EventVoucherMail
{
    use EventVoucherMailBatch, EventVoucherMailPages;
    private EventVouchers $vouchers;
    public function __construct(private PDO $db,private string $actorId,?WebsiteCalendar $website=null) {
        $this->vouchers=new EventVouchers($db,$actorId,$website);
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    /** Current sources remain together, without acquiring the manager's global writer gate. */
    private function readContext(callable $read):mixed {
        $owned=!$this->db->inTransaction();
        try{
            if($owned)$this->db->exec('START TRANSACTION');
            $result=$read();
            if($owned)$this->db->commit();
            return$result;
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Gutscheine werden gerade bearbeitet. Bitte erneut öffnen.',503,$failure);
            throw$failure;
        }
    }
    private function currentLock():string{return$this->db->inTransaction()?' FOR SHARE NOWAIT':'';}

    /** One current read for the complete native review, its saved template and both batch lists. */
    public function workspace(array $general,array $query,array &$session,array $legacyPost=[]):array {
        require_once __DIR__.'/EventVoucherMailState.php';
        try{return$this->readContext(function()use($general,$query,&$session,$legacyPost):array{
            return EventVoucherMailState::prepare($this,$general,$query,$session,$legacyPost);
        });}catch(\Throwable $failure){return EventVoucherMailState::failed($failure);}
    }

    public static function templateDefaults():array {
        return ['subject'=>'Deine Gutscheine · {{VERANSTALTUNG}}','message'=>"Hallo {{NAME}},\n\nhier sind deine persönlichen Gutscheine für:\n\n{{VERANSTALTUNG}}\n{{DATUM}} · {{UHRZEIT}} Uhr\n{{ORT}}"];
    }
    public static function normalizeTemplate(array $data):array {
        if(array_diff(array_keys($data),['subject','message'])||count($data)!==2)throw new DomainException('Bitte Betreff und Anschreiben ausfüllen.');
        $out=[];foreach(['subject'=>200,'message'=>6000] as $field=>$limit){
            $raw=$data[$field]??null;
            if(!is_string($raw)&&!is_int($raw))throw new DomainException('Textfeld enthält ungültige Daten.',422);
            $value=trim(str_replace("\r\n","\n",(string)$raw));
            if($value===''||preg_match('//u',$value)!==1||mb_strlen($value)>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new DomainException('Pflichtfeld oder zulässige Textlänge prüfen.',422);
            if($field==='subject'&&preg_match('/[\r\n\t]/',$value))throw new DomainException('Der Betreff darf nur eine Zeile enthalten.');
            $remaining=preg_replace('/\{\{(?:NAME|VORNAME|VERANSTALTUNG|DATUM|UHRZEIT|ORT)\}\}/','',$value);
            if(str_contains($remaining,'{{')||str_contains($remaining,'}}'))throw new DomainException('Bitte nur die angegebenen Platzhalter verwenden.');
            $out[$field]=$value;
        }return $out;
    }
    public function template(string $eventId):array {
        return$this->readContext(function()use($eventId):array{
            $a=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            $row=$this->q('SELECT version,payload FROM club_settings WHERE club_id=? AND section=?'.$this->currentLock(),[$a['club_id'],'event_voucher_mail:'.$eventId])->fetch();
            return ['version'=>(int)($row['version']??0),'data'=>$row?self::normalizeTemplate(json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)):self::templateDefaults()];
        });
    }
    public function saveTemplate(string $eventId,int $version,array $data,string $key):void {
        $a=BoardAccess::require($this->db,$this->actorId);$data=self::normalizeTemplate($data);
        $this->vouchers->request($key,['mail-template',$eventId,$version,$data],function()use($a,$eventId,$version,$data){
            $old=$this->template($eventId);if($version!==$old['version'])throw new DomainException('Die Mailvorlage wurde inzwischen geändert. Bitte neu laden.',409);
            $section='event_voucher_mail:'.$eventId;$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$time=gmdate('c');
            if($version===0)$this->q('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,?,?)',[$a['club_id'],$section,$json,1,$time]);
            else $this->q('UPDATE club_settings SET payload=?,version=version+1,updated_at=? WHERE club_id=? AND section=? AND version=?',[$json,$time,$a['club_id'],$section,$version]);
            $this->q('INSERT INTO settings_revisions(club_id,section,version,payload,actor_id,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$section,$version+1,$json,$a['id'],$time]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'event-voucher.mail-template',$eventId,$version+1,$time]);
            return 'saved';
        });
    }
    public function preview(string $eventId,string $contact):array {
        return $this->readContext(fn():array=>$this->previewUsingTemplate($eventId,$contact,$this->template($eventId)['data']));
    }
    /** A queued message uses its confirmed template, never a subsequently edited default. */
    private function previewUsingTemplate(string $eventId,string $contact,?array $template):array {
        return$this->readContext(function()use($eventId,$contact,$template):array{
            $a=BoardAccess::require($this->db,$this->actorId);$e=$this->vouchers->event($eventId);$source=$this->vouchers->liveEvent($e,true);
            $person=$this->q("SELECT id,name,email FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL".$this->currentLock(),[$a['club_id'],$contact])->fetch();
            if(!$person)throw new DomainException('Mitglied nicht verfügbar.');
            try{$snapshot=$this->vouchers->managementPrintSnapshot($eventId,$contact);}
            catch(DomainException $failure){
                if($failure->getMessage()==='Höchstens 200 Gutscheine pro Druckdatei.')throw new DomainException('Höchstens 200 offene Gutscheine je persönlicher Mail.',422,$failure);
                throw$failure;
            }
            return$this->personalPreview($e,$source,$person,$snapshot['vouchers'],$template);
        });
    }
    private function personalPreview(array $event,array $source,array $person,array $rows,?array $template):array {
        $eventId=$event['id'];
        $rows=array_map(static fn($v)=>array_intersect_key($v,array_flip(['id','ticket_id','label','unit','granted','remaining','code'])),$rows);
        if(!$rows)throw new DomainException('Keine offenen Gutscheine für dieses Mitglied.');
        if(count($rows)>200)throw new DomainException('Höchstens 200 offene Gutscheine je persönlicher Mail.');
        $date=(new \DateTimeImmutable($source['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i');
        $text='Hallo '.$person['name'].",\n\nHier sind deine persönlichen Gutscheine:\n\n".$source['title']."\n".$date." Uhr\n".$source['location']."\n\n";
        foreach($rows as $v)$text.=$v['label'].': '.EventVouchers::display((int)$v['remaining'],$v['unit']).' · Code '.implode('-',str_split($v['code'],4))."\n";
        $text.="\nNur für dich und diese Veranstaltung gültig. Keine Übertragung auf andere Veranstaltungen. Zeige bei der Ausgabe den jeweiligen QR-Code oder Gutscheincode vor.";
        $snapshot=['event_id'=>$eventId,'source_id'=>$event['source_id'],'title'=>$source['title'],'date'=>$source['starts_at'],'location'=>$source['location'],'person'=>$person,'vouchers'=>$rows,'text'=>$text];
        if($template!==null){
            $snapshot['mail_template']=self::normalizeTemplate($template);$text=self::message($snapshot)['intro']."\n\n";
            foreach($rows as $v)$text.=$v['label'].': '.EventVouchers::display((int)$v['remaining'],$v['unit']).' · Code '.implode('-',str_split($v['code'],4))."\n";
            $snapshot['text']=$text."\nNur für dich und diese Veranstaltung gültig. Keine Übertragung auf andere Veranstaltungen. Zeige bei der Ausgabe den jeweiligen QR-Code oder Gutscheincode vor.";
        }
        return ['snapshot'=>$snapshot,'hash'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),'can_send'=>(bool)filter_var($person['email'],FILTER_VALIDATE_EMAIL)];
    }
    private function enqueue(array $actor,string $eventId,string $contact,array $preview):string {
        $id=bin2hex(random_bytes(16));$this->q('INSERT INTO event_voucher_outbox(club_id,id,event_id,contact_id,actor_id,email,snapshot_json,fingerprint,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$actor['club_id'],$id,$eventId,$contact,$actor['id'],$preview['snapshot']['person']['email'],json_encode($preview['snapshot'],JSON_THROW_ON_ERROR),$preview['hash'],gmdate('c')]);return $id;
    }
    public function queue(string $eventId,string $contact,string $hash,string $key):string {
        $a=BoardAccess::require($this->db,$this->actorId);
        if(!MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL')))throw new DomainException('Mailversand ist in dieser Umgebung ausgeschaltet.');
        return $this->vouchers->request($key,['mail',$eventId,$contact,$hash],function()use($a,$eventId,$contact,$hash){
            $p=$this->preview($eventId,$contact);
            if(!$p['can_send'])throw new DomainException('Beim Mitglied fehlt eine gültige E-Mail-Adresse.');
            if(!hash_equals($p['hash'],$hash))throw new DomainException('Gutscheine oder Empfängerdaten geändert. Bitte Vorschau erneut prüfen.',409);
            if($this->q("SELECT 1 FROM event_voucher_outbox WHERE club_id=? AND event_id=? AND contact_id=? AND state IN ('pending','sending','uncertain')",[$a['club_id'],$eventId,$contact])->fetchColumn())throw new DomainException('Ein Versand ist bereits vorgemerkt oder seine Zustellung noch ungeklärt.',409);
            return $this->enqueue($a,$eventId,$contact,$p);
        });
    }
    public function deliveries(string $eventId):array {
        return$this->readContext(function()use($eventId):array{
            $a=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            return $this->q('SELECT o.id,o.contact_id,c.name,o.email,o.state,o.error,o.updated_at FROM event_voucher_outbox o JOIN contacts c ON c.club_id=o.club_id AND c.id=o.contact_id WHERE o.club_id=? AND o.event_id=? ORDER BY o.updated_at DESC,o.id DESC LIMIT 100'.$this->currentLock(),[$a['club_id'],$eventId])->fetchAll();
        });
    }
    public function cancel(string $eventId,string $id,string $key,?array $expected=null):void {
        $a=BoardAccess::require($this->db,$this->actorId);
        if($expected!==null&&(array_keys($expected)!==['state','updated_at','fingerprint']||count(array_filter($expected,'is_string'))!==3))throw new DomainException('Ursprünglichen Versandstand prüfen.',422);
        $payload=['cancel-mail',$eventId,$id];if($expected!==null)$payload[]=$expected;
        $this->vouchers->request($key,$payload,function()use($a,$eventId,$id,$expected){
            BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            if($expected!==null){
                $current=$this->q('SELECT state,updated_at,fingerprint FROM event_voucher_outbox WHERE club_id=? AND event_id=? AND id=? FOR UPDATE',[$a['club_id'],$eventId,$id])->fetch();
                if($current!==$expected)throw new DomainException('Versandstand inzwischen geändert. Bitte den aktuellen Vorgang prüfen.',409);
            }
            $q=$this->q("UPDATE event_voucher_outbox SET state='cancelled',error='Vom Vorstand nach Prüfung beendet',updated_at=? WHERE club_id=? AND event_id=? AND id=? AND state IN ('pending','uncertain','blocked')",[gmdate('c'),$a['club_id'],$eventId,$id]);
            if(!$q->rowCount())throw new DomainException('Versand läuft bereits oder ist abgeschlossen.',409);return 'cancelled';
        });
    }
    /** Contacts retain one display name; keep hyphenated names and support "surname, given names". */
    public static function firstName(string $name):string {
        $name=trim(preg_replace('/[\s\p{Z}]+/u',' ',$name)??$name);
        if(str_contains($name,','))return trim(explode(',',$name,2)[1]);
        return explode(' ',$name,2)[0];
    }
    /** Plain-text substitution only; HTML escaping belongs to the existing mail layout. */
    public static function message(array $snapshot):array {
        $date=(new \DateTimeImmutable($snapshot['date']))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        if(array_key_exists('mail_template',$snapshot)){
            $template=self::normalizeTemplate($snapshot['mail_template']);
            $vars=['{{NAME}}'=>$snapshot['person']['name'],'{{VORNAME}}'=>self::firstName($snapshot['person']['name']),'{{VERANSTALTUNG}}'=>$snapshot['title'],'{{DATUM}}'=>$date->format('d.m.Y'),'{{UHRZEIT}}'=>$date->format('H:i'),'{{ORT}}'=>$snapshot['location']??''];
            return ['subject'=>mb_substr(preg_replace('/[\r\n\t]+/',' ',strtr($template['subject'],$vars)),0,200),'intro'=>strtr($template['message'],$vars)];
        }
        // Preserve messages confirmed before editable templates were introduced.
        return ['subject'=>'Deine Gutscheine · '.preg_replace('/[\r\n]+/',' ',mb_substr($snapshot['title'],0,150)),
            'intro'=>'Hallo '.$snapshot['person']['name'].",\n\nhier sind deine persönlichen Gutscheine für:\n\n".$snapshot['title']."\n".$date->format('d.m.Y · H:i').' Uhr'.(!empty($snapshot['location'])?"\n".$snapshot['location']:'')];
    }
    public static function content(array $snapshot,string $url):array {
        $message=self::message($snapshot);$subject=$message['subject'];
        $parts=parse_url($url);$base=($parts['scheme']??'https').'://'.($parts['host']??'');
        $link=rtrim($url,'/').'/?view=portal&tab=documents#meine-gutscheine';
        $attachmentNote='Die Gutscheine findest du als Druck-PDF im Anhang.';
        $intro=$message['intro']."\n\n".$attachmentNote;
        $html=ApplicationMail::html($intro,$base,$subject,$link,'Meine Gutscheine','VERANSTALTUNG');
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$cards='';$attachments=[];
        $date=(new \DateTimeImmutable($snapshot['date']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
        foreach($snapshot['vouchers'] as $i=>$v){
            $cid='voucher-'.$v['code'].'@kulturhus';$attachments[]=['bytes'=>EventVoucherQr::png($snapshot['event_id'],$v['code']),'name'=>'Gutschein-'.($i+1).'.png','mime'=>'image/png','cid'=>$cid];
            $cards.='<div style="border:2px dashed #17352f;padding:18px;margin:18px 0;break-inside:avoid;overflow-wrap:anywhere"><strong>'.$e($snapshot['person']['name']).'</strong><br>'.$e($snapshot['title']).' · '.$e($date).'<h2 style="font-size:22px">'.$e($v['label']).' · '.$e(EventVouchers::display((int)$v['remaining'],$v['unit'])).'</h2><img src="cid:'.$cid.'" width="222" height="222" style="display:block;width:100%;max-width:222px;height:auto;border:0" alt="QR-Code Gutschein '.($i+1).'"><p>Code '.$e(implode('-',str_split($v['code'],4))).'</p><p>Nur für diese Person und Veranstaltung gültig.</p></div>';
        }
        $attachments[]=['bytes'=>EventVoucherPdf::fromSnapshot($snapshot),'name'=>'Gutscheine-'.(new \DateTimeImmutable($snapshot['date']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d').'.pdf','mime'=>'application/pdf'];
        $needle='<p style="margin:28px 0">';$html=str_replace($needle,$cards.$needle,$html);
        return ['subject'=>$subject,'text'=>$snapshot['text']."\n\n".$attachmentNote."\n\nMeine Gutscheine in der Dokumentenablage:\n".$link,'html'=>$html,'attachments'=>$attachments];
    }
    /** Claim first, recheck authority and frozen preview, then call only the supplied transport. */
    public static function dispatch(PDO $db,string $url,callable $send,?WebsiteCalendar $website=null,?string $club=null):int {
        if(!MailPolicy::allowed($url))return 0;
        $q=static fn(string $sql,array $args=[])=>DocumentCatalog::query($db,$sql,$args);
        $q("UPDATE event_voucher_outbox SET state='uncertain',error='Zustellung bitte prüfen' WHERE state='sending' AND updated_at<? AND (? IS NULL OR club_id=?)",[gmdate('c',time()-900),$club,$club]);
        $sent=0;$rows=$q("SELECT * FROM event_voucher_outbox WHERE state='pending' AND (? IS NULL OR club_id=?) ORDER BY updated_at,id LIMIT 20",[$club,$club])->fetchAll();
        foreach($rows as $row){
            if(!$q("UPDATE event_voucher_outbox SET state='sending',updated_at=? WHERE club_id=? AND id=? AND state='pending'",[gmdate('c'),$row['club_id'],$row['id']])->rowCount())continue;
            try{
                $a=BoardAccess::require($db,$row['actor_id']);if($a['club_id']!==$row['club_id'])throw new DomainException('Vereinszuordnung geändert.');
                $saved=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($saved)||!hash_equals($row['fingerprint'],hash('sha256',json_encode($saved,JSON_THROW_ON_ERROR))))throw new DomainException('Gespeicherte Versandvorschau prüfen.');
                $p=(new self($db,$row['actor_id'],$website))->previewUsingTemplate($row['event_id'],$row['contact_id'],array_key_exists('mail_template',$saved)?self::normalizeTemplate($saved['mail_template']):null);
                if(!$p['can_send']||!hash_equals($row['fingerprint'],$p['hash'])||$row['email']!==$p['snapshot']['person']['email'])throw new DomainException('Gutscheine oder Empfängerdaten geändert. Neue Vorschau prüfen.');
                $content=self::content($p['snapshot'],$url);
            }catch(\Throwable){$q("UPDATE event_voucher_outbox SET state='blocked',error='Veranstaltung, Gutscheine, PDF-Anhang oder Versandberechtigung erneut prüfen',updated_at=? WHERE club_id=? AND id=?",[gmdate('c'),$row['club_id'],$row['id']]);continue;}
            try{
                $send($row['email'],$content['subject'],$content['text'],$content['html'],$content['attachments'],$row['club_id']);
                $q("UPDATE event_voucher_outbox SET state='sent',error='',updated_at=? WHERE club_id=? AND id=? AND state='sending'",[gmdate('c'),$row['club_id'],$row['id']]);++$sent;
            }catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){
                $q("UPDATE event_voucher_outbox SET state=?,error=?,updated_at=? WHERE club_id=? AND id=? AND state='sending'",[$e->retryable?'pending':'blocked',\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$e->stage],gmdate('c'),$row['club_id'],$row['id']]);
            }catch(\Throwable){
                $actual=$q('SELECT * FROM event_voucher_outbox WHERE club_id=? AND id=?',[$row['club_id'],$row['id']])->fetch();
                if($actual&&$actual['state']==='sent'&&$actual['event_id']===$row['event_id']&&$actual['contact_id']===$row['contact_id']&&$actual['email']===$row['email']&&$actual['fingerprint']===$row['fingerprint']&&$actual['snapshot_json']===$row['snapshot_json']){++$sent;continue;}
                $q("UPDATE event_voucher_outbox SET state='uncertain',error='Zustellung unklar. Vor erneutem Versand prüfen.',updated_at=? WHERE club_id=? AND id=? AND state='sending'",[gmdate('c'),$row['club_id'],$row['id']]);
            }
        }return $sent;
    }
}
