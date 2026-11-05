<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original contract review for ordinary linear depreciation on economic club-owned movable leased assets. */
final class OriginalLeasing
{
 public static function check(array $plan):void{
  $code=$plan['depreciation_code']??null;$proof=$plan['original_leasing']??null;
  if($code!=='62500'){if($proof!==null)throw new DomainException('Kaufleasingnachweis gehört ausschließlich zum originalen Gegenkonto 62500.');return;}
  $account=$plan['skr_code']??$plan['account']??'';
  if(($plan['method']??'linear_monthly')!=='linear_monthly'||!in_array($account,AssetTypes::codes('movable'),true)||isset($plan['gwg'])||isset($plan['degressive'])||!is_array($proof)||count($proof)!==5||array_diff(array_keys($proof),['schema','hgb_economic_owner','tax_economic_owner','ordinary_only','document'])||($proof['schema']??null)!==1||$proof['hgb_economic_owner']!=='club'||$proof['tax_economic_owner']!=='club'||$proof['ordinary_only']!==true)throw new DomainException('Kaufleasing: wirtschaftliches Eigentum für HGB und Steuer anhand des Vertrags einzeln bestätigen. Nur regelmäßige lineare Abschreibung beweglicher Wirtschaftsgüter; keine Rate, Zinsen oder Sonderabschreibung daraus ableiten.');
  AssetValuation::document($proof['document']);
 }
 public static function input(array $input):array{
  if(($input['lease_owner_hgb']??null)!=='1'||($input['lease_owner_tax']??null)!=='1'||($input['lease_ordinary_only']??null)!=='1')throw new DomainException('Vertragliche Zurechnung für HGB und Steuer sowie ausschließlich regelmäßige Abschreibung ausdrücklich bestätigen.');
  $proof=['schema'=>1,'hgb_economic_owner'=>'club','tax_economic_owner'=>'club','ordinary_only'=>true,'document'=>$input['lease_document']??null];AssetValuation::document($proof['document']);return $proof;
 }
}
