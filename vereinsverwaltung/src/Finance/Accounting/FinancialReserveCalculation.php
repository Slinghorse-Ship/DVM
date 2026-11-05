<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use DateTimeImmutable;

/** Isolated original §6b(10) calculation. No runtime/account/XML qualification. */
final class FinancialReserveCalculation
{
    public const LIMIT=100000000000;
    public const FLAGS=['same_taxpayer','domestic_business','six_year_business_holding',
        'original_remaining_reserve','no_other_transfers','no_grandfathered_shares','original_election'];
    public const KEYS=['schema','year','taxpayer_kind','sale_year','held_since','sold_on',
        'sale_price_cents','sale_cost_cents','sold_tax_book_cents','formed_cents','available_cents',
        'reduction_cents','acquired_on','replacement_kind','cost_cents',...self::FLAGS];

    private static function need(bool $ok):void
    {if(!$ok)throw new DomainException('Ursprünglichen §6b-Absatz10-Fall vollständig prüfen; keine Körperschafts-, Konten- oder XML-Freigabe.');}

    private static function day(mixed $value):DateTimeImmutable
    {
        self::need(is_string($value)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)===1);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        self::need($date!==false&&$date->format('Y-m-d')===$value);
        return $date;
    }

    // The earliest supported replacement year2025 permits an original sale in2023.
    public static function calendarLimit(mixed $year):int
    {self::need(is_int($year)&&$year>=2023&&$year<=2027);return $year<=2026?50000000:200000000;}

    public static function derive(mixed $source):array
    {
        self::need(is_array($source));$keys=array_keys($source);$expected=self::KEYS;
        sort($keys);sort($expected);self::need($keys===$expected&&is_int($source['schema'])&&$source['schema']===1);
        self::need($source['taxpayer_kind']==='natural_person_sole_business');
        $year=$source['year'];$saleYear=$source['sale_year'];
        self::need(is_int($year)&&in_array($year,[2025,2026],true)&&is_int($saleYear)&&$saleYear>=$year-2&&$saleYear<$year);
        foreach(self::FLAGS as $flag)self::need($source[$flag]===true);
        self::need($source['replacement_kind']==='corporation_shares');
        $held=self::day($source['held_since']);$sold=self::day($source['sold_on']);$acquired=self::day($source['acquired_on']);
        self::need((int)$sold->format('Y')===$saleYear&&(int)$acquired->format('Y')===$year&&$held<$sold);
        self::need($sold->format('m-d')!=='02-29'&&$held<=$sold->modify('-6 years'));
        foreach(['sale_price_cents','sale_cost_cents','sold_tax_book_cents','formed_cents',
                 'available_cents','reduction_cents','cost_cents'] as $field)
            self::need(is_int($source[$field])&&$source[$field]>=0&&$source[$field]<=self::LIMIT);
        $gain=$source['sale_price_cents']-$source['sale_cost_cents']-$source['sold_tax_book_cents'];
        $n=$source['reduction_cents'];$remaining=$source['available_cents'];$formed=$source['formed_cents'];$cost=$source['cost_cents'];
        self::need($n>0&&$n<=$remaining&&$remaining<=$formed&&$formed<=$gain&&$formed<=self::calendarLimit($saleYear)&&$n<=$cost);
        return ['schema'=>1,'original_gain_cents'=>$gain,'original_gain_limit_cents'=>self::calendarLimit($saleYear),
            'tax_basis_cents'=>$cost-$n,'reserve_closing_cents'=>$remaining-$n,'reserve_release_cents'=>$n,
            'activation_ready'=>false,'native_xml_qualified'=>false];
    }
}
