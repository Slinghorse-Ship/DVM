<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Integer cent intervals inside an original receipt. No money or allocations are rewritten. */
final class PaymentFundingRanges
{
    public const MAX_CENTS=100000000000;

    /** Canonical disjoint half-open intervals; adjacent intervals share the same funding. */
    public static function normalize(array $ranges,int $face):array
    {
        if($face<1||$face>self::MAX_CENTS)throw new DomainException('Ursprünglichen vollständigen Belegbetrag prüfen.',422);
        foreach($ranges as$range)if(!is_array($range)||array_keys($range)!==[0,1]||!is_int($range[0])||!is_int($range[1])||$range[0]<0||$range[1]<=$range[0]||$range[1]>$face)throw new DomainException('Centbereiche der ursprünglichen Zahlungszuordnung stimmen nicht überein.',422);
        usort($ranges,static fn(array$a,array$b):int=>$a<=>$b);$out=[];
        foreach($ranges as$range){
            $last=count($out)-1;
            if($last>=0&&$range[0]<$out[$last][1])throw new DomainException('Dieselben Belegcents sind bereits einer anderen Zahlung zugeordnet.',409);
            if($last>=0&&$range[0]===$out[$last][1])$out[$last][1]=$range[1];else $out[]=$range;
        }
        return $out;
    }

    public static function cents(array $ranges,int $face):int
    {
        $sum=0;foreach(self::normalize($ranges,$face)as[$start,$end])$sum+=$end-$start;return $sum;
    }

    /** Remove only cents actually held by this original payment. Never move another payment. */
    public static function subtract(array $funded,array $returned,int $face):array
    {
        $funded=self::normalize($funded,$face);$returned=self::normalize($returned,$face);$out=[];$index=0;
        foreach($funded as[$start,$end]){
            $cursor=$start;
            while(isset($returned[$index])&&$returned[$index][0]<$end){
                [$a,$b]=$returned[$index];
                if($a<$cursor||$b>$end)throw new DomainException('Der gewählte Teilbetrag gehört nicht vollständig zu dieser ursprünglichen Zahlung.',409);
                if($a>$cursor)$out[]=[$cursor,$a];$cursor=$b;++$index;
            }
            if($cursor<$end)$out[]=[$cursor,$end];
        }
        if($index!==count($returned))throw new DomainException('Der gewählte Teilbetrag gehört nicht vollständig zu dieser ursprünglichen Zahlung.',409);
        return self::normalize($out,$face);
    }

    public static function vacant(array $occupied,int $face):array
    {
        return self::subtract([[0,$face]],$occupied,$face);
    }

    /** Choose a precise amount from the first vacant or last refundable cents. */
    public static function take(array $available,int $amount,int $face,bool $fromEnd=false):array
    {
        $available=self::normalize($available,$face);
        if($amount<1||$amount>self::cents($available,$face))throw new DomainException('Der Teilbetrag übersteigt die noch verfügbare ursprüngliche Zahlung.',409);
        $remaining=$amount;$taken=[];$parts=$fromEnd?array_reverse($available):$available;
        foreach($parts as[$start,$end]){
            $use=min($remaining,$end-$start);$taken[]=$fromEnd?[$end-$use,$end]:[$start,$start+$use];$remaining-=$use;if($remaining===0)break;
        }
        $taken=self::normalize($taken,$face);
        return ['taken'=>$taken,'remaining'=>self::subtract($available,$taken,$face)];
    }

    public static function fill(array $occupied,int $amount,int $face):array
    {
        return self::take(self::vacant($occupied,$face),$amount,$face)['taken'];
    }

    /** A revoked return can restore its exact cents only while that range is still vacant. */
    public static function restore(array $occupied,array $original,int $face):array
    {
        return self::normalize([...self::normalize($occupied,$face),...self::normalize($original,$face)],$face);
    }
}
