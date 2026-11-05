<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Finance};

/** Prepares physical unit details in this response. Never writes stock or money. */
final class InventoryPieceForm
{
 public static function limit(bool $finance):int
 {
  $max=(int)ini_get('max_input_vars');return max(0,min(250,$max>0?intdiv(max(0,$max-200),$finance?3:1):250));
 }
 public static function original(array $body):array{return array_intersect_key($body,array_flip(['piece_enabled','piece_step']));}
 public static function shape(array $body):void
 {
  foreach(['piece_enabled','piece_step','piece_form_complete']as$field)if(array_key_exists($field,$body)&&!is_string($body[$field]))throw new DomainException('Ursprüngliche Stückvorbereitung vollständig prüfen.',422);
  if(isset($body['piece_enabled'])&&$body['piece_enabled']!=='1')throw new DomainException('Ursprüngliche Stückvorbereitung vollständig prüfen.',422);
  if(isset($body['piece_step'])&&!in_array($body['piece_step'],['prepare','discard'],true))throw new DomainException('Ursprüngliche Stückvorbereitung vollständig prüfen.',422);
  if(array_intersect(array_keys($body),['piece_enabled','piece_step','piece_form_complete'])&&($body['piece_form_complete']??null)!=='1')throw new DomainException('Stückformular unvollständig angekommen. Angaben neu prüfen; es wurde nichts gespeichert.',422);
  $rows=$body['data']['purchase_units']??null;
  if($rows!==null&&(!is_array($rows)||!array_is_list($rows)))throw new DomainException('Ursprüngliche Stückvorbereitung vollständig prüfen.',422);
  if($rows!==null&&isset($body['piece_form_complete'])&&!isset($body['piece_enabled'])&&($body['piece_step']??null)!=='discard')throw new DomainException('Vorbereitete Stückangaben bleiben erhalten. Zum Entfernen ausdrücklich „Alle Stückangaben verwerfen“ wählen.',422);
 }
 public static function prepare(Inventory $inventory,Finance $finance,PDO $db,string $actor,array $body,array $query,string $mode):array
 {
  self::shape($body);$data=$body['data'];
  if(($body['piece_step']??null)==='discard'){unset($data['purchase_units']);return ['data'=>$data,'enabled'=>false];}
  if(($body['piece_step']??null)!=='prepare')throw new DomainException('Ursprüngliche Stückvorbereitung vollständig prüfen.',422);
  self::eligible($inventory,$body,$mode);
  if($mode==='create'){
   if(InventoryItemForm::saved($db,$actor,$body['request_key'],$query)!==null)throw new DomainException('Dieser Gegenstand wurde bereits angelegt.',409);
   $count=(int)$data['initial_quantity'];$location=$data['home_location'];
  }else{
   $item=$inventory->item($body['target']);
   if((string)$item['stock_version']!==$body['version'])throw new DomainException('Der Bestand wurde inzwischen geändert. Ursprüngliche Stückangaben behalten und aktuellen Bestand neu prüfen.',409);
   $location=$data['location'];$count=(int)$data['quantity'];
   if($data['kind']==='count')$count-=(int)(array_column($item['stock'],'shelf','location_id')[$location]??0);
   if(!$item['has_purchase_units']&&array_sum(array_map(static fn(array$r):int=>(int)$r['shelf']+(int)$r['lent']+(int)$r['blocked'],$item['stock']))>0)throw new DomainException('Vorhandenen Mengenbestand zuerst vollständig mit Stückdetails erfassen; nur neue Stücke zu ergänzen wäre keine vollständige Bewertung.',422);
  }
  try{$place=$inventory->location($location);}catch(DomainException $failure){throw new DomainException('Ausgewählter Lagerort nicht mehr verfügbar – bitte neu auswählen',404,$failure);}if(!$place['active'])throw new DomainException('Archivierte Einträge können nicht verwendet werden.',422);
  if($count<1||$count>self::limit($finance->canRead()))throw new DomainException('Für Stückdetails eine positive Menge innerhalb der angezeigten Höchstzahl wählen.',422);
  $rows=$data['purchase_units']??[];
  if(count($rows)>$count)throw new DomainException('Die neue Menge ist kleiner als die vorbereiteten Stückzeilen. Angaben bleiben erhalten; zum Neubeginn ausdrücklich alle Stückangaben verwerfen.',422);
  while(count($rows)<$count)$rows[]=$finance->canRead()?['serial_number'=>'','unit_value'=>$mode==='create'?($data['unit_value']??''):'','value_note'=>'']:['serial_number'=>''];
  $data['purchase_units']=$rows;return ['data'=>$data,'enabled'=>true];
 }
 public static function save(Inventory $inventory,array $body,string $mode):void
 {
  self::shape($body);if(!isset($body['piece_enabled'])&&!isset($body['data']['purchase_units']))return;
  self::eligible($inventory,$body,$mode);
  if(empty($body['data']['purchase_units']))throw new DomainException('Stückzeilen zuerst vorbereiten und für jedes Stück prüfen. Es wurde nichts gespeichert.',422);
  if($mode==='move'&&$body['data']['kind']==='count'){
   $item=$inventory->item($body['target']);
   // An already committed replay still belongs to the model's original event.
   if((string)$item['stock_version']===$body['version']&&(int)$body['data']['quantity']<=(int)(array_column($item['stock'],'shelf','location_id')[$body['data']['location']]??0))throw new DomainException('Vorbereitete Stückzeilen benötigen einen positiven Zugang. Vor einer Verringerung alle Stückangaben ausdrücklich verwerfen.',422);
  }
 }
 private static function eligible(Inventory $inventory,array $body,string $mode):void
 {
  $data=$body['data'];
  if($mode==='create')$ok=$body['target']===''&&($data['cash_account']??'')==='';
  else{$item=$inventory->item($body['target']);$ok=$item['tracking_mode']==='quantity'&&empty($item['cash_account'])&&$item['active']&&in_array($data['kind'],['receive','count'],true);}
  if(!$ok)throw new DomainException('Stückzeilen gehören nur zum Anfangsbestand oder zu einem Zugang normaler Mengenartikel. Vorbereitete Angaben ausdrücklich verwerfen, bevor der Vorgang gewechselt wird.',422);
 }
}
