<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Explicit inclusive accounting periods. A calendar-year label is not a period identity. */
final class BalancePeriod
{
    private static function date(mixed $value):DateTimeImmutable
    {
        if(!is_string($value)||!preg_match('/^(?:19|20|21)[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$value))throw new DomainException('Beginn und Ende im Format JJJJ-MM-TT angeben.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException('Gültiges Datum für den Abschlusszeitraum angeben.');
        return $date;
    }

    public static function check(array $period,?int $closingYear=null):array
    {
        if(count($period)!==2||!array_key_exists('start',$period)||!array_key_exists('end',$period))throw new DomainException('Beginn und Ende des Abschlusszeitraums vollständig angeben.');
        $start=self::date($period['start']);$end=self::date($period['end']);
        if($end<$start||$end>$start->modify('+1 year')->modify('-1 day'))throw new DomainException('Ein Abschlusszeitraum darf höchstens zwölf Monate umfassen.');
        if($closingYear!==null&&(int)$end->format('Y')!==$closingYear)throw new DomainException('Abschlussjahr und Enddatum stimmen nicht überein.');
        return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d')];
    }

    public static function calendar(int $year):array
    {
        return self::check(['start'=>sprintf('%04d-01-01',$year),'end'=>sprintf('%04d-12-31',$year)],$year);
    }

    public static function key(array $period):string
    {
        $p=self::check($period);return str_replace('-','',$p['start']).'-'.str_replace('-','',$p['end']);
    }

    public static function label(array $period):string
    {
        $p=self::check($period);return self::date($p['start'])->format('d.m.Y').' – '.self::date($p['end'])->format('d.m.Y');
    }

    public static function contains(array $period,string $date):bool
    {
        $p=self::check($period);self::date($date);return $date>=$p['start']&&$date<=$p['end'];
    }

    /** No guessing a twelve-month predecessor for a short or changed financial year. */
    public static function follows(array $previous,array $current):bool
    {
        $a=self::check($previous);$b=self::check($current);
        return self::date($a['end'])->modify('+1 day')->format('Y-m-d')===$b['start'];
    }

    /** Months used for monthly depreciation; split months require a reviewed daily allocation. */
    public static function isFullMonths(array $period):bool
    {
        $p=self::check($period);$a=self::date($p['start']);$b=self::date($p['end']);return $a->format('d')==='01'&&$b->format('d')===$b->format('t');
    }
    public static function fullMonths(array $period):int
    {
        $p=self::check($period);$a=self::date($p['start']);$b=self::date($p['end']);
        if($a->format('d')!=='01'||$b->format('d')!==$b->format('t'))throw new DomainException('Angebrochene Monate benötigen eine gesondert geprüfte Abschreibungsaufteilung.');
        return ((int)$b->format('Y')-(int)$a->format('Y'))*12+(int)$b->format('n')-(int)$a->format('n')+1;
    }
}
