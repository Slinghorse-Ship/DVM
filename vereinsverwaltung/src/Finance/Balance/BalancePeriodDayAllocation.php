<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use Brick\Math\{BigRational,RoundingMode};
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Civil-day allocation of an original monthly clock; no journal or tax approval. */
final class BalancePeriodDayAllocation
{
    public const POLICY='original-months-cumulative-days-v1';
    public static function policy(array $definition):?string
    {
        if(!array_key_exists('day_allocation',$definition))return null;
        if($definition['day_allocation']!==['policy'=>self::POLICY,'scope'=>'internal_books','reviewed'=>true])throw new DomainException('Ursprünglichen bestätigten Nachweis der internen Tagesaufteilung prüfen.');return self::POLICY;
    }
    public static function assertTaxQualified(array $report):void
    {
        if(isset($report['period'])&&(!BalancePeriod::isFullMonths($report['period'])||array_key_exists('day_allocation',$report['definition']??[])))throw new DomainException('Interne Teilmonatsaufteilung bestätigt keine steuerliche Jahres-AfA. Einen fachlich qualifizierten Steuerabschluss verwenden.');
    }
    private static function date(string $value):DateTimeImmutable
    {
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value))throw new DomainException('Gültige ursprüngliche Tagesgrenze angeben.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException('Gültige ursprüngliche Tagesgrenze angeben.');return $date;
    }
    public static function before(string $date):string{return self::date($date)->modify('-1 day')->format('Y-m-d');}
    public static function month(string $date):array
    {
        $date=self::date($date);return ['start'=>$date->format('Y-m-01'),'end'=>$date->format('Y-m-t')];
    }
    private static function runtime():void{require_once dirname(__DIR__,4).'/vendor/autoload.php';}
    /** Cumulative active month-equivalents at an inclusive day boundary. */
    public static function age(string $acquired,string $through):BigRational
    {
        self::runtime();$start=self::date($acquired);$end=self::date($through);if($end<$start)return BigRational::of(0);
        $months=((int)$end->format('Y')-(int)$start->format('Y'))*12+(int)$end->format('n')-(int)$start->format('n');
        $first=$months===0?(int)$start->format('j'):1;$days=(int)$end->format('j')-$first+1;$available=(int)$end->format('t')-$first+1;
        return BigRational::of($months)->plus(BigRational::of($days)->dividedBy($available));
    }
    public static function rounded(BigRational $amount):int{return $amount->toScale(0,RoundingMode::HalfUp)->toInt();}
    /** Split already rounded original monthly cents, retaining both month endpoints. */
    public static function cumulative(int $basis,int $residual,int $months,string $acquired,string $through):int
    {
        if($basis<=0||$basis>BalanceCalculation::LIMIT||$residual<0||$residual>=$basis||$months<1||$months>1200)throw new DomainException('Ursprüngliche monatliche Anschaffungsbasis und Nutzungsdauer prüfen.');
        $start=self::date($acquired);$end=self::date($through);if($end<$start)return 0;
        $index=((int)$end->format('Y')-(int)$start->format('Y'))*12+(int)$end->format('n')-(int)$start->format('n');$depreciable=$basis-$residual;
        if($index>=$months)return $depreciable;
        $opening=intdiv($depreciable*$index+intdiv($months,2),$months);$closing=intdiv($depreciable*($index+1)+intdiv($months,2),$months);
        $first=$index===0?(int)$start->format('j'):1;$used=(int)$end->format('j')-$first+1;$available=(int)$end->format('t')-$first+1;
        return $opening+intdiv(($closing-$opening)*$used+intdiv($available,2),$available);
    }
    public static function linear(array $plan,array $period,?array $previous=null,?string $disposalDate=null):array
    {
        $period=BalancePeriod::check($period);$method=$plan['method']??'linear_monthly';
        if(!in_array($method,['linear_monthly','gwg_immediate'],true))throw new DomainException('Den ursprünglichen Abschreibungsweg für diese Tagesaufteilung nachweisen.');
        // Validate with the unchanged original whole-month calculator.
        BalancePeriodAssets::calculate($plan,self::month($period['start']));
        $date=$plan['acquired_on'];$basis=$plan['basis_cents'];$before=self::before($period['start']);
        $at=static fn(string$boundary):int=>$method==='gwg_immediate'?($date<=$boundary?$basis:0):self::cumulative($basis,$plan['residual_cents'],$plan['months'],$date,$boundary);
        if($disposalDate!==null&&(!BalancePeriod::contains($period,$disposalDate)||$disposalDate<$date))throw new DomainException('Den tatsächlichen Abgang im Abschlusszeitraum nachweisen.');
        $through=$disposalDate===null?$period['end']:self::month($disposalDate)['end'];
        $opening=$at($before);$closing=$at($through);$costOpening=$date<$period['start']?$basis:0;$costClosing=$date<=$period['end']?$basis:0;
        $out=['period'=>$period,'cost_opening_cents'=>$costOpening,'additions_cents'=>$costClosing-$costOpening,'cost_closing_cents'=>$costClosing,
            'accumulated_opening_cents'=>$opening,'depreciation_cents'=>$closing-$opening,'accumulated_closing_cents'=>$closing,
            'book_opening_cents'=>$costOpening-$opening,'book_closing_cents'=>$costClosing-$closing];
        if($previous!==null){
            if(!isset($previous['period'])||!BalancePeriod::follows($previous['period'],$period))throw new DomainException('Tagesaufteilung benötigt den unveränderten unmittelbar vorherigen Anlagenstand.');
            foreach(['cost','accumulated','book']as$key)if(($previous[$key.'_closing_cents']??null)!==$out[$key.'_opening_cents'])throw new DomainException('Ursprünglicher Centvortrag stimmt nicht mit dieser Tagesaufteilung überein.');
        }
        return $out;
    }
}
