<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Daily planning and durable private invoice/reminder delivery. No transport fallback. */
final class ContributionReminders
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung für Beitragsläufe erforderlich.');return $a;
    }
    private function q(string $sql,array $values=[]):\PDOStatement
    {
        if($this->db->inTransaction()&&str_starts_with($sql,'SELECT ')&&!str_contains($sql,' FOR '))$sql.=' FOR SHARE NOWAIT';
        $q=$this->db->prepare($sql);$q->execute($values);return $q;
    }
    private static function day(string $day):\DateTimeImmutable
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$day)throw new DomainException('Datum des Beitragslaufs prüfen.');return $date;
    }
    private static function now(string $day,?\DateTimeImmutable $clock=null):string
    {
        $date=self::day($day);$clock=($clock??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        if($day===$clock->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d'))return $clock->format('Y-m-d\TH:i:s\Z');
        return $date->format('Y-m-d').'T'.$clock->format('H:i:s').'Z';
    }
    private static function money(int $cents):string{return intdiv($cents,100).','.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT).' €';}
    private function tx(callable $fn):mixed
    {
        return Infrastructure\Database\Transactions::run($this->db,$fn);
    }
    private function family(string $club,string $contact):?array
    {
        return $this->q('SELECT f.* FROM member_families f JOIN member_family_people p ON p.club_id=f.club_id AND p.family_id=f.id WHERE p.club_id=? AND p.contact_id=?',[$club,$contact])->fetch()?:null;
    }
    private function isNonPayer(string $club,string $contact):bool
    {
        $family=$this->family($club,$contact);if($family&&$family['payer_contact_id']!==$contact)return true;
        $raw=$this->q("SELECT answers FROM membership_applications WHERE club_id=? AND contact_id=? AND status='accepted' ORDER BY reviewed_at DESC,submitted_at DESC,id DESC LIMIT 1",[$club,$contact])->fetchColumn();
        if(!$raw)return false;$answers=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return ($answers['membership_type']??'single')==='family_member';
    }
    private function annualCandidates(string $today):array
    {
        $a=$this->actor();if(substr($today,5)!=='01-01')return [];
        $rows=$this->q("SELECT DISTINCT c.id,c.name,c.email FROM memberships m JOIN contacts c ON c.club_id=m.club_id AND c.id=m.contact_id WHERE c.archived_at IS NULL AND m.club_id=? AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?) ORDER BY c.name,c.id LIMIT 10001",[$a['club_id'],$today,$today])->fetchAll();
        if(count($rows)>10000)throw new DomainException('Mehr als 10.000 Mitglieder. Beitragslauf bitte gesondert planen.');
        $out=[];$year=(int)substr($today,0,4);
        foreach($rows as $r){if($this->isNonPayer($a['club_id'],$r['id']))continue;$family=$this->family($a['club_id'],$r['id']);$entry=$this->q('SELECT entry_id FROM member_dues_people WHERE club_id=? AND contact_id=? AND year=?',[$a['club_id'],$r['id'],$year])->fetchColumn();$out[]=['contact_id'=>$r['id'],'name'=>$r['name'],'email'=>$r['email'],'family_id'=>$family['id']??'','year'=>$year,'amount_cents'=>$entry?(int)$this->q('SELECT amount_cents FROM finance_entries WHERE club_id=? AND id=?',[$a['club_id'],$entry])->fetchColumn():ContributionRates::all($this->db,$a['club_id'],true)[$family?'family':'single']['amount_cents'],'entry_id'=>$entry?:''];}
        return $out;
    }
    /** Re-evaluate current balance and recipient from source records, not queued text. */
    private function current(string $entryId,string $today,string $type,?string $ownDelivery=null):array
    {
        $a=$this->actor();$entry=(new Finance($this->db,$this->actorId))->currentEntry($entryId);$year=(int)substr($today,0,4);
        if($entry['kind']!=='contribution'||in_array($entry['payment_state'],['cancelled','reversed'],true)||(int)$entry['remaining_cents']<=0)throw new DomainException('Forderung bezahlt, storniert oder nicht mehr offen.');
        $notes=json_decode($entry['notes'],true,32,JSON_THROW_ON_ERROR);if(!is_array($notes)||(int)($notes['year']??0)!==$year||empty($notes['payer']))throw new DomainException('Nur Jahresbeiträge des aktuellen Jahres berücksichtigen.');
        $payer=(string)$notes['payer'];if($this->isNonPayer($a['club_id'],$payer))throw new DomainException('Familienangehörige erhalten keine eigene Beitragsmail.');
        $person=$this->q('SELECT * FROM contacts WHERE club_id=? AND id=?',[$a['club_id'],$payer])->fetch();if(!$person)throw new DomainException('Beitragszahler fehlt.');
        $email=strtolower(trim($person['email']));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('Gültige E-Mail-Adresse des Beitragszahlers fehlt.');
        $invoice=$this->q('SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=?',[$a['club_id'],$entryId])->fetch();
        if(!$invoice||$invoice['state']!=='issued')throw new DomainException('Beitragsrechnung ist noch nicht vollständig ausgegeben.');
        if($invoice['recipient_contact_id']!==$payer||(int)$invoice['year']!==$year)throw new DomainException('Rechnung und Beitragszahler passen nicht zusammen.');
        $open=$this->q("SELECT status FROM contribution_deliveries WHERE club_id=? AND entry_id=? AND status IN ('pending','sending','uncertain') AND id<>? LIMIT 1",[$a['club_id'],$entryId,$ownDelivery??''])->fetchColumn();
        if($open)throw new DomainException('Für diesen Beitrag läuft bereits ein Versand oder der Versandstatus ist ungeklärt.');
        $welcome=$this->q('SELECT invoice_status,invoice_sent_at FROM membership_welcome WHERE club_id=? AND entry_id=?',[$a['club_id'],$entryId])->fetchAll();
        if($type==='invoice'&&$welcome)throw new DomainException('Erste Beitragsrechnung wird mit der Aufnahme versendet.');
        $last=$this->q("SELECT MAX(sent_at) FROM contribution_deliveries WHERE club_id=? AND entry_id=? AND status='sent'",[$a['club_id'],$entryId])->fetchColumn()?:null;
        foreach($welcome as $w){if(in_array($w['invoice_status'],['waiting','pending','sending','uncertain'],true))throw new DomainException('Erste Beitragsrechnung ist noch nicht sicher zugestellt.');if($w['invoice_sent_at']&&(!$last||$w['invoice_sent_at']>$last))$last=$w['invoice_sent_at'];}
        $cycle=0;
        if($type==='reminder'){
            $days=(int)self::day($entry['due_date'])->diff(self::day($today))->format('%r%a');if($days<=0)throw new DomainException('Zahlungsziel noch nicht überschritten.');
            if($last&&strtotime(self::now($today))-strtotime($last)<30*86400)throw new DomainException('Seit dem letzten Versand sind noch keine 30 Tage vergangen.');
            $cycle=intdiv($days-1,30);
        }
        return ['entry'=>$entry,'invoice'=>$invoice,'recipient'=>$person,'recipient_email'=>$email,'year'=>$year,'cycle'=>$cycle,'remaining_cents'=>(int)$entry['remaining_cents']];
    }
    /** Current retry source, never queues or contacts a transport. */
    public function deliverySource(string $id,string $day):array
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$actor=$this->actor();self::day($day);
            $delivery=$this->q('SELECT * FROM contribution_deliveries WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch();
            if(!$delivery)throw new DomainException('Beitragsmailauftrag nicht verfügbar.',404);
            $current=$this->current($delivery['entry_id'],$day,$delivery['type'],$id);
            $original=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($delivery['entry_id']);
            $message=ContributionMail::build($this->db,$actor['club_id'],$delivery['entry_id'],$delivery['type']);
            if($own)$this->db->commit();return compact('delivery','current','original','message');
        }catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beitragslauf oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    }
    public function deliveries():array
    {
        $a=$this->actor();return $this->q('SELECT id,entry_id,type,cycle,year,scheduled_for,recipient_contact_id,recipient_email,status,created_at,attempted_at,sent_at,sent_remaining_cents,reason FROM contribution_deliveries WHERE club_id=? ORDER BY created_at DESC,id LIMIT 100',[$a['club_id']])->fetchAll();
    }
    public function preview(string $today):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$this->previewCurrent($today);if($own)$this->db->commit();return $result;}
        catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beitragslauf oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    }
    private function previewCurrent(string $today):array
    {
        self::day($today);$a=$this->actor();$year=(int)substr($today,0,4);$reminders=[];$blocked=[];
        $ids=$this->q('SELECT DISTINCT d.entry_id FROM member_dues_people d WHERE d.club_id=? AND d.year=? ORDER BY d.entry_id LIMIT 10001',[$a['club_id'],$year])->fetchAll();
        if(count($ids)>10000)throw new DomainException('Mehr als 10.000 Jahresbeiträge. Beitragslauf bitte eingrenzen.');
        foreach($ids as $r){try{$c=$this->current($r['entry_id'],$today,'reminder');$exists=$this->q("SELECT 1 FROM contribution_deliveries WHERE club_id=? AND entry_id=? AND type='reminder' AND cycle=?",[$a['club_id'],$r['entry_id'],$c['cycle']])->fetchColumn();if($exists)continue;$reminders[]=['entry_id'=>$r['entry_id'],'name'=>$c['recipient']['name'],'recipient_email'=>$c['recipient_email'],'due_date'=>$c['entry']['due_date'],'remaining_cents'=>$c['remaining_cents'],'cycle'=>$c['cycle'],'year'=>$year];}
            catch(DomainException $e){if($e->getCode()===503)throw$e;$blocked[]=['entry_id'=>$r['entry_id'],'reason'=>$e->getMessage()];}}
        return ['today'=>$today,'annual_due'=>substr($today,5)==='01-01','annual_candidates'=>$this->annualCandidates($today),'reminders'=>$reminders,'blocked'=>$blocked,'deliveries'=>$this->deliveries()];
    }
    private function queue(string $entryId,string $type,string $today):?string
    {
        return $this->tx(function()use($entryId,$type,$today){$a=$this->actor(true);$c=$this->current($entryId,$today,$type);$old=$this->q('SELECT id FROM contribution_deliveries WHERE club_id=? AND entry_id=? AND type=? AND cycle=?',[$a['club_id'],$entryId,$type,$c['cycle']])->fetchColumn();if($old)return null;
            $id=bin2hex(random_bytes(16));$this->q("INSERT INTO contribution_deliveries(club_id,id,entry_id,type,cycle,year,scheduled_for,recipient_contact_id,recipient_email,document_id,actor_id,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending',?)",[$a['club_id'],$id,$entryId,$type,$c['cycle'],$c['year'],$today,$c['recipient']['id'],$c['recipient_email'],$c['invoice']['document_id'],$a['id'],self::now($today)]);
            $this->q("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,'contribution.mail.queued',?,1,?)",[$a['club_id'],$a['id'],$id,self::now($today)]);return $id;});
    }
    public function run(string $today,bool $execute=false):array
    {
        $preview=$this->preview($today);$preview+=['executed'=>false,'created'=>0,'queued'=>0,'errors'=>[]];if(!$execute)return $preview;$a=$this->actor(true);$preview['executed']=true;
        if($preview['annual_due'])foreach($preview['annual_candidates'] as $person){
            try{$entry=(new Finance($this->db,$this->actorId))->createAnnualContribution($person['contact_id'],(int)$person['year'],$person['family_id'],$today);if($person['entry_id']==='')++$preview['created'];(new ContributionInvoices($this->db,$this->actorId))->issue($entry);if($this->queue($entry,'invoice',$today)!==null)++$preview['queued'];}
            catch(DomainException $e){$preview['errors'][]=['contact_id'=>$person['contact_id'],'reason'=>$e->getMessage()];}}
        foreach($preview['reminders'] as $item){try{if($this->queue($item['entry_id'],'reminder',$today)!==null)++$preview['queued'];}catch(DomainException $e){$preview['errors'][]=['entry_id'=>$item['entry_id'],'reason'=>$e->getMessage()];}}
        return $preview;
    }
    private function mark(string $id,string $state,string $reason='',string $expected='pending'):void
    {
        $club=$this->actor()['club_id'];$this->q('UPDATE contribution_deliveries SET status=?,reason=? WHERE club_id=? AND id=? AND status=?',[$state,$reason,$club,$id,$expected]);
    }
    public function dispatch(string $publicUrl,callable $send,?string $today=null,?callable $automaticGuard=null):int
    {
        if(!MailPolicy::allowed($publicUrl))return 0;if($this->db->inTransaction())throw new DomainException('Beitragsversand erst nach Abschluss des offenen Speichervorgangs starten.',503);$a=$this->actor(true);$today??=date('Y-m-d');self::day($today);$sent=0;
        $rows=$this->q("SELECT * FROM contribution_deliveries WHERE club_id=? AND status='pending' AND scheduled_for<=? ORDER BY scheduled_for,created_at,id LIMIT 20",[$a['club_id'],$today])->fetchAll();
        foreach($rows as $delivery){
            if($automaticGuard!==null&&!$automaticGuard())break;
            try{
                $author=Permissions::actor($this->db,$delivery['actor_id']);if($author['club_id']!==$a['club_id']||$author['finance_access']!=='write')throw new DomainException('Finanzberechtigung des Versandauftrags wurde entzogen.');
                $c=$this->current($delivery['entry_id'],$today,$delivery['type'],$delivery['id']);
                if($c['recipient']['id']!==$delivery['recipient_contact_id']||$c['recipient_email']!==$delivery['recipient_email'])throw new DomainException('Empfängerdaten wurden geändert; Versand neu prüfen.');
                if((int)$delivery['year']!==$c['year'])throw new DomainException('Versand gehört nicht zum aktuellen Beitragsjahr.');
                $file=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($delivery['entry_id'])['file'];
                $claim=$this->q("UPDATE contribution_deliveries SET status='sending',attempted_at=?,sent_remaining_cents=? WHERE club_id=? AND id=? AND status='pending'",[self::now($today),$c['remaining_cents'],$a['club_id'],$delivery['id']]);if($claim->rowCount()!==1)continue;
            }catch(DomainException $e){if($e->getCode()!==503)$this->mark($delivery['id'],'skipped',$e->getMessage());continue;}
            $transportStarted=false;$preparing=false;try{
                $this->db->exec('START TRANSACTION');$preparing=true;$this->actor(true);
                $locked=$this->db->prepare('SELECT status FROM contribution_deliveries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$locked->execute([$a['club_id'],$delivery['id']]);if($locked->fetchColumn()!=='sending')throw new DomainException('Versandauftrag inzwischen geändert. Erneut prüfen.',409);
                // Claim first; then repeat all changing checks immediately before transport.
                if(!MailPolicy::allowed($publicUrl))throw new DomainException('Mailversand gesperrt.');$author=Permissions::actor($this->db,$delivery['actor_id']);if($author['club_id']!==$a['club_id']||$author['finance_access']!=='write')throw new DomainException('Finanzberechtigung entzogen.');
                $c=$this->current($delivery['entry_id'],$today,$delivery['type'],$delivery['id']);if($c['recipient_email']!==$delivery['recipient_email'])throw new DomainException('Empfänger geändert.');
                $currentFile=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($delivery['entry_id'])['file'];if($currentFile['document_id']!==$file['document_id']||(int)$currentFile['revision']!==(int)$file['revision']||!hash_equals($file['sha256'],$currentFile['sha256']))throw new DomainException('Ursprüngliches Beitragsrechnungs-PDF wurde geändert. Versand erneut prüfen.',409);$file=$currentFile;
                if($automaticGuard!==null&&!$automaticGuard())throw new DomainException('Automatischer Beitragsversand wurde deaktiviert.');
                $mail=ContributionMail::build($this->db,$a['club_id'],$delivery['entry_id'],$delivery['type']);$subject=$mail['subject'];$text=$mail['text'];
                $html=ApplicationMail::html($text,$publicUrl,$subject,'','',$delivery['type']==='reminder'?'ZAHLUNGSERINNERUNG':'BEITRAG',$mail['club_name'],$mail['html']);
                if($mail['remaining_cents']!==$c['remaining_cents'])throw new DomainException('Zahlungsstand während der Nachrichtenvorbereitung geändert. Erneut prüfen.',409);
                $this->q("UPDATE contribution_deliveries SET sent_remaining_cents=? WHERE club_id=? AND id=? AND status='sending'",[$mail['remaining_cents'],$a['club_id'],$delivery['id']]);$this->db->commit();$preparing=false;
                $transportStarted=true;$result=$send($delivery['recipient_email'],$subject,$text,$html,$mail['attach_pdf']?[['name'=>$file['name'],'bytes'=>$file['bytes'],'mime'=>'application/pdf']]:[],$delivery['type']==='reminder'?'reminders':'contributions');if($result===false)throw new \RuntimeException('Unklarer Transportstatus.');
                $this->q("UPDATE contribution_deliveries SET status='sent',sent_at=?,sent_remaining_cents=?,reason='' WHERE club_id=? AND id=? AND status='sending'",[self::now($today),$c['remaining_cents'],$a['club_id'],$delivery['id']]);++$sent;
            }catch(\Throwable $failure){
                if($preparing&&$this->db->inTransaction())$this->db->rollBack();
                if($failure instanceof \Kulturhus\Native\Delivery\DeliveryNotStarted)$this->mark($delivery['id'],$failure->retryable?'pending':'skipped',\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$failure->stage],'sending');
                elseif(!$transportStarted&&$failure instanceof DomainException)$this->mark($delivery['id'],$failure->getCode()===503?'pending':'skipped',$failure->getMessage(),'sending');
                elseif(!$transportStarted)$this->mark($delivery['id'],'pending','Nachrichtenvorbereitung nicht abgeschlossen. Vor einem neuen Serverlauf prüfen.','sending');
                else $this->mark($delivery['id'],'uncertain','Versandergebnis ungeklärt; vor einem neuen Versuch manuell prüfen.','sending');
            }
        }return $sent;
    }
}
