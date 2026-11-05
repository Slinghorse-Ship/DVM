<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/PortalDocumentPages.php';
/** Own issued invoices are authorized by the explicit recipient ID, never shared email. */
final class PortalDocuments
{
 use PortalDocumentPages;
 public function __construct(private PDO $db,private string $actor){}
 /** Current personal authorization and original bytes belong to one read transaction. */
 private function readContext(callable $read):array{
  $owned=!$this->db->inTransaction();if($owned)$this->db->beginTransaction();
  try{$result=$read();if($owned)$this->db->commit();return $result;}
  catch(\Throwable $failure){
   if($owned&&$this->db->inTransaction())$this->db->rollBack();
   if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Belege werden gerade bearbeitet. Bitte erneut öffnen.',503,$failure);
   throw $failure;
  }
 }
 private function currentReadLock():string{return $this->db->inTransaction()?' FOR SHARE NOWAIT':'';}
 public function actor():array{return $this->readContext(fn():array=>$this->identity());}

 private function identity():array{
  $a=Permissions::actor($this->db,$this->actor);
  if($a['role']==='editor'||!$a['contact_id'])throw new DomainException('Diesem Zugang ist kein eigenes Mitgliederprofil zugeordnet.');
  if(!DocumentCatalog::query($this->db,'SELECT 1 FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL'.$this->currentReadLock(),[$a['club_id'],$a['contact_id']])->fetchColumn())throw new DomainException('Mitgliederprofil nicht verfügbar.');return $a;
 }
 /** Personal document view: even board members/helpers see only their own vouchers here. */
 public function vouchers(?WebsiteCalendar $website=null):array{return $this->readContext(fn():array=>$this->voucherRows($website));}
 private function voucherRows(?WebsiteCalendar $website=null,bool $metadata=false):array{
  $a=$this->identity();
  $rows=DocumentCatalog::query($this->db,"SELECT e.*,v.id voucher_id,v.label,v.unit,v.remaining,
   (SELECT COUNT(*) FROM event_voucher_tickets t WHERE t.club_id=v.club_id AND t.voucher_id=v.id AND t.state='active') open_tickets
   FROM event_voucher_events e JOIN event_vouchers v ON v.club_id=e.club_id AND v.event_id=e.id
   WHERE v.club_id=? AND v.contact_id=? AND v.state='active'
   AND (v.unit='eur' OR EXISTS(SELECT 1 FROM event_voucher_tickets t WHERE t.club_id=v.club_id AND t.voucher_id=v.id AND t.state<>'revoked'))
   ORDER BY e.starts_at DESC,e.id,v.label,v.id".$this->currentReadLock(),[$a['club_id'],$a['contact_id']])->fetchAll();
  $events=[];$service=new EventVouchers($this->db,$this->actor,$website);
  foreach($rows as $row){
   $id=$row['id'];
   if(!isset($events[$id])){
    $event=['id'=>$id,'contact_id'=>$a['contact_id'],'title'=>$row['title'],'starts_at'=>$row['starts_at'],'benefits'=>[],'open_cards'=>0,'available'=>true,'notice'=>''];
    try{$source=$service->liveEvent($row,$metadata);$event['title']=$source['title'];$event['starts_at']=$source['starts_at'];}
    catch(DomainException $ex){$event['available']=false;$event['notice']=$ex->getMessage();}
    if($metadata)$event['benefit_rows']=[];$events[$id]=$event;
   }
   $remaining=(int)$row['remaining'];
   if($row['unit']==='piece'&&(int)$row['open_tickets']!==$remaining){$events[$id]['available']=false;$events[$id]['notice']='Gutscheinbestand bitte prüfen lassen.';}
   if($remaining>0){
    $events[$id]['benefits'][]=$row['label'].' · '.EventVouchers::display($remaining,$row['unit']);
    if($metadata)$events[$id]['benefit_rows'][]=['label'=>$row['label'],'remaining'=>$remaining,'unit'=>$row['unit']];
    $events[$id]['open_cards']+=$row['unit']==='piece'?$remaining:1;
   }
  }
  foreach($events as &$event){
   $event['can_download']=$event['available']&&$event['open_cards']>0&&$event['open_cards']<=200;
   if($event['open_cards']>200)$event['notice']='Mehr als 200 Gutscheine. Bitte die Verwaltung um Aufteilung bitten.';
  }unset($event);
  return array_values($events);
 }
 public function shares():array{return $this->readContext(fn():array=>$this->sharedRows());}
 private function sharedRows():array{$a=$this->identity();return DocumentCatalog::query($this->db,"SELECT d.id,d.title,d.document_date,s.revision FROM portal_document_shares s JOIN documents d ON d.club_id=s.club_id AND d.id=s.document_id WHERE s.club_id=? AND s.contact_id=? AND d.state='active' AND d.admission_only=0 ORDER BY d.document_date DESC,d.id".$this->currentReadLock(),[$a['club_id'],$a['contact_id']])->fetchAll();}
 /** Saved grants and their document version form one current, locked view. */
 public function sharingState(string $id):array{
  return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($id):array{
   $a=BoardAccess::require($this->db,$this->actor);$d=(new Documents($this->db,$this->actor))->document($id,true);
   if($d['admission_only'])throw new DomainException('Vertrauliche Aufnahmeunterlagen können hier nicht freigegeben werden.');
   $shares=DocumentCatalog::query($this->db,'SELECT s.contact_id,c.name,c.archived_at,s.revision,s.created_at FROM portal_document_shares s JOIN contacts c ON c.club_id=s.club_id AND c.id=s.contact_id WHERE s.club_id=? AND s.document_id=? ORDER BY c.name,s.contact_id FOR UPDATE',[$a['club_id'],$id])->fetchAll();
   return ['shares'=>$shares,'version'=>(int)$d['version']];
  });
 }
 public function documentShares(string $id):array{return $this->sharingState($id)['shares'];}
 /** Both versions come from the reviewed form. A grant change advances document metadata, never file revision. */
 public function share(string $id,string $contact,bool $enabled,int $version,int $revision):void{
  \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($id,$contact,$enabled,$version,$revision):void{
   $a=BoardAccess::require($this->db,$this->actor);$d=(new Documents($this->db,$this->actor))->document($id,true);
   if($d['admission_only'])throw new DomainException('Vertrauliche Aufnahmeunterlagen können hier nicht freigegeben werden.');
   if($version<1||$revision<1)throw new DomainException('Geprüfte Dateifassung und Freigabestand fehlen. Dokument neu öffnen und prüfen.');
   if((int)$d['version']!==$version||(int)$d['revision']!==$revision)throw new \Kulturhus\Verein\Documents\ShareConflict();
   $person=DocumentCatalog::query($this->db,'SELECT archived_at FROM contacts WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$contact])->fetch();
   if(!$person)throw new DomainException('Person nicht verfügbar.');
   $prior=DocumentCatalog::query($this->db,'SELECT revision FROM portal_document_shares WHERE club_id=? AND document_id=? AND contact_id=? FOR UPDATE',[$a['club_id'],$id,$contact])->fetchColumn();
   if($enabled){
    if($person['archived_at']!==null)throw new DomainException('Archivierte Personen können keine neue Freigabe erhalten. Bestehende Freigaben können zurückgenommen werden.');
    if($d['state']!=='active')throw new DomainException('Archivierte Dokumente zuerst wieder aktivieren. Bestehende Freigaben können zurückgenommen werden.');
    if($prior!==false&&(int)$prior===$revision)return;
    DocumentCatalog::query($this->db,'INSERT INTO portal_document_shares VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),created_by=VALUES(created_by),created_at=VALUES(created_at)',[$a['club_id'],$id,$contact,$revision,$a['id'],gmdate('c')]);
    ManagerNotifications::documentAvailable($this->db,$a['club_id'],$id,$contact,$revision);
   }else{
    if($prior===false)return;
    DocumentCatalog::query($this->db,'DELETE FROM portal_document_shares WHERE club_id=? AND document_id=? AND contact_id=?',[$a['club_id'],$id,$contact]);
   }
   $now=gmdate('c');DocumentCatalog::query($this->db,'UPDATE documents SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$id]);
   DocumentCatalog::query($this->db,"INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,'sharing',?,?,?)",[$a['club_id'],$id,$a['id'],$version+1,$now,json_encode(['contact_id'=>$contact,'enabled'=>$enabled,'revision'=>$revision,'previous_revision'=>$prior===false?null:(int)$prior],JSON_THROW_ON_ERROR)]);
   DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$enabled?'portal.document.shared':'portal.document.revoked',$id,$version+1,$now]);
  });
 }
 public function listing():array{return $this->readContext(fn():array=>$this->invoiceRows());}
 private function invoiceRows():array{
  $a=$this->identity();$rows=DocumentCatalog::query($this->db,"SELECT e.id AS funding_entry,d.id,d.title,d.document_date,i.year,e.number,e.amount_cents,e.status,CASE WHEN e.status='cancelled' THEN 'cancelled' WHEN EXISTS(SELECT 1 FROM finance_reversals r WHERE r.club_id=e.club_id AND r.entry_id=e.id) THEN 'reversed' WHEN ".PaymentFunding::paidSql($this->db,'e').">=e.amount_cents THEN 'paid' WHEN ".PaymentFunding::paidSql($this->db,'e').">0 THEN 'partial' ELSE 'open' END AS payment_state FROM contribution_invoices i JOIN documents d ON d.club_id=i.club_id AND d.id=i.document_id JOIN finance_entries e ON e.club_id=i.club_id AND e.id=i.entry_id WHERE i.club_id=? AND i.recipient_contact_id=? AND i.state='issued' AND d.state='active' AND d.admission_only=0 ORDER BY i.year DESC,d.document_date DESC,d.id".$this->currentReadLock(),[$a['club_id'],$a['contact_id']])->fetchAll();
  foreach($rows as&$row){
   if(!in_array($row['payment_state'],['cancelled','reversed'],true)){$paid=PaymentFunding::paid($this->db,$a['club_id'],$row['funding_entry'],(int)$row['amount_cents'],true);$row['payment_state']=$paid>=(int)$row['amount_cents']?'paid':($paid>0?'partial':'open');}
   unset($row['funding_entry']);
  }unset($row);return $rows;
 }
 public function file(string $id):array{return $this->readContext(fn():array=>$this->originalFile($id));}
 private function originalFile(string $id):array{
  $a=$this->identity();$ok=DocumentCatalog::query($this->db,"SELECT 1 FROM contribution_invoices i JOIN documents d ON d.club_id=i.club_id AND d.id=i.document_id WHERE i.club_id=? AND i.recipient_contact_id=? AND i.document_id=? AND i.state='issued' AND d.state='active' AND d.admission_only=0".$this->currentReadLock(),[$a['club_id'],$a['contact_id'],$id])->fetchColumn();
  $revision=$ok?DocumentCatalog::query($this->db,'SELECT revision FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1'.$this->currentReadLock(),[$a['club_id'],$id])->fetchColumn():1;
  if(!$ok){$revision=DocumentCatalog::query($this->db,"SELECT s.revision FROM portal_document_shares s JOIN documents d ON d.club_id=s.club_id AND d.id=s.document_id WHERE s.club_id=? AND s.contact_id=? AND s.document_id=? AND d.state='active' AND d.admission_only=0".$this->currentReadLock(),[$a['club_id'],$a['contact_id'],$id])->fetchColumn();if(!$revision)throw new DomainException('Beleg nicht verfügbar.');}
  $f=DocumentCatalog::query($this->db,'SELECT name,mime,bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=?'.$this->currentReadLock(),[$a['club_id'],$id,$revision])->fetch();
  if(!$f||!is_string($f['bytes'])||!hash_equals($f['sha256'],hash('sha256',$f['bytes'])))throw new DomainException('Beleg nicht verfügbar.');unset($f['sha256']);return $f;
 }
}
