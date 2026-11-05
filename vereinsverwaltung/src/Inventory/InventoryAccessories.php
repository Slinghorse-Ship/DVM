<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** A packing guide only. Quantities here never reserve or move physical inventory. */
trait InventoryAccessories
{
 public function accessories(string $parent):array
 {
  $a=$this->actor();$this->row('inventory_items',$a['club_id'],$parent);
  return $this->query('SELECT a.child_item child_id,a.quantity_per_unit,a.usage_mode,i.name,i.code,i.unit,i.active,i.content_quantity,i.content_unit FROM inventory_accessories a JOIN inventory_items i ON i.club_id=a.club_id AND i.id=a.child_item WHERE a.club_id=? AND a.parent_item=? ORDER BY i.name,i.code',[$a['club_id'],$parent])->fetchAll();
 }
 public function saveAccessories(string $parent,int $version,array $rows,string $key):string
 {
  if(count($rows)>50)throw new DomainException('Bitte höchstens 50 Zubehörpositionen verwenden.');
  return $this->change('accessories',$key,$parent,[$version,$rows],function($a,$id)use($version,$rows){
   $item=$this->row('inventory_items',$a['club_id'],$id);self::version($item,$version);self::active($item);$selected=[];
   foreach($rows as $row){if(!is_array($row))throw new DomainException('Zubehörangaben prüfen.');$child=self::text($row['child']??'',80,true);if($child===$id)throw new DomainException('Ein Gegenstand kann nicht sein eigenes Zubehör sein.');if(isset($selected[$child]))throw new DomainException('Dasselbe Zubehör bitte nur einmal auswählen.');$existing=$this->row('inventory_items',$a['club_id'],$child);self::active($existing);$mode=$row['usage_mode']??'required';if(!is_string($mode)||!in_array($mode,['required','optional'],true))throw new DomainException('Bitte „Immer dabei“ oder „Bei Bedarf“ auswählen.');$selected[$child]=['quantity'=>self::integer($row['quantity_per_unit']??'',1),'mode'=>$mode];}
   $graph=[];foreach($this->query('SELECT parent_item,child_item FROM inventory_accessories WHERE club_id=? AND parent_item<>?',[$a['club_id'],$id])->fetchAll() as $edge)$graph[$edge['parent_item']][]=$edge['child_item'];
   $queue=array_keys($selected);$seen=[];while($queue){$node=array_pop($queue);if($node===$id)throw new DomainException('Diese Zubehörzuordnung würde einen Kreis bilden. Bitte die bestehende Zuordnung prüfen.');if(isset($seen[$node]))continue;$seen[$node]=true;if(count($seen)>10000)throw new DomainException('Zubehörstruktur zu umfangreich.');foreach($graph[$node]??[] as $next)$queue[]=$next;}
   $this->query('DELETE FROM inventory_accessories WHERE club_id=? AND parent_item=?',[$a['club_id'],$id]);foreach($selected as $child=>$detail)$this->query('INSERT INTO inventory_accessories(club_id,parent_item,child_item,quantity_per_unit,usage_mode) VALUES(?,?,?,?,?)',[$a['club_id'],$id,$child,$detail['quantity'],$detail['mode']]);
   $this->query('UPDATE inventory_items SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$id]);return $version+1;
  });
 }
}
