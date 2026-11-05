<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once dirname(__DIR__, 1).'/Documents/ManagerDocumentWorkflows.php';
require_once __DIR__.'/MailPolicy.php';
require_once dirname(__DIR__, 1).'/People/Members/ApplicationMail.php';

/** Explicit reminder batches. Authentication remains mandatory; email contains no signing token. */
final class WorkflowReminderMail
{
    private ManagerDocumentWorkflows $module;
    public function __construct(private PDO $db,private string $actor,private string $url,?callable $guard=null){$this->module=new ManagerDocumentWorkflows($db,$actor,$guard);}
    private function authority():array{$a=$this->module->store->actor();if($a['access']!=='write')throw new DomainException('Schreibrecht für die Gefährdungsbeurteilung erforderlich.');return $a;}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function digest(array $snapshot):string{ksort($snapshot,SORT_STRING);return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    public function preview(string $document,int $revision):array
    {
        $a=$this->authority();$doc=$this->module->store->document($document);
        if($doc['status']!=='approved'||(int)$doc['version']!==$revision)throw new DomainException('Zuerst die aktuelle Beurteilung freigeben.');
        $members=array_column($this->module->signatures->members(),null,'id');$recipients=[];$missing=[];
        $requests=$this->module->signatures->forDocument($document,$revision);
        foreach($requests as $s){
            if($s['signed_at']||!isset($members[$s['recipient_id']]))continue;
            $person=$this->q('SELECT c.name,c.email FROM actors a JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.id=? AND a.active=1 AND c.archived_at IS NULL',[$a['club_id'],$s['recipient_id']])->fetch(PDO::FETCH_ASSOC);
            if(!$person||!filter_var(trim($person['email']),FILTER_VALIDATE_EMAIL)){$missing[]=$members[$s['recipient_id']]['name'];continue;}
            if($this->q("SELECT 1 FROM dwf_reminders WHERE club_id=? AND signature_id=? AND (state IN ('pending','sending') OR (state IN ('sent','uncertain') AND updated_at>UTC_TIMESTAMP(6)-INTERVAL 1 DAY))",[$a['club_id'],$s['id']])->fetchColumn())continue;
            $recipients[]=['signature_id'=>$s['id'],'recipient_id'=>$s['recipient_id'],'name'=>$person['name'],'email'=>strtolower(trim($person['email'])),'document_id'=>$document,'revision'=>$revision,'document_sha256'=>$s['document_sha256'],'title'=>$doc['title']];
        }
        $data=['document_id'=>$document,'revision'=>$revision,'title'=>$doc['title'],'recipients'=>$recipients,'missing'=>$missing];
        $data['hash']=hash('sha256',json_encode([$a['club_id'],$a['id'],$this->url,$data],JSON_THROW_ON_ERROR));return $data;
    }
    public function queue(string $document,int $revision,string $hash,string $key):int
    {
        if(!MailPolicy::allowed($this->url))throw new DomainException('In dieser Testumgebung werden keine Mails versendet.');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||$this->db->inTransaction())throw new DomainException('Versandvorschau erneut öffnen.');
        $a=$this->authority();
        $this->db->beginTransaction();
        try{
            // Lock before any consistent read can pin an older recipient/reminder snapshot.
            $this->q('SELECT id FROM dwf_documents WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$document])->fetchColumn();
            $current=$this->authority();
            if($current['id']!==$a['id']||$current['club_id']!==$a['club_id'])throw new DomainException('Versandberechtigung geändert.');
            if($this->q('SELECT 1 FROM dwf_reminders WHERE club_id=? AND request_key=? FOR UPDATE',[$a['club_id'],$key])->fetchColumn())throw new DomainException('Diese Erinnerung wurde bereits vorgemerkt.');
            $preview=$this->preview($document,$revision);
            if(!hash_equals($preview['hash'],$hash)||!$preview['recipients'])throw new DomainException('Empfänger oder Beurteilung geändert. Vorschau erneut öffnen.');
            foreach($preview['recipients'] as $p){$json=json_encode($p,JSON_THROW_ON_ERROR);$this->q("INSERT INTO dwf_reminders(id,club_id,signature_id,requested_by,request_key,snapshot_json,snapshot_sha256,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[bin2hex(random_bytes(16)),$a['club_id'],$p['signature_id'],$a['id'],$key,$json,self::digest($p)]);}
            $this->db->commit();return count($preview['recipients']);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function content(array $person,string $url):array
    {
        $link=rtrim($url,'/').'/?view=portal&tab=signatures&wf_sign='.rawurlencode($person['signature_id']);
        $subject='Bitte unterschreiben: '.preg_replace('/[\r\n]+/u',' ',mb_substr($person['title'],0,130));
        $text='Guten Tag '.$person['name'].",\n\nbitte lies die Gefährdungsbeurteilung „".$person['title'].'“ (Fassung '.$person['revision'].") und unterschreibe sie in deinem Vereinskonto.\n\nÖffne den Link, melde dich an und bestätige die Beurteilung mit deiner Unterschrift.\n\n".$link;
        $parts=parse_url($url);$base=($parts['scheme']??'https').'://'.($parts['host']??'').(isset($parts['port'])?':'.$parts['port']:'');
        return ['subject'=>$subject,'text'=>$text,'html'=>ApplicationMail::html($text,$base,$subject,$link,'Beurteilung lesen und unterschreiben','GEFÄHRDUNGSBEURTEILUNG')];
    }
    public function deliveries(string $document,int $revision):array
    {
        $a=$this->authority();$this->module->store->document($document,$revision);
        return $this->q('SELECT r.state,r.error,r.updated_at,r.snapshot_json FROM dwf_reminders r JOIN dwf_signatures s ON s.id=r.signature_id WHERE r.club_id=? AND s.document_id=? AND s.revision=? ORDER BY r.created_at DESC,r.id',[$a['club_id'],$document,$revision])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function deliveryPage(string $document,int $revision,mixed $page=1):array
    {
        $a=$this->authority();$this->module->store->document($document,$revision);
        $from=' FROM dwf_reminders r JOIN dwf_signatures s ON s.club_id=r.club_id AND s.id=r.signature_id WHERE r.club_id=? AND s.document_id=? AND s.revision=?';
        return \Kulturhus\Verein\DocumentWorkflow\WorkflowList::query($this->db,'SELECT r.state,r.error,r.updated_at,r.snapshot_json'.$from.' ORDER BY r.created_at DESC,r.id DESC','SELECT COUNT(*)'.$from,[$a['club_id'],$document,$revision],$page);
    }
    public static function dispatch(PDO $db,string $url,callable $send,string $club):int
    {
        if(!MailPolicy::allowed($url)||!ManagerDocumentWorkflows::installed($db))return 0;
        $q=static function(string $sql,array $args=[])use($db):\PDOStatement{$q=$db->prepare($sql);$q->execute($args);return $q;};
        $q("UPDATE dwf_reminders SET state='uncertain',error='Zustellung vor erneutem Versand prüfen',updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND state='sending' AND updated_at<UTC_TIMESTAMP(6)-INTERVAL 15 MINUTE",[$club]);
        $sent=0;
        foreach($q("SELECT * FROM dwf_reminders WHERE club_id=? AND state='pending' ORDER BY created_at,id LIMIT 20",[$club])->fetchAll(PDO::FETCH_ASSOC) as $row){
            try{
                $p=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
                if(!is_array($p)||!hash_equals($row['snapshot_sha256'],self::digest($p)))throw new DomainException('Versandnachweis geändert.');
                $service=new self($db,$row['requested_by'],$url);$a=$service->authority();
                if($a['club_id']!==$club||$p['signature_id']!==$row['signature_id'])throw new DomainException('Versandberechtigung geändert.');
                $s=$q('SELECT s.*,d.title,d.status,d.version FROM dwf_signatures s JOIN dwf_documents d ON d.club_id=s.club_id AND d.id=s.document_id WHERE s.id=? AND s.club_id=?',[$row['signature_id'],$club])->fetch(PDO::FETCH_ASSOC);
                $current=$q('SELECT c.name,c.email FROM actors a JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.id=? AND a.active=1 AND c.archived_at IS NULL',[$club,$p['recipient_id']])->fetch(PDO::FETCH_ASSOC);
                if(!$s||$s['signed_at']||$s['status']!=='approved'||(int)$s['version']!==(int)$p['revision']||$s['recipient_id']!==$p['recipient_id']||$s['document_id']!==$p['document_id']||!hash_equals($s['document_sha256'],$p['document_sha256'])||$s['title']!==$p['title']||!in_array($p['recipient_id'],array_column($service->module->signatures->members(),'id'),true)||!$current||$current['name']!==$p['name']||strtolower(trim($current['email']))!==$p['email']||strtotime($row['created_at'].' UTC')<time()-172800)throw new DomainException('Empfänger oder Beurteilung geändert.');
                $snapshot=$q('SELECT snapshot_json FROM dwf_document_versions WHERE club_id=? AND document_id=? AND revision=?',[$club,$p['document_id'],$p['revision']])->fetchColumn();
                if(!is_string($snapshot)||!hash_equals($p['document_sha256'],hash('sha256',$snapshot)))throw new DomainException('Dokumentnachweis geändert.');
                $content=self::content($p,$url);
            }catch(\Throwable){$q("UPDATE dwf_reminders SET state='blocked',error='Unterschrift, Empfänger oder Versandrecht erneut prüfen',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='pending'",[$row['id']]);continue;}
            if(!$q("UPDATE dwf_reminders SET state='sending',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='pending'",[$row['id']])->rowCount())continue;
            try{$send($p['email'],$content['subject'],$content['text'],$content['html'],[],$club);$q("UPDATE dwf_reminders SET state='sent',error='',updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND state='sending'",[$club,$row['id']]);$sent++;}
            catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){
                $q("UPDATE dwf_reminders SET state=?,error=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND state='sending'",[$e->retryable?'pending':'blocked',\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$e->stage],$club,$row['id']]);
            }
            catch(\Throwable){
                $actual=$q('SELECT * FROM dwf_reminders WHERE club_id=? AND id=?',[$club,$row['id']])->fetch(PDO::FETCH_ASSOC);
                if($actual&&$actual['state']==='sent'&&$actual['signature_id']===$row['signature_id']&&$actual['snapshot_sha256']===$row['snapshot_sha256']&&$actual['snapshot_json']===$row['snapshot_json']&&$actual['request_key']===$row['request_key']){$sent++;continue;}
                $q("UPDATE dwf_reminders SET state='uncertain',error='Zustellung vor erneutem Versand prüfen',updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND state='sending'",[$club,$row['id']]);
            }
        }
        return $sent;
    }
}
