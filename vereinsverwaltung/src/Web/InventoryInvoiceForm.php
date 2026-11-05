<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{Documents,Finance,Inventory,InventoryInvoiceUpload,LiveUploads,Permissions};
use Kulturhus\Verein\Infrastructure\Database\Transactions;
require_once dirname(__DIR__).'/Inventory/InventoryInvoiceUpload.php';

/** The existing financial upload remains one whole decision at the opened item. */
final class InventoryInvoiceForm
{
 public const HEADER=['action','return_context','inventory_invoice_item','inventory_invoice_version','request_key','scope','kind','title','document_date','document_confirm','inventory_invoice_complete'];
 public static function applies(array$body,array$query):bool{return array_key_exists('invoice_request',$query)||array_key_exists('invoice_q',$query)||($body['return_context']??null)==='inventory-invoice'||array_key_exists('inventory_invoice_complete',$body);}
 public static function key(mixed$value):string
 {
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprünglichen Rechnungsdatei-Auftrag vollständig prüfen.',422);return$value;
 }
 private static function text(mixed$value,int$max):string
 {
  if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Angaben prüfen. Diese Eingabeform wird nicht übernommen.',422);return$value;
 }
 private static function date(mixed$value):string
 {
  $v=self::text($value,10);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Ursprüngliches vollständiges Dokumentdatum prüfen.',422);return$v;
 }
 public static function opened(PDO$db,string$actor,array$query,bool$write=false):array
 {
  foreach(['view','tab','item','new_item','invoice_request','invoice_q']as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Quelle der Rechnungsdateien vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||array_key_exists('new_item',$query))throw new DomainException('Rechnungsdateien am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  if(array_key_exists('invoice_q',$query))self::text($query['invoice_q'],150);$i=new Inventory($db,$actor);$f=new Finance($db,$actor);if($write?(!$i->canWrite()||!$f->canWrite()):(!$i->canRead()||!$f->canRead()))throw new DomainException('Aktuelle Finanz- und Inventarberechtigung für diese Rechnungsdateien erforderlich.',403);
  $item=InventoryItemForm::opened($i,$query['item']??null);(new Documents($db,$actor))->source('item',$item['id'],$write);if(array_key_exists('invoice_request',$query))self::key($query['invoice_request']);return$item;
 }
 /** Validate before the shared transport attaches files or scalar helpers discard shapes. */
 public static function guard(PDO$db,string$actor,array$body,array$query):array
 {
  $item=self::opened($db,$actor,$query,true);
  if(($body['inventory_invoice_complete']??null)!=='1')throw new DomainException('Rechnungsdatei-Auftrag unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde keine Datei zugeordnet.',422);
  foreach(['action','return_context','inventory_invoice_item','inventory_invoice_version','request_key','scope','kind','title','document_date']as$field)if(!is_string($body[$field]??null))throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Angaben prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if($body['action']!=='document-upload-many'||$body['return_context']!=='inventory-invoice'||$body['inventory_invoice_item']!==$item['id']||$body['scope']!=='finance'||$body['kind']!=='receipt')throw new DomainException('Rechnungsdateien am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  if(self::key($body['request_key'])!==self::key($query['invoice_request']??null))throw new DomainException('Rechnungsdateien unter ihrer ursprünglichen Vorgangsadresse erneut prüfen.',409);
  if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$body['inventory_invoice_version'])||(int)$body['inventory_invoice_version']>2147483647)throw new DomainException('Ursprüngliche Gegenstandsfassung der Rechnungsdateien vollständig prüfen.',422);
  self::text($body['title'],300);self::date($body['document_date']);
  // These fields would be discarded by the fixed financial item placement. Do not accept them silently.
  if(array_intersect(array_keys($body),['folder','links','notes','state','reason','document_groups','document_group_ids','document_group_links','document_group_folders','document_group_scopes','data']))throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Zuordnung prüfen. Keine Angabe wird still entfernt.',422);
  $tokens=null;
  if(array_key_exists('live_uploads',$body)){
   $live=$body['live_uploads'];if(!is_array($live)||array_keys($live)!==['document_files']||!is_array($live['document_files'])||!array_is_list($live['document_files'])||count($live['document_files'])<1||count($live['document_files'])>10||($body['document_confirm']??null)!=='1')throw new DomainException('Ursprüngliche vollständige vorbereitete Rechnungsdatei-Liste prüfen.',422);
   $tokens=$live['document_files'];$seen=[];foreach($tokens as$token){if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token)||isset($seen[$token]))throw new DomainException('Ursprüngliche vollständige vorbereitete Rechnungsdatei-Liste prüfen.',422);$seen[$token]=true;}
   foreach(['document_titles','document_dates']as$field){$values=$body[$field]??null;if(!is_array($values)||count($values)!==count($tokens)||array_diff_key($values,$seen)||array_diff_key($seen,$values))throw new DomainException('Titel und Datum jeder ursprünglichen Rechnungsdatei vollständig prüfen.',422);foreach($values as$value)$field==='document_dates'?self::date($value):self::text($value,300);}
  }elseif(array_key_exists('document_titles',$body)||array_key_exists('document_dates',$body)||array_key_exists('document_confirm',$body))throw new DomainException('Titel und Datum jeder ursprünglichen Rechnungsdatei vollständig prüfen.',422);
  $header=[];foreach(self::HEADER as$field)if(array_key_exists($field,$body))$header[$field]=$body[$field];$originalHash=hash('sha256',json_encode($header,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));return['item'=>$item,'tokens'=>$tokens,'data'=>['title'=>$body['title'],'kind'=>'receipt','document_date'=>$body['document_date'],'folder'=>'','scope'=>'finance','notes'=>'','state'=>'','reason'=>'','links'=>[],'inventory_invoice_original'=>$originalHash]];
 }
 /** The exact uploaded list is supported only when all PHP parallel arrays agree. */
 public static function files(array$files):array
 {
  $input=$files['document_files']??null;if(!is_array($input))throw new DomainException('Eine bis zehn vollständige Rechnungsdateien auswählen.',422);$names=$input['name']??null;if(!is_array($names)||!array_is_list($names)||count($names)<1||count($names)>10)throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Liste prüfen. Keine Datei wird gekürzt oder umnummeriert.',422);
  foreach(['name','type','tmp_name','error','size']as$field)if(!is_array($input[$field]??null)||array_keys($input[$field])!==array_keys($names))throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Liste prüfen. Keine Datei wird gekürzt oder umnummeriert.',422);
  foreach($names as$index=>$name){self::text($name,300);if($name===''||preg_match('/[\x00-\x1f\x7f]/',$name)||!is_string($input['type'][$index])||!is_int($input['error'][$index])||$input['error'][$index]!==UPLOAD_ERR_OK||!is_int($input['size'][$index])||$input['size'][$index]<1||!is_string($input['tmp_name'][$index])||!LiveUploads::file($input['tmp_name'][$index]))throw new DomainException('Rechnungsdateien nicht vollständig übertragen. Gespeicherten Stand prüfen und gegebenenfalls dieselben Dateien erneut auswählen.',422);}
  return$input;
 }
 /** Child request keys and revision one prove the saved whole batch without a new table. */
 public static function recorded(PDO$db,string$actor,string$item,string$key):?array
 {
  $who=Permissions::actor($db,$actor);$key=self::key($key);$keys=[];for($n=0;$n<10;$n++)$keys[]=substr(hash('sha256',$key.':'.$n),0,32);$q=$db->prepare('SELECT request_key,document_id FROM document_requests WHERE club_id=? AND request_key IN ('.implode(',',array_fill(0,10,'?')).')');$q->execute([$who['club_id'],...$keys]);$rows=array_column($q->fetchAll(PDO::FETCH_ASSOC),'document_id','request_key');if(!$rows)return null;$out=[];$documents=new Documents($db,$actor);
  foreach($keys as$index=>$child){if(!isset($rows[$child])){if(count($out)!==count($rows))throw new DomainException('Gespeicherte ursprüngliche Rechnungsdatei-Liste ist unvollständig. Aktuellen Dokumentenstand prüfen.',409);break;}
   $id=$rows[$child];$doc=$documents->document($id);$q=$db->prepare("SELECT details FROM document_history WHERE club_id=? AND document_id=? AND actor_id=? AND action='uploaded' ORDER BY sequence LIMIT 1");$q->execute([$who['club_id'],$id,$who['id']]);$history=$q->fetchColumn();$original=is_string($history)?json_decode($history,true):null;
   if($doc['created_by']!==$who['id']||!is_array($original)||($original['links']??null)!==['item:'.$item]||!in_array('finance',$original['scopes']??[],true))throw new DomainException('Diese Rechnungsdatei-Kennung gehört zu einem anderen ursprünglichen Auftrag.',409);
   if(!array_filter($doc['links'],static fn(array$link):bool=>$link['source_type']==='item'&&$link['source_id']===$item))throw new DomainException('Eine ursprüngliche Rechnungsdatei wurde inzwischen anders zugeordnet. Aktuellen Dokumentenstand prüfen.',409);
   $q=$db->prepare('SELECT name,mime,sha256,length(bytes) size FROM document_files WHERE club_id=? AND document_id=? AND revision=1');$q->execute([$who['club_id'],$id]);$file=$q->fetch(PDO::FETCH_ASSOC);if(!$file||($original['name']??null)!==$file['name']||($original['sha256']??null)!==$file['sha256'])throw new DomainException('Ursprüngliche Rechnungsdatei ist nicht vollständig nachgewiesen.',409);$out[]=['document'=>$doc,'original_file'=>$file];
  }return$out;
 }
 /** The club lock serializes initial decisions and changed-length simultaneous replays. */
 public static function upload(PDO$db,string$actor,array$body,array$query,array$files):array
 {
  return Transactions::run($db,static function()use($db,$actor,$body,$query,$files):array{
   $q=$db->prepare('SELECT c.id FROM clubs c JOIN actors a ON a.club_id=c.id WHERE a.id=? FOR UPDATE');$q->execute([$actor]);$q->fetchColumn();$checked=self::guard($db,$actor,$body,$query);$item=$checked['item'];if($checked['tokens']!==null)InventoryRecognitionForm::stageSelection($actor,$query,$checked['tokens']);$saved=self::recorded($db,$actor,$item['id'],$body['request_key']);
   if(!array_is_list($files)||count($files)<1||count($files)>10||($checked['tokens']!==null&&count($files)!==count($checked['tokens'])))throw new DomainException('Ursprüngliche vollständige Rechnungsdatei-Liste prüfen.',422);
   if($saved&&count($saved)!==count($files))throw new DomainException('Die bestätigte ursprüngliche Rechnungsdatei-Liste darf nicht erweitert oder verkürzt werden.',409);
   if(!$saved&&(int)$body['inventory_invoice_version']!==$item['version'])throw new DomainException('Gegenstand wurde inzwischen geändert. Ursprüngliche Rechnungsdateien erhalten und aktuellen Stand neu prüfen.',409);
   try{return(new InventoryInvoiceUpload($db,$actor))->upload($item['id'],$files,$checked['data'],$body['request_key']);}catch(DomainException$e){if($saved)throw new DomainException($e->getMessage(),409,$e);throw$e;}
  });
 }
 public static function transport(PDO$db,string$actor,array$body,array$query):void
 {
  $item=self::opened($db,$actor,$query,true);self::key($query['invoice_request']??null);if(($body['action']??null)==='live-upload-stage'){
   if(($body['upload_action']??null)!=='document-upload-many'||($body['upload_field']??null)!=='document_files')throw new DomainException('Ursprüngliches Rechnungsdatei-Uploadziel vollständig prüfen.',422);self::key($body['upload_key']??null);(new InventoryInvoiceUpload($db,$actor))->authorize($item['id']);
  }
 }
 /** Review known entered values; never disclose transport tokens, local paths or credentials. */
 public static function original(array$body,array$query,array$files,string$actor=''):array
 {
  $out=array_intersect_key($body,array_flip(self::HEADER));$out['query']=array_intersect_key($query,array_flip(['view','tab','item','invoice_request','new_item','invoice_q']));$input=$files['document_files']??null;if($input!==null)$out['files']=is_array($input)?array_intersect_key($input,array_flip(['name','type','size','error'])):$input;
  foreach(['document_titles','document_dates']as$field)if(array_key_exists($field,$body)){
   if(!is_array($body[$field])){$out[$field]=$body[$field];continue;}$values=[];foreach($body[$field]as$token=>$value){$row=['value'=>$value];if(is_string($token)&&preg_match('/\A[a-f0-9]{64}\z/D',$token)){
     try{$row['file']=LiveUploads::description($actor,$token,'document-upload-many','document_files');}catch(DomainException){$row['file']='Ursprüngliche vorbereitete Datei nicht mehr verfügbar.';}
    }else $row['unsupported_key']=$token;$values[]=$row;}$out[$field]=$values;
  }return$out;
 }
 public static function state(PDO$db,string$actor,array$body,array$query,array$files=[]):array
 {
  $posted=array_key_exists('inventory_invoice_complete',$body)||($body['return_context']??null)==='inventory-invoice'||(($body['action']??null)==='document-upload-many'&&array_key_exists('invoice_request',$query));$state=['item'=>null,'key'=>null,'saved'=>null,'blocked'=>false,'original'=>$posted?self::original($body,$query,$files,$actor):null,'status'=>200,'error'=>'','route'=>['view'=>'inventory','tab'=>'items']];
  foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group','invoice_q']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];
  try{$sourceQuery=$query;unset($sourceQuery['invoice_q']);$state['item']=self::opened($db,$actor,$sourceQuery);$state['route']['item']=$state['item']['id'];if(array_key_exists('invoice_q',$query))self::text($query['invoice_q'],150);$state['key']=array_key_exists('invoice_request',$query)?self::key($query['invoice_request']):bin2hex(random_bytes(16));if($posted)self::guard($db,$actor,$body,$query);$state['saved']=self::recorded($db,$actor,$state['item']['id'],$state['key']);if($state['saved'])$state['blocked']=true;
   if($posted&&!$state['saved']&&(int)$body['inventory_invoice_version']!==$state['item']['version'])throw new DomainException('Gegenstand wurde inzwischen geändert. Ursprüngliche Rechnungsdateien erhalten und aktuellen Stand neu prüfen.',409);
  }catch(\Throwable$e){$state['blocked']=true;$state['status']=self::status($e);$state['error']=$e instanceof DomainException?$e->getMessage():'Rechnungsdatei-Auftrag konnte nicht bestätigt werden. Gespeicherten Stand prüfen.';if(!$posted)$state['original']=['query'=>array_intersect_key($query,array_flip(['view','tab','item','invoice_request','new_item','invoice_q']))];}return$state;
 }
 public static function status(\Throwable$e):int{return InventoryItemForm::status($e);}
}
