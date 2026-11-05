<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Documents,Permissions};
/** An attachment belongs to the item actually opened and one complete, original request. */
final class InventoryFileForm
{
 public static function key(mixed$key):string{if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Dateivorgangskennung vollständig prüfen.',422);return$key;}
 public static function original(array$body,array$files):array
 {
  $out=array_intersect_key($body,array_flip(['action','target','version','request_key','inventory_file_complete']));if(array_key_exists('inventory_file',$files))$out['file']=is_array($files['inventory_file'])?array_intersect_key($files['inventory_file'],array_flip(['name','type','size','error'])):$files['inventory_file'];return$out;
 }
 public static function opened(Inventory$i,array$query):array
 {
  foreach(['view','tab','item','new_item','file_request']as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Dateiquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||array_key_exists('new_item',$query))throw new DomainException('Datei am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  return InventoryItemForm::opened($i,$query['item']??null);
 }
 public static function guard(PDO$db,string$actor,array$body,array$query,?Inventory$i=null):array
 {
  $i??=new Inventory($db,$actor);if(!$i->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Dateianhang erforderlich.',403);$item=self::opened($i,$query);
  if(($body['inventory_file_complete']??null)!=='1')throw new DomainException('Dateiformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts zugeordnet.',422);
  foreach(['action','target','version','request_key']as$field)if(!is_string($body[$field]??null))throw new DomainException('Ursprünglichen Dateiauftrag vollständig prüfen.',422);
  if($body['action']!=='inventory-file'||$body['target']!==$item['id'])throw new DomainException('Datei am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$body['version'])||(int)$body['version']>2147483647||array_key_exists('data',$body))throw new DomainException('Ursprünglichen Dateiauftrag vollständig prüfen.',422);
  if(self::key($body['request_key'])!==self::key($query['file_request']??null))throw new DomainException('Datei unter ihrer ursprünglichen Vorgangsadresse erneut prüfen.',409);
  return$item;
 }
 public static function file(array$files):array
 {
  $file=$files['inventory_file']??null;if(!is_array($file)||!is_int($file['error']??null)||$file['error']!==UPLOAD_ERR_OK||!is_string($file['name']??null)||$file['name']===''||strlen($file['name'])>300||preg_match('//u',$file['name'])!==1||preg_match('/[\x00-\x1f\x7f]/',$file['name'])||!is_string($file['tmp_name']??null)||!is_int($file['size']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name']))throw new DomainException('Datei konnte nicht hochgeladen werden (maximal 2 MB).',422);return$file;
 }
 public static function recorded(PDO$db,string$actor,string$item,string$key):?array
 {
  $who=Permissions::actor($db,$actor);$q=$db->prepare('SELECT document_id FROM document_requests WHERE club_id=? AND request_key=?');$q->execute([$who['club_id'],self::key($key)]);$id=$q->fetchColumn();if($id===false)return null;
  $doc=(new Documents($db,$actor))->document($id);$q=$db->prepare("SELECT details FROM document_history WHERE club_id=? AND document_id=? AND actor_id=? AND action='uploaded' ORDER BY version LIMIT 1");$q->execute([$who['club_id'],$id,$who['id']]);$history=$q->fetchColumn();$original=is_string($history)?json_decode($history,true):null;
  if($doc['created_by']!==$who['id']||!is_array($original)||($original['links']??null)!==['item:'.$item])throw new DomainException('Diese Dateikennung gehört zu einem anderen ursprünglichen Auftrag.',409);
  if(!array_filter($doc['links'],static fn(array$link):bool=>$link['source_type']==='item'&&$link['source_id']===$item))throw new DomainException('Die ursprüngliche Dateizuordnung wurde inzwischen geändert. Aktuellen Dokumentenstand prüfen.',409);return$doc;
 }
 public static function state(PDO$db,string$actor,array$body,array$query,array$files=[]):array
 {
  $posted=($body['action']??null)==='inventory-file';$state=['item'=>null,'key'=>null,'saved'=>null,'blocked'=>false,'original'=>$posted?self::original($body,$files):null,'status'=>200,'error'=>'','route'=>['view'=>'inventory','tab'=>'items']];
  foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];
  try{$i=new Inventory($db,$actor);$state['item']=self::opened($i,$query);$state['route']['item']=$state['item']['id'];$state['key']=array_key_exists('file_request',$query)?self::key($query['file_request']):bin2hex(random_bytes(16));if($posted)self::guard($db,$actor,$body,$query,$i);$state['saved']=self::recorded($db,$actor,$state['item']['id'],$state['key']);}
  catch(\Throwable$e){$state['blocked']=true;$state['status']=self::status($e);$state['error']=$e instanceof DomainException?$e->getMessage():'Dateizuordnung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';if(!$posted)$state['original']=['query'=>array_intersect_key($query,array_flip(['view','tab','item','file_request','new_item']))];}return$state;
 }
 public static function status(\Throwable$e):int{return InventoryItemForm::status($e);}
}
