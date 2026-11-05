<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Finance};

/** Complete metadata input and its opened source; stock operations remain separate. */
final class InventoryItemForm
{
 public const BASE=['name','category','unit','content_quantity','content_unit','kind','minimum','acquired_on','ownership','owner_id','responsible_id','active','condition_note','notes'];
 public const SCALAR=['code','serial_number','initial_quantity','home_location','home_location_version','source','unit_value','value_note','cash_account','counted','invoice_position','invoice_entry_version','purchase_stock_version','purchase_change_reason'];
 public const ROWS=['purchase_units','purchase_unit_updates'];
 public const META=['target','version','request_key','source_form','source_picker_kind','source_picker_page','source_picker_q','after_save'];
 public static function original(array $body):array
 {
  $out=array_intersect_key($body,array_flip(self::META))+InventoryPieceForm::original($body)+InventoryPieceCorrectionForm::original($body);if(array_key_exists('data',$body)){$out['data']=is_array($body['data'])?array_intersect_key($body['data'],array_flip([...self::BASE,...self::SCALAR,...self::ROWS])):$body['data'];if(is_array($out['data']))foreach(self::ROWS as$name)if(is_array($out['data'][$name]??null))$out['data'][$name]=array_map(static fn($row)=>is_array($row)?array_intersect_key($row,array_flip(['serial_number','unit_value','value_note'])):$row,$out['data'][$name]);}return $out;
 }
 public static function key(mixed $value):string
 {
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Gegenstands-Vorgangskennung vollständig prüfen.',422);return $value;
 }
 public static function applies(array $body,array $query):bool
 {
  return ($body['action']??null)==='inventory-item'||(($body['action']??null)==='inventory-source-browse'&&($body['source_form']??null)!=='individual')||(is_string($query['item']??null)&&$query['item']!=='')||(($query['new_item']??null)==='1'&&(($query['create_mode']??null)==='quantity'||isset($query['cash_source'])||isset($query['invoice_position'])));
 }
 public static function opened(Inventory $inventory,mixed $id):array
 {
  if(!is_string($id)||$id===''||strlen($id)>80||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Gegenstandskennung vollständig prüfen.',422);
  if(!$inventory->canRead())throw new DomainException('Inventar-Leserecht für Gegenstände erforderlich.',403);
  try{return $inventory->item($id);}catch(DomainException $failure){throw new DomainException('Gegenstand nicht verfügbar.',404,$failure);}
 }
 public static function target(array $body,array $query,Inventory $inventory,Finance $finance,PDO $db,string $actor):string
 {
  if(($body['action']??null)==='inventory-item'&&!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Gegenstandsvorgang erforderlich.',403);
  if(($body['action']??null)==='inventory-source-browse'&&(!$inventory->canRead()||!$finance->canRead()))throw new DomainException('Belegverknüpfungen benötigen eine eigene Finanzberechtigung.',403);
  if(($body['item_form_complete']??null)!=='1')throw new DomainException('Gegenstandsformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts gespeichert.',422);
  foreach(['target','version','request_key']as$name)if(!array_key_exists($name,$body)||!is_string($body[$name]))throw new DomainException('Ursprüngliche Gegenstandsangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach(['source_form','source_picker_kind','source_picker_page','source_picker_q','after_save']as$name)if(array_key_exists($name,$body)&&!is_string($body[$name]))throw new DomainException('Ursprüngliche Gegenstandsangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if(($body['action']??null)==='inventory-source-browse'&&($body['source_form']??null)!=='item')throw new DomainException('Ursprüngliche Gegenstandsquelle vollständig prüfen.',422);
  if(isset($body['source_form'])&&$body['source_form']!=='item')throw new DomainException('Ursprüngliche Gegenstandsquelle vollständig prüfen.',422);
  if(isset($body['source_picker_kind'])&&!in_array($body['source_picker_kind'],['expense','goods'],true))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  if(isset($body['source_picker_page'])&&(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$body['source_picker_page'])||(int)$body['source_picker_page']>1000000))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  if(isset($body['source_picker_q'])&&(strlen($body['source_picker_q'])>150||preg_match('//u',$body['source_picker_q'])!==1))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  $id=$body['target'];$version=$body['version'];$key=self::key($body['request_key']);
  if(strlen($id)>80||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483647)throw new DomainException('Ursprüngliche Gegenstandskennung und Fassung vollständig prüfen.',422);
  foreach(['view','tab','item','new_item','create_mode','source','cash_source','invoice_position','item_request']as$name)if(array_key_exists($name,$query)&&!is_string($query[$name]))throw new DomainException('Ursprüngliche Gegenstandsquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||(isset($query['item_request'])&&$query['item_request']!==$key))throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  if($id!==''){
   if(($query['item']??null)!==$id||array_key_exists('new_item',$query))throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
   $item=self::opened($inventory,$query['item']);if($item['id']!==$id)throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  }else{
   if(($query['new_item']??null)!=='1'||array_key_exists('item',$query)||$version!=='0'||!(($query['create_mode']??null)==='quantity'||($query['cash_source']??'')!==''||($query['invoice_position']??'')!==''))throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);$item=null;
  }
  $data=$body['data']??null;if(!is_array($data)||array_diff(array_keys($data),[...self::BASE,...self::SCALAR,...self::ROWS]))throw new DomainException('Ursprüngliche Gegenstandsangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  $required=self::BASE;if($item){$required[]='code';if($item['tracking_mode']==='individual')$required[]='serial_number';}elseif(($query['cash_source']??'')==='')array_push($required,'initial_quantity','home_location');
  if($finance->canRead()){array_push($required,'source','value_note');if(empty($item['has_purchase_units']))$required[]='unit_value';if($finance->cashEnabled()||(!$item&&($query['cash_source']??'')!==''))$required[]='cash_account';}
  foreach($required as$name)if(!array_key_exists($name,$data))throw new DomainException('Ursprüngliche Gegenstandsangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach([...self::BASE,...self::SCALAR]as$name)if(array_key_exists($name,$data)&&(!is_string($data[$name])||preg_match('//u',$data[$name])!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$data[$name])))throw new DomainException('Ursprüngliche Gegenstandsangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach(['minimum'=>0,'content_quantity'=>1,'initial_quantity'=>0,'purchase_stock_version'=>1,'home_location_version'=>1]as$field=>$minimum)if(array_key_exists($field,$data)&&(!preg_match('/\A[0-9]{1,7}\z/D',$data[$field])||(int)$data[$field]<$minimum||(int)$data[$field]>1000000))throw new DomainException('Ursprüngliche Mengen- und Versionsangaben vollständig prüfen.',422);
  if($data['acquired_on']!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['acquired_on']);if(!$date||$date->format('Y-m-d')!==$data['acquired_on']||!checkdate((int)substr($data['acquired_on'],5,2),(int)substr($data['acquired_on'],8,2),(int)substr($data['acquired_on'],0,4)))throw new DomainException('Ursprüngliches Anschaffungsdatum vollständig prüfen.',422);}
  if(!in_array($data['kind'],['reusable','consumable'],true)||!in_array($data['ownership'],['club','third_party'],true)||!in_array($data['active'],['0','1'],true))throw new DomainException('Ursprüngliche Verwendung, Eigentum und Status vollständig prüfen.',422);
  if(!$finance->canRead()&&array_intersect(array_keys($data),['source','unit_value','value_note','cash_account','counted','invoice_position','invoice_entry_version']))throw new DomainException('Werte und Kassenverknüpfungen benötigen Finanzberechtigung.',403);
  if(isset($data['source'])&&!in_array($data['source'],['keep','none'],true)&&!preg_match('/\A(?:expense|goods):[^\x00-\x1f\x7f]{1,80}\z/uD',$data['source']))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  foreach(self::ROWS as$name)if(array_key_exists($name,$data)){
   if(!is_array($data[$name])||!$data[$name]||count($data[$name])>500)throw new DomainException('Ursprüngliche Stückangaben vollständig prüfen.',422);
   foreach($data[$name]as$row){if(!is_array($row)||array_diff(array_keys($row),['serial_number','unit_value','value_note'])||!array_key_exists('serial_number',$row))throw new DomainException('Ursprüngliche Stückangaben vollständig prüfen.',422);foreach($row as$value)if(!is_string($value)||preg_match('//u',$value)!==1)throw new DomainException('Ursprüngliche Stückangaben vollständig prüfen.',422);if($name==='purchase_unit_updates'&&!$finance->canWrite()&&array_intersect(array_keys($row),['unit_value','value_note']))throw new DomainException('Stückpreise ändern benötigt Finanz-Bearbeitungsrecht.',403);if(!$finance->canRead()&&array_intersect(array_keys($row),['unit_value','value_note']))throw new DomainException('Stückpreise benötigen Finanzberechtigung.',403);if(($name==='purchase_units'&&$finance->canRead())||($name==='purchase_unit_updates'&&$finance->canWrite()))foreach(['unit_value','value_note']as$field)if(!array_key_exists($field,$row))throw new DomainException('Ursprüngliche Stückangaben vollständig prüfen.',422);}
   if($name==='purchase_unit_updates')foreach(['purchase_stock_version','purchase_change_reason']as$field)if(!array_key_exists($field,$data))throw new DomainException('Ursprüngliche Stückangaben vollständig prüfen.',422);
  }
  if(array_key_exists('after_save',$body)&&(!is_string($body['after_save'])||$body['after_save']!=='invoice-upload'))throw new DomainException('Ursprünglichen Folgeschritt vollständig prüfen.',422);
  if(($body['after_save']??null)==='invoice-upload'&&$id!=='')throw new DomainException('Ursprünglichen Folgeschritt vollständig prüfen.',409);
  if(($body['after_save']??null)==='invoice-upload'&&!$finance->canWrite())throw new DomainException('Finanz-Bearbeitungsrecht für den anschließenden Rechnungseingang erforderlich.',403);
  if(!$item){$context=self::context($inventory,$finance,$query,$db,$actor);if(isset($context['invoice'])&&$key!==$context['invoice']['key'])throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);if(($query['source']??'')!==''&&($data['source']??null)!==$query['source'])throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);if(($query['cash_source']??'')!==''&&($data['cash_account']??null)!==$query['cash_source'])throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);if(($query['invoice_position']??'')!==''&&(($data['invoice_position']??null)!==$query['invoice_position']||!array_key_exists('invoice_entry_version',$data)))throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);}
  InventoryPieceForm::shape($body);InventoryPieceCorrectionForm::guard($body,$query,$item,$db,$actor);return $id;
 }
 public static function context(Inventory $inventory,Finance $finance,array $query,PDO $db,string $actor):array
 {
  foreach(['new_item','create_mode','source','cash_source','invoice_position','item_request']as$name)if(array_key_exists($name,$query)&&!is_string($query[$name]))throw new DomainException('Ursprüngliche Gegenstandsquelle vollständig prüfen.',422);
  $context=[];$source=$query['source']??'';$cash=$query['cash_source']??'';
  if(($source!==''||$cash!==''||($query['invoice_position']??'')!=='')&&!$finance->canRead())throw new DomainException('Werte und Kassenverknüpfungen benötigen Finanzberechtigung.',403);
  if($source!==''&&!preg_match('/\A(?:expense|goods):[^\x00-\x1f\x7f]{1,80}\z/uD',$source))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  if($source!==''&&($query['invoice_position']??'')==='')$context['source']=(new \Kulturhus\Verein\InventoryFinancialSources($db,$actor))->original($source);
  if($cash!==''){$account=$finance->account($cash);if($account['kind']!=='cash')throw new DomainException('Die ausgewählte Handkasse ist nicht verfügbar. Bitte den Vorgang über Bank & Kasse erneut öffnen.',422);$context['cash']=$account;}
  if(($query['invoice_position']??'')!==''){
   if($cash!==''||!preg_match('/\A[1-9][0-9]?\z/D',$query['invoice_position'])||!str_starts_with($source,'expense:'))throw new DomainException('Bitte den Inventarentwurf aus der Rechnungsposition öffnen.',422);
   $context['invoice']=\Kulturhus\Verein\InvoiceInventoryDraft::position($db,$actor,substr($source,8),(int)$query['invoice_position']);
  }
  return $context;
 }
 public static function saved(PDO $db,string $actor,string $key,array $query=[]):?array
 {
  $inventory=new Inventory($db,$actor);if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Gegenstandsvorgang erforderlich.',403);$key=self::key($key);$identity=\Kulturhus\Verein\Permissions::actor($db,$actor);
  $q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$identity['club_id'],$key]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)return null;$details=json_decode($event['details'],true);
  if($event['actor_id']!==$identity['id']||$event['action']!=='item'||!is_array($details)||($details[0]??null)!==0)throw new DomainException('Diese Gegenstandskennung gehört zu einem anderen Vorgang.',409);
  $original=$details[1]??null;if(!is_array($original))throw new DomainException('Diese Gegenstandskennung gehört zu einem anderen Vorgang.',409);
  foreach(['source','cash_source','invoice_position']as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Gegenstandsquelle vollständig prüfen.',422);
  $source=$query['source']??'';$cash=$query['cash_source']??'';$ordinal=$query['invoice_position']??'';
  if(($source!==''||$cash!==''||$ordinal!=='')&&!(new Finance($db,$actor))->canRead())throw new DomainException('Werte und Kassenverknüpfungen benötigen Finanzberechtigung.',403);
  if(($source!==''&&(($original['source_kind']??'').':'.($original['source_id']??''))!==$source)||($cash!==''&&($original['cash_account']??null)!==$cash)||($ordinal!==''&&($original['invoice_position']??null)!==$ordinal)||($ordinal===''&&array_key_exists('invoice_position',$original)))throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
  return self::opened($inventory,$event['object_id']);
 }
 public static function state(PDO $db,string $actor,array $body,array $query):array
 {
  $i=new Inventory($db,$actor);$f=new Finance($db,$actor);$requested=($body['action']??null)==='inventory-item'||(($body['action']??null)==='inventory-source-browse'&&($body['source_form']??null)!=='individual');
  $state=['item'=>null,'blocked'=>false,'original'=>$requested?self::original($body):null,'status'=>200,'error'=>'','key'=>null,'saved'=>null,'redirect'=>null,'context'=>[],'route'=>['view'=>'inventory','tab'=>'items']];
  foreach(['return_where','return_assets','where_page','asset_page','item_page','q','member','location','state','category','status','group','piece_page']as$name)if(is_string($query[$name]??null))$state['route'][$name]=$query[$name];
  try{
   if(array_key_exists('item',$query)){$state['item']=self::opened($i,$query['item']);if($state['item']['id']!==$query['item'])throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);$state['route']['item']=$state['item']['id'];}
   elseif(($query['new_item']??null)==='1'){
    if(!$i->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Gegenstandsvorgang erforderlich.',403);
    $firstKey=$query['item_request']??($requested?($body['request_key']??null):null);
    if($firstKey!==null){$state['key']=self::key($firstKey);$state['saved']=self::saved($db,$actor,$state['key'],$query);if($state['saved']){$state['blocked']=true;if(!$requested)$state['redirect']=['view'=>'inventory','tab'=>'items','item'=>$state['saved']['id']];return $state;}}
    $state['context']=self::context($i,$f,$query,$db,$actor);foreach(['new_item','create_mode','source','cash_source','invoice_position']as$name)if(isset($query[$name]))$state['route'][$name]=$query[$name];
   }
   if($state['item']){try{$piecePage=InventoryPieceCorrectionForm::page($query,$state['item']);}catch(\Throwable $failure){if(!$requested)$state['original']=['query'=>['piece_page'=>$query['piece_page']??null]];throw $failure;}if(isset($query['piece_page']))$state['route']['piece_page']=(string)$piecePage;}
   if($requested)self::target($body,$query,$i,$f,$db,$actor);
   $candidate=$requested?($body['request_key']??null):($query['item_request']??null);$state['key']=$candidate!==null?self::key($candidate):($state['context']['invoice']['key']??bin2hex(random_bytes(16)));
   if(!$state['item']&&($query['new_item']??null)==='1'){
    if(isset($state['context']['invoice'])&&$state['key']!==$state['context']['invoice']['key'])throw new DomainException('Gegenstandsangaben am ursprünglich geöffneten Gegenstand erneut prüfen.',409);
    $state['saved']=($state['context']['invoice']['existing']??'')!==''?self::opened($i,$state['context']['invoice']['existing']):self::saved($db,$actor,$state['key'],$query);
    if($state['saved']){$state['blocked']=true;if(!$requested)$state['redirect']=['view'=>'inventory','tab'=>'items','item'=>$state['saved']['id']];}
    else{$state['route']['item_request']=$state['key'];if(!$requested&&!isset($query['item_request']))$state['redirect']=$state['route'];}
   }
  }catch(\Throwable $failure){$state['blocked']=true;$state['status']=self::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Gegenstand konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';}
  return $state;
 }
 public static function status(\Throwable $failure):int
 {
  if(in_array($failure->getCode(),[403,404,409,422,503],true))return $failure->getCode();
  if(in_array($failure->getMessage(),['Werte und Kassenverknüpfungen benötigen Finanzberechtigung.','Belegverknüpfungen benötigen eine eigene Finanzberechtigung.','Stückpreise ändern benötigt Finanz-Bearbeitungsrecht.','Stückpreise benötigen Finanzberechtigung.','Finanz- und Inventar-Schreibrecht erforderlich.'],true))return 403;
  return InventoryLocationForm::status($failure);
 }
}
