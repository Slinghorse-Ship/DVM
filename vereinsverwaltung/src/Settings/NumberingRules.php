<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** Existing numbering semantics with Unicode text limits and closed original rules. */
final class NumberingRules
{
    public const KINDS=['invoice','expense','member'];
    public static function normalize(array $data):array
    {
        if(array_diff(array_keys($data),self::KINDS)||array_diff(self::KINDS,array_keys($data)))throw new DomainException('Nummernkreis unvollständig.',422);$out=[];
        foreach(self::KINDS as$kind){
            $row=$data[$kind];$keys=['prefix','date','width','suffix','next'];
            if(!is_array($row)||array_diff(array_keys($row),$keys)||array_diff($keys,array_keys($row)))throw new DomainException('Nummernkreis unvollständig.',422);
            try{$rule=['prefix'=>GeneralValues::text($row['prefix'],30),'date'=>SettingsSchema::choice($row['date'],['none','year','month']),'width'=>SettingsSchema::integer($row['width'],1,9),'suffix'=>GeneralValues::text($row['suffix'],20),'next'=>SettingsSchema::integer($row['next'],1,999999999)];}
            catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
            foreach(['prefix','suffix']as$field)if(!preg_match('/\A[\pL\pN ._\/-]*\z/uD',$rule[$field]))throw new DomainException('Präfix/Suffix enthält unzulässige Zeichen.',422);
            $out[$kind]=$rule;
        }
        return$out;
    }
}
