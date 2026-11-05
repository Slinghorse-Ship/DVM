<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;

/** Optional inventory drafts travel with reviewed positions, never as separate invoices. */
final class InvoiceInventoryCreate
{
 public static function requests(PDO $db,string $actor,array $data,?array $review):array
 {
  $rows=$data['invoice_positions']??[];if(!is_array($rows))return [];$requests=[];
  foreach($rows as $index=>$row){
   if(!is_array($row)||!array_key_exists('new_item',$row))continue;
   $ordinal=is_int($index)?$index+1:0;
   if(!$review||$ordinal<1||!isset($review['details'][$ordinal]))throw new DomainException('Neue Gegenstände benötigen geprüfte und aktivierte Rechnungspositionen.');
   if(!empty($review['links'][$ordinal]))throw new DomainException('Position '.$ordinal.': Entweder einen neuen Gegenstand anlegen oder vorhandene Gegenstände zuordnen.');
   $request=$row['new_item'];$allowed=['name','home_location','location_version','unit','content_quantity','content_unit','category'];
   if(!is_array($request)||array_diff(array_keys($request),$allowed))throw new DomainException('Position '.$ordinal.': Angaben für den neuen Gegenstand prüfen.');
   $read=static function(string $field,int $max,string $default='')use($request,$ordinal):string{$value=$request[$field]??$default;if(!is_string($value)||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Position '.$ordinal.': Gegenstandsangaben prüfen.');return trim($value);};
   $item=['name'=>$read('name',160),'home_location'=>$read('home_location',80),'location_version'=>$read('location_version',7),'unit'=>$read('unit',30),'content_quantity'=>$read('content_quantity',7,'1'),'content_unit'=>$read('content_unit',40,'Stück'),'category'=>$read('category',100)];
   if($item['name']==='')throw new DomainException('Position '.$ordinal.': Bitte die Bezeichnung des Gegenstands ausfüllen.');
   if($item['home_location']===''||!preg_match('/^[1-9]\d{0,6}$/D',$item['location_version']))throw new DomainException('Position '.$ordinal.': Bitte einen aktuellen Lagerort auswählen.');
   if(!isset(Inventory::UNITS[$item['unit']],Inventory::UNITS[$item['content_unit']]))throw new DomainException('Position '.$ordinal.': Einheit und Einheit des Inhalts auswählen.');
   if(!preg_match('/^[1-9]\d{0,6}$/D',$item['content_quantity'])||(int)$item['content_quantity']>1000000)throw new DomainException('Position '.$ordinal.': Inhalt je Einheit als ganze Zahl zwischen 1 und 1.000.000 eingeben.');
   $quantity=(string)$review['details'][$ordinal]['quantity'];
   if(!preg_match('/^\d+(?:\.0+)?$/D',$quantity)||(int)$quantity<1||(int)$quantity>1000000)throw new DomainException('Position '.$ordinal.': Für den Inventarbestand muss die geprüfte Rechnungsmenge eine positive ganze Zahl sein.');
   $requests[$ordinal]=$item;
  }
  if($requests&&(!(new Finance($db,$actor))->canWrite()||!(new Inventory($db,$actor))->canWrite()))throw new DomainException('Neue Gegenstände aus der Rechnung benötigen Finanz- und Inventar-Schreibrecht.');
  return $requests;
 }
 public static function createForEntry(PDO $db,string $actor,string $entry,array $requests):array
 {
  if(!$db->inTransaction())throw new DomainException('Rechnung und neue Gegenstände müssen gemeinsam gespeichert werden.');
  $inventory=new Inventory($db,$actor);$created=[];
  foreach($requests as $ordinal=>$request){
   $draft=InvoiceInventoryDraft::position($db,$actor,$entry,(int)$ordinal);$data=$draft['defaults'];unset($data['source']);
   $data=array_replace($data,['name'=>$request['name'],'home_location'=>$request['home_location'],'home_location_version'=>$request['location_version'],'unit'=>$request['unit'],'content_quantity'=>$request['content_quantity'],'content_unit'=>$request['content_unit'],'category'=>$request['category'],'source_kind'=>'expense','source_id'=>$entry]);
   // Quantity, price, original and entry version come only from the reviewed stored line.
   if($data['initial_quantity']==='')throw new DomainException('Position '.$ordinal.': Die Rechnungsmenge ist nicht als Inventarbestand verwendbar.');
   try{$created[$ordinal]=$inventory->createFromInvoiceInTransaction($data);}
   catch(DomainException $e){throw new DomainException('Position '.$ordinal.': '.$e->getMessage(),0,$e);}
  }
  return $created;
 }
 /** A retry cannot append items to a previously saved invoice or alter their draft. */
 public static function assertReplay(PDO $db,string $actor,string $entry,array $requests,?array $review):void
 {
  $stored=[];$details=[];
  foreach(InvoicePositionReview::details($db,$actor,$entry) as $row){$details[$row['ordinal']]=$row['details'];if(isset($row['details']['new_inventory']))$stored[$row['ordinal']]=$row['details']['new_inventory'];}
  if($stored!==$requests)throw new DomainException('Diese Rechnung wurde bereits gespeichert. Die Auswahl neuer Gegenstände wurde danach geändert. Bitte die gespeicherte Rechnung öffnen.');
  foreach($requests as $ordinal=>$ignored)if(($details[$ordinal]??null)!==($review['details'][$ordinal]??null))throw new DomainException('Diese Rechnung wurde bereits gespeichert. Menge oder Preis der neuen Gegenstände wurde danach geändert. Bitte die gespeicherte Rechnung öffnen.');
 }
}
