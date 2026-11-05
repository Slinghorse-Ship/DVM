<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Finance,Inventory,Permissions};
/** Explicit editing of existing physical unit IDs. Preparation never changes their stock or value. */
final class InventoryPieceCorrectionForm
{
 public const FIELDS=['piece_correction_enabled','piece_correction_step','piece_correction_page','piece_correction_target_page','piece_correction_stock_version'];
 public static function original(array $body):array{return array_intersect_key($body,array_flip(self::FIELDS));}
 private static function number(mixed $value,int $maximum=1000000):int
 {
  if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>$maximum)throw new DomainException('Ursprüngliche Stückseite und Bestandsfassung vollständig prüfen.',422);return(int)$value;
 }
 public static function query(array $query):int{return array_key_exists('piece_page',$query)?self::number($query['piece_page']):1;}
 public static function limit():int{$limit=(int)ini_get('max_input_vars');return max(1,min(100,$limit>0?intdiv(max(3,$limit-200),3):100));}
 public static function page(array $query,array $item):int{return min(self::query($query),max(1,(int)ceil(count($item['purchase_units'])/self::limit())));}
 public static function rows(array $item,int $page):array{return array_slice($item['purchase_units'],($page-1)*self::limit(),self::limit());}
 public static function guard(array $body,array $query,?array $item,PDO $db,string $actor):void
 {
  foreach(self::FIELDS as$field)if(array_key_exists($field,$body)&&!is_string($body[$field]))throw new DomainException('Ursprüngliche Stückbearbeitung vollständig prüfen.',422);
  self::query($query);$updates=$body['data']['purchase_unit_updates']??null;$has=(bool)array_intersect(array_keys($body),self::FIELDS)||$updates!==null;
  if(!$item||!$item['purchase_units']){if(!$has)return;if(!$item||!$item['has_purchase_units'])throw new DomainException('Stückbearbeitung am ursprünglich geöffneten Gegenstand erneut prüfen.',409);}
  $page=self::number($body['piece_correction_page']??null);self::number($body['piece_correction_stock_version']??null,2147483647);
  if($page!==self::query($query))throw new DomainException('Stückbearbeitung auf der ursprünglich geöffneten Seite erneut prüfen.',409);
  if(isset($body['piece_correction_target_page']))self::number($body['piece_correction_target_page']);
  if(isset($body['piece_correction_enabled'])&&$body['piece_correction_enabled']!=='1')throw new DomainException('Ursprüngliche Stückbearbeitung vollständig prüfen.',422);
  $step=$body['piece_correction_step']??'';if(!in_array($step,['','open','discard','page:previous','page:next','page:show'],true))throw new DomainException('Ursprüngliche Stückbearbeitung vollständig prüfen.',422);
  if($updates!==null){
   if(!isset($body['piece_correction_enabled'])&&$step!=='discard')throw new DomainException('Vorbereitete Korrekturen bleiben erhalten. Zum Entfernen ausdrücklich „Stückkorrekturen verwerfen“ wählen.',422);
   if(!is_array($updates)||!$updates||count($updates)>self::limit())throw new DomainException('Ursprüngliche Stückbearbeitung vollständig prüfen.',422);
   foreach(array_keys($updates)as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Ursprüngliche Stückkennungen vollständig prüfen.',422);
   if(($body['data']['purchase_stock_version']??null)!==$body['piece_correction_stock_version'])throw new DomainException('Stückbearbeitung am ursprünglichen Bestandsstand erneut prüfen.',409);
  }
  $active=isset($body['piece_correction_enabled'])||$updates!==null||$step!=='';
  if(!$active||$step==='discard')return;
  // The existing transaction still decides whether an exact committed payload is a replay.
  $who=Permissions::actor($db,$actor);$q=$db->prepare("SELECT 1 FROM inventory_events WHERE club_id=? AND request_key=? AND actor_id=? AND action='item' AND object_id=?");$q->execute([$who['club_id'],$body['request_key'],$who['id'],$item['id']]);if($q->fetchColumn()){if($step!=='')throw new DomainException('Diese Stückkorrektur wurde bereits gespeichert. Aktuellen Gegenstand neu prüfen.',409);return;}
  if((string)$item['version']!==$body['version']||(string)$item['stock_version']!==$body['piece_correction_stock_version'])throw new DomainException('Die Gegenstands- oder Bestandsfassung wurde geändert. Ursprüngliche Stückkorrekturen behalten und aktuell neu prüfen.',409);
  if($updates!==null){$expected=array_column(self::rows($item,$page),'id');$actual=array_keys($updates);sort($expected);sort($actual);if($expected!==$actual)throw new DomainException('Stückkennungen passen nicht zur ursprünglich geöffneten Seite. Angaben neu prüfen.',409);}
 }
 public static function prepare(Inventory $inventory,Finance $finance,array $body,array $query):array
 {
  $data=$body['data'];$page=self::number($body['piece_correction_page']);$step=$body['piece_correction_step'];$stock=$body['piece_correction_stock_version'];
  if($step==='discard'){unset($data['purchase_unit_updates'],$data['purchase_stock_version'],$data['purchase_change_reason']);return ['data'=>$data,'enabled'=>false,'page'=>$page,'stock_version'=>$stock];}
  $item=$inventory->item($body['target']);if(!$item['purchase_units'])throw new DomainException('Keine aktiven Stückangaben verfügbar. Gegenstand neu prüfen.',409);if(!$item['active'])throw new DomainException('Archivierte Einträge können nicht verwendet werden.',422);
  if($step!=='open'){
   if(isset($body['piece_correction_enabled'])||isset($data['purchase_unit_updates']))throw new DomainException('Vor dem Seitenwechsel Stückkorrekturen speichern oder ausdrücklich verwerfen. Alle Angaben bleiben erhalten.',422);
   $pages=max(1,(int)ceil(count($item['purchase_units'])/self::limit()));$page=match($step){'page:previous'=>max(1,$page-1),'page:next'=>min($pages,$page+1),'page:show'=>min($pages,self::number($body['piece_correction_target_page']??null)),default=>throw new DomainException('Ursprüngliche Stückbearbeitung vollständig prüfen.',422)};return ['data'=>$data,'enabled'=>false,'page'=>$page,'stock_version'=>$stock];
  }
  if(!isset($data['purchase_unit_updates'])){
   $data['purchase_unit_updates']=[];foreach(self::rows($item,$page)as$row){$edit=['serial_number'=>$row['serial_number']];if($finance->canWrite())$edit+=['unit_value'=>$row['unit_value_cents']===null?'':number_format($row['unit_value_cents']/100,2,',',''),'value_note'=>$row['value_note']];$data['purchase_unit_updates'][$row['id']]=$edit;}
   $data['purchase_stock_version']=$stock;$data['purchase_change_reason']='';
  }
  return ['data'=>$data,'enabled'=>true,'page'=>$page,'stock_version'=>$stock];
 }
 public static function save(array $body):void
 {
  if(isset($body['piece_correction_enabled'])&&empty($body['data']['purchase_unit_updates']))throw new DomainException('Stückbearbeitung zuerst öffnen und die vorhandenen Stücke prüfen. Es wurde nichts gespeichert.',422);
 }
}
