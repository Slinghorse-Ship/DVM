<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** Fixed shared document controls; files and human metadata never pass through this adapter. */
final class InterfaceText
{
 private static array $messages=[];
 /** Set once from the current authenticated request Catalog, before rendering any document control. */
 public static function configure(array $messages):void{self::$messages=$messages;}
 public static function text(string $source,?array $messages=null):string
 {
  $messages??=self::$messages;
  return $messages?\Kulturhus\Native\Language\DocumentMessages::text($source,$messages):$source;
 }
 public static function format(string $source,array $values,?array $messages=null):string
 {
  $replace=[];foreach($values as$key=>$value)if(is_string($value)||is_int($value))$replace['{'.$key.'}']=(string)$value;
  return strtr(self::text($source,$messages),$replace);
 }
}
