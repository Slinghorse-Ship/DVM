<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\{Inventory,Permissions};

/** Complete creation input, its opened planning mode and its recoverable original request. */
final class InventoryLoanCreateForm
{
 public const DATA=['contact'=>80,'purpose'=>200,'notes'=>3000,'reservation_mode'=>20,'starts_on'=>10,'due_on'=>10,'window_start'=>16,'window_end'=>16,'lines'=>0];
 public const META=['target','version','request_key','stock_q','stock_page'];
 public const QUERY=['view','tab','new_loan','timed','loan_request','loan','item','new_item','loan_draft_remove','stock_move','q','state','borrower','loan_page','return_where','where_page','member','location'];
 public static function applies(array $body,array $query):bool
 {
  $action=$body['action']??null;if(in_array($action,['inventory-reserve','inventory-loan-draft','inventory-loan-stock-browse','inventory-window-check'],true))return true;
  return $action===null&&array_key_exists('new_loan',$query);
 }
 public static function original(array $body):array
 {
  $out=array_intersect_key($body,array_flip(self::META));if(array_key_exists('data',$body)){$out['data']=is_array($body['data'])?array_intersect_key($body['data'],self::DATA):$body['data'];if(is_array($out['data'])&&is_array($out['data']['lines']??null))$out['data']['lines']=array_map(static fn($row)=>is_array($row)?array_intersect_key($row,array_flip(['stock','quantity'])):$row,$out['data']['lines']);}return$out;
 }
 public static function key(mixed $value):string
 {
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Ausleih-Vorgangskennung vollständig prüfen.',422);return$value;
 }
 private static function text(mixed $value,int $maximum):string
 {
  if(!is_string($value)||strlen($value)>$maximum||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Ursprüngliche Ausleihangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);return$value;
 }
 private static function integer(mixed $value,bool $empty=false):void
 {
  if($empty&&$value==='')return;if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)||(int)$value>1000000)throw new DomainException('Ursprüngliche Ausleihmengen und Seiten vollständig prüfen.',422);
 }
 private static function day(string $value):void
 {
  if($value==='')return;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))throw new DomainException('Ursprüngliche Ausleihdaten vollständig prüfen.',422);
 }
 public static function mode(array $query):string
 {
  foreach(self::QUERY as$field)if(array_key_exists($field,$query)&&!is_string($query[$field]))throw new DomainException('Ursprüngliche Ausleihquelle vollständig prüfen.',422);
  if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='loans'||($query['new_loan']??null)!=='1'||array_intersect(array_keys($query),['loan','item','new_item']))throw new DomainException('Ausleihanlage an der ursprünglich geöffneten Vorgangsadresse erneut prüfen.',409);
  $timed=$query['timed']??'0';if(!in_array($timed,['0','1'],true))throw new DomainException('Ursprüngliche Reservierungsart vollständig prüfen.',422);
  if(isset($query['stock_move'])&&!in_array($query['stock_move'],['','search','previous','next'],true))throw new DomainException('Ursprüngliche Bestandsauswahl vollständig prüfen.',422);
  if(isset($query['loan_draft_remove'])&&!preg_match('/\A(?:0|[1-4]?[0-9])\z/D',$query['loan_draft_remove']))throw new DomainException('Ursprüngliche Ausleihposition vollständig prüfen.',422);
  return$timed==='1'?'window':'immediate';
 }
 public static function stock(string $value,bool $empty=true):array
 {
  if($empty&&$value==='')return[];
  if(!preg_match('/\A([^:\x00-\x1f\x7f]{1,80}):([^:\x00-\x1f\x7f]{1,80}):(0|[1-9][0-9]{0,6})\z/uD',$value,$m)||(int)$m[3]>1000000)throw new DomainException('Ursprünglichen Gegenstand, Lagerort und Bestandsstand vollständig prüfen.',422);
  return ['item'=>$m[1],'location'=>$m[2],'stock_version'=>$m[3]];
 }
 public static function guard(array $body,array $query,Inventory $inventory):void
 {
  if(!$inventory->canWriteLoans())throw new DomainException('Aktuelles Ausleih-Bearbeitungsrecht für diese Anlage erforderlich.',403);$mode=self::mode($query);
  if(($body['loan_form_complete']??null)!=='1')throw new DomainException('Ausleihformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts gespeichert.',422);
  foreach(['target','version','request_key','stock_q','stock_page']as$field)if(!array_key_exists($field,$body)||!is_string($body[$field]))throw new DomainException('Ursprüngliche Ausleihangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if($body['target']!==''||$body['version']!=='0'||self::key($body['request_key'])!==self::key($query['loan_request']??null))throw new DomainException('Ausleihanlage an der ursprünglich geöffneten Vorgangsadresse erneut prüfen.',409);
  self::text($body['stock_q'],150);self::integer($body['stock_page']);$data=$body['data']??null;
  if(!is_array($data)||array_diff(array_keys($data),array_keys(self::DATA)))throw new DomainException('Ursprüngliche Ausleihangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  if(!is_string($data['reservation_mode']??null)||!in_array($data['reservation_mode'],['immediate','window'],true))throw new DomainException('Ursprüngliche Reservierungsart vollständig prüfen.',422);
  if($data['reservation_mode']!==$mode)throw new DomainException('Ausleihanlage im ursprünglich geöffneten Planungsweg erneut prüfen.',409);
  $required=['contact','purpose','notes','reservation_mode','lines',...($mode==='window'?['window_start','window_end']:['starts_on','due_on'])];
  if(array_diff(array_keys($data),$required))throw new DomainException('Ursprüngliche Ausleihangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach($required as$field)if(!array_key_exists($field,$data))throw new DomainException('Ursprüngliche Ausleihangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
  foreach($data as$field=>$value)if($field!=='lines')self::text($value,self::DATA[$field]);
  if($mode==='window'){foreach(['window_start','window_end']as$field)if($data[$field]!=='')try{Inventory::windowUtc($data[$field]);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),422,$failure);}}
  else foreach(['starts_on','due_on']as$field)self::day($data[$field]);
  if(($body['action']??null)==='inventory-window-check'&&$mode!=='window')throw new DomainException('Verfügbarkeit nur im ursprünglich geöffneten Zeitfenster prüfen.',409);
  $lines=$data['lines'];if(!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>50)throw new DomainException('Ursprüngliche Ausleihpositionen vollständig prüfen. Keine Zeile wird still entfernt.',422);
  foreach($lines as$line){if(!is_array($line)||array_diff(array_keys($line),['stock','quantity'])||!array_key_exists('stock',$line)||!array_key_exists('quantity',$line))throw new DomainException('Ursprüngliche Ausleihpositionen vollständig prüfen. Keine Zeile wird still entfernt.',422);self::text($line['stock'],200);self::stock($line['stock']);self::integer($line['quantity'],true);}
 }
 public static function normalise(array $body):array
 {
  $data=$body['data'];$lines=[];foreach($data['lines']as$line){self::integer($line['quantity']);$row=self::stock($line['stock'],false)+['quantity'=>$line['quantity']];ksort($row);$lines[]=$row;}$data['lines']=$lines;ksort($data);return$data;
 }
 public static function saved(PDO $db,string $actor,string $key,string $mode):?array
 {
  if(!(new Inventory($db,$actor))->canWriteLoans())throw new DomainException('Aktuelles Ausleih-Bearbeitungsrecht für diese Anlage erforderlich.',403);$who=Permissions::actor($db,$actor);$q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$who['club_id'],self::key($key)]);$event=$q->fetch(PDO::FETCH_ASSOC);if(!$event)return null;$data=json_decode($event['details'],true);
  if($event['actor_id']!==$who['id']||$event['action']!==($mode==='window'?'plan':'reserve')||!is_array($data)||($data['reservation_mode']??null)!==$mode)throw new DomainException('Diese Ausleihkennung gehört zu einem anderen Vorgang.',409);
  return ['loan'=>$event['object_id']];
 }
 public static function state(PDO $db,string $actor,array $body,array $query):array
 {
  $posted=in_array($body['action']??null,['inventory-reserve','inventory-loan-draft','inventory-loan-stock-browse','inventory-window-check'],true);$state=['blocked'=>false,'original'=>$posted?self::original($body):null,'status'=>200,'error'=>'','key'=>null,'mode'=>'immediate','saved'=>null,'redirect'=>null,'route'=>['view'=>'inventory','tab'=>'loans','new_loan'=>'1']];
  try{$inventory=new Inventory($db,$actor);if(!$inventory->canWriteLoans())throw new DomainException('Aktuelles Ausleih-Bearbeitungsrecht für diese Anlage erforderlich.',403);$state['mode']=self::mode($query);$state['route']['timed']=$state['mode']==='window'?'1':'0';$candidate=$query['loan_request']??($posted?($body['request_key']??null):null);if($candidate!==null){$state['key']=self::key($candidate);$state['saved']=self::saved($db,$actor,$state['key'],$state['mode']);if($state['saved']){$state['blocked']=true;if(!$posted)$state['redirect']=['view'=>'inventory','tab'=>'loans','loan'=>$state['saved']['loan']];return$state;}}
   if($posted)self::guard($body,$query,$inventory);$state['key']??=bin2hex(random_bytes(16));$state['route']['loan_request']=$state['key'];foreach(['q','state','borrower','loan_page','return_where','where_page','member','location']as$field)if(isset($query[$field]))$state['route'][$field]=$query[$field];if(!$posted&&(!isset($query['loan_request'])||!isset($query['timed'])))$state['redirect']=$state['route'];
  }catch(\Throwable$failure){$state['blocked']=true;$state['status']=self::status($failure);$state['error']=$failure instanceof DomainException?$failure->getMessage():'Ausleihanlage konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';if(!$posted)$state['original']=['query'=>array_intersect_key($query,array_flip(self::QUERY))];}return$state;
 }
 public static function status(\Throwable$failure):int{return InventoryItemForm::status($failure);}
}
