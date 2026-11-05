<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
final class PersonalDocumentText
{
 /** Exact signed integer cents, including values beyond floating-point precision. */
 public static function money(int $cents,string $locale):string
 {
  $english=explode('-',$locale,2)[0]==='en';$negative=$cents<0;$digits=ltrim((string)$cents,'-');$digits=str_pad($digits,3,'0',STR_PAD_LEFT);
  $whole=substr($digits,0,-2);$fraction=substr($digits,-2);$whole=preg_replace('/\B(?=(\d{3})+(?!\d))/',$english?',':'.',$whole);
  return ($negative?'-':'').($english?'€':'').$whole.($english?'.':',').$fraction.($english?'':' €');
 }
}
