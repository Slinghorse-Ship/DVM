<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use Kulturhus\Verein\Inventory;
/** Read the original item-history query before scalar filtering can replace its meaning. */
final class InventoryHistoryForm
{
 public const FIELDS=['view','tab','item','movement_location','movement_page','item_history_page'];
 public static function page(mixed$value):string
 {
  if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)||(int)$value>1000000)throw new DomainException('Ursprüngliche Verlaufsseite vollständig prüfen.',422);return$value;
 }
 public static function state(Inventory$i,array$query):array
 {
  $state=['item'=>null,'blocked'=>false,'original'=>null,'error'=>'','status'=>200,'movement_location'=>'','movement_page'=>'1','item_history_page'=>'1','route'=>['view'=>'inventory','tab'=>'items']];
  foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];
  try{
   foreach(['view','tab','item']as$field)if(array_key_exists($field,$query)&&(!is_string($query[$field])||preg_match('//u',$query[$field])!==1||preg_match('/[\x00-\x1f\x7f]/',$query[$field])))throw new DomainException('Ursprüngliche Verlaufsquelle vollständig prüfen.',422);
   if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items')throw new DomainException('Verlauf am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
   $state['item']=InventoryItemForm::opened($i,$query['item']??null);$state['route']['item']=$state['item']['id'];
   foreach(['movement_location','movement_page','item_history_page']as$field)if(array_key_exists($field,$query)&&(!is_string($query[$field])||preg_match('//u',$query[$field])!==1||preg_match('/[\x00-\x1f\x7f]/',$query[$field])))throw new DomainException('Ursprüngliche Verlaufsquelle vollständig prüfen.',422);
   if(array_key_exists('movement_location',$query)){if(strlen($query['movement_location'])>80)throw new DomainException('Ursprünglichen Lagerortfilter vollständig prüfen.',422);$state['movement_location']=$query['movement_location'];}
   foreach(['movement_page','item_history_page']as$field)if(array_key_exists($field,$query))$state[$field]=self::page($query[$field]);
  }catch(\Throwable$failure){$state['blocked']=true;$state['status']=InventoryItemForm::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Verlauf konnte nicht vollständig gelesen werden. Aktuellen Vorgang neu prüfen.';$state['original']=array_intersect_key($query,array_flip(self::FIELDS));}
  return$state;
 }
}
