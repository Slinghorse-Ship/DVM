<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\MembershipApplications;

/** Binds an explicit board action to the original application, never another write route. */
final class ApplicationReviewForm
{
 public const ACTIONS=['review-application','admission-open','admission-vote'];
 public static function marked(array $post):bool
 {
  foreach(['application_decision_intent','application_decision_source','application_decision_version','application_decision_fingerprint']as$key)if(array_key_exists($key,$post))return true;return false;
 }
 public static function target(array $query,array $post):void
 {
  $action=$post['action']??null;$source=$query['application']??null;
  if(($query['view']??null)!=='portal'||($query['tab']??null)!=='application'||array_diff(array_keys($query),['view','tab','application','status','applications_q','applications_page','pdf'])!==[]||!is_string($source)||!preg_match('/\A[a-f0-9]{32}\z/D',$source)||($post['id']??null)!==$source||($post['application_decision_source']??null)!==$source||!in_array($action,self::ACTIONS,true)||($post['application_decision_intent']??null)!==$action)throw new DomainException('Entscheidung im ursprünglich geöffneten Antrag prüfen.',422);
 }
 public static function check(MembershipApplications $intake,array $query,array $post):array
 {
  self::target($query,$post);$raw=$post['application_decision_version']??null;$hash=$post['application_decision_fingerprint']??null;
  if(!is_string($raw)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Ursprünglichen Antragsstand prüfen.',422);
  $current=$intake->application($post['id']);
  if($current['status']!=='pending'||(string)$current['version']!==$raw||!hash_equals(MembershipApplications::fingerprint($current),$hash))throw new DomainException('Antrag bereits bearbeitet oder inzwischen geändert.',409);
  return$current;
 }
 public static function save(MembershipApplications $intake,array $query,array $post):?string
 {
  self::check($intake,$query,$post);
  foreach(['version','decision','review_note','number','start','existing_contact','external_decision','decision_date','family_target']as$key)if(isset($post[$key])&&!is_string($post[$key]))throw new DomainException('Entscheidung und Prüfvermerk angeben.',422);
  $version=$post['version']??'';if(!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$version)||filter_var($version,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new DomainException('Ursprünglichen Antragsstand prüfen.',422);
  try{return $intake->review($post['id'],(int)$version,$post['decision']??'',$post['review_note']??'',$post['number']??'',$post['start']??'',$post['existing_contact']??'',($post['external_decision']??'')==='1',$post['decision_date']??'',$post['family_target']??'',$post['application_decision_fingerprint']);}
  catch(\PDOException $failure){throw new DomainException('Entscheidung konnte nicht gespeichert werden. Die Eingaben bleiben erhalten. Bitte erneut versuchen.',503,$failure);}
 }
 public static function prepare(array $application,array $post):array
 {
  $posted=self::marked($post)||in_array($post['action']??null,self::ACTIONS,true);$state=['source'=>$application['id'],'version'=>(string)$application['version'],'fingerprint'=>MembershipApplications::fingerprint($application),'stale'=>false,'invalid'=>false];
  if($posted){foreach(['source','version','fingerprint']as$key)$state[$key]=is_string($post['application_decision_'.$key]??null)?$post['application_decision_'.$key]:'';$state['stale']=$state['source']!==$application['id']||$state['version']!==(string)$application['version']||!hash_equals(MembershipApplications::fingerprint($application),$state['fingerprint']);}
  foreach(['version','decision','review_note','number','start','existing_contact','external_decision','decision_date','family_target','reason','choice']as$key)if(isset($post[$key])&&!is_string($post[$key]))$state['invalid']=true;
  if(($post['action']??null)==='review-application'&&($post['version']??null)!==$state['version'])$state['stale']=true;return$state;
 }
}
