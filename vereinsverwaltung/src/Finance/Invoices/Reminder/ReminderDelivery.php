<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Delivery\DeliveryNotStarted;
use Kulturhus\Verein\{Documents,MailChannelSettings,MailPolicy,Permissions};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** One durable claim per accepted mail. Interrupted outcomes never become pending again. */
final class ReminderDelivery
{
    public function __construct(private PDO $db,private string $actorId,#[\SensitiveParameter]private string $master){}
    public static function dispatchPending(PDO $db,string $club,#[\SensitiveParameter]string $master,string $origin,?callable $send,?string $workerActor=null):array
    {
        if($db->inTransaction())throw new DomainException('Erinnerungsversand erst nach Abschluss des offenen Speichervorgangs starten.',503);
        $result=['status'=>'disabled','sent'=>0,'blocked'=>0,'uncertain'=>0,'unavailable'=>0];
        if($send===null||!MailPolicy::allowed($origin))return $result;
        $result['status']='enabled';
        $rows=Db::run($db,"SELECT id,actor_id FROM native_invoice_reminder_deliveries WHERE club_id=? AND state='pending' ORDER BY created_at,id LIMIT 25",[$club])->fetchAll();
        foreach($rows as$row){
            try{$outcome=(new self($db,$workerActor??$row['actor_id'],$master))->dispatch($row['id'],$origin,$send);if(in_array($outcome['state'],['sent','blocked','uncertain'],true))$result[$outcome['state']]++;}
            catch(DomainException $failure){if(!in_array($failure->getCode(),[403,404,409,503],true))throw $failure;$result['unavailable']++;}
        }
        return $result;
    }
    private static function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException $failure){if($failure->getCode()===503||$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')throw $failure;throw new DomainException('Aktuelle Finanzberechtigung erforderlich.',403,$failure);}
    }
    private function row(string $id,bool $write=false):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigene Erinnerungsvormerkung nicht verfügbar.',404);$actor=$this->actor($write);
        foreach(['native_invoice_reminder_deliveries','native_invoice_reminder_attempts']as$table)if(!Db::run($this->db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])->fetchColumn())throw new DomainException('Rechnungserinnerungen benötigen das aktuelle installierte Paket.',503);
        $row=Db::run($this->db,'SELECT * FROM native_invoice_reminder_deliveries WHERE club_id=? AND id=?'.($this->db->inTransaction()?($write?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'):''),[$actor['club_id'],$id])->fetch();if(!$row)throw new DomainException('Eigene Erinnerungsvormerkung nicht verfügbar.',404);
        $output=(new Documents($this->db,$this->actorId))->currentFinancialOriginal($row['document_id'],1);if($output['file']['sha256']!==$row['document_sha256'])throw new DomainException('Ursprüngliches Erinnerungsdokument bitte prüfen lassen.',503);return $row;
    }
    public function get(string $id):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$this->current($id);if($own)$this->db->commit();return $result;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Rechnung oder Erinnerung wird gerade bearbeitet. Erneut prüfen.',503,$failure);throw $failure;}
    }
    public function listing(string $invoice,array $query=[]):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice))throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.',404);
        $raw=$query['page']??'1';$page=is_string($raw)||is_int($raw)?(string)$raw:'';
        $actor=$this->actor();$parent=(new ReminderRequests($this->db,$this->actorId,$this->master))->parentDocument($invoice);(new Documents($this->db,$this->actorId))->document($parent);
        $base=['rows'=>[],'total'=>0,'pages'=>1,'page'=>null,'page_input'=>$page,'per_page'=>25,'error'=>''];
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))return array_replace($base,['error'=>'Verfügbare genaue Versandseite auswählen.']);
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_invoice_reminder_deliveries'")->fetchColumn())throw new DomainException('Rechnungserinnerungen benötigen das aktuelle installierte Paket.',503);
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_invoice_reminder_deliveries WHERE club_id=? AND invoice_id=?',[$actor['club_id'],$invoice])->fetchColumn();$pages=max(1,(int)ceil($total/25));
        if((int)$page>$pages)return array_replace($base,['total'=>$total,'pages'=>$pages,'error'=>'Verfügbare genaue Versandseite auswählen.']);
        $ids=Db::run($this->db,'SELECT id FROM native_invoice_reminder_deliveries WHERE club_id=? AND invoice_id=? ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.(((int)$page-1)*25),[$actor['club_id'],$invoice])->fetchAll(PDO::FETCH_COLUMN);
        $rows=[];foreach($ids as$id)$rows[]=$this->get($id);
        return array_replace($base,['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>(int)$page]);
    }
    private function current(string $id):array
    {
        $row=$this->row($id);unset($row['sealed_message']);$row['attempt']=null;
        if($row['attempt_id'])$row['attempt']=Db::run($this->db,'SELECT * FROM native_invoice_reminder_attempts WHERE club_id=? AND id=?',[$row['club_id'],$row['attempt_id']])->fetch()?:null;
        return $row;
    }
    private function source(array $row,array $payload):void
    {
        $author=Permissions::finance($this->db,$row['actor_id'],true);if($author['club_id']!==$row['club_id'])throw new DomainException('Finanzberechtigung des ursprünglichen Auftrags entzogen.',403);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $source=ReminderSource::read($this->db,$row['actor_id'],$row['invoice_id'],$today);
        // The letter day belongs to the immutable accepted message; current payment facts are independent of midnight.
        $source['source']['reminder_day']=$payload['source']['reminder_day'];
        if(!hash_equals($row['source_sha256'],hash('sha256',ReminderRequests::json($source['source']))))throw new DomainException('Zahlungsstand oder ursprüngliche Rechnungsquelle inzwischen geändert.',409);
        (new Documents($this->db,$row['actor_id']))->currentFinancialOriginal($row['document_id'],1);
        $resolved=MailChannelSettings::resolve($this->db,$row['club_id'],$this->master,'reminders');$config=$resolved['override'];if(!$config)throw new DeliveryNotStarted('mail_configuration_missing');
        $sender=['from'=>$config['from'],'name'=>$config['name']??'','reply_to'=>$config['reply']?:$config['from']];if($sender!==$payload['sender'])throw new DeliveryNotStarted('mail_message_invalid');
    }
    private function finish(string $club,string $id,string $attempt,string $state,string $reason,string $reference=''):void
    {
        Transactions::run($this->db,function()use($club,$id,$attempt,$state,$reason,$reference):void{
            $row=Db::run($this->db,'SELECT state,attempt_id FROM native_invoice_reminder_deliveries WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$club,$id])->fetch();
            $owner=Db::run($this->db,'SELECT actor_id FROM native_invoice_reminder_attempts WHERE club_id=? AND id=? AND delivery_id=? FOR SHARE NOWAIT',[$club,$attempt,$id])->fetchColumn();
            if($owner!==$this->actorId||!$row||$row['state']!=='sending'||$row['attempt_id']!==$attempt)return;
            $now=self::now();$attemptState=match($state){'sent'=>'sent','blocked'=>'not_sent',default=>'uncertain'};
            Db::run($this->db,'UPDATE native_invoice_reminder_attempts SET state=?,external_reference=?,reason_code=?,completed_at=? WHERE club_id=? AND id=? AND delivery_id=? AND state=\'claimed\'',[$attemptState,$reference,$reason,$now,$club,$attempt,$id]);
            Db::run($this->db,'UPDATE native_invoice_reminder_deliveries SET state=?,reason_code=?,version=version+1,updated_at=? WHERE club_id=? AND id=? AND state=\'sending\' AND attempt_id=?',[$state,$reason,$now,$club,$id,$attempt]);
        });
    }
    private function blockUnclaimed(string $id,int $version,string $reason):void
    {
        Transactions::run($this->db,function()use($id,$version,$reason):void{$row=$this->row($id,true);if($row['state']!=='pending'||(int)$row['version']!==$version)return;Db::run($this->db,'UPDATE native_invoice_reminder_deliveries SET state=\'blocked\',reason_code=?,version=version+1,updated_at=? WHERE club_id=? AND id=? AND state=\'pending\' AND version=?',[$reason,self::now(),$row['club_id'],$id,$version]);});
    }
    public function dispatch(string $id,string $publicUrl,?callable $send=null):array
    {
        if($this->db->inTransaction())throw new DomainException('Erinnerungsversand erst nach Abschluss des offenen Speichervorgangs starten.',503);
        $this->actor(true);$before=$this->row($id,true);if(!MailPolicy::allowed($publicUrl)||$before['state']!=='pending')return $this->get($id);
        try{$payload=ReminderEnvelope::open($this->master,$before);$this->source($before,$payload);}catch(DomainException $failure){if($failure->getCode()===503)throw $failure;$this->blockUnclaimed($id,(int)$before['version'],'source_or_sender_changed');return $this->get($id);}
        $attempt=bin2hex(random_bytes(16));$transportStarted=false;$claimed=false;
        try{
            $claimed=Transactions::run($this->db,function()use($id,$before,$attempt):bool{
                $row=$this->row($id,true);if($row['state']!=='pending'||(int)$row['version']!==(int)$before['version'])return false;$now=self::now();
                Db::run($this->db,"INSERT INTO native_invoice_reminder_attempts(club_id,id,delivery_id,actor_id,message_sha256,state,started_at) VALUES(?,?,?,?,?,'claimed',?)",[$row['club_id'],$attempt,$id,$this->actorId,$row['message_sha256'],$now]);
                Db::run($this->db,"UPDATE native_invoice_reminder_deliveries SET state='sending',attempt_id=?,version=version+1,updated_at=? WHERE club_id=? AND id=? AND state='pending' AND version=?",[$attempt,$now,$row['club_id'],$id,$row['version']]);return true;
            });
            if(!$claimed)return $this->get($id);
            // A second current read ends before the transport callback. No financial transaction spans SMTP.
            $this->db->exec('START TRANSACTION');try{$current=$this->row($id,true);if($current['state']!=='sending'||$current['attempt_id']!==$attempt)throw new DomainException('Versandvormerkung inzwischen geändert.',409);$payload=ReminderEnvelope::open($this->master,$current);$this->source($current,$payload);$this->db->commit();}catch(\Throwable $failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
            if(!MailPolicy::allowed($publicUrl))throw new DeliveryNotStarted('mail_delivery_disabled');
            $transportStarted=true;$send??=(new ReminderTransport($this->db,$this->master))->send(...);$reference=$send($current['club_id'],$payload);
            if(!is_string($reference)||trim($reference)===''||mb_strlen($reference)>200||preg_match('/[\x00-\x1f\x7f]/',$reference))throw new \RuntimeException('Unbestätigter Erinnerungstransport.');
            $this->finish($before['club_id'],$id,$attempt,'sent','',$reference);
        }catch(\Throwable $failure){
            if($this->db->inTransaction())$this->db->rollBack();
            // The conditional finish cannot downgrade an already committed sent result after acknowledgement loss.
            if($failure instanceof DeliveryNotStarted)$this->finish($before['club_id'],$id,$attempt,'blocked',$failure->stage);
            elseif(!$transportStarted)$this->finish($before['club_id'],$id,$attempt,'blocked','pretransport_not_started');
            else $this->finish($before['club_id'],$id,$attempt,'uncertain','transport_outcome_unknown');
            if($failure instanceof DomainException&&$failure->getCode()===503)throw $failure;
        }
        return $this->get($id);
    }
}
