<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

trait InventoryAcquisitions
{
 /** Composition boundary: validates all stock invariants and never changes existing physical stock. */
 public function integrateAcquisitionInTransaction(array $group,array $location,string $reason):string
 {
  if(!$this->db->inTransaction())throw new DomainException('Inventarübernahme benötigt eine gemeinsame Transaktion.');
  $a=$this->actor(true);if($a['finance_access']!=='write')throw new DomainException('Finanzschreibrecht erforderlich.');
  $place=$this->row('inventory_locations',$a['club_id'],$location['id']);self::active($place);self::version($place,(int)$location['version']);
  $id=$group['item_id'];$quantity=self::integer($group['quantity'],1);$old=$id!==''?$this->item($id):null;
  if($old){self::active($old);self::version($old,$group['version']);self::version($old,$group['stock_version'],'stock_version');
   if($old['cash_account']||$old['tracking_mode']!=='quantity')throw new DomainException('Nur normale Mengenbestände abgleichen.');
   if(array_sum(array_column($old['stock'],'shelf'))!==$quantity||array_sum(array_column($old['stock'],'lent'))||array_sum(array_column($old['stock'],'reserved'))||array_sum(array_column($old['stock'],'blocked')))throw new DomainException('Vorhandenen vollständigen freien Bestand zuerst prüfen.');
   foreach($old['stock'] as $stock)if($stock['shelf']&&$stock['location_id']!==$place['id'])throw new DomainException('Vorhandener Bestand liegt nicht am bestätigten Lagerort.');
   if($old['unit']!==$group['unit']||(int)$old['content_quantity']!==$group['content_quantity']||$old['content_unit']!==$group['content_unit'])throw new DomainException('Einheit oder Satzinhalt stimmt nicht mit dem vorhandenen Bestand überein.');
  }elseif($group['version']!==0||$group['stock_version']!==0)throw new DomainException('Neue Gegenstände dürfen keinen vorherigen Stand angeben.');
  $target=$id?:bin2hex(random_bytes(16));$data=$old??[];
  $data=array_replace($data,['name'=>$group['name'],'category'=>$group['category'],'unit'=>$group['unit'],'content_quantity'=>(string)$group['content_quantity'],'content_unit'=>$group['content_unit'],'minimum'=>(string)($old['minimum']??0),'active'=>'1','kind'=>'reusable','home_location'=>$place['id'],'unit_value'=>'','value_note'=>self::text($group['value_note']??'',1000,true),'acquired_on'=>$old['acquired_on']??$group['acquired_on'],'notes'=>trim(($old['notes']??'').' '. $group['notes']),'source_kind'=>'keep','ownership'=>$old['ownership']??'club']);
  foreach(['cash_account','owner_id','responsible_id'] as $field)$data[$field]=$data[$field]??'';
  $version=$this->writeItem($a,$target,$id,$group['version'],$data);
  $event=$this->individualEvent($a,bin2hex(random_bytes(16)),$target,'item',[$group['version'],['reason'=>$reason,'integration'=>'verified_original','quantity'=>$quantity,'stock_unchanged'=>$old!==null]]);
  $units=array_map(static fn($u)=>['serial_number'=>$u['serial_number'],'unit_value'=>$u['unit_value_cents']===null?'':Finance::decimal($u['unit_value_cents']),'value_note'=>$u['value_note']],$group['units']);
  if($old&&$old['purchase_units']){
   if(count($old['purchase_units'])!==count($units))throw new DomainException('Stückdetails passen nicht zum bestätigten Bestand.');
   $updates=[];foreach($old['purchase_units'] as $i=>$unit){if($unit['serial_number']!==$units[$i]['serial_number'])throw new DomainException('Vorhandene Seriennummern dürfen bei der Übernahme nicht ersetzt werden.');$updates[$unit['id']]=$units[$i];}
   $this->updatePurchaseDetails($a,$target,['purchase_stock_version'=>(string)$old['stock_version'],'purchase_change_reason'=>$reason,'purchase_unit_updates'=>$updates]);
  }else $this->addPurchaseUnits($a,$target,$event,$units,$quantity,$reason);
  if(!$old)$this->delta($a['club_id'],$target,$place['id'],$event,$quantity);
  $this->query('UPDATE inventory_items SET stock_version=stock_version+1 WHERE club_id=? AND id=?',[$a['club_id'],$target]);
  DocumentCatalog::register($this->db,$a['club_id'],'item',$target);
  $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'inventory.acquisition-integrated',$target,$version,gmdate('c')]);
  return $target;
 }
}
