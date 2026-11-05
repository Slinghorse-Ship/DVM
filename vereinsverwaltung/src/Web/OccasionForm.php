<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\Registry;
/** Exact occasion selection and rule drafts, including JavaScript-free editing. */
final class OccasionForm
{
 public const ACTIONS=['set-favourite','save-anniversary-rules'];
 public static function marked(array $query):bool{return($query['view']??null)==='contacts-extra'||($query['view']??null)==='members-extra'||(($query['view']??null)==='members'&&($query['list']??null)==='birthdays');}
 public static function text(mixed $value,int $limit=150):string
 {
  if(!is_string($value)||strlen($value)>$limit||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Auswahl und vollständige Angaben prüfen.',422);return$value;
 }
 public static function query(array $query):array
 {
  $kind=match(true){($query['view']??null)==='members'&&($query['list']??null)==='birthdays'=>'birthdays',($query['view']??null)==='contacts-extra'&&($query['tab']??null)==='favourites'=>'favourites',($query['view']??null)==='members-extra'&&($query['tab']??null)==='anniversaries'=>'anniversaries',default=>throw new DomainException('Anlässe im ursprünglich geöffneten Bereich prüfen.',422)};
  $base=$kind==='birthdays'?['view'=>'members','list'=>'birthdays']:['view'=>$query['view'],'tab'=>$query['tab']];
  $neutral=$kind==='birthdays'?['status'=>'','kind'=>'','sort'=>'name','direction'=>'ASC','as_of'=>'']:[];
  foreach($neutral as$key=>$value)if(array_key_exists($key,$query)&&$query[$key]!==$value)throw new DomainException('Ursprüngliche Auswahl und vollständige Angaben prüfen.',422);
  $allowed=[...array_keys($base),...array_keys($neutral),'q','size','page',...($kind==='anniversaries'?['from','to','edit_rules']:[])];
  if(array_diff(array_keys($query),$allowed))throw new DomainException('Anlässe im ursprünglich geöffneten Bereich prüfen.',422);
  $q=array_key_exists('q',$query)?self::text($query['q']):'';$size=array_key_exists('size',$query)?$query['size']:'40';$page=array_key_exists('page',$query)?$query['page']:'1';
  if(!in_array($size,['20','40','100'],true)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))throw new DomainException('Ursprüngliche Ergebnisseite und Seitengröße prüfen.',422);
  if(array_key_exists('edit_rules',$query)&&$query['edit_rules']!=='1')throw new DomainException('Ursprüngliche Jubiläumsregelansicht prüfen.',422);
  $from=$kind==='anniversaries'?(array_key_exists('from',$query)?$query['from']:date('Y').'-01-01'):'';$to=$kind==='anniversaries'?(array_key_exists('to',$query)?$query['to']:(new \DateTimeImmutable('today'))->modify('+3 years')->format('Y-m-d')):'';
  if($kind==='anniversaries')foreach([$from,$to]as$date){self::text($date,10);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('Ursprünglichen Jubiläumszeitraum vollständig prüfen.',422);}
  if($kind==='anniversaries'&&$from>$to)throw new DomainException('Ursprünglichen Jubiläumszeitraum vollständig prüfen.',422);
  return compact('kind','base','q','from','to')+['size'=>(int)$size,'page'=>(int)$page,'edit'=>isset($query['edit_rules']),'route'=>$base+['q'=>$q,'size'=>$size,'page'=>$page]+($kind==='anniversaries'?['from'=>$from,'to'=>$to]:[])];
 }
 public static function saved(array $query,array $saved,array $filters):array
 {
  if(($query['view']??null)!=='members'||!is_string($query['saved']??null)||$query['saved']!==($saved['id']??null)||array_diff(array_keys($query),['view','saved','page','size'])||($filters['list']??null)!=='birthdays')throw new DomainException('Ursprüngliche Auswahl und vollständige Angaben prüfen.',422);
  $source=['view'=>'members']+$filters+array_intersect_key($query,array_flip(['page','size']));self::query($source);return$source;
 }
 public static function favourite(array $query,array $post):array
 {
  if(($post['action']??null)!=='set-favourite'||array_diff(array_keys($post),['action','csrf','id','enabled','favourite_return']))throw new DomainException('Ursprüngliche Favoritenangaben vollständig prüfen.',422);
  $id=self::text($post['id']??null,150);if($id===''||!in_array($post['enabled']??null,['0','1'],true))throw new DomainException('Ursprüngliche Favoritenangaben vollständig prüfen.',422);
  if(($query['view']??null)==='members'&&array_key_exists('id',$query)&&!self::marked($query)){
   if(($query['id']??null)!==$id||isset($query['import'])||isset($query['new'])||array_key_exists('favourite_return',$post))throw new DomainException('Favorit gehört nicht zum ursprünglich geöffneten Profil.',409);
   return['id'=>$id,'enabled'=>$post['enabled']==='1','route'=>['view'=>'members','id'=>$id]];
  }
  $state=self::query($query);if(($post['favourite_return']??null)!==$state['kind'])throw new DomainException('Favorit gehört nicht zur ursprünglichen Liste.',409);
  return['id'=>$id,'enabled'=>$post['enabled']==='1','route'=>$state['route']];
 }
 public static function draft(array $query,array $post):array
 {
  $state=self::query($query);
  if($state['kind']!=='anniversaries'||!$state['edit']||($post['action']??null)!=='save-anniversary-rules'||($post['occasion_complete']??null)!=='1'||array_diff(array_keys($post),['action','csrf','occasion_complete','version','rule_name','rule_duration','rule_unit','rule_edit']))throw new DomainException('Jubiläumsformular vollständig am ursprünglichen Stand prüfen.',422);
  $version=$post['version']??null;if(!is_string($version)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483646)throw new DomainException('Ursprüngliche Jubiläumsfassung vollständig prüfen.',422);
  foreach(['rule_name','rule_duration','rule_unit']as$key){$values=array_key_exists($key,$post)?$post[$key]:[];if(!is_array($values)||!array_is_list($values)||count($values)>24)throw new DomainException('Jubiläumsformular vollständig am ursprünglichen Stand prüfen.',422);foreach($values as$value)self::text($value,400);}
  $names=$post['rule_name']??[];$durations=$post['rule_duration']??[];$units=$post['rule_unit']??[];
  if(count($names)!==count($durations)||count($names)!==count($units))throw new DomainException('Jubiläumsformular vollständig am ursprünglichen Stand prüfen.',422);
  $rows=[];foreach($names as$i=>$name)$rows[]=['name'=>$name,'duration'=>$durations[$i],'unit'=>$units[$i]];
  $edit=array_key_exists('rule_edit',$post)?self::text($post['rule_edit'],20):null;
  if($edit!==null){
   if($edit==='add'){if(count($rows)>=24)throw new DomainException('Höchstens 24 Jubiläumsregeln sind möglich.',422);$rows[]=['name'=>'','duration'=>'','unit'=>'years'];}
   elseif(is_string($edit)&&preg_match('/\Aremove:([0-9]{1,2})\z/D',$edit,$m)&&array_key_exists((int)$m[1],$rows))array_splice($rows,(int)$m[1],1);
   else throw new DomainException('Ursprüngliche Regelbearbeitung prüfen.',422);
  }
  return['rows'=>$rows,'version'=>$version,'editing'=>$edit!==null];
 }
 public static function rules(array $draft):array
 {
  if($draft['editing'])throw new DomainException('Regelbearbeitung speichert keine Jubiläumsregeln.',422);
  return Registry::rulesFromForm(array_column($draft['rows'],'name'),array_column($draft['rows'],'duration'),array_column($draft['rows'],'unit'));
 }
 public static function originals(array $query,array $post):string
 {
  $safe=array_intersect_key($post,array_flip(['action','id','enabled','favourite_return','version','occasion_complete','rule_name','rule_duration','rule_unit','rule_edit']));
  $encode=static function(mixed$value)use(&$encode):array{
   if(is_string($value))return mb_check_encoding($value,'UTF-8')?['type'=>'text','value'=>$value]:['type'=>'bytes','base64'=>base64_encode($value)];
   if(is_array($value)){$entries=[];foreach($value as$k=>$v){if(is_string($k)&&preg_match('/csrf|password|token|secret|factor_code|recovery_code/i',$k))continue;$entries[]=['key'=>$encode($k),'value'=>$encode($v)];}return['type'=>'array','entries'=>$entries];}
   return['type'=>get_debug_type($value),'value'=>is_scalar($value)||$value===null?$value:null];
  };
  return json_encode(['query'=>$encode($query),'form'=>$encode($safe)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }
}
