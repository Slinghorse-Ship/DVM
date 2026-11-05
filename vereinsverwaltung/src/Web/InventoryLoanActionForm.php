<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Permissions};

/** Existing-loan decisions remain bound to the opened loan, operation and whole original input. */
final class InventoryLoanActionForm
{
 public const ACTIONS=['issue','cancel','relocate','extend','reschedule','return'];
 public const DATA=['date','reason','current_place','due_on','window_start','window_end','lines'];
 public const ROW=['line','good','damaged','lost','location','purchase_unit_ids'];
 public static function applies(array $body,array $query):bool
 {
  $action=$body['action']??null;if(is_string($action)&&in_array($action,array_map(static fn($op)=>'inventory-'.$op,self::ACTIONS),true))return true;
  return $action===null&&array_key_exists('loan',$query)&&!array_key_exists('new_loan',$query);
 }
 public static function original(array $body):array
 {
  $out=array_intersect_key($body,array_flip(['action','target','version','request_key']));if(array_key_exists('data',$body)){$out['data']=is_array($body['data'])?array_intersect_key($body['data'],array_flip(self::DATA)):$body['data'];if(is_array($out['data'])&&is_array($out['data']['lines']??null))$out['data']['lines']=array_map(static fn($row)=>is_array($row)?array_intersect_key($row,array_flip(self::ROW)):$row,$out['data']['lines']);}return$out;
 }
 private static function text(mixed $value,int $limit):string
 {
  if(!is_string($value)||strlen($value)>$limit||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Ursprüngliche Ausleihänderung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);return$value;
 }
 public static function key(mixed $value):string
 {
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Ausleihänderungskennung vollständig prüfen.',422);return$value;
 }
 private static function identifier(mixed $value):string
 {
  self::text($value,80);if($value===''||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Ausleihkennung vollständig prüfen.',422);return$value;
 }
 private static function number(mixed $value):void
 {
  if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D',$value)||(int)$value>1000000)throw new DomainException('Ursprüngliche vollständige Rückgabemengen prüfen.',422);
 }
 private static function day(string $value):void
 {
  if($value==='')return;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))throw new DomainException('Ursprüngliche Ausleihänderungsdaten vollständig prüfen.',422);
 }
 public static function opened(Inventory $inventory,array $query):array
 {
  foreach(['view','tab','loan','loan_action','new_loan','item']as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Ausleihänderungsquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='loans'||array_intersect(array_keys($query),['new_loan','item']))throw new DomainException('Ausleihänderung an der ursprünglich geöffneten Ausleihe erneut prüfen.',409);
  if(!$inventory->canReadLoans())throw new DomainException('Aktuelles Ausleih-Leserecht erforderlich.',403);$id=self::identifier($query['loan']??null);if(isset($query['loan_action'])&&!in_array($query['loan_action'],self::ACTIONS,true))throw new DomainException('Ursprünglichen Ausleihänderungsschritt vollständig prüfen.',422);
  try{return$inventory->loan($id);}catch(DomainException$failure){throw new DomainException('Ausleihe nicht verfügbar.',404,$failure);}
 }
 public static function recorded(PDO $db,string $actor,string $key,string $loan,string $operation):?array
 {
  $who=Permissions::actor($db,$actor);$q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$who['club_id'],self::key($key)]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)return null;$data=json_decode($event['details'],true);
  if($event['actor_id']!==$who['id']||$event['object_id']!==$loan||$event['action']!==$operation||!is_array($data)||!is_int($data[0]??null)||!is_array($data[1]??null))throw new DomainException('Diese Änderungskennung gehört zu einem anderen Ausleihvorgang.',409);return ['version'=>$data[0],'data'=>$data[1],'loan'=>$event['object_id']];
 }
 public static function guard(PDO $db,string $actor,array $body,array $query,?Inventory $inventory=null):array
 {
  $inventory??=new Inventory($db,$actor);if(!$inventory->canWriteLoans())throw new DomainException('Aktuelles Ausleih-Bearbeitungsrecht für diese Änderung erforderlich.',403);$loan=self::opened($inventory,$query);
  if(($body['loan_action_complete']??null)!=='1')throw new DomainException('Ausleihänderungsformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts gespeichert.',422);
  foreach(['action','target','version','request_key']as$field)if(!array_key_exists($field,$body)||!is_string($body[$field]))throw new DomainException('Ursprüngliche Ausleihänderung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  $operation=substr($body['action'],10);if(!in_array($operation,self::ACTIONS,true)||($query['loan_action']??null)!==$operation||$body['target']!==$loan['id'])throw new DomainException('Ausleihänderung an der ursprünglich geöffneten Ausleihe und ihrem Schritt erneut prüfen.',409);
  if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$body['version'])||(int)$body['version']>2147483647)throw new DomainException('Ursprüngliche Ausleihfassung vollständig prüfen.',422);$key=self::key($body['request_key']);$timed=!empty($loan['window_start']);
  $fields=match($operation){'issue'=>['date','current_place','reason'],'cancel'=>['date','reason'],'relocate'=>['current_place','reason'],'extend'=>$timed?['window_end','reason']:['due_on','reason'],'reschedule'=>['window_start','window_end','reason'],'return'=>['date','reason','lines']};$data=$body['data']??null;
  if(!is_array($data)||array_diff(array_keys($data),$fields)||array_diff($fields,array_keys($data)))throw new DomainException('Ursprüngliche Ausleihänderung vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach($data as$field=>$value)if($field!=='lines'){self::text($value,match($field){'reason'=>1000,'current_place'=>200,'window_start','window_end'=>16,default=>10});if(in_array($field,['date','due_on'],true))self::day($value);if(in_array($field,['window_start','window_end'],true)&&$value!=='')try{Inventory::windowUtc($value);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),422,$failure);}}
  $recorded=self::recorded($db,$actor,$key,$loan['id'],$operation);if($recorded&&($recorded['version']!==(int)$body['version']||$recorded['data']!==$data))throw new DomainException('Diese Ausleihänderung wurde mit anderen ursprünglichen Angaben bestätigt.',409);
  if(!$recorded&&(int)$body['version']!==$loan['version'])throw new DomainException('Die Ausleihe wurde inzwischen geändert. Ursprüngliche Angaben erhalten und aktuell neu prüfen.',409);
  if($operation==='return'){
   $rows=$data['lines'];if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>50)throw new DomainException('Ursprüngliche Rückgabepositionen vollständig prüfen. Keine Zeile wird still entfernt.',422);$originalLines=array_column($loan['lines'],null,'id');$seen=[];
   foreach($rows as$row){if(!is_array($row)||array_diff(array_keys($row),self::ROW)||array_diff(['line','good','damaged','lost','location'],array_keys($row)))throw new DomainException('Ursprüngliche Rückgabepositionen vollständig prüfen. Keine Zeile wird still entfernt.',422);$id=self::identifier($row['line']);if(isset($seen[$id])||!isset($originalLines[$id]))throw new DomainException('Rückgabepositionen gehören nicht vollständig zur ursprünglich geöffneten Ausleihe.',409);$seen[$id]=true;foreach(['good','damaged','lost']as$field)self::number($row[$field]);self::text($row['location'],80);
    if(array_key_exists('purchase_unit_ids',$row)){if(!is_array($row['purchase_unit_ids'])||!array_is_list($row['purchase_unit_ids'])||count($row['purchase_unit_ids'])>500)throw new DomainException('Ursprüngliche betroffene Stücke vollständig prüfen.',422);$units=[];foreach($row['purchase_unit_ids']as$unit){self::identifier($unit);if(isset($units[$unit]))throw new DomainException('Ursprüngliche betroffene Stücke vollständig prüfen.',422);$units[$unit]=true;}if(!$recorded){$active=array_fill_keys(array_column($inventory->purchaseUnits($originalLines[$id]['item_id']),'id'),true);if(array_diff_key($units,$active))throw new DomainException('Betroffene Stücke passen nicht zur ursprünglichen Rückgabeposition.',409);}}
   }
   if(!$recorded){$open=[];foreach($loan['lines']as$line)if($line['quantity']>$line['returned']+$line['lost'])$open[$line['id']]=true;if(array_diff_key($open,$seen)||array_diff_key($seen,$open))throw new DomainException('Alle ursprünglich offenen Rückgabepositionen vollständig prüfen.',409);}
  }
  return ['loan'=>$loan,'operation'=>$operation,'recorded'=>$recorded];
 }
 public static function state(PDO $db,string $actor,array $body,array $query):array
 {
  $posted=is_string($body['action']??null)&&in_array($body['action'],array_map(static fn($op)=>'inventory-'.$op,self::ACTIONS),true);$state=['loan'=>null,'blocked'=>false,'original'=>$posted?self::original($body):null,'error'=>'','status'=>200,'recorded'=>null,'route'=>['view'=>'inventory','tab'=>'loans']];
  try{$inventory=new Inventory($db,$actor);$state['loan']=self::opened($inventory,$query);$state['route']['loan']=$state['loan']['id'];foreach(['q','state','borrower','loan_page','return_where','where_page','member','location']as$field)if(is_string($query[$field]??null))$state['route'][$field]=$query[$field];if($posted){$checked=self::guard($db,$actor,$body,$query,$inventory);$state['recorded']=$checked['recorded'];if($state['recorded'])$state['blocked']=true;}}
  catch(\Throwable$failure){$state['blocked']=true;$state['status']=self::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Ausleihänderung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';if(!$posted)$state['original']=['query'=>array_intersect_key($query,array_flip(['view','tab','loan','loan_action','new_loan','item']))];}return$state;
 }
 public static function status(\Throwable$failure):int{return InventoryLoanCreateForm::status($failure);}
}
