<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Plausibility only: does not verify bank account existence or its holder. */
final class Iban
{
 /** National BBAN structures explicitly verified in the same SWIFT registry. */
 public const PATTERNS=['DE'=>'^[0-9]{18}$','AT'=>'^[0-9]{16}$'];
 /** SWIFT IBAN registry release 101 (Dec 2025), checked 2026-09-07.
  * https://www.swift.com/sites/default/files/files/iban-registry-v101.pdf
  * Length transcription cross-checked with django-localflavor generic/validators.py. */
 public const LENGTHS=[
  'AD'=>24,
  'AE'=>23,
  'AL'=>28,
  'AT'=>20,
  'AZ'=>28,
  'BA'=>20,
  'BE'=>16,
  'BG'=>22,
  'BH'=>22,
  'BI'=>27,
  'BR'=>29,
  'BY'=>28,
  'CH'=>21,
  'CR'=>22,
  'CY'=>28,
  'CZ'=>24,
  'DE'=>22,
  'DJ'=>27,
  'DK'=>18,
  'DO'=>28,
  'EE'=>20,
  'EG'=>29,
  'ES'=>24,
  'FI'=>18,
  'FK'=>18,
  'FO'=>18,
  'FR'=>27,
  'GB'=>22,
  'GE'=>22,
  'GI'=>23,
  'GL'=>18,
  'GR'=>27,
  'GT'=>28,
  'HN'=>28,
  'HR'=>21,
  'HU'=>28,
  'IE'=>22,
  'IL'=>23,
  'IQ'=>23,
  'IS'=>26,
  'IT'=>27,
  'JO'=>30,
  'KW'=>30,
  'KZ'=>20,
  'LB'=>28,
  'LC'=>32,
  'LI'=>21,
  'LT'=>20,
  'LU'=>20,
  'LV'=>21,
  'LY'=>25,
  'MC'=>27,
  'MD'=>24,
  'ME'=>22,
  'MK'=>19,
  'MN'=>20,
  'MR'=>27,
  'MT'=>31,
  'MU'=>30,
  'NI'=>28,
  'NL'=>18,
  'NO'=>15,
  'OM'=>23,
  'PK'=>24,
  'PL'=>28,
  'PS'=>29,
  'PT'=>25,
  'QA'=>29,
  'RO'=>24,
  'RS'=>22,
  'RU'=>33,
  'SA'=>24,
  'SC'=>31,
  'SD'=>18,
  'SE'=>24,
  'SI'=>19,
  'SK'=>24,
  'SM'=>27,
  'SO'=>23,
  'ST'=>25,
  'SV'=>28,
  'TL'=>23,
  'TN'=>24,
  'TR'=>26,
  'UA'=>29,
  'VA'=>22,
  'VG'=>24,
  'XK'=>20,
  'YE'=>30,
 ];
 public static function normalize(string $value):string
 {
  if(strlen($value)>500||!mb_check_encoding($value,'UTF-8'))throw new DomainException('IBAN prüfen: unzulässige Eingabe.');
  $value=strtoupper(preg_replace('/[\s\p{Z}]+/u','',$value));
  if($value==='')return '';
  if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D',$value))throw new DomainException('IBAN darf nur lateinische Buchstaben, Ziffern und Leerzeichen enthalten.');
  $country=substr($value,0,2);
  if(!isset(self::LENGTHS[$country]))throw new DomainException('IBAN-Länderkennzeichen wird nicht erkannt. Bitte die ersten zwei Buchstaben prüfen.');
  if(strlen($value)!==self::LENGTHS[$country])throw new DomainException('IBAN für '.$country.' muss '.self::LENGTHS[$country].' Zeichen ohne Leerzeichen enthalten.');
  if(isset(self::PATTERNS[$country])&&!preg_match('/'.self::PATTERNS[$country].'/D',substr($value,4)))throw new DomainException('IBAN für '.$country.' darf nach Länderkennung und Prüfziffer nur Ziffern enthalten.');
  $rest=0;foreach(str_split(substr($value,4).substr($value,0,4)) as $char){$digits=ctype_alpha($char)?(string)(ord($char)-55):$char;foreach(str_split($digits) as $digit)$rest=($rest*10+(int)$digit)%97;}
  if($rest!==1)throw new DomainException('IBAN-Prüfziffer stimmt nicht. Bitte die IBAN mit dem Original vergleichen.');
  return $value;
 }
}
