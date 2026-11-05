<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
/** Exact own proposal and board decision metadata, independent of current stored defaults. */
final class MemberChangeForm
{
 public const ACTIONS=['member-change-submit','member-change-withdraw','member-change-review'];
 public const STATES=['','pending','accepted','rejected','withdrawn'];
 public static function text(mixed $value,bool $multiline=false):string
 {
  if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Änderungsangaben vollständig prüfen.',422);
  return$value;
 }
 public static function id(mixed $value,bool $empty=false):string
 {
  if($empty&&$value==='')return'';
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Änderungskennung vollständig prüfen.',422);
  return$value;
 }
 public static function version(mixed $value):int
 {
  if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>2147483646)throw new DomainException('Ursprünglichen Stand der Mitgliederänderung prüfen.',422);
  return(int)$value;
 }
 public static function query(array $query):array
 {
  if(!in_array($query['view']??null,['members','portal'],true)||($query['tab']??null)!=='changes'||array_diff(array_keys($query),['view','tab','change','state','q','page','submitted_request','compose']))throw new DomainException('Mitgliederänderung im ursprünglich geöffneten Bereich prüfen.',422);
  if(array_key_exists('compose',$query)&&($query['compose']!=='1'||($query['view']??null)!=='portal'||($query['change']??'')!==''||array_intersect(['state','q','page','submitted_request'],array_keys($query))))throw new DomainException('Ursprüngliche Einreichungsansicht prüfen.',422);
  foreach(['change','submitted_request']as$key)if(array_key_exists($key,$query))self::id($query[$key],$key==='change');
  if(array_key_exists('q',$query)){self::text($query['q']);if(strlen($query['q'])>150)throw new DomainException('Bitte einen kürzeren Suchtext eingeben.',422);}
  if(array_key_exists('state',$query)&&!in_array($query['state'],self::STATES,true))throw new DomainException('Ursprünglichen Antragsstatus prüfen.',422);
  $page=array_key_exists('page',$query)?$query['page']:'1';if(array_key_exists('page',$query)&&(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000))throw new DomainException('Ursprüngliche Antragsseite prüfen.',422);
  if(isset($query['submitted_request'])&&(($query['view']??null)!=='portal'||($query['change']??'')!==''||array_intersect(['q','state','page','compose'],array_keys($query))))throw new DomainException('Ursprünglichen Einreichungsvorgang getrennt prüfen.',422);
  return$query+['change'=>'','state'=>$query['view']==='members'?'pending':'','q'=>'','page'=>$page];
 }
 public static function command(array $query,#[\SensitiveParameter] array $post):array
 {
  $source=self::query($query);$action=$post['action']??null;
  if(!in_array($action,self::ACTIONS,true)||isset($source['submitted_request']))throw new DomainException('Änderungsformular am ursprünglich geöffneten Vorgang prüfen.',422);
  $allowed=$action==='member-change-submit'?['name','email','phone','address','contact_id','contact_version','request_key','retry_original','retry_confirm']:['change','change_version','confirm_change','retry_original','retry_confirm'];
  if($action==='member-change-review')$allowed=[...$allowed,'decision','decision_note'];
  if(array_diff(array_keys($post),['action','csrf',...$allowed]))throw new DomainException('Änderungsformular vollständig am ursprünglichen Stand prüfen.',422);
  foreach(['confirm_change','retry_original','retry_confirm']as$key)if(array_key_exists($key,$post)&&$post[$key]!=='1')throw new DomainException('Ursprüngliche Änderungsaktion ausdrücklich bestätigen.',422);
  $out=['confirmed'=>($post['confirm_change']??null)==='1','retry_original'=>($post['retry_original']??null)==='1','retry_confirmed'=>($post['retry_confirm']??null)==='1'];
  if($action==='member-change-submit'){
   if($source['view']!=='portal'||$source['change']!==''||array_intersect(['q','state','page'],array_keys($query)))throw new DomainException('Eigene Änderung im ursprünglichen Einreichungsformular prüfen.',409);
   $contact=self::id($post['contact_id']??null);$version=self::version($post['contact_version']??null);$key=self::id($post['request_key']??null);$data=[];foreach(['name','email','phone','address']as$field)$data[$field]=self::text($post[$field]??null,$field==='address');
   return$out+['action'=>$action,'contact_id'=>$contact,'contact_version'=>$version,'request_key'=>$key,'data'=>$data];
  }
  $id=self::id($post['change']??null);$version=self::version($post['change_version']??null);
  if($source['change']!==$id||($source['view']==='members')!==($action==='member-change-review'))throw new DomainException('Aktion gehört nicht zum ursprünglich geöffneten Änderungsantrag.',409);
  if($action==='member-change-review'){
   if(!in_array($post['decision']??null,['accepted','rejected'],true))throw new DomainException('Ursprüngliche Entscheidung prüfen.',422);
   self::text($post['decision_note']??null,true);return$out+['action'=>$action,'id'=>$id,'version'=>$version,'decision'=>$post['decision'],'note'=>$post['decision_note']];
  }
  return$out+['action'=>$action,'id'=>$id,'version'=>$version];
 }
}
