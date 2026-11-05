<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Read immutable accounting catalogs once during one pure calculation.
 * All journal, allocation and carry checks still run against their full inputs.
 * Never use this context around writes or retain it between requests.
 */
final class BalanceSourceRead
{
    private static ?array $values=null;

    public static function run(callable $read):array
    {
        if(self::$values!==null)return $read();
        self::$values=[];
        try{return $read();}
        finally{self::$values=null;}
    }

    public static function once(string $kind,callable $read):array
    {
        if(self::$values===null)return $read();
        if(array_key_exists($kind,self::$values))return self::$values[$kind];
        $value=$read();
        // Only compact, verified catalogs belong here; full reports stay transient.
        if(count(self::$values)<4)self::$values[$kind]=$value;
        return $value;
    }
}
