<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\MembershipApplications;
final class ApplicationSetupForm
{
 public static function marked(array $post):bool{foreach(['application_setup_intent','application_setup_source','application_setup_fingerprint','application_setup_recheck']as$key)if(array_key_exists($key,$post))return true;return false;}
 public static function target(array $query,array $post):void
 {
  if(($query['view']??null)!=='portal'||($query['tab']??null)!=='application'||($query['configure']??null)!=='1'||array_diff(array_keys($query),['view','tab','configure'])!==[]||($post['action']??null)!=='save-application-settings'||($post['application_setup_intent']??null)!=='save')throw new DomainException('Einrichtung im ursprünglich geöffneten Bereich prüfen.',422);
 }
 public static function number(mixed $value,int $minimum=0):int
 {
  if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$value)||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum]])===false)throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);return(int)$value;
 }
 private static function source(array $post,string $club):array
 {
  $raw=$post['application_setup_source']??null;$hash=$post['application_setup_fingerprint']??null;
  if(!is_string($raw)||strlen($raw)>262144||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals(hash('sha256',$raw),$hash))throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);
  try{$source=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);}
  if(!is_array($source)||($source['club_id']??null)!==$club||($source['version']??null)!==self::number($post['version']??null)||!is_array($source['configuration']??null)||!is_array($source['configuration']['fields']??null)||!is_string($source['configuration']['notice_title']??null)||!is_string($source['configuration']['notice_text']??null))throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);
  foreach(['single','family']as$kind)if(!is_array($source['rates'][$kind]??null)||!is_int($source['rates'][$kind]['amount_cents']??null)||$source['rates'][$kind]['amount_cents']<0)throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);
  if(!is_string($post['request_key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['request_key']))throw new DomainException('Ursprüngliche Einrichtung und Vorgangskennung prüfen.',422);return$source;
 }
 public static function prepare(MembershipApplications $service,array $post=[]):array
 {
  $current=$service->setupContext();$posted=($post['action']??null)==='save-application-settings'||self::marked($post);$original=json_decode($current['source_json'],true,64,JSON_THROW_ON_ERROR);$source=$current['source_json'];$version=(string)$current['version'];$hash=$current['fingerprint'];$key=bin2hex(random_bytes(16));$error='';
  if($posted){$source=is_string($post['application_setup_source']??null)?$post['application_setup_source']:'';$version=is_string($post['version']??null)?$post['version']:'';$hash=is_string($post['application_setup_fingerprint']??null)?$post['application_setup_fingerprint']:'';$key=is_string($post['request_key']??null)?$post['request_key']:'';try{$original=self::source($post,$current['club_id']);}catch(DomainException $failure){$original=null;$error=$failure->getMessage();}}
  return['current'=>$current,'original'=>$original,'source'=>$source,'version'=>$version,'fingerprint'=>$hash,'request_key'=>$key,'draft'=>$posted?$post:null,'stale'=>$error!==''||$version!==(string)$current['version']||!hash_equals($current['fingerprint'],$hash),'source_error'=>$error,'saved_version'=>null];
 }
 public static function save(MembershipApplications $service,array $query,array $post):array
 {
  self::target($query,$post);$context=$service->setupContext();self::source($post,$context['club_id']);$data=array_intersect_key($post,array_flip(['fields','payment','notice_title','notice_text','notice_required','enabled']));
  foreach(['notice_required','enabled']as$key){if(!is_string($data[$key]??null)||!in_array($data[$key],['0','1'],true))throw new DomainException('Bestätigungen zur Antragseinrichtung prüfen.',422);$data[$key]=$data[$key]==='1';}
  return$service->saveSettings($data,self::number($post['version']??null),$post['application_setup_fingerprint'],$post['request_key']);
 }
 public static function recoveryHidden(array $post):string
 {
  $allowed=['action','version','application_setup_intent','application_setup_source','application_setup_fingerprint','request_key','fields','payment','notice_title','notice_text','notice_required','enabled'];$escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $field=static function(string $name,mixed $value)use(&$field,$escape):string{if(is_array($value)){$h='';foreach($value as$key=>$part)$h.=$field($name.'['.$key.']',$part);return$h;}return'<input type="hidden" name="'.$escape($name).'" value="'.$escape((string)$value).'">';};$h='';foreach(array_intersect_key($post,array_flip($allowed))as$key=>$value)$h.=$field($key,$value);return$h;
 }
 public static function status(\Throwable $failure):int
 {
  if(!$failure instanceof DomainException)return 503;
  if(in_array($failure->getCode(),[403,404,409,422,503],true))return$failure->getCode();
  return match($failure->getMessage()){'Antrag nicht verfügbar.'=>404,'Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'=>403,default=>422};
 }
}
