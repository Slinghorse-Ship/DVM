<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Manual copies within one original photo; no URLs or embedded replacement pixels. */
final class PhotoClones
{
    public const LIMIT=64;
    public const DOCUMENT_LIMIT=256;
    public static function validate(mixed $input):array
    {
        if(!is_array($input)||!array_is_list($input)||count($input)>self::LIMIT)throw new \DomainException('Höchstens 64 Klonstempel je Foto erlaubt.');
        $result=[];$keys=['source_x','source_y','target_x','target_y','radius'];
        foreach($input as $stamp){
            if(!is_array($stamp)||array_diff(array_keys($stamp),[...$keys,'softness','mode'])||array_diff($keys,array_keys($stamp)))throw new \DomainException('Vollständigen Klonstempel angeben.');
            $value=[];foreach($keys as $key){$number=$stamp[$key];if((!is_int($number)&&!is_float($number))||!is_finite((float)$number)||$number<0||$number>100)throw new \DomainException('Klonstempel benötigt Prozentwerte zwischen 0 und 100.');$value[$key]=(float)$number;}
            if($value['radius']<1||$value['radius']>25)throw new \DomainException('Stempelradius zwischen 1 und 25 Prozent angeben.');
            foreach(['source_x','source_y','target_x','target_y']as $key)if($value[$key]<$value['radius']||$value[$key]>100-$value['radius'])throw new \DomainException('Quell- und Zielmitte müssen mindestens einen Stempelradius vom Bildrand entfernt liegen.');
            if(array_key_exists('softness',$stamp)){$number=$stamp['softness'];if((!is_int($number)&&!is_float($number))||!is_finite((float)$number)||$number<0||$number>100)throw new \DomainException('Randweichheit zwischen 0 und 100 Prozent angeben.');$value['softness']=(float)$number;}
            if(array_key_exists('mode',$stamp)){if($stamp['mode']!=='heal')throw new \DomainException('Gültigen Reparaturpinsel wählen.');$value['mode']='heal';}
            $result[]=$value;
        }
        return $result;
    }
}
