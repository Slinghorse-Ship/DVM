<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
/** Read the original accounting positions; never duplicate tax or stock state in inventory. */
final class InventoryAccounting
{
 public static function forItem(PDO $db,string $actor,string $itemId):array
 {
  $finance=new Finance($db,$actor);if(!$finance->canRead())return [];
  $item=(new Inventory($db,$actor))->item($itemId);$club=Permissions::actor($db,$actor)['club_id'];$sources=[];
  if(!empty($item['source_entry']))$sources['entry:'.$item['source_entry']]=['type'=>'entry','id'=>$item['source_entry'],'ordinals'=>[]];
  if(!empty($item['source_goods']))$sources['goods:'.$item['source_goods']]=['type'=>'goods','id'=>$item['source_goods'],'ordinals'=>[]];
  $q=$db->prepare('SELECT entry_id,ordinal FROM invoice_position_items WHERE club_id=? AND item_id=? ORDER BY entry_id,ordinal');$q->execute([$club,$itemId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $link){$key='entry:'.$link['entry_id'];$sources[$key]??=['type'=>'entry','id'=>$link['entry_id'],'ordinals'=>[]];$sources[$key]['ordinals'][]=(int)$link['ordinal'];}
  $out=[];foreach($sources as $source){$entry=$source['type']==='entry'?$finance->entry($source['id']):$finance->goodsEntry($source['id']);
   $positions=$finance->positions($source['type'],$source['id']);if($source['ordinals'])$positions=array_values(array_filter($positions,fn($p)=>in_array((int)$p['ordinal'],$source['ordinals'],true)));
   $out[]=$source+['title'=>$source['type']==='entry'?($entry['description']??'Rechnung'):($entry['designation']!==''?$entry['designation']:$entry['description']),'positions'=>$positions,'specific'=>(bool)$source['ordinals']];
  }return $out;
 }
}
