<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
/** Reviewed inputs use existing accounting positions. No automatic rounding adjustment. */
final class InvoicePositionReview
{
 public static function form(array $data):?array
 {
  if(($data['use_invoice_positions']??'')!=='1')return null;
  if(($data['positions_reviewed']??'')!=='1')throw new DomainException('Bitte alle Positionen und die Summe am Original prüfen und bestätigen.');
  $rows=$data['invoice_positions']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>50)throw new DomainException('Bitte 1 bis 50 Rechnungspositionen prüfen.');
  $out=[];$links=[];$details=[];$sum=0;$taxSum=0;$linkCount=0;
  foreach($rows as $i=>$row){if(!is_array($row))throw new DomainException('Rechnungsposition prüfen.');
   foreach(['description','amount','tax_rate'] as $field)if(!is_string($row[$field]??null))throw new DomainException('Positionsfelder prüfen.');
   $description=trim($row['description']);if($description===''||mb_strlen($description)>300||preg_match('/[\x00-\x1f]/',$description))throw new DomainException('Positionsbeschreibung prüfen.');
   $amount=Finance::cents($row['amount']);if($amount<=0||!in_array($row['tax_rate'],['0','7','19'],true))throw new DomainException('Positive Positionsbeträge und unterstützten Steuersatz prüfen. Rabatte oder Sonderfälle zuerst fachlich klären.');
   foreach(['sphere','account','booking_key','input_tax'] as $key)if(isset($row[$key])&&!is_string($row[$key]))throw new DomainException('Kontierung prüfen.');
   $position=['description'=>$description,'amount'=>$row['amount'],'tax_rate'=>$row['tax_rate'],'sphere'=>$row['sphere']??'','account'=>$row['account']??'','booking_key'=>$row['booking_key']??''];if(array_key_exists('input_tax',$row))$position['input_tax']=InputTaxTreatment::validate($row['input_tax'],'expense',$position['booking_key']);$out[]=$position;$sum+=$amount;$rate=(int)$row['tax_rate'];$taxSum+=$rate===0?0:intdiv($amount*$rate+intdiv(100+$rate,2),100+$rate);
   foreach(['quantity','unit','unit_price','price_basis','calculation_note','source_hint'] as $detailKey)if(isset($row[$detailKey])&&!is_string($row[$detailKey]))throw new DomainException('Positionsdetails prüfen.');
   $quantity=(string)($row['quantity']??'1');$unit=(string)($row['unit']??'');$unitPrice=(string)($row['unit_price']??'');$basis=(string)($row['price_basis']??'gross');$reason=trim((string)($row['calculation_note']??''));$hint=(string)($row['source_hint']??'');
   if(!preg_match('/^\d{1,6}(?:[.,]\d{1,3})?$/D',$quantity)||(float)str_replace(',','.',$quantity)<=0||!in_array($basis,['gross','net'],true)||mb_strlen($unit)>30||mb_strlen($reason)>300||mb_strlen($hint)>1000||preg_match('/[\x00-\x1f]/',$unit.$reason))throw new DomainException('Menge, Einheit oder Positionshinweise prüfen.');
   $unitCents=$unitPrice===''?null:Finance::cents($unitPrice);if($unitCents!==null&&$unitCents<0)throw new DomainException('Einzelpreis prüfen.');
   if($unitCents!==null){$expected=(int)round((float)str_replace(',','.',$quantity)*$unitCents*($basis==='net'?(100+$rate)/100:1));if($expected!==$amount&&$reason==='')throw new DomainException('Menge × Einzelpreis passt nicht zum Positionsbetrag. Am Original prüfen und eine dort belegte Rundung oder Abweichung ausdrücklich erläutern.');}
   $details[$i+1]=['quantity'=>str_replace(',','.',$quantity),'unit'=>$unit,'unit_price_cents'=>$unitCents,'price_basis'=>$basis,'gross_cents'=>$amount,'net_cents'=>$amount-($rate===0?0:intdiv($amount*$rate+intdiv(100+$rate,2),100+$rate)),'tax_rate'=>$rate,'calculation_note'=>$reason,'source_hint'=>$hint,'reviewed'=>true];
   $refs=$row['items']??[];if(!is_array($refs)||count($refs)>50)throw new DomainException('Gegenstandszuordnung je Position prüfen.');
   foreach($refs as $ref){if(!is_string($ref)||!preg_match('/^([a-f0-9]{32})\|(\d+)$/D',$ref,$m))throw new DomainException('Gegenstandszuordnung prüfen.');if(isset($links[$i+1][$m[1]]))throw new DomainException('Position '.($i+1).': Ein Gegenstand ist in dieser Position mehrfach zugeordnet. Entferne die doppelte oder widersprüchliche Zuordnung ausdrücklich; es wird keine Version automatisch übernommen.',422);$links[$i+1][$m[1]]=(int)$m[2];if(++$linkCount>100)throw new DomainException('Bitte höchstens 100 Positionszuordnungen erfassen.');}
  }
  if(!is_string($data['amount']??null))throw new DomainException('Rechnungsbetrag prüfen.');
  if($sum!==Finance::cents($data['amount']))throw new DomainException('Die Summe der Positions-Bruttobeträge muss genau dem Rechnungs-Gesamtbetrag entsprechen. Steuern, Rabatte und Rundungsdifferenzen am Original prüfen; es wird nichts automatisch ergänzt.');
  $rounding=null;$expectedTax=$data['positions_tax_total']??'';if(!is_string($expectedTax))throw new DomainException('Umsatzsteuer-Gesamtsumme prüfen.');
  if(trim($expectedTax)!==''&&Finance::cents($expectedTax)!==$taxSum){
   $taxRows=[];foreach($details as $ordinal=>$detail)$taxRows[]=['gross_cents'=>$detail['gross_cents'],'tax_rate'=>$detail['tax_rate'],'tax_cents'=>$detail['gross_cents']-$detail['net_cents']];
   $allocated=Finance::roundInvoiceTax($taxRows,Finance::cents($expectedTax),($data['positions_rounding_reviewed']??'')==='1');
   $rounding=['total_cents'=>Finance::cents($expectedTax),'confirmed'=>true];foreach($allocated as $i=>$row){$details[$i+1]['calculated_tax_cents']=$taxRows[$i]['tax_cents'];$details[$i+1]['allocated_tax_cents']=$row['tax_cents'];$details[$i+1]['tax_rounding_delta']=$row['tax_cents']-$taxRows[$i]['tax_cents'];$details[$i+1]['invoice_tax_total_cents']=$rounding['total_cents'];$details[$i+1]['net_cents']=$row['gross_cents']-$row['tax_cents'];}
  }
  return ['positions'=>$out,'links'=>$links,'details'=>$details,'tax_rounding'=>$rounding];
 }
 public static function forEntry(PDO $db,string $actor,string $entry):array
 {
  $e=(new Finance($db,$actor))->entry($entry);$q=$db->prepare('SELECT ordinal,item_id,description FROM invoice_position_items WHERE club_id=? AND entry_id=? ORDER BY ordinal,item_id');$q->execute([$e['club_id'],$entry]);$rows=[];$inventory=new Inventory($db,$actor);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){try{$item=$inventory->item($row['item_id']);$row['label']=$item['code'].' · '.$item['name'];$row['can_open']=true;}catch(DomainException){$row['label']='Gegenstand – kein aktueller Zugriff';$row['can_open']=false;}$rows[]=$row;}return $rows;
 }
 public static function details(PDO $db,string $actor,string $entry,bool $current=false):array
 {
  if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Positionsdetails benötigen einen offenen Speichervorgang.');$finance=new Finance($db,$actor);$e=$current?$finance->currentEntry($entry):$finance->entry($entry);$q=$db->prepare('SELECT ordinal,details_json,actor_id,created_at FROM invoice_position_details WHERE club_id=? AND entry_id=? ORDER BY ordinal'.($current?' FOR SHARE NOWAIT':''));$q->execute([$e['club_id'],$entry]);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$details=json_decode((string)$r['details_json'],true,64);if(!is_array($details))continue;$out[]=['ordinal'=>(int)$r['ordinal'],'details'=>$details,'actor_id'=>$r['actor_id'],'created_at'=>$r['created_at']];}return $out;
 }
 /** Captured invoice labels precede later accounting edits. Never substitute a later label. */
 public static function originalDescriptions(PDO $db,string $actor,string $entry):array
 {
  $own=!$db->inTransaction();try{
   if($own)$db->beginTransaction();$e=(new Finance($db,$actor))->entry($entry);
   $q=$db->prepare("SELECT previous_positions FROM accounting_revisions WHERE club_id=? AND object_type='entry' AND object_id=? ORDER BY version LIMIT 1");$q->execute([$e['club_id'],$entry]);$previous=$q->fetchColumn();
   if($previous!==false){$rows=json_decode((string)$previous,true,64);if(!is_array($rows)||!array_is_list($rows))$rows=[];}
   else{$q=$db->prepare('SELECT ordinal,description FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal');$q->execute([$e['club_id'],$entry]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
   $out=[];foreach($rows as$row)if(is_array($row)&&isset($row['ordinal'])&&is_string($row['description']??null)&&(int)$row['ordinal']>0&&(int)$row['ordinal']<=50)$out[(int)$row['ordinal']]=$row['description'];
   if($own)$db->commit();return$out;
  }catch(\Throwable$failure){if($own&&$db->inTransaction())$db->rollBack();throw$failure;}
 }
 public static function save(PDO $db,string $actor,string $entry,array $review):void
 {
  $finance=new Finance($db,$actor);if(!$finance->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.');if(!$db->inTransaction())throw new DomainException('Positionszuordnung muss zusammen mit dem Beleg gespeichert werden.');$e=$finance->entry($entry);$inventory=new Inventory($db,$actor);
  foreach($review['details']??[] as $ordinal=>$detail)$db->prepare('INSERT INTO invoice_position_details VALUES(?,?,?,?,?,?)')->execute([$e['club_id'],$entry,$ordinal,json_encode($detail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor,gmdate('c')]);
  foreach($review['links'] as $ordinal=>$items)foreach($items as $id=>$version){
   if(!$inventory->canWrite())throw new DomainException('Inventar-Schreibrecht für Positionszuordnungen erforderlich.');$item=$inventory->item($id);if(!$item['active']||(int)$item['version']!==$version)throw new DomainException('Ein Positionsgegenstand wurde inzwischen geändert. Bitte erneut auswählen.');
   $db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute([$e['club_id'],$entry,$ordinal,$id,$version,$review['positions'][$ordinal-1]['description'],$actor,gmdate('c')]);
  }
 }
}
