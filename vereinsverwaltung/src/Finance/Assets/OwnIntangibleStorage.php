<?php declare(strict_types=1);
namespace Kulturhus\Verein;
/** Preserve typed empty maps inside new own-created proofs on JSON readback.
 * Other histories keep their original byte representation and array ordering.
 */
final class OwnIntangibleStorage
{
 public static function normal(mixed $value,bool $own=false,?string $key=null):mixed{
  $own=$own||in_array($key,['own_book','own_proof','own_intangible_sources','own_hgb_sources'],true);
  $object=is_object($value);if($object)$value=get_object_vars($value);if(!is_array($value))return $value;
  foreach($value as $name=>&$child)$child=self::normal($child,$own,is_string($name)?$name:null);unset($child);
  return $object||($own&&in_array($key,['cost_rows','original_cost_lines','original_book_lines','book_links','tax_links'],true))?(object)$value:$value;
 }
}
