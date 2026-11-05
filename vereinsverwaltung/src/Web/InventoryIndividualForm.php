<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Finance,InventoryFinancialSources,Permissions};
/** Whole source-bound preparation, review and creation of separate physical items. */
final class InventoryIndividualForm
{
 public const COMMON=['name'=>160,'category'=>100,'location'=>80,'acquired_on'=>10,'ownership'=>20,'owner_id'=>80,'responsible_id'=>80,'condition_note'=>1000,'notes'=>4000,'source'=>140,'value_note'=>1000];
 public const UNIT=['name'=>160,'code'=>60,'serial_number'=>100,'acquired_on'=>10,'location'=>80,'unit_value'=>40,'source'=>140];
 public const META=['target','version','request_key','unit_count','price_suggestion','individual_step','source_form','source_picker_kind','source_picker_page','source_picker_q','confirmed'];
 public static function applies(array $body,array $query):bool
 {
  $action=$body['action']??null;if(in_array($action,['inventory-individuals','inventory-individual-draft'],true))return true;
  if($action==='inventory-source-browse')return ($body['source_form']??null)==='individual'||isset($body['common'])||isset($body['units']);
  if($action!==null)return false;
  return array_key_exists('new_item',$query)&&!array_key_exists('invoice_position',$query)&&!array_key_exists('cash_source',$query)&&($query['create_mode']??'individual')!=='quantity';
 }
 public static function original(array $body):array
 {
  $out=array_intersect_key($body,array_flip(self::META));if(array_key_exists('common',$body))$out['common']=is_array($body['common'])?array_intersect_key($body['common'],self::COMMON):$body['common'];if(array_key_exists('units',$body))$out['units']=is_array($body['units'])?array_map(static fn($r)=>is_array($r)?array_intersect_key($r,self::UNIT):$r,$body['units']):$body['units'];return$out;
 }
 public static function key(mixed $key):string
 {
  if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Einzelstück-Vorgangskennung vollständig prüfen.',422);return$key;
 }
 private static function text(mixed $value,int $limit):string
 {
  if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Ursprüngliche Einzelstückangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);return$value;
 }
 private static function number(mixed $value,int $maximum=50):int
 {
  if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)||(int)$value>$maximum)throw new DomainException('Ursprüngliche Einzelstückzahl vollständig prüfen.',422);return(int)$value;
 }
 private static function date(string $value):void
 {
  if($value==='')return;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))throw new DomainException('Ursprüngliches Anschaffungsdatum vollständig prüfen.',422);
 }
 private static function source(string $value,bool $inherit=false):void
 {
  if($value==='none'||($inherit&&$value==='inherit'))return;if(!preg_match('/\A(?:expense|goods):[^\x00-\x1f\x7f]{1,80}\z/uD',$value))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
 }
 private static function address(array $query):void
 {
  foreach(['view','tab','new_item','create_mode','source','individual_request','item','group','cash_source','invoice_position','item_request']as$name)if(array_key_exists($name,$query)&&!is_string($query[$name]))throw new DomainException('Ursprüngliche Einzelstückquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='items'||($query['new_item']??null)!=='1'||($query['create_mode']??'individual')!=='individual'||array_intersect(array_keys($query),['item','group','cash_source','invoice_position','item_request']))throw new DomainException('Einzelstückanlage an der ursprünglich geöffneten Vorgangsadresse erneut prüfen.',409);
 }
 public static function context(array $query,Inventory $inventory,Finance $finance,PDO $db,string $actor):array
 {
  if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diese Einzelstückanlage erforderlich.',403);
  self::address($query);
  $source=$query['source']??'';if($source!==''){self::source($source);if($source==='none')throw new DomainException('Ursprüngliche Einzelstückquelle vollständig prüfen.',422);if(!$finance->canRead())throw new DomainException('Belegverknüpfungen benötigen eine eigene Finanzberechtigung.',403);$record=(new InventoryFinancialSources($db,$actor))->original($source);return ['source'=>$source,'record'=>$record];}return ['source'=>''];
 }
 public static function guard(array $body,array $query,Inventory $inventory,Finance $finance,PDO $db,string $actor):void
 {
  $context=self::context($query,$inventory,$finance,$db,$actor);
  if(($body['individual_form_complete']??null)!=='1')throw new DomainException('Einzelstückformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts gespeichert.',422);
  foreach(['target','version','request_key','unit_count','source_form']as$field)if(!array_key_exists($field,$body)||!is_string($body[$field]))throw new DomainException('Ursprüngliche Einzelstückangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if($body['target']!==''||$body['version']!=='0'||$body['source_form']!=='individual'||self::key($body['request_key'])!==self::key($query['individual_request']??null))throw new DomainException('Einzelstückanlage an der ursprünglich geöffneten Vorgangsadresse erneut prüfen.',409);
  self::number($body['unit_count']);foreach(['price_suggestion'=>40,'source_picker_kind'=>20,'source_picker_page'=>7,'source_picker_q'=>150,'individual_step'=>30,'confirmed'=>1,'review_token'=>64]as$field=>$limit)if(array_key_exists($field,$body))self::text($body[$field],$limit);
  if(isset($body['confirmed'])&&$body['confirmed']!=='1')throw new DomainException('Ursprüngliche Einzelstückbestätigung vollständig prüfen.',422);
  if(isset($body['source_picker_kind'])&&!in_array($body['source_picker_kind'],['expense','goods'],true))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);if(isset($body['source_picker_page']))self::number($body['source_picker_page'],1000000);
  if(isset($query['source_move'])&&(!is_string($query['source_move'])||!in_array($query['source_move'],['','search','previous','next'],true)))throw new DomainException('Ursprüngliche Belegauswahl vollständig prüfen.',422);
  $step=$body['individual_step']??(($body['action']??null)==='inventory-individual-draft'?'prepare':'');if(!in_array($step,['','prepare','add','prices','review','edit'],true)&&!preg_match('/\Aremove:(?:0|[1-9][0-9]?)\z/D',$step))throw new DomainException('Ursprünglichen Einzelstückschritt vollständig prüfen.',422);
  $common=$body['common']??null;if(!is_array($common)||array_diff(array_keys($common),array_keys(self::COMMON)))throw new DomainException('Ursprüngliche gemeinsame Einzelstückangaben vollständig prüfen.',422);
  $required=array_diff(array_keys(self::COMMON),['source','value_note']);if($finance->canRead())$required=array_keys(self::COMMON);foreach($required as$field)if(!array_key_exists($field,$common))throw new DomainException('Ursprüngliche gemeinsame Einzelstückangaben vollständig prüfen.',422);foreach($common as$field=>$value)self::text($value,self::COMMON[$field]);self::date($common['acquired_on']);if(!in_array($common['ownership'],['club','third_party'],true))throw new DomainException('Ursprüngliches Eigentum vollständig prüfen.',422);
  if(!$finance->canRead()&&(array_intersect(array_keys($common),['source','value_note'])||isset($body['price_suggestion'])))throw new DomainException('Werte und Belegverknüpfungen benötigen Finanzberechtigung.',403);
  if(isset($common['source']))self::source($common['source']);if($context['source']!==''&&($common['source']??null)!==$context['source'])throw new DomainException('Einzelstückanlage an der ursprünglich geöffneten Belegquelle erneut prüfen.',409);
  $rows=$body['units']??[];if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new DomainException('Ursprüngliche Einzelstückzeilen vollständig prüfen. Keine Zeile wird still entfernt.',422);
  foreach($rows as$row){if(!is_array($row)||array_diff(array_keys($row),array_keys(self::UNIT)))throw new DomainException('Ursprüngliche Einzelstückzeilen vollständig prüfen. Keine Zeile wird still entfernt.',422);$required=$finance->canRead()?array_keys(self::UNIT):array_diff(array_keys(self::UNIT),['unit_value','source']);foreach($required as$field)if(!array_key_exists($field,$row))throw new DomainException('Ursprüngliche Einzelstückzeilen vollständig prüfen. Keine Zeile wird still entfernt.',422);foreach($row as$field=>$value)self::text($value,self::UNIT[$field]);self::date($row['acquired_on']);if(!$finance->canRead()&&array_intersect(array_keys($row),['unit_value','source']))throw new DomainException('Werte und Belegverknüpfungen benötigen Finanzberechtigung.',403);if(isset($row['source']))self::source($row['source'],true);}
 }
 public static function draft(array $body,bool $finance):array
 {
  $out=['common'=>$body['common'],'units'=>$body['units']??[],'unit_count'=>$body['unit_count'],'price_suggestion'=>$body['price_suggestion']??'','review'=>false];$rows=$out['units'];$step=$body['individual_step']??'prepare';$wanted=self::number($body['unit_count']);
  if($step==='edit')return$out;
  if(str_starts_with($step,'remove:')){$index=(int)substr($step,7);if(count($rows)<=1||!array_key_exists($index,$rows))throw new DomainException('Mindestens ein vorbereitetes Einzelstück muss erhalten bleiben.',422);array_splice($rows,$index,1);$out['unit_count']=(string)count($rows);}
  elseif($step==='prices'){
   if(!$finance)throw new DomainException('Werte und Belegverknüpfungen benötigen Finanzberechtigung.',403);if($out['price_suggestion']==='')throw new DomainException('Zuerst einen dokumentierten Preisvorschlag eingeben.',422);self::price($out['price_suggestion']);foreach($rows as&$row)if($row['unit_value']==='')$row['unit_value']=$out['price_suggestion'];unset($row);
  }elseif(in_array($step,['prepare','add'],true)){
   if($step==='add')$wanted=count($rows)+1;if($wanted>50)throw new DomainException('Höchstens 50 Einzelstücke pro Vorgang.',422);if(trim($out['common']['name'])==='')throw new DomainException('Zuerst die gemeinsame Bezeichnung eintragen.',422);if($wanted<count($rows))throw new DomainException('Die gewünschte Anzahl ist kleiner als die vorbereiteten Zeilen. Angaben bleiben erhalten; nicht benötigte Stücke ausdrücklich einzeln entfernen.',422);
   while(count($rows)<$wanted){$name=$out['common']['name'].($wanted>1?' '.(count($rows)+1):'');if(mb_strlen($name)>160)throw new DomainException('Gemeinsame Bezeichnung für die nummerierten Einzelstücke kürzen.',422);$row=['name'=>$name,'code'=>'','serial_number'=>'','acquired_on'=>'','location'=>''];if($finance)$row+=['unit_value'=>$out['price_suggestion'],'source'=>'inherit'];$rows[]=$row;}$out['unit_count']=(string)count($rows);
  }else throw new DomainException('Ursprünglichen Einzelstückschritt vollständig prüfen.',422);
  $out['units']=$rows;return$out;
 }
 public static function price(string $value):?int
 {
  if($value==='')return null;if(!preg_match('/\A[0-9]{1,10}(?:[.,][0-9]{1,2})?\z/D',$value))throw new DomainException('Preis ohne Tausendertrennzeichen mit höchstens zwei Nachkommastellen eingeben.',422);return$value==='0'?0:Finance::cents($value);
 }
 public static function normalise(array $body):array
 {
  $common=$body['common'];$rows=$body['units']??[];if(!$rows||count($rows)!==self::number($body['unit_count']))throw new DomainException('Anzahl und vorbereitete Einzelstücke stimmen nicht überein. Angaben prüfen; keine Zeile wird still entfernt.',422);
  $source=static function(array $row):array{if(isset($row['source'])){[$kind,$id]=array_pad(explode(':',$row['source'],2),2,'');$row['source_kind']=$kind;$row['source_id']=$id;unset($row['source']);}ksort($row);return$row;};$common=$source($common);foreach($rows as&$row){if(($row['source']??null)==='inherit')unset($row['source']);foreach(['location','acquired_on']as$field)if($row[$field]==='')unset($row[$field]);$row=$source($row);}unset($row);return [$common,$rows];
 }
 public static function review(PDO $db,Inventory $inventory,Finance $finance,InventoryFinancialSources $sources,array $body,string $actor,string $csrf):array
 {
  [$common,$rows]=self::normalise($body);$preview=[];$serials=[];$codes=[];$who=Permissions::actor($db,$actor);foreach(['owner_id','responsible_id']as$field)if($common[$field]!==''){$q=$db->prepare('SELECT 1 FROM contacts WHERE club_id=? AND id=?');$q->execute([$who['club_id'],$common[$field]]);if(!$q->fetchColumn())throw new DomainException('Ausgewählte Person nicht mehr verfügbar – bitte neu auswählen',404);}if($common['ownership']==='third_party'&&$common['owner_id']==='')throw new DomainException('Bitte den Eigentümer der fremden Leihgabe zuordnen.',422);
  foreach($rows as$index=>$row){$data=array_replace($common,$row);if(trim($data['name'])==='')throw new DomainException('Bezeichnung jedes Einzelstücks vollständig prüfen.',422);if($data['location']==='')throw new DomainException('Bitte den tatsächlichen Lagerort auswählen.',422);$place=$inventory->location($data['location']);if(!$place['active'])throw new DomainException('Archivierte Einträge können nicht verwendet werden.',422);$ref=isset($data['source_kind'])&&$data['source_kind']!=='none'?$data['source_kind'].':'.$data['source_id']:'';$source=$ref!==''?$sources->original($ref):null;$price=isset($data['unit_value'])?self::price($data['unit_value']):null;if($price!==null&&trim($data['value_note']??'')==='')throw new DomainException('Bitte die Herkunft des dokumentierten Stückwerts angeben.',422);foreach(['code','serial_number']as$unique){$value=$data[$unique];if($value==='')continue;$sql=$unique==='code'?'SELECT 1 FROM inventory_items WHERE club_id=? AND code=?':"SELECT 1 FROM inventory_items WHERE club_id=? AND tracking_mode='individual' AND serial_number=?";$q=$db->prepare($sql);$q->execute([$who['club_id'],$value]);if($q->fetchColumn())throw new DomainException($unique==='code'?'Diese Inventarnummer ist bereits vergeben.':'Diese Seriennummer ist bereits einem anderen Einzelstück zugeordnet.',422);}$serial=$data['serial_number'];if($serial!==''&&isset($serials[$serial]))throw new DomainException('Dieselbe Seriennummer steht bei mehreren vorbereiteten Einzelstücken.',422);if($serial!=='')$serials[$serial]=true;$code=$data['code'];if($code!==''&&isset($codes[$code]))throw new DomainException('Dieselbe Inventarnummer steht bei mehreren vorbereiteten Einzelstücken.',422);if($code!=='')$codes[$code]=true;$preview[]=['data'=>$data,'location'=>$place,'source'=>$source,'unit_value_cents'=>$price];}
  return ['common'=>$body['common'],'units'=>$body['units'],'unit_count'=>$body['unit_count'],'price_suggestion'=>$body['price_suggestion']??'','review'=>true,'preview'=>$preview,'review_token'=>self::token($body,$actor,$csrf)];
 }
 public static function token(array $body,string $actor,string $csrf):string
 {
  [$common,$rows]=self::normalise($body);return hash_hmac('sha256',json_encode([$actor,$body['request_key'],$common,$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$csrf);
 }
 public static function confirm(array $body,string $actor,string $csrf):array
 {
  if(($body['confirmed']??null)!=='1'||!is_string($body['review_token']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$body['review_token'])||!hash_equals(self::token($body,$actor,$csrf),$body['review_token']))throw new DomainException('Einzelstücke zuerst vollständig prüfen. Geänderte Angaben erneut zur Prüfung öffnen.',409);return self::normalise($body);
 }
 public static function saved(PDO $db,string $actor,string $key,array $query):?array
 {
  $who=Permissions::actor($db,$actor);$q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$who['club_id'],self::key($key)]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;$details=json_decode($row['details'],true);if($row['actor_id']!==$who['id']||$row['action']!=='individual-create'||!is_array($details)||!is_array($details[0]??null))throw new DomainException('Diese Einzelstückkennung gehört zu einem anderen Vorgang.',409);$source=$query['source']??'';if(!is_string($source))throw new DomainException('Ursprüngliche Einzelstückquelle vollständig prüfen.',422);if($source!==''&&!(new Finance($db,$actor))->canRead())throw new DomainException('Belegverknüpfungen benötigen eine eigene Finanzberechtigung.',403);if($source!==''&&(($details[0]['source_kind']??'').':'.($details[0]['source_id']??''))!==$source)throw new DomainException('Einzelstückanlage an der ursprünglich geöffneten Belegquelle erneut prüfen.',409);return ['group'=>$row['object_id']];
 }
 public static function state(PDO $db,string $actor,array $body,array $query):array
 {
  $requested=in_array($body['action']??null,['inventory-individuals','inventory-individual-draft','inventory-source-browse'],true);$state=['blocked'=>false,'original'=>$requested?self::original($body):null,'status'=>200,'error'=>'','key'=>null,'context'=>[],'saved'=>null,'redirect'=>null,'route'=>['view'=>'inventory','tab'=>'items','new_item'=>'1','create_mode'=>'individual']];
  try{$i=new Inventory($db,$actor);$f=new Finance($db,$actor);if(!$i->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diese Einzelstückanlage erforderlich.',403);self::address($query);$candidate=$query['individual_request']??($requested?($body['request_key']??null):null);if($candidate!==null){$state['key']=self::key($candidate);if(!$i->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diese Einzelstückanlage erforderlich.',403);$state['saved']=self::saved($db,$actor,$state['key'],$query);if($state['saved']){$state['context']=['source'=>$query['source']??''];$state['blocked']=true;if(!$requested)$state['redirect']=['view'=>'inventory','tab'=>'items','group'=>$state['saved']['group']];return$state;}}$state['context']=self::context($query,$i,$f,$db,$actor);if($requested)self::guard($body,$query,$i,$f,$db,$actor);$state['key']??=bin2hex(random_bytes(16));$state['route']['individual_request']=$state['key'];if($state['context']['source']!=='')$state['route']['source']=$state['context']['source'];foreach(['item_page','asset_page','return_assets','return_where','where_page','state','member','location','q','category','status']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];if(!$requested&&(!isset($query['individual_request'])||($query['create_mode']??null)!=='individual'))$state['redirect']=$state['route'];}
  catch(\Throwable $failure){$state['blocked']=true;$state['status']=self::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Einzelstückanlage konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';if(!$requested)$state['original']=['query'=>array_intersect_key($query,array_flip(['new_item','create_mode','source','individual_request','item','cash_source','invoice_position','item_request']))];}return$state;
 }
 public static function status(\Throwable $failure):int{return InventoryItemForm::status($failure);}
}
