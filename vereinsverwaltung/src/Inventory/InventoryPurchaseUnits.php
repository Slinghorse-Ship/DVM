<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Physical detail records of a quantity item; never a second financial booking. */
trait InventoryPurchaseUnits
{
 /** Event payloads may contain nested per-piece prices; redact every depth for non-finance readers. */
 private static function withoutInventoryPrices(array $details):array
 {
  foreach($details as $key=>$value){if(in_array($key,['unit_value','unit_value_cents','value_note','counted'],true)){unset($details[$key]);continue;}if(is_array($value))$details[$key]=self::withoutInventoryPrices($value);}return $details;
 }
 public function purchaseUnits(string $item,bool $includeRetired=false):array
 {
  $a=$this->actor();$this->row('inventory_items',$a['club_id'],$item);
  $rows=$this->query('SELECT * FROM inventory_purchase_units WHERE club_id=? AND item_id=?'.($includeRetired?'':' AND retired_event IS NULL').' ORDER BY created_event,sequence',[$a['club_id'],$item])->fetchAll();
  if($a['finance_access']==='none')foreach($rows as &$row){unset($row['unit_value_cents'],$row['value_note']);}unset($row);foreach($rows as $index=>&$row){$row['label']='Stück '.($index+1).($row['serial_number']!==''?' · Seriennummer '.$row['serial_number']:'').(array_key_exists('unit_value_cents',$row)?($row['unit_value_cents']===null?' · Preis unbekannt':' · '.number_format($row['unit_value_cents']/100,2,',','.').' €'):'');}unset($row);return $rows;
 }
 /** Metadata correction, never a stock movement; existing IDs and retired evidence remain. */
 private function updatePurchaseDetails(array $actor,string $item,array $data):void
 {
  $parent=$this->row('inventory_items',$actor['club_id'],$item);self::active($parent);self::version($parent,self::integer($data['purchase_stock_version']??''),'stock_version');
  $updates=$data['purchase_unit_updates'];if(!is_array($updates)||!$updates||count($updates)>500)throw new DomainException('Stückangaben prüfen.');self::text($data['purchase_change_reason']??'',1000,true);
  $rows=$this->query('SELECT * FROM inventory_purchase_units WHERE club_id=? AND item_id=? AND retired_event IS NULL',[$actor['club_id'],$item])->fetchAll();$all=array_column($rows,null,'id');
  foreach($updates as $id=>$update){if(!is_string($id)||!isset($all[$id])||!is_array($update))throw new DomainException('Ein Stück gehört nicht mehr zu diesem Gegenstand. Bitte neu öffnen.');$row=$all[$id];$row['serial_number']=self::text($update['serial_number']??$row['serial_number'],120);
   if(array_key_exists('unit_value',$update)||array_key_exists('value_note',$update)){if($actor['finance_access']!=='write')throw new DomainException('Stückpreise ändern benötigt Finanz-Bearbeitungsrecht.');$value=self::text($update['unit_value']??'',40);$row['unit_value_cents']=$value===''?null:Finance::cents($value);$row['value_note']=self::text($update['value_note']??'',1000);if($row['unit_value_cents']!==null&&$row['value_note']==='')throw new DomainException('Wertnachweis je Stück erforderlich.');}
   $all[$id]=$row;
  }
  $serials=[];foreach($all as $row){$serial=$row['serial_number'];if($serial!==''&&isset($serials[$serial]))throw new DomainException('Dieselbe Seriennummer ist bei diesem Gegenstand bereits vorhanden.');if($serial!=='')$serials[$serial]=true;}
  foreach($updates as $id=>$ignored){$row=$all[$id];$this->query('UPDATE inventory_purchase_units SET serial_number=?,unit_value_cents=?,value_note=? WHERE club_id=? AND item_id=? AND id=? AND retired_event IS NULL',[$row['serial_number'],$row['unit_value_cents'],$row['value_note'],$actor['club_id'],$item,$id]);}
 }
 private function hasPurchaseUnits(string $club,string $item):bool{return (bool)$this->query('SELECT 1 FROM inventory_purchase_units WHERE club_id=? AND item_id=? LIMIT 1',[$club,$item])->fetchColumn();}
 private function addPurchaseUnits(array $actor,string $item,int $event,mixed $units,int $quantity,string $commonNote=''):void
 {
  if(!is_array($units)||count($units)!==$quantity||$quantity<1||$quantity>500)throw new DomainException('Für den Detailbestand bitte genau eine Zeile je Stück erfassen (höchstens 500 Stück).');
  $parent=$this->row('inventory_items',$actor['club_id'],$item);if($parent['tracking_mode']!=='quantity'||$parent['cash_account'])throw new DomainException('Stückdetails sind nur bei normalen Mengen-Gegenständen möglich.');
  foreach($units as $unit){if(!is_array($unit))throw new DomainException('Stückangaben prüfen.');$serial=self::text($unit['serial_number']??'',120);$value=self::text($unit['unit_value']??'',40);$note=self::text($unit['value_note']??'',1000);if($note==='')$note=$commonNote;$cents=null;
   if($value!==''){if($actor['finance_access']==='none')throw new DomainException('Stückpreise benötigen Finanzberechtigung.');$cents=$value==='0'?0:Finance::cents($value);if($note==='')throw new DomainException('Bitte die Herkunft der Stückpreise angeben.');}
   if($serial!==''&&$this->query('SELECT 1 FROM inventory_purchase_units WHERE club_id=? AND item_id=? AND serial_number=? AND retired_event IS NULL',[$actor['club_id'],$item,$serial])->fetchColumn())throw new DomainException('Dieselbe Seriennummer ist bei diesem Gegenstand bereits vorhanden.');
   $this->query('INSERT INTO inventory_purchase_units(club_id,id,item_id,serial_number,unit_value_cents,value_note,created_event) VALUES(?,?,?,?,?,?,?)',[$actor['club_id'],bin2hex(random_bytes(16)),$item,$serial,$cents,$note,$event]);
  }
 }
 /** No FIFO or average: partial departure must identify actual physical pieces. */
 private function retirePurchaseUnits(string $club,string $item,int $event,int $quantity,mixed $selection=[]):void
 {
  if(!$this->hasPurchaseUnits($club,$item))return;
  $active=$this->query('SELECT id FROM inventory_purchase_units WHERE club_id=? AND item_id=? AND retired_event IS NULL',[$club,$item])->fetchAll(\PDO::FETCH_COLUMN);
  if(!is_array($selection)||array_filter($selection,fn($id)=>!is_string($id)))throw new DomainException('Betroffene Stücke auswählen.');$selection=array_values(array_unique($selection));
  if(!$selection&&$quantity===count($active))$selection=$active;
  if(count($selection)!==$quantity||array_diff($selection,$active))throw new DomainException('Bei unterschiedlichen Stückpreisen oder Seriennummern bitte die tatsächlich abgegangenen Stücke auswählen. Es wird kein Stück automatisch bestimmt.');
  foreach($selection as $unit)$this->query('UPDATE inventory_purchase_units SET retired_event=? WHERE club_id=? AND item_id=? AND id=? AND retired_event IS NULL',[$event,$club,$item,$unit]);
 }
 private function purchaseStockChange(array $actor,string $item,int $event,int $delta,array $data):void
 {
  if(!$delta)return;
  if($delta<0){$this->retirePurchaseUnits($actor['club_id'],$item,$event,-$delta,$data['purchase_unit_ids']??[]);return;}
  if($this->hasPurchaseUnits($actor['club_id'],$item)||array_key_exists('purchase_units',$data)){if(!$this->hasPurchaseUnits($actor['club_id'],$item)&&(int)$this->query('SELECT COALESCE(SUM(shelf+lent+blocked),0) FROM inventory_stock WHERE club_id=? AND item_id=?',[$actor['club_id'],$item])->fetchColumn()>0)throw new DomainException('Vorhandenen Mengenbestand zuerst vollständig mit Stückdetails erfassen; nur neue Stücke zu ergänzen wäre keine vollständige Bewertung.');$this->addPurchaseUnits($actor,$item,$event,$data['purchase_units']??null,$delta,self::text($data['value_note']??'',1000));}
 }
}
