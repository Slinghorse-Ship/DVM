<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Documents,Finance,Inventory,InvoiceRecognition,LiveUploads};
/** Bind local recognition to the opened item and exact stored or privately prepared original. */
final class InventoryRecognitionForm
{
 public const FIELDS=['inventory_item','inventory_version','inventory_ocr_source','inventory_ocr_complete','doc','revision','token','upload_action','upload_field','upload_key'];
 public static function applies(array$b,array$q):bool{return($q['view']??null)==='inventory'||(($q['view']??null)!=='finance'&&array_key_exists('item',$q))||array_key_exists('inventory_item',$b)||array_key_exists('inventory_ocr_complete',$b);}
 private static function id(mixed$v,int$size):string{if(!is_string($v)||!preg_match('/\A[a-f0-9]{'.$size.'}\z/D',$v))throw new DomainException('Ursprüngliche Erkennungsquelle vollständig prüfen.',422);return$v;}
 private static function version(mixed$v):int{if(!is_string($v)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$v)||(int)$v>2147483647)throw new DomainException('Ursprüngliche Dateifassung vollständig prüfen. Keine aktuelle Fassung wird eingesetzt.',422);return(int)$v;}
 public static function opened(PDO$db,string$actor,array$q,bool$write):array
 {
  if(($q['invoice_recognition']??null)!=='1')throw new DomainException('Ursprüngliche Erkennungsquelle vollständig prüfen.',422);
  $item=InventoryInvoiceForm::opened($db,$actor,$q,$write);if($write&&!$item['active'])throw new DomainException('Archivierter Gegenstand: Vorhandene Ergebnisse bleiben lesbar; keine neue Erkennung starten.',409);return$item;
 }
 private static function document(PDO$db,string$actor,array$item,mixed$id,mixed$revision):array
 {
  $id=self::id($id,32);$revision=self::version($revision);$docs=new Documents($db,$actor);$doc=$docs->document($id);
  if(!in_array($doc['kind'],['receipt','invoice'],true)||!in_array('finance',$doc['scopes'],true)||!array_filter($doc['links'],static fn(array$l):bool=>($l['source_type']==='item'&&$l['source_id']===$item['id'])||($l['source_type']==='entry'&&!empty($item['source_entry'])&&$l['source_id']===$item['source_entry'])))throw new DomainException('Diese ursprüngliche Rechnung gehört nicht zum geöffneten Gegenstand.',409);
  if((int)$doc['revision']!==$revision)throw new DomainException('Die ursprüngliche Dateifassung ist nicht mehr aktuell. Original und heutigen Stand neu prüfen.',409);
  return['document'=>$id,'revision'=>$revision,'sha256'=>$docs->file($id,$revision)['sha256']];
 }
 public static function bindStage(string$token,string$item,string$request):void
 {
  self::id($token,64);self::id($item,32);self::id($request,32);if(!isset($_SESSION['live_uploads'][$token]))throw new DomainException('Private Vorbereitung ist nicht mehr verfügbar.',409);
  $_SESSION['live_uploads'][$token]['inventory_source']=['item'=>$item,'request'=>$request];
 }
 public static function stageSelection(string$actor,array$q,array$tokens):void
 {
  $item=self::id($q['item']??null,32);$request=self::id($q['invoice_request']??null,32);
  foreach($tokens as$token){$token=self::id($token,64);$r=$_SESSION['live_uploads'][$token]??null;if(!is_array($r)||($r['actor']??null)!==$actor||($r['inventory_source']??null)!==['item'=>$item,'request'=>$request])throw new DomainException('Die private Originaldatei gehört zu einem anderen Gegenstand oder Dateiauftrag. Ursprüngliche Vorbereitung neu prüfen.',409);LiveUploads::description($actor,$token,'document-upload-many','document_files');}
 }
 /** Earlier unbound private originals may be explicitly discarded, never adopted. */
 public static function removeStage(string$actor,array$q,mixed$token):void
 {
  $token=self::id($token,64);$r=$_SESSION['live_uploads'][$token]??null;
  if(is_array($r)&&!array_key_exists('inventory_source',$r)){LiveUploads::description($actor,$token,'document-upload-many','document_files');return;}
  self::stageSelection($actor,$q,[$token]);
 }
 public static function guard(PDO$db,string$actor,array$b,array$q):array
 {
  $item=self::opened($db,$actor,$q,true);
  foreach(['inventory_item','inventory_version','inventory_ocr_source','inventory_ocr_complete']as$k)if(!is_string($b[$k]??null))throw new DomainException('Erkennungsauftrag unvollständig. Die ursprünglichen Angaben bleiben erhalten.',422);
  if($b['inventory_ocr_complete']!=='1')throw new DomainException('Erkennungsauftrag unvollständig. Die ursprünglichen Angaben bleiben erhalten.',422);
  if($b['inventory_item']!==$item['id'])throw new DomainException('Rechnungserkennung am ursprünglich geöffneten Gegenstand neu prüfen.',409);
  if(self::version($b['inventory_version'])!==(int)$item['version'])throw new DomainException('Gegenstand wurde inzwischen geändert. Ursprünglichen Entwurf behalten und heutigen Stand neu prüfen.',409);
  if($b['inventory_ocr_source']==='document'){
   foreach(['token','upload_action','upload_field','upload_key']as$k)if(array_key_exists($k,$b))throw new DomainException('Ursprüngliche Erkennungsquelle vollständig prüfen.',422);
   if(self::id($b['doc']??null,32)!==self::id($q['doc']??null,32)||self::version($b['revision']??null)!==self::version($q['revision']??null))throw new DomainException('Rechnung unter ihrer ursprünglichen Datei- und Fassungsadresse neu prüfen.',409);
   return['item'=>$item,'source'=>'document']+self::document($db,$actor,$item,$b['doc'],$b['revision']);
  }
  if($b['inventory_ocr_source']==='staged'){
   if(array_key_exists('doc',$b)||array_key_exists('revision',$b)||array_key_exists('doc',$q)||array_key_exists('revision',$q))throw new DomainException('Ursprüngliche Erkennungsquelle vollständig prüfen.',422);
   if(($b['upload_action']??null)!=='document-upload-many'||($b['upload_field']??null)!=='document_files')throw new DomainException('Ursprüngliche private Rechnungsdatei vollständig prüfen.',422);
   $token=self::id($b['token']??null,64);$key=self::id($b['upload_key']??null,32);self::stageSelection($actor,$q,[$token]);$file=LiveUploads::snapshot($actor,$token,'document-upload-many','document_files',$key);
   return['item'=>$item,'source'=>'staged','token'=>$token,'key'=>$key,'sha256'=>$file['sha256']];
  }
  throw new DomainException('Ursprüngliche Erkennungsquelle vollständig prüfen.',422);
 }
 public static function run(PDO$db,string$actor,array$b,array$q):array
 {
  $s=self::guard($db,$actor,$b,$q);$service=new InvoiceRecognition($db,$actor);$result=$s['source']==='document'?$service->runInventory($s['document'],$s['revision'],$s['item']['id']):$service->runStaged($s['token'],'document-upload-many','document_files',$s['key'],$s['item']['id']);
  $fresh=self::guard($db,$actor,$b,$q);if($fresh['sha256']!==$s['sha256']||($result['sha256']??null)!==$s['sha256'])throw new DomainException('Die Originaldatei wurde während der Erkennung geändert. Heutigen Stand neu prüfen.',409);return$result;
 }
 public static function result(PDO$db,string$actor,array$q):?array
 {
  $item=self::opened($db,$actor,$q,false);if(($q['inventory_ocr_read']??null)!=='1')throw new DomainException('Ursprüngliche Ergebnisadresse vollständig prüfen.',422);
  $s=self::document($db,$actor,$item,$q['doc']??null,$q['revision']??null);$result=(new InvoiceRecognition($db,$actor))->result($s['document']);if($result!==null&&(($result['revision']??null)!==$s['revision']||($result['sha256']??null)!==$s['sha256']))throw new DomainException('Das Ergebnis gehört nicht zur ursprünglichen Dateifassung.',409);return$result;
 }
 public static function original(array$b,array$q):array
 {
  $out=['query'=>array_intersect_key($q,array_flip(['view','tab','item','invoice_recognition','doc','revision','inventory_ocr_read'])),'fields'=>array_intersect_key($b,array_flip(['inventory_item','inventory_version','inventory_ocr_source','inventory_ocr_complete','doc','revision','upload_action','upload_field']))];if(array_key_exists('token',$b))$out['private_preparation']='retained_without_disclosing_token';return$out;
 }
}
