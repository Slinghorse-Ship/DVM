<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** A callback must come from an actual reviewed local file and plain captured configuration. */
final class Source
{
    public static function hash(\Closure $callback):string
    {
        $reflection=new \ReflectionFunction($callback);$file=$reflection->getFileName();
        if(!$file||!is_file($file)||is_link($file)||$reflection->getClosureThis()!==null)throw new \InvalidArgumentException('Statische lokale Typfunktion erforderlich.');
        $captures=$reflection->getStaticVariables();$count=0;$captures=self::plain($captures,0,$count);$json=json_encode($captures,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
        if(strlen($json)>16384)throw new \InvalidArgumentException('Lokale Typkonfiguration begrenzen.');
        return hash('sha256',hash_file('sha256',$file).'|'.$reflection->getStartLine().'|'.$reflection->getEndLine().'|'.$json);
    }
    private static function plain(mixed $value,int $depth,int &$count):mixed
    {
        if($depth>8||++$count>1000)throw new \InvalidArgumentException('Lokale Typkonfiguration begrenzen.');
        if(is_array($value)){if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=self::plain($item,$depth+1,$count);return $value;}
        if($value===null||is_bool($value)||is_int($value)||(is_float($value)&&is_finite($value))||(is_string($value)&&strlen($value)<=16384))return $value;
        throw new \InvalidArgumentException('Typkonfiguration muss aus einfachen Werten bestehen.');
    }
}
