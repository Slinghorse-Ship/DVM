<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ContributionRunInput
{
    public static function capture(array $raw):array
    {
        if(array_keys($raw)!==['data']||!is_array($raw['data'])||array_keys($raw['data'])!==['day']||!is_string($raw['data']['day'])||strlen($raw['data']['day'])>40000||preg_match('//u',$raw['data']['day'])!==1)throw new DomainException('Ganzen gewünschten Beitragsstichtag prüfen.',422);
        return ['data'=>['day'=>$raw['data']['day']]];
    }
    public static function today():string{return(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');}
    public static function normalize(array $raw):array
    {
        $raw=self::capture($raw);$day=$raw['data']['day'];$today=self::today();
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$day))throw new DomainException('Gültigen Beitragsstichtag im laufenden Jahr bis heute angeben.',422);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('Europe/Berlin'));
        if(!$date||$date->format('Y-m-d')!==$day||substr($day,0,4)!==substr($today,0,4)||$day>$today)throw new DomainException('Gültigen Beitragsstichtag im laufenden Jahr bis heute angeben.',422);
        return ['day'=>$day];
    }
}
