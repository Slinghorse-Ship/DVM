<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\{ManualApplications,MembershipApplications,ContributionRates};

final class ManualApplicationPage
{
 public static function marked(array $post):bool
 {
  foreach(['manual_application_intent','manual_application_version','manual_application_fingerprint','manual_application_source']as$key)if(array_key_exists($key,$post))return true;return false;
 }
 public static function target(array $query,array $post):void
 {
  if(($query['view']??null)!=='portal'||($query['tab']??null)!=='application'||($query['manual']??null)!=='1'||array_diff(array_keys($query),['view','tab','manual','family'])!==[]||($post['action']??null)!=='manual-application'||($post['manual_application_intent']??null)!=='create')throw new DomainException('Manuellen Antrag im ursprünglich geöffneten Bereich prüfen.',422);
 }
 public static function version(array $post):int
 {
  $raw=$post['manual_application_version']??null;if(!is_string($raw)||!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);return(int)$raw;
 }
 private static function source(array $post,string $club):array
 {
  $raw=$post['manual_application_source']??null;$hash=$post['manual_application_fingerprint']??null;
  if(!is_string($raw)||strlen($raw)>262144||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals(hash('sha256',$raw),$hash))throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);
  try{$source=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);}
  if(!is_array($source)||($source['club_id']??null)!==$club||($source['version']??null)!==self::version($post)||!is_array($source['configuration']??null)||!is_string($source['configuration']['notice_title']??null)||!is_string($source['configuration']['notice_text']??null))throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);
  foreach(['single','family']as$kind)if(!is_array($source['rates'][$kind]??null)||!is_int($source['rates'][$kind]['amount_cents']??null)||$source['rates'][$kind]['amount_cents']<0)throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);
  return$source;
 }
 public static function record(ManualApplications $service,array $query,array $post):array
 {
  self::target($query,$post);$current=$service->context();self::source($post,$current['club_id']);
  foreach(['consent_received','external_decision']as$key)if(isset($post[$key])&&(!is_string($post[$key])||!in_array($post[$key],['','0','1'],true)))throw new DomainException('Ursprüngliche Bestätigungen zur Antragserfassung prüfen.',422);
  if(!is_string($post['access_mail']??null)||!in_array($post['access_mail'],['0','1'],true))throw new DomainException('Zugangsmail auswählen.',422);
  $command=array_intersect_key($post,array_flip(['request_key','decision','review_note','number','start','existing_contact','decision_date','family_target']));$command+=['version'=>self::version($post),'fingerprint'=>$post['manual_application_fingerprint'],'consent'=>($post['consent_received']??'')==='1','external_decision'=>($post['external_decision']??'')==='1','access_mail'=>$post['access_mail']==='1'];
  $fields=[...array_keys(MembershipApplications::FIELDS),'membership_type','family_members','family_reference'];return$service->record(array_intersect_key($post,array_flip($fields)),$command);
 }
 public static function prepare(ManualApplications $service,array $post=[]):array
 {
  $current=$service->context();$posted=($post['action']??null)==='manual-application'||self::marked($post);$original=$current;$source=$current['source_json'];$version=(string)$current['version'];$hash=$current['fingerprint'];$key=bin2hex(random_bytes(16));$error='';
  if($posted){$source=is_string($post['manual_application_source']??null)?$post['manual_application_source']:'';$version=is_string($post['manual_application_version']??null)?$post['manual_application_version']:'';$hash=is_string($post['manual_application_fingerprint']??null)?$post['manual_application_fingerprint']:'';$key=is_string($post['request_key']??null)?$post['request_key']:'';
   try{$raw=self::source($post,$current['club_id']);$original=['configuration'=>$raw['configuration'],'rates'=>$raw['rates'],'notice'=>strtr($raw['configuration']['notice_text'],['{{EINZELBEITRAG}}'=>ContributionRates::label($raw['rates']['single']['amount_cents']),'{{FAMILIENBEITRAG}}'=>ContributionRates::label($raw['rates']['family']['amount_cents'])])];}catch(DomainException $failure){$original=null;$error=$failure->getMessage();}
  }
  return['current'=>$current,'original'=>$original,'draft'=>$posted?$post:null,'source'=>$source,'version'=>$version,'fingerprint'=>$hash,'request_key'=>$key,'stale'=>$error!==''||$version!==(string)$current['version']||!hash_equals($current['fingerprint'],$hash),'source_error'=>$error,'saved_id'=>null];
 }
 /** Keep submitted values as inert fields; the next POST repeats all current checks. */
 public static function recoveryHidden(array $post):string
 {
  $fields=[...array_keys(MembershipApplications::FIELDS),'action','membership_type','family_members','family_reference','manual_application_intent','manual_application_version','manual_application_fingerprint','manual_application_source','request_key','consent_received','external_decision','access_mail','decision','review_note','number','start','existing_contact','decision_date','family_target','admission_family_query','admission_family_page','admission_contact_query','admission_contact_page'];
  $escape=static fn(string $text):string=>htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $hidden=static function(string $name,mixed $value)use(&$hidden,$escape):string{if(is_array($value)){$h='';foreach($value as$key=>$entry)$h.=$hidden($name.'['.$key.']',$entry);return$h;}return'<input type="hidden" name="'.$escape($name).'" value="'.$escape((string)$value).'">';};
  $h='';foreach(array_intersect_key($post,array_flip($fields))as$key=>$value)$h.=$hidden($key,$value);return$h;
 }
 public static function status(\Throwable $failure):int
 {
  if($failure instanceof DomainException){if(in_array($failure->getCode(),[403,404,409,422,503],true))return$failure->getCode();if(in_array($failure->getMessage(),['Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'],true))return 403;return 422;}return 503;
 }
}
