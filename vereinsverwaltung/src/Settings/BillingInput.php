<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;

/** Exact original local billing selection and dates, distinct from their validated values. */
final class BillingInput
{
    public static function capture(array $raw):array
    {
        $keys=array_keys($raw);sort($keys);
        if($keys!==['assignments','data','tab']||!in_array($raw['tab']??null,['rates','services'],true)||!is_array($raw['assignments'])||!array_is_list($raw['assignments'])||count($raw['assignments'])<1||count($raw['assignments'])>6||!is_array($raw['data']))throw new DomainException('Eine bis sechs ursprüngliche eigene Tarifzuweisungen auswählen.',422);
        $seen=[];foreach($raw['assignments']as$id){CatalogInput::id($id);if(isset($seen[$id]))throw new DomainException('Jede Tarifzuweisung nur einmal auswählen.',422);$seen[$id]=true;}
        $keys=array_keys($raw['data']);sort($keys);if($keys!==['date','due','mode','price_day'])throw new DomainException('Vollständige ursprüngliche Abrechnungsfelder prüfen.',422);
        foreach($raw['data']as$value)if(!is_string($value)||strlen($value)>10000||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Abrechnung enthält ungültige Daten.',422);
        return['tab'=>$raw['tab'],'assignments'=>$raw['assignments'],'data'=>$raw['data']];
    }
    private static function date(string $raw):string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw);if(!$date||$date->format('Y-m-d')!==$raw)throw new DomainException('Vollständiges Abrechnungsdatum im Format JJJJ-MM-TT prüfen.',422);return$raw;
    }
    public static function normalize(array $input):array
    {
        $input=self::capture($input);$data=$input['data'];$day=self::date($data['date']);$due=self::date($data['due']);$priceDay=$data['price_day']===''?null:self::date($data['price_day']);
        if($day>date('Y-m-d')||$due<$day)throw new DomainException('Abrechnungsdatum bis heute und Zahlungsziel ab Abrechnungsdatum auswählen.',422);
        if(!in_array($data['mode'],['full','days'],true))throw new DomainException('Vollen Tarifbetrag oder anteilige Tage auswählen.',422);
        return['date'=>$day,'due'=>$due,'mode'=>$data['mode'],'price_day'=>$priceDay];
    }
}
