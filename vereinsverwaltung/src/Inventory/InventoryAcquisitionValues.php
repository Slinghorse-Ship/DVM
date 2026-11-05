<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Der belegte Rechnungsbetrag wird bei vollständigen Teilegruppen centgenau verteilt. */
final class InventoryAcquisitionValues
{
 public static function pools(PDO $db,string $actor,?string $item=null):array
 {
  $a=Permissions::actor($db,$actor);if($a['finance_access']==='none')throw new DomainException('Finanzberechtigung für Anschaffungswerte erforderlich.');
  $q=$db->prepare('SELECT p.* FROM inventory_acquisition_pools p WHERE p.club_id=?'.($item!==null?' AND EXISTS(SELECT 1 FROM inventory_acquisition_sources s WHERE s.club_id=p.club_id AND s.pool_id=p.id AND s.item_id=?)':'').' ORDER BY p.created_at,p.id');
  $q->execute($item!==null?[$a['club_id'],$item]:[$a['club_id']]);$pools=$q->fetchAll(PDO::FETCH_ASSOC);$out=[];
  foreach($pools as $pool){
   $q=$db->prepare('SELECT i.id,i.name,i.code,i.unit,i.active,i.deleted_at,i.ownership,i.cash_account,i.unit_value_cents,s.pool_quantity,COALESCE((SELECT SUM(st.shelf+st.lent+st.blocked) FROM inventory_stock st WHERE st.club_id=i.club_id AND st.item_id=i.id),0) quantity,(SELECT COUNT(*) FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL) piece_count,(SELECT COUNT(unit_value_cents) FROM inventory_purchase_units u WHERE u.club_id=i.club_id AND u.item_id=i.id AND u.retired_event IS NULL) valued_pieces FROM inventory_acquisition_sources s JOIN inventory_items i ON i.club_id=s.club_id AND i.id=s.item_id WHERE s.club_id=? AND s.pool_id=? ORDER BY i.name,i.code');
   $q->execute([$a['club_id'],$pool['id']]);$pool['items']=$q->fetchAll(PDO::FETCH_ASSOC);$pool['complete_pending']=count($pool['items'])>1;$pool['all_priced']=count($pool['items'])>1;
   foreach($pool['items'] as $row){
    $present=(bool)$row['active']&&$row['deleted_at']===null&&$row['ownership']==='club'&&!$row['cash_account']&&(int)$row['quantity']===(int)$row['pool_quantity'];
    $unknown=(int)$row['piece_count']===(int)$row['quantity']&&(int)$row['valued_pieces']===0;
    $priced=(int)$row['piece_count']>0?((int)$row['piece_count']===(int)$row['quantity']&&(int)$row['valued_pieces']===(int)$row['quantity']):$row['unit_value_cents']!==null;
    $pool['complete_pending']=$pool['complete_pending']&&$present&&$unknown;
    $pool['all_priced']=$pool['all_priced']&&$present&&$priced;
   }
   if($pool['complete_pending'])$pool['items']=self::allocate($pool['items'],(int)$pool['gross_cents']);
   $out[]=$pool;
  }
  return $out;
 }

 /** Divide a reviewed gross amount over all reviewed inventory units, preserving cents exactly. */
 private static function allocate(array $items,int $gross):array
 {
  if($gross<0)throw new DomainException('Belegbetrag für die Inventarzuordnung prüfen.');
  $units=0;foreach($items as $item){$quantity=(int)($item['pool_quantity']??0);if($quantity<1)throw new DomainException('Inventarmenge für die Zuordnung prüfen.');if($units>PHP_INT_MAX-$quantity)throw new DomainException('Inventarmenge überschreitet den Zahlenbereich.');$units+=$quantity;}
  if($units<1)return $items;
  $base=intdiv($gross,$units);$remainder=$gross%$units;$offset=0;
  foreach($items as &$item){
   $quantity=(int)$item['pool_quantity'];$extra=max(0,min($quantity,$remainder-$offset));if($base>0&&$quantity>intdiv(PHP_INT_MAX,$base))throw new DomainException('Inventarwert überschreitet den Zahlenbereich.');$amount=$base*$quantity+$extra;
   $item['allocated_total_cents']=$amount;$item['allocated_unit_min_cents']=$extra===$quantity?$base+1:$base;$item['allocated_unit_max_cents']=$extra>0?$base+1:$base;$item['allocated_unit_value_cents']=$item['allocated_unit_min_cents']===$item['allocated_unit_max_cents']?$item['allocated_unit_min_cents']:null;$offset+=$quantity;
  }
  unset($item);return $items;
 }

 /** Apply derived invoice allocations to visible rows without changing source records. */
 public static function includeInOverview(array $overview,array $pools):array
 {
  $visible=array_fill_keys(array_column($overview['rows'],'id'),true);$indexes=[];foreach($overview['rows'] as $index=>$row)$indexes[$row['id']]=$index;
  $individualTotal=(int)$overview['total'];$allocatedTotal=0;$allocatedRows=0;$allocatedIds=[];$shown=[];
  foreach($pools as $pool){
   $ids=array_column($pool['items'],'id');if(!array_intersect_key($visible,array_fill_keys($ids,true)))continue;
   $pool['included']=false;
   if($pool['complete_pending']){
    $pool['included']=true;
    foreach(self::allocate($pool['items'],(int)$pool['gross_cents']) as $allocated){
     $id=$allocated['id'];if(!isset($indexes[$id])||isset($allocatedIds[$id]))continue;$rowIndex=$indexes[$id];$amount=(int)$allocated['allocated_total_cents'];
     if($allocatedTotal>PHP_INT_MAX-$amount)throw new DomainException('Gemeinsame Anschaffungswerte überschreiten den Zahlenbereich.');
     $overview['rows'][$rowIndex]['documented_total']=$amount;$overview['rows'][$rowIndex]['allocated_from_invoice']=true;$overview['rows'][$rowIndex]['allocated_unit_min_cents']=(int)$allocated['allocated_unit_min_cents'];$overview['rows'][$rowIndex]['allocated_unit_max_cents']=(int)$allocated['allocated_unit_max_cents'];$overview['rows'][$rowIndex]['allocated_unit_value_cents']=$allocated['allocated_unit_value_cents'];$allocatedIds[$id]=true;$allocatedTotal+=$amount;$allocatedRows++;
    }
   }
   $shown[]=$pool;
  }
  if($allocatedTotal>PHP_INT_MAX-$individualTotal)throw new DomainException('Inventarwertsumme überschreitet den Zahlenbereich.');
  $overview['individual_total']=$individualTotal;$overview['pooled_total']=$allocatedTotal;$overview['total']=$individualTotal+$allocatedTotal;$overview['pools']=$shown;$overview['unallocated']=0;$overview['unvalued']=max(0,(int)$overview['unvalued']-$allocatedRows);return $overview;
 }
}
