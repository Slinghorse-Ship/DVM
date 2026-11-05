<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;use ZipArchive;
require_once dirname(__DIR__, 2).'/Finance/Invoices/InvoicePositionReview.php';
/** A consistent, permission-checked snapshot; original document bytes are never rewritten. */
final class FinanceBundle
{
 public function __construct(private PDO $db,private string $actor){}
 private function provenance(string $club,string $document,array &$files,int &$total,array &$missing):array
 {
  $imports=DocumentCatalog::query($this->db,'SELECT content_hash,sender,subject,received_at,imported_at,message_uid,mailbox_key,mailbox_label,message_id,original_date FROM invoice_mail_imports WHERE club_id=? AND document_id=? ORDER BY imported_at,content_hash',[$club,$document])->fetchAll();
  $originals=DocumentCatalog::query($this->db,'SELECT l.mailbox_key,l.message_uid,o.sha256,o.bytes,o.created_at FROM invoice_mail_original_links l LEFT JOIN invoice_mail_originals o ON o.club_id=l.club_id AND o.mailbox_key=l.mailbox_key AND o.message_uid=l.message_uid WHERE l.club_id=? AND l.document_id=? ORDER BY l.mailbox_key,l.message_uid',[$club,$document])->fetchAll();
  $messages=[];
  if($imports&&!$originals){$missing[$document]=['document_id'=>$document,'reason'=>'Keine zugehörige Originalnachricht vorhanden.'];}
  foreach($originals as $original){$meta=array_intersect_key($original,array_flip(['mailbox_key','message_uid','sha256','created_at']));
   $mailReadable=true;$guard=new Documents($this->db,$this->actor);$related=DocumentCatalog::query($this->db,'SELECT document_id FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=?',[$club,$original['mailbox_key'],$original['message_uid']])->fetchAll(PDO::FETCH_COLUMN);foreach($related as $relatedId){try{$guard->document($relatedId);}catch(DomainException){$mailReadable=false;}}
   if(!$mailReadable){$meta+=['state'=>'unavailable','reason'=>'Die Originalnachricht enthält weitere zugeordnete Unterlagen ohne ausreichende Leseberechtigung.'];$missing[$original['mailbox_key'].':'.$original['message_uid']]=['document_id'=>$document]+$meta;}
   elseif(!is_string($original['bytes'])||!is_string($original['sha256'])||!hash_equals($original['sha256'],hash('sha256',$original['bytes']))){$meta+=['state'=>'unavailable','reason'=>'Originalnachricht fehlt oder Prüfsumme stimmt nicht.'];$missing[$original['mailbox_key'].':'.$original['message_uid']]=['document_id'=>$document]+$meta;}
   else{$path='emails/'.$original['sha256'].'.eml';if(!isset($files[$path])){$total+=strlen($original['bytes']);if($total>52428800)throw new \LengthException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$path]=$original['bytes'];}$meta+=['state'=>'included','path'=>$path];}
   $messages[]=$meta;
  }
  $validations=DocumentCatalog::query($this->db,'SELECT * FROM einvoice_validations WHERE club_id=? AND document_id=? ORDER BY id',[$club,$document])->fetchAll();
  foreach($validations as &$validation){$report=$validation['report'];unset($validation['report']);$reportHash=hash('sha256',$report);$reportPath='pruefberichte/'.$reportHash.'.xml';if(!isset($files[$reportPath])){$total+=strlen($report);if($total>52428800)throw new \LengthException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$reportPath]=$report;}$validation['report_path']=$reportPath;$validation['report_sha256']=$reportHash;
   $original=(new Documents($this->db,$this->actor))->file($document,(int)$validation['revision']);if(!hash_equals($validation['sha256'],hash('sha256',$original['bytes'])))throw new DomainException('Original zur E-Rechnungsprüfung stimmt nicht mit der gespeicherten Prüfsumme überein.');$originalPath='prueforiginale/'.$validation['sha256'].(($original['mime']??'')==='application/pdf'?'.pdf':'.xml');if(!isset($files[$originalPath])){$total+=strlen($original['bytes']);if($total>52428800)throw new \LengthException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$originalPath]=$original['bytes'];}$validation['original_path']=$originalPath;
  }
  unset($validation);
  return ['imports'=>$imports,'original_messages'=>$messages,'einvoice_validations'=>$validations];
 }
 public function build(array $filters):array
 {
  if($this->db->inTransaction())throw new DomainException('Belegpaket erst nach dem laufenden Speichervorgang erstellen. Die vorhandene Arbeit bleibt erhalten.');
  $this->db->exec('BEGIN');try{
   $finance=new Finance($this->db,$this->actor);$entries=$finance->entries($filters);if(count($entries)>250)throw new DomainException('Belegpaket auf höchstens 250 Belege eingrenzen.');
   $a=Permissions::actor($this->db,$this->actor);$documents=new Documents($this->db,$this->actor);$files=[];$rows=[];$missing=[];$blocked=[];$historicalMissing=[];$mailMissing=[];$total=0;
   foreach($entries as $entry){$record=['id'=>$entry['id'],'number'=>$entry['display_number'],'description'=>$entry['description'],'date'=>$entry['document_date'],'amount_cents'=>(int)$entry['amount_cents'],'payment_state'=>$entry['payment_state'],'allocated_cents'=>(int)$entry['allocated_cents'],'positions'=>$finance->positions('entry',$entry['id']),'documents'=>[],'inventory_positions'=>InvoicePositionReview::forEntry($this->db,$this->actor,$entry['id']),'position_details'=>InvoicePositionReview::details($this->db,$this->actor,$entry['id']),'event_assignment'=>(new InvoiceInbox($this->db,$this->actor))->eventAssignment($entry['id'])];
    $ids=DocumentCatalog::query($this->db,'SELECT document_id FROM document_links WHERE club_id=? AND source_type=\'entry\' AND source_id=?',[$a['club_id'],$entry['id']])->fetchAll(PDO::FETCH_COLUMN);
    foreach($ids as $id){try{$doc=$documents->document($id);if($doc['state']!=='active')continue;$file=$documents->file($id,(int)$doc['revision']);}catch(DomainException){$blocked[$entry['id']]=$entry['display_number'];continue;}
     $extension=match($file['mime']){'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/heic'=>'heic','image/heif'=>'heif','text/csv'=>'csv','application/xml'=>'xml',default=>'bin'};$path='belege/'.$id.'-v'.$doc['revision'].'.'.$extension;
     if(!isset($files[$path])){$total+=strlen($file['bytes']);if($total>52428800)throw new DomainException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$path]=$file['bytes'];}
     $record['documents'][]=['path'=>$path,'title'=>$doc['title'],'original_name'=>$file['name'],'revision'=>(int)$doc['revision'],'sha256'=>hash('sha256',$file['bytes']),'mail_origin'=>$this->provenance($a['club_id'],$id,$files,$total,$mailMissing)];
    }
    if($entry['kind']==='expense'){
     $approvals=new InvoiceApprovals($this->db,$this->actor);$approvalState=$approvals->state($entry['id']);$record['approval_status']=$approvalState['status'];$record['approval_history']=$approvals->history($entry['id']);$record['approval_documents']=[];
     foreach($record['approval_history'] as $decision){$snapshot=json_decode($decision['snapshot'],true,512,JSON_THROW_ON_ERROR);
      foreach($snapshot['documents'] as $reference){$refKey=$reference['id'].':'.$reference['revision'];if(isset($record['approval_documents'][$refKey]))continue;
       $meta=['document_id'=>$reference['id'],'revision'=>(int)$reference['revision'],'expected_sha256'=>$reference['sha256']];
       try{$historical=$documents->file($reference['id'],(int)$reference['revision']);if(!hash_equals($reference['sha256'],hash('sha256',$historical['bytes'])))throw new DomainException('Historische Dateiprüfsumme stimmt nicht überein.');
        $ext=match($historical['mime']){'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/heic'=>'heic','image/heif'=>'heif','text/csv'=>'csv','application/xml'=>'xml',default=>'bin'};$path='belege/'.$reference['id'].'-v'.$reference['revision'].'.'.$ext;
        if(!isset($files[$path])){$total+=strlen($historical['bytes']);if($total>52428800)throw new \LengthException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$path]=$historical['bytes'];}
        $meta+=['state'=>'included','path'=>$path,'original_name'=>$historical['name'],'sha256'=>hash('sha256',$historical['bytes']),'mail_origin'=>$this->provenance($a['club_id'],$reference['id'],$files,$total,$mailMissing)];
       }catch(DomainException){$meta+=['state'=>'unavailable','reason'=>'Dateifassung fehlt, ist nicht freigegeben oder ihre Prüfsumme stimmt nicht mehr.'];$historicalMissing[]=['entry_id'=>$entry['id'],'number'=>$entry['display_number']]+$meta;}
       $record['approval_documents'][$refKey]=$meta;
      }
     }
     $record['approval_documents']=array_values($record['approval_documents']);
    }
    if($entry['kind']==='expense')$record['payment_report']=['meaning'=>'Eigene Zahlungsmeldung, kein Bankbeleg und keine Kontobuchung']+(new InvoicePaymentReports($this->db,$this->actor))->summary($entry['id']);
    $record['excess_returns']=[];$excessPage=1;
    do{$excess=$finance->excessReturnsForEntry($entry['id'],$excessPage);foreach($excess['rows'] as $return){$proof=['meaning'=>'Bezug einer tatsächlich erfolgten Überschussrückzahlung; ursprüngliche Forderung und Kontoumsätze bleiben erhalten','original'=>$return];$proofBytes=json_encode($proof,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);$proofPath='rueckzahlungen/'.$return['id'].'.json';if(!isset($files[$proofPath])){$total+=strlen($proofBytes);if($total>52428800)throw new DomainException('Belegpaket über 50 MB. Zeitraum eingrenzen.');$files[$proofPath]=$proofBytes;}$record['excess_returns'][]=['id'=>$return['id'],'cents'=>(int)$return['cents'],'source_id'=>$return['source_id'],'returned_id'=>$return['returned_id'],'state'=>$return['voided_at']===null?'active':'released','path'=>$proofPath,'sha256'=>hash('sha256',$proofBytes)];}++$excessPage;}while($excessPage<=$excess['pages']);
    if(!$record['documents'])$missing[]=$entry['display_number'];$rows[]=$record;
   }
   $csv=$finance->export($filters);$manifest=['version'=>2,'created_at'=>gmdate('c'),'filters'=>$filters,'entries'=>$rows,'without_accessible_file'=>$missing,'restricted_files_on_entries'=>array_values($blocked),'unavailable_approval_files'=>$historicalMissing,'unavailable_original_messages'=>array_values($mailMissing)];
   $files['finanzliste.csv']=$csv;$files['zuordnung.json']=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
   $procedurePath=dirname(__DIR__, 2).'/../resources/invoice-procedure.md';$procedure=is_readable($procedurePath)?file_get_contents($procedurePath):false;if($procedure===false||trim($procedure)==='')throw new DomainException('Verfahrensbeschreibung für das Prüfpaket fehlt. Bitte den Betrieb prüfen.');$files['VERFAHREN.md']=$procedure;
   $files['LIESMICH.txt']="Kulturhus Belegpaket\nFinanzliste und Originaldateien des gewählten Belegzeitraums.\nZuordnung, Dateiversion und SHA-256 stehen in zuordnung.json.\nEnthalten sind aktuelle aktive Dateien sowie zugängliche historische Dateifassungen aus den Freigabeentscheidungen.\nFreigabeverlauf, ursprüngliche Prüfstände, Amtsnachweis und gegebenenfalls Mailherkunft stehen im Manifest.\nBelege ohne aktuelle zugängliche Datei: ".count($missing)."\nBelege mit weiteren geschützten Dateien: ".count($blocked)."\nNicht verfügbare historische Freigabedateien: ".count($historicalMissing)."\nBei fehlenden Dateien ist das Prüfpaket unvollständig; Berechtigungen und Ablage prüfen.\nKein DATEV-Importformat, keine zugesicherte GoBD-Konformität und kein vollständiges Systembackup.\n";
   $files['LIESMICH.txt'].="Zahlungsmeldungen stehen separat unter payment_report: eigene Meldung, kein Bankbeleg. Der tatsächliche Zahlungsstatus wird ausschließlich aus Kontozuordnungen berechnet.\n";
   $files['LIESMICH.txt'].="Tatsächliche Überschussrückzahlungen stehen mit ursprünglicher Prüfung und Korrekturverlauf unter excess_returns und rueckzahlungen/. Sie ändern die ursprüngliche Forderung nicht. Die historischen Prüfstände ersetzen keinen aktuellen Bankabruf.\n";
   $files['LIESMICH.txt'].="Nicht verfügbare Originalnachrichten: ".count($mailMissing)."\nOriginalmails liegen als unveränderte .eml-Dateien unter emails/. Auch weitere Mails mit identischem Rechnungsanhang werden getrennt nach Nachricht dokumentiert.\n";
   $path=tempnam(sys_get_temp_dir(),'kh-bundle-');if($path===false)throw new DomainException('Exportdatei nicht verfügbar.');chmod($path,0600);$zip=new ZipArchive();try{if($zip->open($path,ZipArchive::OVERWRITE)!==true)throw new DomainException('ZIP konnte nicht erstellt werden.');foreach($files as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new DomainException('ZIP-Datei konnte nicht hinzugefügt werden.');if(!$zip->close())throw new DomainException('ZIP konnte nicht abgeschlossen werden.');$bytes=(string)file_get_contents($path);}finally{if(is_file($path))unlink($path);}
   $this->db->commit();return ['bytes'=>$bytes,'count'=>count($entries),'missing'=>count($missing),'restricted'=>count($blocked),'historical_missing'=>count($historicalMissing),'mail_missing'=>count($mailMissing)];
  }catch(\Throwable $e){$this->db->rollBack();if($e instanceof \LengthException)throw new DomainException($e->getMessage());throw $e;}
 }
}
