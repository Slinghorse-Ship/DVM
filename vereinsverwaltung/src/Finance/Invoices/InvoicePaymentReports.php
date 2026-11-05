<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Human payment report only. Never a transaction or donation-payment evidence. */
final class InvoicePaymentReports
{
 public function __construct(private PDO $db,private string $actor){}
 private function entry(string $id,bool $write=false):array{$f=new Finance($this->db,$this->actor);if($write&&!$f->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');$r=$f->entry($id);if($r['kind']!=='expense')throw new DomainException('Zahlungsmeldungen sind nur für Ausgabenrechnungen möglich.');return $r;}
 public function assertDocument(string $doc,string $entry):void{$this->entry($entry);$d=(new InvoiceInbox($this->db,$this->actor))->document($doc);if(!in_array($entry,array_column($d['entry_links'],'source_id'),true))throw new DomainException('Originalbeleg und Rechnung passen nicht zusammen.');}
 public function summary(string $entry,bool $current=false):array
 {
  if($current&&!$this->db->inTransaction())throw new DomainException('Aktuelle Zahlungsmeldung benötigt einen offenen Speichervorgang.');$r=$current?(new Finance($this->db,$this->actor))->currentEntry($entry):$this->entry($entry);if($r['kind']!=='expense')throw new DomainException('Zahlungsmeldungen sind nur für Ausgabenrechnungen möglich.');$q=$this->db->prepare('SELECT * FROM invoice_payment_reports WHERE club_id=? AND entry_id=? ORDER BY version DESC'.($current?' FOR SHARE NOWAIT':''));$q->execute([$r['club_id'],$entry]);$history=$q->fetchAll(PDO::FETCH_ASSOC);$last=$history[0]??null;
  $state=!$last?'none':($last['operation']==='withdrawn'?'withdrawn':(in_array($r['payment_state'],['cancelled','reversed'],true)?'inactive':($r['remaining_cents']===0?'resolved':'reported')));
  return ['state'=>$state,'version'=>(int)($last['version']??0),'latest'=>$last,'history'=>$history,'remaining_cents'=>$r['remaining_cents']];
 }
 public function save(string $entry,int $entryVersion,int $reportVersion,string $date,string $note,string $key):int{return $this->change($entry,$entryVersion,$reportVersion,'reported',$date,$note,$key);}
 public function withdraw(string $entry,int $entryVersion,int $reportVersion,string $reason,string $key):int{return $this->change($entry,$entryVersion,$reportVersion,'withdrawn','',$reason,$key);}
 private function change(string $entry,int $entryVersion,int $reportVersion,string $operation,string $date,string $note,string $key):int
 {
  $this->entry($entry,true);$note=trim($note);if(!preg_match('/^[a-f0-9]{32}$/D',$key)||$note===''||!mb_check_encoding($note,'UTF-8')||mb_strlen($note)>1000)throw new DomainException('Datum und kurze Notiz zur Zahlungsmeldung prüfen.');
  if($operation==='reported'){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new DomainException('Tatsächliches Zahlungsdatum bis heute eingeben.');}
  $hash=hash('sha256',json_encode([$this->actor,$entry,$entryVersion,$reportVersion,$operation,$date,$note],JSON_THROW_ON_ERROR));$this->db->beginTransaction();
  try{$r=$this->entry($entry,true);$q=$this->db->prepare('SELECT fingerprint,version FROM invoice_payment_reports WHERE club_id=? AND request_key=?');$q->execute([$r['club_id'],$key]);if($old=$q->fetch()){if(!hash_equals($old['fingerprint'],$hash))throw new DomainException('Dieses Formular wurde bereits mit anderen Angaben gespeichert.');$this->db->commit();return (int)$old['version'];}
   $current=$this->summary($entry);if((int)$r['version']!==$entryVersion||$current['version']!==$reportVersion)throw new DomainException('Rechnung oder Zahlungsmeldung wurde geändert. Bitte neu laden.');
   if($operation==='reported'&&($r['status']!=='open'||$r['remaining_cents']<=0||in_array($r['payment_state'],['cancelled','reversed'],true)))throw new DomainException('Nur offene, noch nicht vollständig zugeordnete Rechnungen als bezahlt melden.');
   if($operation==='withdrawn'&&($current['latest']['operation']??'')!=='reported')throw new DomainException('Es gibt keine aktive Zahlungsmeldung.');
   $version=$reportVersion+1;$now=gmdate('c');$this->db->prepare('INSERT INTO invoice_payment_reports VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$r['club_id'],$entry,$version,$operation,$date,$note,$this->actor,$now,$entryVersion,$key,$hash]);
   $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$r['club_id'],$this->actor,'invoice.payment.'.$operation,$entry,$version,$now]);$this->db->commit();return $version;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
