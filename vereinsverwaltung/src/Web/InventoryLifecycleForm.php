<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Permissions};
/** Existing item lifecycle decisions stay bound to the opened item and original operation. */
final class InventoryLifecycleForm
{
 public const OPS=['archive','restore','remove'];
 public const DATA=['reason','kind','location','quantity','purchase_unit_ids'];
 public static function applies(array$body,array$query):bool{return in_array($body['action']??null,['inventory-archive','inventory-restore','inventory-remove'],true)||array_key_exists('item_action',$query)||array_key_exists('lifecycle_request',$query);}
 public static function original(array$body):array
 {
  $out=array_intersect_key($body,array_flip(['action','target','version','stock_version','request_key','confirmed','inventory_lifecycle_complete']));if(array_key_exists('data',$body))$out['data']=is_array($body['data'])?array_intersect_key($body['data'],array_flip(self::DATA)):$body['data'];return$out;
 }
 public static function key(mixed$value):string{if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Inventarentscheidung vollständig prüfen.',422);return$value;}
 private static function number(mixed$value,bool$zero=false):int
 {
  if(!is_string($value)||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,9})\z/D':'/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>2147483647)throw new DomainException('Ursprüngliche Gegenstands- und Bestandsfassung vollständig prüfen.',422);return(int)$value;
 }
 private static function text(mixed$value,int$max):string
 {
  if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche vollständige Inventarentscheidung prüfen. Diese Eingabeform wird nicht übernommen.',422);return$value;
 }
 public static function opened(Inventory$i,array$query):array
 {
  foreach(['view','tab','item','item_action','lifecycle_request','new_item']as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Quelle der Inventarentscheidung vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||array_key_exists('new_item',$query))throw new DomainException('Entscheidung am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  if(isset($query['item_action'])&&!in_array($query['item_action'],self::OPS,true))throw new DomainException('Ursprünglichen Inventarschritt vollständig prüfen.',422);return InventoryItemForm::opened($i,$query['item']??null);
 }
 public static function recorded(PDO$db,string$actor,string$item,string$key,string$op):?array
 {
  $who=Permissions::actor($db,$actor);$q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$who['club_id'],self::key($key)]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)return null;$data=json_decode($event['details'],true);$actions=$op==='remove'?['remove','discard']:[$op];
  if($event['actor_id']!==$who['id']||$event['object_id']!==$item||!in_array($event['action'],$actions,true)||!is_array($data)||!is_int($data[0]??null)||!is_array($data[1]??null))throw new DomainException('Diese Inventarkennung gehört zu einer anderen ursprünglichen Entscheidung.',409);return ['operation'=>$op,'action'=>$event['action'],'version'=>$data[0],'data'=>$data[1]];
 }
 public static function guard(PDO$db,string$actor,array$body,array$query,?Inventory$i=null):array
 {
  $i??=new Inventory($db,$actor);if(!$i->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diese Entscheidung erforderlich.',403);$item=self::opened($i,$query);
  if(($body['inventory_lifecycle_complete']??null)!=='1')throw new DomainException('Inventarentscheidung unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts entfernt oder wiederhergestellt.',422);
  foreach(['action','target','version','request_key']as$field)if(!is_string($body[$field]??null))throw new DomainException('Ursprüngliche vollständige Inventarentscheidung prüfen. Diese Eingabeform wird nicht übernommen.',422);
  $op=substr($body['action'],10);if(!in_array($op,self::OPS,true)||$body['action']!=='inventory-'.$op||($query['item_action']??null)!==$op||$body['target']!==$item['id'])throw new DomainException('Entscheidung am ursprünglich geöffneten Gegenstand und seinem Schritt erneut prüfen.',409);
  $version=self::number($body['version'],$op==='remove');$key=self::key($body['request_key']);if($key!==self::key($query['lifecycle_request']??null))throw new DomainException('Inventarentscheidung unter ihrer ursprünglichen Vorgangsadresse erneut prüfen.',409);
  if($op==='archive'){$stock=self::number($body['stock_version']??null,true);$data=$body['data']??null;if(!is_array($data)||array_keys($data)!==['reason'])throw new DomainException('Ursprünglichen vollständigen Archivierungsgrund prüfen.',422);$reason=self::text($data['reason'],1000);$expected=['reason'=>trim($reason),'stock_version'=>$stock,'active'=>0];}
  elseif($op==='restore'){if(array_key_exists('data',$body)||array_key_exists('stock_version',$body))throw new DomainException('Ursprünglichen Wiederherstellungsauftrag vollständig prüfen.',422);$expected=['reason'=>'Gegenstand wiederhergestellt; Bestand nicht verändert.','active'=>1];}
  else{
   if(array_key_exists('stock_version',$body)||array_key_exists('confirmed',$body))throw new DomainException('Ursprüngliche vollständige Inventarentscheidung prüfen. Diese Eingabeform wird nicht übernommen.',422);$data=$body['data']??null;
   if(!is_array($data)||array_diff(array_keys($data),self::DATA)||array_diff(['kind','location','quantity','reason'],array_keys($data)))throw new DomainException('Ursprüngliche Teilentfernung vollständig prüfen. Keine Angabe wird still entfernt.',422);
   foreach(['kind','location','quantity','reason']as$field)self::text($data[$field],$field==='reason'?1000:80);if(trim($data['location'])!==$data['location']||preg_match('/[\x00-\x1f\x7f]/',$data['location']))throw new DomainException('Ursprüngliche Teilentfernung vollständig prüfen. Keine Angabe wird still entfernt.',422);
   if(!in_array($data['kind'],['remove','discard'],true)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$data['quantity'])||(int)$data['quantity']>1000000)throw new DomainException('Ursprüngliche vollständige Entfernungsmenge und Bestandsart prüfen.',422);
   if(array_key_exists('purchase_unit_ids',$data)){$ids=$data['purchase_unit_ids'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>500)throw new DomainException('Ursprüngliche betroffene Stücke vollständig prüfen. Keine Auswahl wird still gekürzt oder umnummeriert.',422);$seen=[];foreach($ids as$id){self::text($id,80);if($id===''||isset($seen[$id]))throw new DomainException('Ursprüngliche betroffene Stücke vollständig prüfen. Keine Auswahl wird still gekürzt oder umnummeriert.',422);$seen[$id]=true;}}$expected=$data;
  }
  if($op!=='remove'&&($body['confirmed']??null)!=='1')throw new DomainException('Gegenstand, Bestand und ursprüngliche Entscheidung ausdrücklich bestätigen.',422);
  $recorded=self::recorded($db,$actor,$item['id'],$key,$op);if($recorded&&($recorded['version']!==$version||$recorded['data']!==$expected||($op==='remove'&&$recorded['action']!==$data['kind'])))throw new DomainException('Diese Inventarentscheidung wurde mit anderen ursprünglichen Angaben bestätigt.',409);
  if(!$recorded){if($version!==$item[$op==='remove'?'stock_version':'version']||($op==='archive'&&$stock!==$item['stock_version']))throw new DomainException('Gegenstand oder Bestand wurden inzwischen geändert. Ursprüngliche Angaben erhalten und aktuellen Stand neu prüfen.',409);
   if($op==='remove'){if($item['tracking_mode']!=='quantity'||$item['cash_account'])throw new DomainException('Teilentfernung ist nur für Mengenartikel ohne Handkasse möglich.',422);$active=array_fill_keys(array_column($i->purchaseUnits($item['id']),'id'),true);if(array_diff_key($seen??[],$active))throw new DomainException('Betroffene Stücke gehören nicht zum aktuellen ursprünglichen Gegenstand.',409);}
  }
  return ['item'=>$item,'operation'=>$op,'recorded'=>$recorded];
 }
 public static function state(PDO$db,string$actor,array$body,array$query):array
 {
  $posted=in_array($body['action']??null,['inventory-archive','inventory-restore','inventory-remove'],true)||((array_key_exists('item_action',$query)||array_key_exists('lifecycle_request',$query))&&(array_key_exists('inventory_lifecycle_complete',$body)||(array_key_exists('action',$body)&&!is_string($body['action']))));$state=['item'=>null,'blocked'=>false,'original'=>$posted?self::original($body)+['query'=>array_intersect_key($query,array_flip(['view','tab','item','item_action','lifecycle_request','new_item']))]:null,'error'=>'','status'=>200,'recorded'=>null,'keys'=>[],'route'=>['view'=>'inventory','tab'=>'items']];foreach(self::OPS as$op)$state['keys'][$op]=bin2hex(random_bytes(16));foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];
  try{$i=new Inventory($db,$actor);$state['item']=self::opened($i,$query);$state['route']['item']=$state['item']['id'];if($posted){$checked=self::guard($db,$actor,$body,$query,$i);$state['recorded']=$checked['recorded'];$state['keys'][$checked['operation']]=$body['request_key'];}
   elseif(array_key_exists('item_action',$query)||array_key_exists('lifecycle_request',$query)){$op=$query['item_action']??null;if(!is_string($op)||!in_array($op,self::OPS,true))throw new DomainException('Ursprünglichen Inventarschritt vollständig prüfen.',422);$key=self::key($query['lifecycle_request']??null);$state['keys'][$op]=$key;$state['recorded']=self::recorded($db,$actor,$state['item']['id'],$key,$op);}
   if($state['recorded'])$state['blocked']=true;
  }catch(\Throwable$e){$state['blocked']=true;$state['status']=self::status($e);$state['error']=$e instanceof DomainException?$e->getMessage():'Inventarentscheidung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';if(!$posted)$state['original']=['query'=>array_intersect_key($query,array_flip(['view','tab','item','item_action','lifecycle_request','new_item']))];}return$state;
 }
 public static function status(\Throwable$e):int{return InventoryItemForm::status($e);}
}
