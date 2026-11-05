<?php declare(strict_types=1);
namespace Kulturhus\Native\Appearance;

/** Fixed native admin layouts. No user CSS, website template or stored HTML. */
final class Values
{
    public const VIEWS=['studio','focus','compact'];
    public const COLORS=['system','light','dark'];
    public const DENSITIES=['comfortable','compact'];
    public static function defaults():array{return ['admin_view'=>'studio','admin_color'=>'system','admin_density'=>'comfortable'];}
    public static function normalize(array $data):array
    {
        if(array_diff(array_keys($data),array_keys(self::defaults())))throw new \DomainException('Unbekannte Anzeigeeinstellung.');
        $result=[];
        foreach(['admin_view'=>self::VIEWS,'admin_color'=>self::COLORS,'admin_density'=>self::DENSITIES]as$key=>$allowed){
            $value=$data[$key]??self::defaults()[$key];
            if(!is_string($value)||!in_array($value,$allowed,true))throw new \DomainException('Anzeigeauswahl prüfen.');
            $result[$key]=$value;
        }
        return $result;
    }
    /** Old account payloads gain read-only defaults; reading never backfills a row. */
    public static function fromAccount(array $account):array
    {
        $selected=[];foreach(self::defaults()as$key=>$default)$selected[$key]=$account[$key]??$default;
        return self::normalize($selected);
    }
    public static function attributes(array $data):string
    {
        $data=self::normalize($data);$attributes='';
        foreach(['admin_view'=>'data-admin-view','admin_color'=>'data-admin-color','admin_density'=>'data-admin-density']as$key=>$attribute)$attributes.=' '.$attribute.'="'.$data[$key].'"';
        return $attributes;
    }
}
