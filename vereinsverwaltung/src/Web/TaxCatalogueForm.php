<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\TaxCatalog;

/** Keep catalogue selections and account changes on the explicitly opened source. */
final class TaxCatalogueForm
{
 public static function text(mixed $value):string
 {
  if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Katalogangaben vollständig prüfen.',422);
  return$value;
 }
 public static function query(array $query):array
 {
  $tab=$query['tab']??null;
  if(($query['view']??null)!=='settings'||!in_array($tab,['tax-accounts','booking-keys'],true))throw new DomainException('Katalog im ursprünglich geöffneten Bereich prüfen.',422);
  $allowed=$tab==='tax-accounts'?['view','tab','q','group','active','page','account']:['view','tab','q','group','page'];
  if(array_diff(array_keys($query),$allowed))throw new DomainException('Ursprüngliche Katalogauswahl vollständig prüfen.',422);
  foreach(['q','group']as$key)if(array_key_exists($key,$query))self::text($query[$key]);
  if(mb_strlen($query['q']??'','UTF-8')>1000)throw new DomainException('Suchtext auf höchstens 1.000 Zeichen begrenzen.',422);
  $group=$query['group']??'';
  $groups=$tab==='tax-accounts'?array_keys(TaxCatalog::GROUPS):array_unique(array_column(TaxCatalog::sourceData('datev-keys-2026.json')['keys'],'group'));
  if($group!==''&&!in_array($group,$groups,true))throw new DomainException('Ursprüngliche Kataloggruppe prüfen.',422);
  $page=array_key_exists('page',$query)?$query['page']:'1';
  if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Ursprüngliche Katalogseite prüfen.',422);
  if($tab==='tax-accounts'){
   $active=array_key_exists('active',$query)?$query['active']:'1';
   if(!in_array($active,['','0','1'],true))throw new DomainException('Ursprünglichen Kontenstatus prüfen.',422);
   if(array_key_exists('account',$query)&&(!is_string($query['account'])||!preg_match('/\A[0-79][0-9]{4}\z/D',$query['account'])))throw new DomainException('Ursprüngliche Kontonummer vollständig prüfen.',422);
   $query+=['active'=>$active];
  }
  return$query+['q'=>'','group'=>'','page'=>$page];
 }
 public static function command(array $query,#[\SensitiveParameter] array $post):array
 {
  $source=self::query($query);
  if($source['tab']!=='tax-accounts'||($post['action']??null)!=='save-tax-account'||array_diff(array_keys($post),['action','csrf','code','name','active','version','create_only']))throw new DomainException('Steuerkonto am ursprünglich geöffneten Formular prüfen.',422);
  foreach(['code','name']as$key)self::text($post[$key]??null);
  $version=$post['version']??null;
  if(!is_string($version)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>4294967294)throw new DomainException('Ursprüngliche Kontofassung prüfen.',422);
  foreach(['active','create_only']as$key)if(array_key_exists($key,$post)&&$post[$key]!=='1')throw new DomainException('Kontenaktion und Aktivierung ausdrücklich auswählen.',422);
  $creating=($post['create_only']??null)==='1';
  if($creating){
   if(isset($source['account'])||$version!=='0')throw new DomainException('Neues Steuerkonto ohne bestehende Kontofassung anlegen.',409);
  }elseif(!isset($source['account'])||$source['account']!==$post['code'])throw new DomainException('Kontenformular gehört nicht zur ursprünglichen Kontonummer.',409);
  return['code'=>$post['code'],'name'=>$post['name'],'active'=>($post['active']??null)==='1','version'=>(int)$version,'create_only'=>$creating];
 }
 public static function page(array $query,int $pages):int
 {
  $page=(int)$query['page'];
  if($page>$pages)throw new DomainException('Die ursprüngliche Katalogseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  return$page;
 }
}
