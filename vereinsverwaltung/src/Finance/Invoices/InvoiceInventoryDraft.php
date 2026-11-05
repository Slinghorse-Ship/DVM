<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/InvoiceAcquisitionLinks.php';
use PDO;use DomainException;
/** A saved, reviewed invoice position opens an editable inventory draft, never a booking. */
final class InvoiceInventoryDraft
{
 public static function position(PDO $db,string $actor,string $entry,int $ordinal):array
 {
  $finance=new Finance($db,$actor);$inventory=new Inventory($db,$actor);
  if(!$finance->canWrite()||!$inventory->canWrite())throw new DomainException('Finanz- und Inventar-Schreibrecht erforderlich.');
  $e=$finance->entry($entry);if($e['kind']!=='expense'||in_array($e['payment_state'],['cancelled','reversed'],true))throw new DomainException('Eine gültige Ausgabenrechnung auswählen.');
  $q=$db->prepare('SELECT p.description,p.gross_cents,d.details_json FROM accounting_positions p JOIN invoice_position_details d ON d.club_id=p.club_id AND d.entry_id=p.entry_id AND d.ordinal=p.ordinal WHERE p.club_id=? AND p.entry_id=? AND p.ordinal=?');$q->execute([$e['club_id'],$entry,$ordinal]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)throw new DomainException('Zuerst die Rechnungspositionen prüfen und zusammen mit der Rechnung speichern.');
  $detail=json_decode((string)$row['details_json'],true,64);if(!is_array($detail))throw new DomainException('Die gespeicherten Positionsdetails sind unvollständig. Bitte die Position am Original erneut prüfen und speichern.');if(empty($detail['reviewed'])||(int)$detail['gross_cents']!==(int)$row['gross_cents'])throw new DomainException('Die Rechnungsposition wurde geändert. Bitte erst die Positionsangaben erneut prüfen.');
  $documents=new Documents($db,$actor);$documents->source('entry',$entry);$docs=[];
  $q=$db->prepare("SELECT document_id FROM document_links WHERE club_id=? AND source_type='entry' AND source_id=?");$q->execute([$e['club_id'],$entry]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $documentId){try{$doc=$documents->document($documentId);if($doc['state']==='active'&&in_array($doc['kind'],['receipt','invoice'],true))$docs[]=$doc;}catch(DomainException){}}
  if(!$docs)throw new DomainException('Der Originalbeleg ist nicht verfügbar. Bitte zuerst im Rechnungseingang zuordnen.');
  $key=substr(hash('sha256','inventory-invoice-position|'.$e['club_id'].'|'.$entry.'|'.$ordinal),0,32);
  $q=$db->prepare('SELECT object_id FROM inventory_events WHERE club_id=? AND request_key=? AND action=?');$q->execute([$e['club_id'],$key,'item']);$existing=(string)($q->fetchColumn()?:'');
  $integrated=[];foreach($docs as $doc)foreach(InvoiceAcquisitionLinks::rows($db,$actor,$doc['id']) as $source){if((int)$source['source_ordinal']!==$ordinal)continue;if((int)$source['original_gross_cents']!==(int)$row['gross_cents'])throw new DomainException('Originalposition und vorhandene Inventarzuordnung widersprechen sich.');$item=$inventory->item($source['item_id']);if(!$item['active'])throw new DomainException('Der bereits zugeordnete Gegenstand ist archiviert. Keine doppelte Neuanlage.');$integrated[$item['id']]=true;}
  if(count($integrated)>1)throw new DomainException('Diese Paketposition ist bereits mehreren Gegenständen zugeordnet. Vorhandene Gegenstände im Rechnungseingang öffnen; keine neue Anlage.');
  if($integrated){$linked=(string)array_key_first($integrated);if($existing!==''&&$existing!==$linked)throw new DomainException('Widersprüchliche bestehende Inventarzuordnungen.');$existing=$linked;}
  $quantity=preg_match('/^\d+(?:\.0+)?$/D',(string)$detail['quantity'])?(int)$detail['quantity']:null;if($quantity!==null&&($quantity<1||$quantity>1000000))$quantity=null;
  $unit=null;if($detail['unit_price_cents']!==null){$unit=$detail['price_basis']==='gross'?(int)$detail['unit_price_cents']:(int)round($detail['unit_price_cents']*(100+$detail['tax_rate'])/100);if($quantity===null||$unit*$quantity!==(int)$row['gross_cents'])$unit=null;}
  $note='Rechnung '.($e['display_number']?:$e['number']).', Position '.$ordinal.': '.$detail['quantity'].' '.$detail['unit'].'; Positionsbetrag brutto '.number_format((int)$row['gross_cents']/100,2,',','').' EUR. '.($unit===null?'Kein eindeutiger Einzelwert übernommen.':'Einzelwerte am Original geprüft.');
  $name=preg_replace('/^Pos\.\s+\d+\s+·\s*/u','',$row['description'])??$row['description'];
  $defaults=['name'=>mb_strcut($name,0,160,'UTF-8'),'initial_quantity'=>$quantity===null?'':(string)$quantity,'unit'=>match(mb_strtolower($detail['unit'])){'stk','stk.','stück'=>'Stück','satz','sätze'=>'Satz',default=>$detail['unit']?:'Stück'},'acquired_on'=>$e['document_date'],'unit_value'=>$unit===null?'':number_format($unit/100,2,'.',''),'value_note'=>$note,'source'=>'expense:'.$entry,'invoice_position'=>(string)$ordinal,'invoice_entry_version'=>(string)$e['version']];
  return ['entry'=>$e,'ordinal'=>$ordinal,'description'=>$row['description'],'details'=>$detail,'defaults'=>$defaults,'documents'=>$docs,'key'=>$key,'existing'=>$existing,'integrated_original'=>(bool)$integrated,'exact_unit_value'=>$unit!==null];
 }
 public static function validate(PDO $db,string $actor,array $data):array
 {
  if(($data['source_kind']??'')!=='expense'||!is_string($data['source_id']??null)||!is_string($data['invoice_position']??null)||!preg_match('/^[1-9]\d?$/D',$data['invoice_position']))throw new DomainException('Rechnungsposition für den Inventarentwurf prüfen.');
  $draft=self::position($db,$actor,$data['source_id'],(int)$data['invoice_position']);
  if($draft['integrated_original'])throw new DomainException('Dieser Gegenstand wurde bereits aus dem Original übernommen. Vorhandenen Gegenstand öffnen; Bestand nicht erneut anlegen.');
  if((string)($data['invoice_entry_version']??'')!==(string)$draft['entry']['version'])throw new DomainException('Die Rechnung wurde inzwischen geändert. Bitte den Inventarentwurf erneut öffnen.');
  return $draft;
 }
 public static function attach(PDO $db,string $actor,string $item,array $draft):void
 {
  if(!$db->inTransaction())throw new DomainException('Positionsverknüpfung muss zusammen mit dem Gegenstand gespeichert werden.');
  $current=self::position($db,$actor,$draft['entry']['id'],(int)$draft['ordinal']);$record=(new Inventory($db,$actor))->item($item);
  if((int)$current['entry']['version']!==(int)$draft['entry']['version']||$record['source_entry']!==$current['entry']['id'])throw new DomainException('Gegenstand und Rechnungsposition passen nicht zusammen.');
  $db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute([$draft['entry']['club_id'],$draft['entry']['id'],$draft['ordinal'],$item,1,$draft['description'],$actor,gmdate('c')]);
 }
}
