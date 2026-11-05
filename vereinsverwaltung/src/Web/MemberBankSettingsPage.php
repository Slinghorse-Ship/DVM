<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\MemberBankSettings;
final class MemberBankSettingsPage
{
 public static function marked(array $post):bool{return array_key_exists('member_bank_settings_intent',$post)||array_key_exists('member_bank_settings_version',$post)||array_key_exists('member_bank_settings_fingerprint',$post);}
 public static function target(array $query,array $post):void
 {
  if(($query['view']??null)!=='settings'||($query['tab']??null)!=='sepa'||array_diff(array_keys($query),['view','tab'])!==[]||($post['action']??null)!=='member-bank-settings-save'||($post['member_bank_settings_intent']??null)!=='save')throw new \DomainException('SEPA-Einrichtung im ursprünglich geöffneten Bereich prüfen.',422);
 }
 public static function version(array $post):int
 {
  $raw=$post['member_bank_settings_version']??null;if(!is_string($raw)||!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new \DomainException('Ursprünglichen SEPA-Einrichtungsstand prüfen.',422);return(int)$raw;
 }
 public static function save(MemberBankSettings $service,array $query,array $post):array
 {
  self::target($query,$post);$version=self::version($post);$hash=$post['member_bank_settings_fingerprint']??null;if(!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new \DomainException('Ursprünglichen SEPA-Einrichtungsstand prüfen.',422);
  return$service->save($version,$post);
 }
 public static function prepare(MemberBankSettings $service,array $post=[]):array
 {
  $current=$service->get();$fingerprint=MemberBankSettings::fingerprint($current);$draft=($post['action']??null)==='member-bank-settings-save'?array_intersect_key($post,array_flip(['creditor_id','creditor_name','creditor_address','sepa_enabled','member_bank_settings_version','member_bank_settings_fingerprint'])):null;
  $state=['current'=>$current,'fields'=>array_intersect_key($current,array_flip(['creditor_id','creditor_name','creditor_address','sepa_enabled'])),'draft'=>$draft,'version'=>(string)$current['version'],'fingerprint'=>$fingerprint,'stale'=>false];
  if($draft!==null){foreach(['creditor_id','creditor_name','creditor_address']as$key)$state['fields'][$key]=is_string($draft[$key]??null)?$draft[$key]:'';$state['fields']['sepa_enabled']=($draft['sepa_enabled']??'')==='1';$state['version']=is_string($draft['member_bank_settings_version']??null)?$draft['member_bank_settings_version']:'';$state['fingerprint']=is_string($draft['member_bank_settings_fingerprint']??null)?$draft['member_bank_settings_fingerprint']:'';$state['stale']=$state['version']!==(string)$current['version']||!hash_equals($fingerprint,$state['fingerprint']);}
  return$state;
 }
}
