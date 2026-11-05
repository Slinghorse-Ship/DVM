<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Explicit board correspondence only. Birthdays and anniversaries never enqueue work. */
final class ClubMailings {
 public const STATES=['draft','queued','sent','cancelled'];
 public function __construct(private PDO $db,private string $actor){}
 private function authority():array{try{return BoardAccess::require($this->db,$this->actor);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}}
 public function contacts():array{$a=$this->authority();$people=(new CalendarInvitations($this->db,$this->actor,new Registry($this->db,$this->actor)))->contacts();foreach($people as &$person){$person['member_number']=(string)(DocumentCatalog::query($this->db,'SELECT member_number FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on DESC,id DESC LIMIT 1',[$a['club_id'],$person['id']])->fetchColumn()?:'');}unset($person);foreach($people as &$person){$latest=(string)(DocumentCatalog::query($this->db,'SELECT status FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on DESC,id DESC LIMIT 1',[$a['club_id'],$person['id']])->fetchColumn()?:'kontakt');$person['recipient_group']=in_array($person['member_status'],['aktiv','passiv'],true)?'members':($latest==='kontakt'?'contacts':'other');}unset($person);return $people;}
 public function attachmentChoices():array{$this->authority();return array_values(array_filter((new Documents($this->db,$this->actor))->listing(),fn($d)=>$d['state']==='active'&&!$d['admission_only']&&$d['scopes']===['orga']));}
 public function get(string $id):array{$a=$this->authority();$r=DocumentCatalog::query($this->db,'SELECT * FROM club_mailings WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Sendung nicht verfügbar.',404);$r['people']=json_decode($r['recipients'],true,512,JSON_THROW_ON_ERROR);$r['merge_fields']=json_decode($r['fields'],true,512,JSON_THROW_ON_ERROR);$r['attachment_refs']=json_decode($r['attachments'],true,512,JSON_THROW_ON_ERROR);return $r;}
 public function listing():array{$a=$this->authority();return DocumentCatalog::query($this->db,'SELECT * FROM club_mailings WHERE club_id=? ORDER BY created_at DESC',[$a['club_id']])->fetchAll();}
 public function catalogue(string $query='',string $state='',int $page=1):array
 {
  if(!mb_check_encoding($query,'UTF-8')||mb_strlen($query)>200||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Ursprüngliche Sendungssuche vollständig prüfen.',422);
  if($state!==''&&!in_array($state,self::STATES,true))throw new DomainException('Ursprünglichen Sendungsstatus auswählen.',422);
  if($page<1||$page>1000000)throw new DomainException('Ursprüngliche Sendungsseite auswählen.',422);
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
  try{
   $a=$this->authority();$where='club_id=?';$params=[$a['club_id']];
   if($query!==''){$where.=" AND subject LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';}
   if($state!==''){$where.=' AND state=?';$params[]=$state;}
   $total=(int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM club_mailings WHERE '.$where,$params)->fetchColumn();
   $pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Die gewählte Sendungsseite ist nicht verfügbar. Seite erneut auswählen.',422);
   $rows=DocumentCatalog::query($this->db,'SELECT id,subject,state,created_at FROM club_mailings WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),$params)->fetchAll();
   if($own)$this->db->commit();return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>6,'query'=>$query,'state'=>$state];
  }catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 public function byRequest(string $key):?array
 {
  $a=$this->authority();if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Sendung und Vorgangskennung prüfen.',422);
  $id=DocumentCatalog::query($this->db,'SELECT id FROM club_mailings WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetchColumn();
  return $id===false?null:$this->get($id);
 }
 public function save(string $id,string $subject,string $body,array $selected,string $key,string $fieldText='',array $attachments=[]):string
 {
  $a=$this->authority();$updating=$id!=='';$subject=trim($subject);$body=trim($body);
  if($subject===''||strlen($subject)>200||preg_match('/[\r\n]/',$subject)||$body===''||strlen($body)>10000||!preg_match($updating?'/^[a-f0-9]{64}$/D':'/^[a-f0-9]{32}$/D',$key))throw new DomainException('Betreff und Nachricht prüfen.');
  $people=array_values(array_filter($this->contacts(),fn($person)=>in_array($person['id'],$selected,true)));
  if(!$people||count($people)>250||array_diff($selected,array_column($people,'id')))throw new DomainException('Eine bis 250 Personen auswählen.');
  $fields=MailingContent::fields($fieldText);$files=MailingContent::snapshots(MailingContent::attachments($this->db,$this->actor,$attachments));
  $encoded=json_encode($people,JSON_THROW_ON_ERROR);$fieldJson=json_encode($fields,JSON_THROW_ON_ERROR);$fileJson=json_encode($files,JSON_THROW_ON_ERROR);
  $hash=hash('sha256',json_encode([$subject,$body,$people,$fields,$files],JSON_THROW_ON_ERROR));
  $this->db->beginTransaction();
  try{
   $this->authority();
   if($updating){
    $old=$this->get($id);
    if($old['state']!=='draft'||!hash_equals($old['preview_hash'],$key))throw new DomainException('Sendung geändert oder bereits eingeplant. Bitte neu laden.',409);
    if(!hash_equals($hash,$key)){
     $changed=DocumentCatalog::query($this->db,"UPDATE club_mailings SET subject=?,body=?,recipients=?,preview_hash=?,fields=?,attachments=? WHERE club_id=? AND id=? AND state='draft' AND preview_hash=?",[$subject,$body,$encoded,$hash,$fieldJson,$fileJson,$a['club_id'],$id,$key]);
     if($changed->rowCount()!==1)throw new DomainException('Sendung geändert oder bereits eingeplant. Bitte neu laden.',409);
    }
   }else{
    $old=DocumentCatalog::query($this->db,'SELECT id,preview_hash,club_id FROM club_mailings WHERE request_key=?',[$key])->fetch();
    if($old){
     if($old['club_id']!==$a['club_id']||!hash_equals($hash,$old['preview_hash']))throw new DomainException('Vorgangskennung bereits verwendet.',409);
     $id=$old['id'];
    }else{
     $id=bin2hex(random_bytes(16));
     DocumentCatalog::query($this->db,'INSERT INTO club_mailings(id,club_id,actor_id,subject,body,recipients,state,created_at,request_key,preview_hash,fields,attachments) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$a['club_id'],$a['id'],$subject,$body,$encoded,'draft',gmdate('c'),$key,$hash,$fieldJson,$fileJson]);
    }
   }
   $this->db->commit();return $id;
  }catch(\Throwable $failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 public function preview(string $id):array{$r=$this->get($id);$current=array_column($this->contacts(),null,'id');$out=[];$missing=[];foreach($r['people'] as $p){if(!isset($current[$p['id']])||$current[$p['id']]['email']!==$p['email']||$current[$p['id']]['name']!==$p['name']||$current[$p['id']]['address']!==$p['address']||$current[$p['id']]['member_number']!==($p['member_number']??''))throw new DomainException('Empfängerdaten geändert. Entwurf erneut speichern.');$email=strtolower(trim($p['email']));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$missing[]=$p;continue;}$out[$email]??=['email'=>$email,'names'=>[],'people'=>[]];$out[$email]['names'][]=$p['name'];$out[$email]['people'][]=$p;}foreach($out as &$p){$p['name']=implode(', ',$p['names']);$values=MailingContent::values($p['people'],$r['merge_fields']);$p['subject']=MailingContent::render($r['subject'],$values);if(strlen($p['subject'])>250||preg_match('/[\r\n]/',$p['subject']))throw new DomainException('Personalisierter Betreff ist zu lang oder enthält Zeilenumbrüche.');$p['body']=(str_contains($r['body'],'{{ANREDE}}')?'':'Guten Tag '.$p['name'].",\n\n").MailingContent::render($r['body'],$values);}unset($p);return ['mailing'=>$r,'recipients'=>array_values($out),'missing'=>$missing,'attachments'=>MailingContent::snapshots(MailingContent::attachments($this->db,$this->actor,$r['attachment_refs'],true))];}
 public function queue(string $id,string $hash):void
 {
  if(!MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL')))throw new DomainException('Mailversand in dieser Umgebung gesperrt.');
  $this->db->beginTransaction();
  try{
   $p=$this->preview($id);$r=$p['mailing'];
   if($r['state']!=='draft'||!hash_equals($r['preview_hash'],$hash)||!$p['recipients'])throw new DomainException('Versandvorschau erneut prüfen.',409);
   $changed=DocumentCatalog::query($this->db,"UPDATE club_mailings SET state='queued' WHERE club_id=? AND id=? AND state='draft' AND preview_hash=?",[$r['club_id'],$id,$hash]);
   if($changed->rowCount()!==1)throw new DomainException('Versandvorschau erneut prüfen.',409);
   foreach($p['recipients'] as $person)DocumentCatalog::query($this->db,'INSERT INTO club_outbox(mailing_id,email,name,body,updated_at,subject) VALUES(?,?,?,?,?,?)',[$id,$person['email'],$person['name'],$person['body'],gmdate('c'),$person['subject']]);
   $this->db->commit();
  }catch(\Throwable $failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 public function deliveries(string $id):array{$this->get($id);return DocumentCatalog::query($this->db,'SELECT id,email,name,state,updated_at,error FROM club_outbox WHERE mailing_id=? ORDER BY id',[$id])->fetchAll();}
 public function cancel(string $id,?string $hash=null):void
 {
  $this->db->beginTransaction();
  try{
   $r=$this->get($id);$hash??=$r['preview_hash'];
   if(!hash_equals($r['preview_hash'],$hash)||!in_array($r['state'],['queued','cancelled'],true))throw new DomainException('Versandvorschau erneut prüfen.',409);
   if($r['state']==='cancelled'){$this->db->commit();return;}
   $changed=DocumentCatalog::query($this->db,"UPDATE club_mailings SET state='cancelled' WHERE club_id=? AND id=? AND state='queued' AND preview_hash=?",[$r['club_id'],$id,$hash]);
   if($changed->rowCount()!==1)throw new DomainException('Versandvorschau erneut prüfen.',409);
   DocumentCatalog::query($this->db,"UPDATE club_outbox SET state='cancelled',updated_at=? WHERE mailing_id=? AND state='pending'",[gmdate('c'),$id]);
   $this->db->commit();
  }catch(\Throwable $failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 /** Read an already generated original; never creates a PDF or acknowledges postal delivery. */
 public function printed(string $id,string $hash,string $key):?string
 {
  $a=$this->authority();$this->get($id);
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Sendung und Vorgangskennung prüfen.',422);
  $r=DocumentCatalog::query($this->db,'SELECT r.document_id,f.snapshot FROM document_requests r JOIN document_files f ON f.club_id=r.club_id AND f.document_id=r.document_id AND f.revision=1 WHERE r.club_id=? AND r.request_key=?',[$a['club_id'],$key])->fetch();
  if(!$r)return null;
  $snapshot=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR);
  if(($snapshot['kind']??null)!=='serial_letter'||($snapshot['mailing_id']??null)!==$id||($snapshot['mailing_hash']??null)!==$hash||($snapshot['actor_id']??null)!==$a['id'])throw new DomainException('Vorgangskennung bereits verwendet.',409);
  (new Documents($this->db,$this->actor))->document($r['document_id']);return$r['document_id'];
 }
 public function letters(string $id,?string $hash=null,?string $key=null):string
 {
  $a=$this->authority();
  if($key!==null){if($hash===null)throw new DomainException('Ursprüngliche Sendung und Vorgangskennung prüfen.',422);$previous=$this->printed($id,$hash,$key);if($previous!==null)return$previous;}
  $p=$this->preview($id);$mailing=$p['mailing'];
  if($hash!==null&&!hash_equals($mailing['preview_hash'],$hash))throw new DomainException('Sendung geändert oder bereits eingeplant. Bitte neu laden.',409);
  $people=$mailing['people'];foreach($people as $person)if(trim($person['address'])==='')throw new DomainException('Postanschrift fehlt bei '.$person['name'].'. Empfängerauswahl korrigieren.');
  foreach($people as &$person){$values=MailingContent::values([$person],$mailing['merge_fields']);$person['title']=MailingContent::render($mailing['subject'],$values);$person['body']=MailingContent::render($mailing['body'],$values);$person['has_salutation']=str_contains($mailing['body'],'{{ANREDE}}');}unset($person);
  $date=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin'));
  $render=['invitation_batch'=>true,'club'=>(new Settings($this->db,$this->actor))->section('club')['data'],'title'=>$mailing['subject'],'body'=>$mailing['body'],'date'=>$date->format('d.m.Y'),'recipients'=>$people];
  $snapshot=['kind'=>'serial_letter','mailing_id'=>$id,'mailing_hash'=>$mailing['preview_hash'],'actor_id'=>$a['id'],'recipients'=>$people,'render'=>$render,'fields'=>$mailing['merge_fields']];
  $pdf=DocumentPreview::pdf($render);
  return(new Documents($this->db,$this->actor))->generated('serienbrief.pdf',$pdf,['title'=>'Serienbrief · '.$mailing['subject'],'kind'=>'export','scope'=>'orga','document_date'=>$date->format('Y-m-d')],$key??bin2hex(random_bytes(16)),$snapshot);
 }
 public static function dispatch(PDO $db,string $url,callable $send,?string $club=null):int{if(!MailPolicy::allowed($url))return 0;DocumentCatalog::query($db,"UPDATE club_outbox SET state='uncertain',error='Versand unterbrochen; Zustellung vor erneutem Versand prüfen' WHERE state='sending' AND updated_at<?".($club!==null?' AND mailing_id IN(SELECT id FROM club_mailings WHERE club_id=?)':''),$club!==null?[gmdate('c',time()-900),$club]:[gmdate('c',time()-900)]);$sent=0;foreach(DocumentCatalog::query($db,"SELECT o.*,m.actor_id,m.club_id,m.subject mailing_subject,m.attachments FROM club_outbox o JOIN club_mailings m ON m.id=o.mailing_id WHERE o.state='pending' AND m.state='queued'".($club!==null?' AND m.club_id=?':'')." ORDER BY o.id LIMIT 20",$club!==null?[$club]:[]) as $row){try{$author=BoardAccess::require($db,$row['actor_id']);if($author['club_id']!==$row['club_id'])throw new DomainException('Vereinszuordnung geändert.');$files=MailingContent::attachments($db,$row['actor_id'],json_decode($row['attachments'],true,512,JSON_THROW_ON_ERROR),true);}catch(DomainException){DocumentCatalog::query($db,"UPDATE club_outbox SET state='blocked',error='Versandberechtigung oder Anhang nicht mehr verfügbar' WHERE id=? AND state='pending'",[$row['id']]);continue;}$claim=DocumentCatalog::query($db,"UPDATE club_outbox SET state='sending',updated_at=? WHERE id=? AND state='pending'",[gmdate('c'),$row['id']]);if(!$claim->rowCount())continue;try{$send($row['email'],$row['subject']?:$row['mailing_subject'],$row['body'],'<p>'.nl2br(htmlspecialchars($row['body'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>',$files);DocumentCatalog::query($db,"UPDATE club_outbox SET state='sent',error='',updated_at=? WHERE id=? AND state='sending'",[gmdate('c'),$row['id']]);++$sent;}catch(\Kulturhus\Native\Delivery\DeliveryNotStarted $e){
 DocumentCatalog::query($db,"UPDATE club_outbox SET state=?,error=?,updated_at=? WHERE id=? AND state='sending'",[$e->retryable?'pending':'blocked',\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$e->stage],gmdate('c'),$row['id']]);
 }catch(\Throwable){
 $actual=DocumentCatalog::query($db,'SELECT o.* FROM club_outbox o JOIN club_mailings m ON m.id=o.mailing_id WHERE o.id=? AND o.mailing_id=? AND m.club_id=?',[$row['id'],$row['mailing_id'],$row['club_id']])->fetch();
 if($actual&&$actual['state']==='sent'&&$actual['email']===$row['email']&&$actual['subject']===$row['subject']&&$actual['body']===$row['body']){++$sent;continue;}
 DocumentCatalog::query($db,"UPDATE club_outbox SET state='uncertain',error='Versandergebnis unklar; vor erneutem Versand prüfen',updated_at=? WHERE id=? AND state='sending'",[gmdate('c'),$row['id']]);
 }}DocumentCatalog::query($db,"UPDATE club_mailings SET state='sent' WHERE state='queued' AND EXISTS(SELECT 1 FROM club_outbox o WHERE o.mailing_id=club_mailings.id) AND NOT EXISTS(SELECT 1 FROM club_outbox o WHERE o.mailing_id=club_mailings.id AND o.state!='sent')".($club!==null?' AND club_id=?':''),$club!==null?[$club]:[]);return $sent;}
}
