<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
/** Exact report kind, original record/version and the reviewed preview receipt. */
final class ActivityReportForm
{
 public const ACTIONS=['activity-report-prepare','activity-report-edit','activity-report-regenerate','activity-report-preview','activity-report-approve'];
 public static function text(mixed $value,bool $multiline=false):string{if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Berichtsangaben vollständig prüfen.',422);return$value;}
 public static function kind(mixed $value):string{if(!in_array($value,['tax','assembly'],true))throw new DomainException('Ursprüngliche Berichtsart prüfen.',422);return$value;}
 public static function id(mixed $value,bool $empty=false):string{if($empty&&$value==='')return'';if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Berichtskennung vollständig prüfen.',422);return$value;}
 public static function version(mixed $value):int{if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>2147483646)throw new DomainException('Ursprünglichen Berichtsstand prüfen.',422);return(int)$value;}
 public static function hash(mixed $value):string{if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))throw new DomainException('Ursprünglichen Tätigkeitsstand prüfen.',422);return$value;}
 public static function year(mixed $value):int{if(!is_string($value)||!preg_match('/\A[0-9]{4}\z/D',$value))throw new DomainException('Ursprünglichen Berichtszeitraum vollständig prüfen.',422);return(int)$value;}
 public static function query(array $query):array
 {
  if(($query['view']??null)!=='documents'||($query['tab']??null)!=='activity-reports'||array_diff(array_keys($query),['view','tab','report_kind','report_id','q','state','page','prepared_from','prepared_to','report_from','report_to','activity_report_download','edit']))throw new DomainException('Tätigkeitsbericht im ursprünglich geöffneten Bereich prüfen.',422);
  $kind=self::kind(array_key_exists('report_kind',$query)?$query['report_kind']:'tax');$id=array_key_exists('report_id',$query)?self::id($query['report_id'],true):'';
  if(array_key_exists('q',$query)){self::text($query['q']);if(strlen($query['q'])>150)throw new DomainException('Bitte einen kürzeren Suchtext eingeben.',422);}
  if(array_key_exists('state',$query)&&!in_array($query['state'],['','review','approved'],true))throw new DomainException('Ursprünglichen Berichtsstatus prüfen.',422);
  if(array_key_exists('page',$query)&&(!is_string($query['page'])||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$query['page'])||(int)$query['page']>1000000))throw new DomainException('Ursprüngliche Berichtsseite prüfen.',422);
  if($id!==''&&array_intersect(['q','state','page','prepared_from','prepared_to','report_from','report_to'],array_keys($query)))throw new DomainException('Ursprünglichen Bericht getrennt von der Übersicht prüfen.',422);
  if(array_key_exists('edit',$query)&&($query['edit']!=='1'||$id===''||array_key_exists('activity_report_download',$query)))throw new DomainException('Ursprüngliche Berichtsbearbeitung getrennt prüfen.',422);
  if(array_key_exists('activity_report_download',$query)&&($query['activity_report_download']!=='1'||$id===''))throw new DomainException('Ursprüngliches freigegebenes Berichts-PDF prüfen.',422);
  if(array_key_exists('prepared_from',$query)||array_key_exists('prepared_to',$query)){if($id!==''||array_intersect(['q','state','page','activity_report_download'],array_keys($query)))throw new DomainException('Ursprünglichen Berichtszeitraum getrennt prüfen.',422);self::year($query['prepared_from']??null);self::year($query['prepared_to']??null);}
  if(array_key_exists('report_from',$query)||array_key_exists('report_to',$query)){
   if($id!==''||array_intersect(['q','state','page','prepared_from','prepared_to','activity_report_download','edit'],array_keys($query)))throw new DomainException('Berichtszeitraum getrennt auswählen.',422);
   $from=self::year($query['report_from']??null);$to=self::year($query['report_to']??null);if($from<2000||$to<$from||$to>$from+2||$to>(int)date('Y'))throw new DomainException('Bitte einen gültigen Berichtszeitraum von höchstens drei Jahren auswählen.',422);
  }
  return$query+['report_kind'=>$kind,'report_id'=>$id,'q'=>'','state'=>'','page'=>'1'];
 }
 public static function command(array $query,#[\SensitiveParameter] array $post):array
 {
  $source=self::query($query);$action=$post['action']??null;if(!in_array($action,self::ACTIONS,true)||isset($source['prepared_from'])||isset($source['activity_report_download']))throw new DomainException('Berichtsformular am ursprünglich geöffneten Vorgang prüfen.',422);
  if(isset($source['edit'])&&$action!=='activity-report-edit')throw new DomainException('Berichtsbearbeitung vor der PDF-Prüfung bewusst abschließen.',409);
  $kind=self::kind($post['report_kind']??null);if($kind!==$source['report_kind'])throw new DomainException('Aktion gehört nicht zur ursprünglich geöffneten Berichtsart.',409);
  $allowed=match($action){'activity-report-prepare'=>['from_year','to_year','supplement','prepare_token'],'activity-report-edit'=>['report_id','version','source_hash','body'],'activity-report-regenerate'=>['report_id','version','source_hash','confirm_report'],default=>['report_id','version','source_hash','preview_token','with_signature','reviewed']};
  if(array_diff(array_keys($post),['action','csrf','report_kind','retry_original','retry_confirm',...$allowed]))throw new DomainException('Berichtsformular vollständig am ursprünglichen Stand prüfen.',422);
  foreach(['retry_original','retry_confirm','confirm_report','with_signature','reviewed']as$key)if(array_key_exists($key,$post)&&$post[$key]!=='1')throw new DomainException('Ursprüngliche Berichtsaktion ausdrücklich bestätigen.',422);
  $out=['action'=>$action,'kind'=>$kind,'retry_original'=>($post['retry_original']??null)==='1','retry_confirmed'=>($post['retry_confirm']??null)==='1'];
  if($action==='activity-report-prepare'){if($source['report_id']!==''||array_intersect(['q','state','page'],array_keys($query)))throw new DomainException('Bericht im ursprünglichen Vorbereitungsformular prüfen.',409);return$out+['from'=>self::year($post['from_year']??null),'to'=>self::year($post['to_year']??null),'supplement'=>self::text($post['supplement']??null,true),'prepare_token'=>self::id($post['prepare_token']??null)];}
  $id=self::id($post['report_id']??null);$version=self::version($post['version']??null);$hash=self::hash($post['source_hash']??null);if($id!==$source['report_id'])throw new DomainException('Aktion gehört nicht zum ursprünglich geöffneten Bericht.',409);$out+=['id'=>$id,'version'=>$version,'source_hash'=>$hash];
  if($action==='activity-report-edit')return$out+['body'=>self::text($post['body']??null,true)];
  if($action==='activity-report-regenerate')return$out+['confirmed'=>($post['confirm_report']??null)==='1'];
  return$out+['preview_token'=>self::id($post['preview_token']??null),'with_signature'=>($post['with_signature']??null)==='1','reviewed'=>($post['reviewed']??null)==='1'];
 }
}
