<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Review is separate from importing receipts, recording past payments and future bank submission. */
final class InvoiceApprovals
{
 public function __construct(private PDO $db,private string $actorId){}
 private bool $currentRead=false;
 private function q(string $sql,array $args=[]):\PDOStatement{if($this->currentRead&&preg_match('/\ASELECT\b/i',ltrim($sql)))$sql.=' FOR SHARE NOWAIT';return DocumentCatalog::query($this->db,$sql,$args);}
 private function actor():array{$a=Permissions::actor($this->db,$this->actorId);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');return $a;}
 private function office(string $actor):?array{
  // Invoice review and payment submission use the same explicit authority:
  // a confirmed board office with finance write access or a superuser.
  try{return FinancePaymentAuthority::find($this->db,$actor);}catch(DomainException){return null;}
 }
 public function canApprove():bool{return $this->office($this->actorId)!==null;}
 private function latest(string $club,string $entry):?array{return $this->q('SELECT * FROM invoice_approvals WHERE club_id=? AND entry_id=? ORDER BY id DESC LIMIT 1',[$club,$entry])->fetch()?:null;}
 private function snapshot(string $entry):array{
  $a=$this->actor();$f=new Finance($this->db,$this->actorId);$r=$this->currentRead?$f->currentEntry($entry):$f->entry($entry);if($r['kind']!=='expense')throw new DomainException('Die Rechnungsfreigabe gilt für Ausgaben.');
  $filesSql=$this->currentRead?"SELECT d.id,d.version,d.state,d.scopes,d.origin,d.kind,f.mime,f.revision,f.sha256 FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id LEFT JOIN document_files newer ON newer.club_id=f.club_id AND newer.document_id=f.document_id AND newer.revision>f.revision WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? AND newer.revision IS NULL ORDER BY d.id":"SELECT d.id,d.version,d.state,d.scopes,d.origin,d.kind,f.mime,f.revision,f.sha256 FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? ORDER BY d.id";$files=$this->q($filesSql,[$a['club_id'],$entry])->fetchAll();
  $allReadable=true;$documents=new Documents($this->db,$this->actorId);
  foreach($files as $file){try{$this->currentRead?$documents->currentFinancialDocument($file['id']):$documents->document($file['id']);}catch(DomainException){$allReadable=false;}}
  $messages=[];
  foreach($files as $file){foreach($this->q('SELECT l.document_id,l.mailbox_key,l.message_uid,o.sha256,o.created_at FROM invoice_mail_original_links l JOIN invoice_mail_originals o ON o.club_id=l.club_id AND o.mailbox_key=l.mailbox_key AND o.message_uid=l.message_uid WHERE l.club_id=? AND l.document_id=? ORDER BY l.mailbox_key,l.message_uid',[$a['club_id'],$file['id']])->fetchAll() as $message){
   $message['attachments']=$this->q('SELECT d.id,d.version,d.scopes,d.state FROM invoice_mail_original_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id WHERE l.club_id=? AND l.mailbox_key=? AND l.message_uid=? ORDER BY d.id',[$a['club_id'],$message['mailbox_key'],$message['message_uid']])->fetchAll();
   foreach($message['attachments'] as $attachment){try{$this->currentRead?$documents->currentFinancialDocument($attachment['id']):$documents->document($attachment['id']);}catch(DomainException){$allReadable=false;}}$messages[]=$message;
  }}
  $snapshot=['entry'=>$r,'positions'=>$f->positions('entry',$entry,$this->currentRead),'documents'=>$files,'original_messages'=>$messages,'position_details'=>InvoicePositionReview::details($this->db,$this->actorId,$entry,$this->currentRead)];
  $issuedCredit=CreditRefundSource::originals($this->db,$this->actorId,$entry);if($issuedCredit)$snapshot['issued_credit']=$issuedCredit['source'];
  $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $originals=count(array_filter($files,fn($file)=>$file['state']==='active'&&$file['origin']==='upload'&&in_array($file['mime'],['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true)));
  if($issuedCredit)++$originals;
  return ['entry'=>$r,'json'=>$json,'hash'=>hash('sha256',$json),'documents_readable'=>$allReadable,'document_count'=>count($files),'original_count'=>$originals,'issued_credit'=>$issuedCredit!==null];
 }
 /** Payment-boundary read with current entries, approvals, file revisions, positions and access checks. */
 public function currentState(string $entry):array
 {
  $own=!$this->db->inTransaction();$previous=$this->currentRead;try{if($own)$this->db->exec('START TRANSACTION');$this->currentRead=true;$state=$this->state($entry);if($own)$this->db->commit();return $state;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungsfreigabe oder Original wird gerade bearbeitet. Erneut prüfen.',503,$failure);throw $failure;}finally{$this->currentRead=$previous;}
 }
 public function state(string $entry):array{
  $a=$this->actor();$current=$this->snapshot($entry);$last=$this->latest($a['club_id'],$entry);$r=$current['entry'];
  $status='pending';
  if($r['payment_state']==='paid')$status='paid';
  elseif(in_array($r['payment_state'],['cancelled','reversed'],true))$status='closed';
  elseif($last&&$last['decision']==='withdrawn'&&str_starts_with((string)$last['reason'],'[Klärung]'))$status='clarified';
  elseif($last&&$last['decision']==='withdrawn')$status='withdrawn';
  elseif($last){$office=$this->office($last['actor_id']);$status=hash_equals($last['snapshot_hash'],$current['hash'])&&$office&&(int)$office['version']===(int)$last['office_version']?'approved':'changed';}
  return ['status'=>$status,'hash'=>$current['hash'],'decision_id'=>(string)($last['id']??''),'last'=>$last,'document_count'=>$current['document_count'],'original_count'=>$current['original_count'],'documents_readable'=>$current['documents_readable'],'can_approve'=>$this->canApprove()&&$current['documents_readable']&&$current['original_count']>0&&!in_array($status,['paid','closed'],true),'can_withdraw'=>$this->canApprove()&&!in_array($status,['paid','closed'],true),'payment_state'=>$r['payment_state']];
 }
 public function history(string $entry):array{$a=$this->actor();$this->snapshot($entry);return $this->q('SELECT * FROM invoice_approvals WHERE club_id=? AND entry_id=? ORDER BY id DESC',[$a['club_id'],$entry])->fetchAll();}
 public function approve(string $entry,string $hash,string $expectedDecision,string $key,bool $reviewed):int{
  if(!$reviewed)throw new DomainException('Rechnung, Betrag, Empfänger und zugeordnete Dateien ausdrücklich prüfen.');
  return $this->decide($entry,$hash,$expectedDecision,$key,'approved','');
 }
 public function withdraw(string $entry,string $hash,string $expectedDecision,string $reason,string $key):int{
  $reason=trim($reason);if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>1000)throw new DomainException('Grund für die Rücknahme angeben (maximal 1000 Zeichen).');
  return $this->decide($entry,$hash,$expectedDecision,$key,'withdrawn',$reason);
 }
 public function clarify(string $entry,string $hash,string $expectedDecision,string $reason,string $key):int{
  $reason=trim($reason);if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>1000)throw new DomainException('Bitte kurz beschreiben, was noch geklärt werden muss (maximal 1000 Zeichen).');
  return $this->decide($entry,$hash,$expectedDecision,$key,'withdrawn','[Klärung] '.$reason,true);
 }
 private function decide(string $entry,string $hash,string $expectedDecision,string $key,string $decision,string $reason,bool $clarifying=false):int{
  if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
  $previousRead=$this->currentRead;$this->currentRead=true;
  try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($entry,$hash,$expectedDecision,$key,$decision,$reason,$clarifying):int{
   $a=$this->actor();$office=$this->office($this->actorId);if(!$office)throw new DomainException('Nur ein bestätigtes Vorstandsamt oder der Superuser mit Finanzbearbeitungsrecht darf Rechnungen freigeben oder die Freigabe zurücknehmen.');
   $inputHash=hash('sha256',json_encode([$entry,$hash,$expectedDecision,$decision,$reason],JSON_THROW_ON_ERROR));
   $old=$this->q('SELECT * FROM invoice_approvals WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();
   if($old){if($old['actor_id']!==$a['id']||!hash_equals($old['input_hash'],$inputHash))throw new DomainException('Diese Vorgangskennung wurde für andere Angaben verwendet.');return (int)$old['id'];}
   $locked=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$locked->execute([$a['club_id'],$entry]);if($locked->fetchColumn()===false)throw new DomainException('Beleg nicht verfügbar.',403);
   $current=$this->snapshot($entry);$last=$this->latest($a['club_id'],$entry);
   if(!hash_equals($current['hash'],$hash)||(string)($last['id']??'')!==$expectedDecision)throw new DomainException('Rechnung, Dateien oder Freigabestand wurden geändert. Neu öffnen und erneut prüfen.');
   if(in_array($current['entry']['payment_state'],['paid','cancelled','reversed'],true))throw new DomainException('Bereits bezahlte oder abgeschlossene Ausgaben werden hier nicht zur Zahlung freigegeben.');
   if($decision==='approved'&&!$current['documents_readable'])throw new DomainException('Alle zugeordneten Rechnungsunterlagen müssen für die Prüfung lesbar sein.');
   if($decision==='approved'&&$current['original_count']===0)throw new DomainException('Zuerst einen aktiven Originalbeleg als PDF, XML oder Bild zuordnen. Papierbelege bitte einscannen. Interne Notizen und erzeugte Vorschauen reichen nicht.');
   if($decision==='withdrawn'&&!$clarifying&&(!$last||$last['decision']!=='approved'))throw new DomainException('Keine vorhandene Freigabe zum Zurücknehmen.');
   $this->q('INSERT INTO invoice_approvals(club_id,entry_id,actor_id,office_version,office_evidence,decision,snapshot_hash,snapshot,reason,request_key,input_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$entry,$a['id'],$office['version'],json_encode($office,JSON_THROW_ON_ERROR),$decision,$hash,$current['json'],$reason,$key,$inputHash,gmdate('c')]);$id=(int)$this->db->lastInsertId();
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$clarifying?'invoice.clarified':'invoice.'.$decision,$entry,$current['entry']['version'],gmdate('c')]);
   $claim=$this->q('SELECT * FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=?',[$a['club_id'],$entry])->fetch();
   if($current['issued_credit'])$claim=false;
   if($claim&&$decision==='approved'&&$claim['state']==='open'){
    $now=gmdate('c');$claimUpdate=$this->q('UPDATE finance_reimbursement_claims SET state=\'approved\',approved_by=?,approved_at=?,version=version+1 WHERE club_id=? AND id=? AND version=?',[$a['id'],$now,$a['club_id'],$claim['id'],$claim['version']]);if($claimUpdate->rowCount()!==1)throw new DomainException('Rechnung, Dateien oder Freigabestand wurden geändert. Neu öffnen und erneut prüfen.',409);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.reimbursement.approved',$claim['id'],((int)$claim['version'])+1,$now]);
   }elseif($claim&&$decision!=='approved'&&$claim['state']==='approved'){
    $now=gmdate('c');$claimUpdate=$this->q('UPDATE finance_reimbursement_claims SET state=\'open\',approved_by=NULL,approved_at=NULL,version=version+1 WHERE club_id=? AND id=? AND version=?',[$a['club_id'],$claim['id'],$claim['version']]);if($claimUpdate->rowCount()!==1)throw new DomainException('Rechnung, Dateien oder Freigabestand wurden geändert. Neu öffnen und erneut prüfen.',409);
    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.reimbursement.review_reopened',$claim['id'],((int)$claim['version'])+1,$now]);
   }
   return $id;
  });}finally{$this->currentRead=$previousRead;}
 }
 /** Future payment initiators must call this under their own transaction immediately before submission. */
 public function assertApproved(string $entry,bool $current=false):void{
  $a=$this->actor();if($a['finance_access']!=='write')throw new DomainException('Finanzbearbeitungsrecht erforderlich.');
  if(($current?$this->currentState($entry):$this->state($entry))['status']!=='approved')throw new DomainException('Zuerst die aktuelle Originalrechnung prüfen und freigeben. Dafür ist eine ausdrückliche Freigabe durch ein bestätigtes Vorstandsamt oder den Superuser erforderlich.');
 }
}
