<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/OriginalLeasing.php';
/** Original optional counter selection. Absence preserves every historical plan. */
final class AssetOriginalDepreciation
{
 public const VEHICLES=['05200','05250','05400','05600'];
 public static function code(array $plan,bool $allowGwg=true):?string
 {
  OriginalLeasing::check($plan);
  $account=$plan['skr_code']??$plan['account']??'';
  if(!array_key_exists('depreciation_code',$plan))return AssetTypes::depreciationCode($account,$allowGwg);
  $code=$plan['depreciation_code'];
  if(!is_string($code)||($plan['method']??'linear_monthly')!=='linear_monthly'||isset($plan['gwg'])||isset($plan['degressive'])
   ||!($code==='62220'&&in_array($account,self::VEHICLES,true)||$code==='62620'&&$account==='06700'||$code==='62500'&&in_array($account,AssetTypes::codes('movable'),true)))
   throw new DomainException('Originales Abschreibungskonto anhand des einzelnen Fahrzeug-/aktivierten GWG-Plans bestätigen. Keine Sofortabschreibung, Sonderabschreibung oder Sammelposten unterstellen.');
  return $code;
 }
}
