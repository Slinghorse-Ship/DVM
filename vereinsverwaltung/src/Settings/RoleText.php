<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;

/** Role fields use their declared character limits; existing normalization stays unchanged. */
final class RoleText
{
    public static function name(mixed $value):string
    {
        return self::text($value,100,true,'Rollenname mit höchstens 100 Zeichen eingeben.');
    }

    public static function description(mixed $value):string
    {
        return self::text($value,1000,false,'Rollenbeschreibung mit höchstens 1.000 Zeichen eingeben.');
    }

    private static function text(mixed $value,int $maximum,bool $required,string $error):string
    {
        if(!is_string($value)&&!is_int($value))throw new DomainException($error,422);
        $text=trim(str_replace("\r\n","\n",(string)$value));
        if(preg_match('//u',$text)!==1||mb_strlen($text)>$maximum||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)||($required&&$text===''))throw new DomainException($error,422);
        return$text;
    }
}
