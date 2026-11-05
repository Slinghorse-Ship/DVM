<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\Finance;

/** Closed personal-price input; the first raw amount remains distinct from its units. */
final class PersonalPriceInput
{
    public static function capture(array $raw):array
    {
        $keys=array_keys($raw);sort($keys);
        if($keys!==['data','id','tab','version']||!in_array($raw['tab']??null,['rates','services'],true))throw new DomainException('Vollständige ursprüngliche Preisangaben prüfen.',422);
        $id=CatalogInput::id($raw['id']);$version=GeneralInput::version($raw['version']);
        if(!is_array($raw['data']))throw new DomainException('Vollständige persönliche Preisfelder prüfen.',422);
        $keys=array_keys($raw['data']);sort($keys);
        if($keys!==['mode','value']||!in_array($raw['data']['mode']??null,['none','fixed','percent'],true))throw new DomainException('Ursprüngliche persönliche Preisart auswählen.',422);
        $value=$raw['data']['value'];
        if(!is_string($value)||strlen($value)>10000||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Persönlicher Preis enthält ungültige Daten.',422);
        return['tab'=>$raw['tab'],'id'=>$id,'version'=>(string)$version,'data'=>$raw['data']];
    }
    public static function normalize(array $input):array
    {
        $input=self::capture($input);$data=$input['data'];
        if(strlen($data['value'])>30)throw new DomainException('Vollständigen persönlichen Preis mit höchstens 30 Zeichen prüfen.',422);
        try{$units=$data['mode']==='none'?0:Finance::cents($data['value']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
        if($data['mode']==='percent'&&$units>10000)throw new DomainException('Rabatt zwischen 0 und 100 Prozent auswählen.',422);
        return['mode'=>$data['mode'],'value_units'=>$units];
    }
}
