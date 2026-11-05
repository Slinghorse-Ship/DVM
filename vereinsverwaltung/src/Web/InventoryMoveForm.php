<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Finance,Permissions};
/** The whole stock intent and the item actually opened; no inferred targets or versions. */
final class InventoryMoveForm
{
 public const SCALAR=['kind','location','destination','quantity','reason','counted','value_note'];
 public const ROWS=['purchase_units','purchase_unit_ids'];
 public const KINDS=['receive','consume','transfer','block','repair','discard','count'];
 public static function original(array $body):array
 {
  $out=array_intersect_key($body,array_flip(['target','version','request_key']))+InventoryPieceForm::original($body);if(array_key_exists('data',$body)){$out['data']=is_array($body['data'])?array_intersect_key($body['data'],array_flip([...self::SCALAR,...self::ROWS])):$body['data'];if(is_array($out['data']['purchase_units']??null))$out['data']['purchase_units']=array_map(static fn($row)=>is_array($row)?array_intersect_key($row,array_flip(['serial_number','unit_value','value_note'])):$row,$out['data']['purchase_units']);}return$out;
 }
 public static function key(mixed $key):string
 {
  if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Bestands-Vorgangskennung vollständig prüfen.',422);return$key;
 }
 public static function target(array $body,array $query,Inventory $inventory,Finance $finance,PDO $db,string $actor):string
 {
  if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diese Lagerbewegung erforderlich.',403);
  if(($body['stock_form_complete']??null)!=='1')throw new DomainException('Ursprüngliche Lagerbewegung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach(['target','version','request_key']as$name)if(!array_key_exists($name,$body)||!is_string($body[$name]))throw new DomainException('Ursprüngliche Lagerbewegung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  $id=$body['target'];$version=$body['version'];self::key($body['request_key']);
  if($id===''||strlen($id)>80||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483647)throw new DomainException('Ursprüngliche Gegenstandskennung und Bestandsfassung vollständig prüfen.',422);
  foreach(['view','tab','item','new_item']as$name)if(array_key_exists($name,$query)&&!is_string($query[$name]))throw new DomainException('Ursprüngliche Bestandsquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||($query['item']??null)!==$id||array_key_exists('new_item',$query))throw new DomainException('Lagerbewegung am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  $item=InventoryItemForm::opened($inventory,$query['item']);if($item['id']!==$id)throw new DomainException('Lagerbewegung am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  $data=$body['data']??null;if(!is_array($data)||array_diff(array_keys($data),[...self::SCALAR,...self::ROWS]))throw new DomainException('Ursprüngliche Lagerbewegung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach(['kind','location','quantity','reason']as$name)if(!array_key_exists($name,$data))throw new DomainException('Ursprüngliche Lagerbewegung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach(self::SCALAR as$name)if(array_key_exists($name,$data)&&(!is_string($data[$name])||preg_match('//u',$data[$name])!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$data[$name])))throw new DomainException('Ursprüngliche Lagerbewegung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if(!in_array($data['kind'],self::KINDS,true))throw new DomainException('Ursprünglichen Bestandsvorgang vollständig prüfen.',422);
  if(!preg_match('/\A[0-9]{1,7}\z/D',$data['quantity'])||(int)$data['quantity']>1000000)throw new DomainException('Ursprüngliche Bestandsmenge vollständig prüfen.',422);
  if($data['kind']==='transfer'&&!array_key_exists('destination',$data))throw new DomainException('Ursprünglichen Zielort der Umlagerung vollständig prüfen.',422);
  $cash=self::cashAccount($db,$actor,$id);
  if($cash!==''&&!$finance->canRead())throw new DomainException('Die Übergabe einer Handkasse benötigt zusätzlich Finanz-Leserechte für den Zählabgleich.',403);
  if($cash!==''&&!array_key_exists('counted',$data))throw new DomainException('Ursprünglichen Zählbestand der Handkasse vollständig prüfen.',422);
  if($cash!==''&&!in_array($data['kind'],['receive','transfer'],true))throw new DomainException('Verknüpfte Handkassen über Zugang oder Umlagerung mit Zählabgleich verwalten.',422);
  if(!$finance->canRead()&&array_intersect(array_keys($data),['counted','value_note']))throw new DomainException('Zählbestände und Wertnachweise benötigen Finanzberechtigung.',403);
  if(array_key_exists('purchase_unit_ids',$data)){
   $ids=$data['purchase_unit_ids'];if(!is_array($ids)||count($ids)>500||!array_is_list($ids))throw new DomainException('Ursprüngliche Stückauswahl vollständig prüfen.',422);foreach($ids as$value)if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Stückauswahl vollständig prüfen.',422);if(count(array_unique($ids))!==count($ids))throw new DomainException('Ursprüngliche Stückauswahl vollständig prüfen.',422);
  }
  if(array_key_exists('purchase_units',$data)){
   $rows=$data['purchase_units'];if(!is_array($rows)||!$rows||count($rows)>500)throw new DomainException('Ursprüngliche Stückangaben des Zugangs vollständig prüfen.',422);
   foreach($rows as$row){if(!is_array($row)||array_diff(array_keys($row),['serial_number','unit_value','value_note'])||!array_key_exists('serial_number',$row))throw new DomainException('Ursprüngliche Stückangaben des Zugangs vollständig prüfen.',422);foreach($row as$value)if(!is_string($value)||preg_match('//u',$value)!==1)throw new DomainException('Ursprüngliche Stückangaben des Zugangs vollständig prüfen.',422);if(!$finance->canRead()&&array_intersect(array_keys($row),['unit_value','value_note']))throw new DomainException('Stückpreise benötigen Finanzberechtigung.',403);if($finance->canRead())foreach(['unit_value','value_note']as$field)if(!array_key_exists($field,$row))throw new DomainException('Ursprüngliche Stückangaben des Zugangs vollständig prüfen.',422);}
  }
  InventoryPieceForm::shape($body);return$id;
 }
 public static function state(PDO $db,string $actor,array $body,array $query):array
 {
  $requested=($body['action']??null)==='inventory-move';$state=['item'=>null,'blocked'=>false,'original'=>$requested?self::original($body):null,'status'=>200,'error'=>'','key'=>null,'cash'=>false,'cash_account'=>'','can_cash_move'=>true,'route'=>['view'=>'inventory','tab'=>'items']];
  foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group','piece_page','stock_edit']as$name)if(is_string($query[$name]??null))$state['route'][$name]=$query[$name];
  try{$inventory=new Inventory($db,$actor);$finance=new Finance($db,$actor);$state['item']=InventoryItemForm::opened($inventory,$query['item']??null);if($state['item']['id']!==($query['item']??null))throw new DomainException('Lagerbewegung am ursprünglich geöffneten Gegenstand erneut prüfen.',409);$state['route']['item']=$state['item']['id'];if($requested)self::target($body,$query,$inventory,$finance,$db,$actor);$cash=self::cashAccount($db,$actor,$state['item']['id']);$state['cash']=$cash!=='';$state['cash_account']=$finance->canRead()?$cash:'';$state['can_cash_move']=$cash===''||$finance->canRead();$state['key']=$requested?self::key($body['request_key']):bin2hex(random_bytes(16));}
  catch(\Throwable $failure){$state['blocked']=true;$state['status']=self::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Lagerbewegung konnte nicht bestätigt werden. Aktuellen Bestand neu prüfen.';}return$state;
 }
 private static function cashAccount(PDO $db,string $actor,string $id):string
 {
  $identity=Permissions::actor($db,$actor);$q=$db->prepare('SELECT cash_account FROM inventory_items WHERE club_id=? AND id=?');$q->execute([$identity['club_id'],$id]);$value=$q->fetchColumn();return $value===false||$value===null?'':(string)$value;
 }
 public static function status(\Throwable $failure):int
 {
  if(in_array($failure->getCode(),[403,404,409,422,503],true))return$failure->getCode();if(in_array($failure->getMessage(),['Die Übergabe einer Handkasse benötigt zusätzlich Finanz-Leserechte für den Zählabgleich.','Zählbestände und Wertnachweise benötigen Finanzberechtigung.','Stückpreise benötigen Finanzberechtigung.'],true))return 403;return InventoryLocationForm::status($failure);
 }
}
