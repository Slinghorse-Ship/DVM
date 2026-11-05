<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Physical current reads; never use an outer UNION lock as a financial decision lock. */
final class PaymentFundingLedger
{
    private static function lock(PDO $db,bool $current):string
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Zahlungsstände gemeinsam prüfen.',409);return $current?' FOR SHARE NOWAIT':'';
    }

    public static function entry(PDO $db,string $club,string $entry,int $face,bool $current=false):array
    {
        $lock=self::lock($db,$current);$basis=PaymentFundingOriginals::baseline($db,$club,$entry,$face,$current);$returns=[];
        if(PaymentFunding::available($db)){
            $q=$db->prepare('SELECT part.return_id FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=? AND part.entry_id=? AND refund.voided_at IS NULL ORDER BY part.return_id,part.ordinal'.$lock);$q->execute([$club,$entry]);$ids=array_unique($q->fetchAll(PDO::FETCH_COLUMN));
            foreach($ids as$id)$returns[$id]=PartialReturnOriginals::read($db,$club,$id,$current);
        }
        $byAllocation=[];$returned=0;
        foreach($returns as$refund)foreach($refund['items']as$item)if($item['entry_id']===$entry){
            if(!isset($basis['allocations'][$item['allocation_id']]))throw new DomainException('Eine Teilrückzahlung hat keine aktive ursprüngliche Zahlungszuordnung. Den gespeicherten Stand prüfen lassen.',503);
            $byAllocation[$item['allocation_id']][]=$item;$returned+=(int)$item['cents'];
        }
        $allocations=[];$occupied=[];$gross=0;
        foreach($basis['allocations']as$id=>$source){
            $ranges=[];foreach($byAllocation[$id]??[]as$item)array_push($ranges,...$item['ranges']);
            try{$net=PaymentFundingRanges::subtract($source['ranges'],$ranges,$face);$cents=PaymentFundingRanges::cents($net,$face);}catch(DomainException$failure){throw new DomainException('Ursprüngliche und bereits zurückgezahlte Centbereiche stimmen nicht überein. Den gespeicherten Stand prüfen lassen.',503,$failure);}
            $allocations[$id]=$source+['returned_items'=>$byAllocation[$id]??[],'remaining_ranges'=>$net,'remaining_cents'=>$cents];array_push($occupied,...$net);$gross+=(int)$source['allocation']['cents'];
        }
        try{$occupied=PaymentFundingRanges::normalize($occupied,$face);$paid=PaymentFundingRanges::cents($occupied,$face);}catch(DomainException$failure){throw new DomainException('Die ursprünglichen Zahlungen beanspruchen dieselben Belegcents. Den gespeicherten Stand prüfen lassen.',503,$failure);}
        if($gross-$returned!==$paid)throw new DomainException('Zahlungsstand und vollständiger Centnachweis stimmen nicht überein.',503);
        return ['entry_id'=>$entry,'face_cents'=>$face,'has_basis'=>$basis['has_basis'],'allocations'=>$allocations,'returns'=>$returns,'gross_cents'=>$gross,'returned_cents'=>$returned,'paid_cents'=>$paid,'occupied'=>$occupied,'vacant'=>PaymentFundingRanges::vacant($occupied,$face)];
    }

    public static function counter(PDO $db,string $club,string $counter,bool $current=false):array
    {
        $lock=self::lock($db,$current);$returns=[];$cents=0;if(!PaymentFunding::available($db))return compact('returns','cents');
        $q=$db->prepare('SELECT id FROM finance_partial_returns WHERE club_id=? AND counter_id=? AND voided_at IS NULL ORDER BY id'.$lock);$q->execute([$club,$counter]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as$id){$first=PartialReturnOriginals::read($db,$club,$id,$current);$returns[$id]=$first;$cents+=(int)$first['cents'];}
        return compact('returns','cents');
    }

    public static function hasBasis(PDO $db,string $club,string $entry,bool $current=false):bool
    {
        $lock=self::lock($db,$current);if(!PaymentFunding::available($db))return false;
        $q=$db->prepare('SELECT allocation_id FROM finance_allocation_funding WHERE club_id=? AND entry_id=? LIMIT 1'.$lock);$q->execute([$club,$entry]);return $q->fetchColumn()!==false;
    }

    /** A preview creates nothing. A later accepted decision stores every original baseline atomically. */
    public static function storeBaselines(PDO $db,array $ledger,string $actor,string $created):array
    {
        $stored=[];
        foreach($ledger['allocations']as$id=>$funding)$stored[$id]=$funding['existing']??PaymentFundingOriginals::store($db,$funding['allocation'],(int)$ledger['face_cents'],$funding['ranges'],$actor,$created,'legacy-current');
        return $stored;
    }
}
