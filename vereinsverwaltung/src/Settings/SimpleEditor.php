<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use Kulturhus\Verein\SettingsSchema;
/** Ordinary current-value editing; technical review receipts remain available on their explicit historic routes. */
final class SimpleEditor
{
 public static function section(array $query):?string
 {
  if(($query['view']??null)!=='settings'||array_diff(array_keys($query),['view','tab','section','group']))return null;
  $tab=$query['tab']??'';if(!is_string($tab))return null;
  if($tab==='paper')return 'paper';if($tab==='numbers')return'numbers';
  if($tab==='texts'){$group=$query['group']??'contributions';return is_string($group)&&in_array($group,['contributions','invoices','donations'],true)?'texts_'.$group:null;}
  $section=$query['section']??'club';return $tab===''&&is_string($section)&&(GeneralValues::supports($section)||in_array($section,['bank','tax','finance_preferences'],true))?$section:null;
 }
 public static function validate(array $query,array $post):string
 {
  $section=self::section($query);
  if($section===null||($post['action']??null)!=='settings-save'||($post['section']??null)!==$section)throw new \DomainException('Einstellungen im ursprünglich geöffneten Bereich speichern.',409);
  if(!is_string($post['version']??null)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['version'])||(int)$post['version']>2147483646||!is_array($post['data']??null))throw new \DomainException('Einstellungsformular unvollständig. Deine Angaben bleiben erhalten.',422);
  if(array_diff(array_keys($post),['action','csrf','section','version','data','intent','preview_template','preview_group']))throw new \DomainException('Einstellungsformular vollständig prüfen.',422);
  if(str_starts_with($section,'texts_')){
   $templates=array_keys(SettingsSchema::templateLabels($section));if(array_diff($templates,array_keys($post['data']))||array_diff(array_keys($post['data']),$templates))throw new \DomainException('Textvorlagen vollständig übertragen.',422);
   foreach($post['data']as$row)if(!is_array($row)||array_diff(['subject','message','intro','closing'],array_keys($row)))throw new \DomainException('Textvorlagen vollständig übertragen.',422);
  }elseif($section!=='numbers'){
   $fields=SettingsSchema::fields($section);$required=[];foreach($fields as$key=>$definition)if($definition[1]!=='checkbox')$required[]=$key;
   if($fields!==[]&&(array_diff($required,array_keys($post['data']))||array_diff(array_keys($post['data']),array_keys($fields))))throw new \DomainException('Einstellungsformular vollständig übertragen. Deine Angaben bleiben erhalten.',422);
  }
  return$section;
 }
}
