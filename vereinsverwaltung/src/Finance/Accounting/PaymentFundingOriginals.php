<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Immutable original funding; callers own the financial authority and entry locks. */
final class PaymentFundingOriginals
{
    public static function json(array $data):string
    {
        return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private static function current(PDO $db,bool $current):string
    {
        if($current&&!$db->inTransaction())throw new DomainException('Ursprüngliche Zahlungszuordnungen gemeinsam aktuell prüfen.',409);
        return $current?' FOR SHARE NOWAIT':'';
    }

    public static function verify(array $row,array $allocation,?int $face=null):array
    {
        foreach(['original','ranges']as$field)if(!hash_equals($row[$field.'_sha256'],hash('sha256',$row[$field.'_json'])))throw new DomainException('Ursprünglichen Centnachweis der Zahlung prüfen lassen.',503);
        try{$original=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$ranges=json_decode($row['ranges_json'],true,128,JSON_THROW_ON_ERROR);}catch(\JsonException$error){throw new DomainException('Ursprünglichen Centnachweis der Zahlung prüfen lassen.',503,$error);}if(!is_array($original)||!is_array($ranges))throw new DomainException('Ursprünglichen Centnachweis der Zahlung prüfen lassen.',503);
        if(($original['format']??null)!=='dvm-payment-funding/1'||!in_array($original['basis']??null,['legacy-current','fill-after-return'],true))throw new DomainException('Ursprünglichen Centnachweis der Zahlung prüfen lassen.',503);
        foreach(['club_id','allocation_id','source_id','entry_id','cents','face_cents','ranges_sha256','created_by','created_at']as$field){$actual=$row[$field];$expected=$original['identity'][$field]??null;if(in_array($field,['cents','face_cents'],true)){$actual=(int)$actual;if(!is_int($expected))throw new DomainException('Identität des ursprünglichen Centnachweises stimmt nicht überein.',503);}if($actual!==$expected)throw new DomainException('Identität des ursprünglichen Centnachweises stimmt nicht überein.',503);}
        foreach(['id','club_id','transaction_id','entry_id','cents','request_key']as$field){$actual=$allocation[$field];$expected=$original['allocation'][$field]??null;if($field==='cents'){$actual=(int)$actual;$expected=is_int($expected)?$expected:null;}if($actual!==$expected)throw new DomainException('Centnachweis und tatsächliche ursprüngliche Zuordnung stimmen nicht überein.',503);}
        if($allocation['id']!==$row['allocation_id']||$allocation['club_id']!==$row['club_id']||$allocation['transaction_id']!==$row['source_id']||$allocation['entry_id']!==$row['entry_id']||(int)$allocation['cents']!==(int)$row['cents']||($face!==null&&$face!==(int)$row['face_cents']))throw new DomainException('Centnachweis und ursprünglicher Belegbetrag stimmen nicht überein.',503);
        try{$normal=PaymentFundingRanges::normalize($ranges,(int)$row['face_cents']);$cents=PaymentFundingRanges::cents($normal,(int)$row['face_cents']);}catch(DomainException$failure){throw new DomainException('Ursprüngliche Centbereiche der Zahlung prüfen lassen.',503,$failure);}
        if($ranges!==$normal||($original['ranges']??null)!==$ranges||$cents!==(int)$row['cents'])throw new DomainException('Ursprüngliche Centbereiche und Zahlungsbetrag stimmen nicht überein.',503);
        return $row+compact('original','ranges');
    }

    public static function read(PDO $db,string $club,string $id,bool $current=false):?array
    {
        $lock=self::current($db,$current);if(!PaymentFunding::available($db))return null;
        $q=$db->prepare('SELECT * FROM finance_allocation_funding WHERE club_id=? AND allocation_id=?'.$lock);$q->execute([$club,$id]);if(!$row=$q->fetch())return null;
        if($row['allocation_id']!==$id)throw new DomainException('Ursprüngliche Zahlungskennung erneut prüfen.',404);
        $q=$db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$id]);if(!$allocation=$q->fetch())throw new DomainException('Ursprüngliche Zahlungszuordnung fehlt.',503);
        return self::verify($row,$allocation);
    }

    /** Whole physical sources in the historical report order, including closed original money. */
    public static function allocations(PDO $db,string $club,string $entry,bool $current=false):array
    {
        $lock=self::current($db,$current);
        $q=$db->prepare('SELECT l.*,t.account_id AS source_account_id,t.booked_on AS source_booked_on,t.cents AS source_cents,t.source AS source_kind,t.reversal_of,returned.id AS returned_id FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE l.club_id=? AND l.entry_id=? ORDER BY t.booked_on,t.id,l.id'.$lock);$q->execute([$club,$entry]);return $q->fetchAll();
    }

    /** Plan the existing canonical contiguous funding without creating any baseline on preview. */
    public static function baseline(PDO $db,string $club,string $entry,int $face,bool $current=false):array
    {
        $lock=self::current($db,$current);$allocations=self::allocations($db,$club,$entry,$current);$stored=[];
        if(PaymentFunding::available($db)){$q=$db->prepare('SELECT * FROM finance_allocation_funding WHERE club_id=? AND entry_id=? ORDER BY allocation_id'.$lock);$q->execute([$club,$entry]);foreach($q as$row)$stored[$row['allocation_id']]=$row;}
        $hasBasis=(bool)$stored;$next=0;$proposals=[];
        foreach($allocations as$allocation){
            if($allocation['voided_at']!==null||$allocation['reversal_of']!==null||$allocation['returned_id']!==null)continue;
            $id=$allocation['id'];
            if(isset($stored[$id])){$record=self::verify($stored[$id],$allocation,$face);$ranges=$record['ranges'];}
            else{
                if($hasBasis)throw new DomainException('Eine spätere Zahlung hat keinen vollständigen ursprünglichen Centnachweis. Den gespeicherten Stand prüfen lassen.',503);
                $end=$next+(int)$allocation['cents'];if($end>$face)throw new DomainException('Ursprüngliche Zahlungen überschreiten den vollständigen Belegbetrag.',503);$ranges=[[$next,$end]];$next=$end;$record=null;
            }
            $proposals[$id]=['allocation'=>$allocation,'ranges'=>$ranges,'face_cents'=>$face,'existing'=>$record];
        }
        return ['has_basis'=>$hasBasis,'allocations'=>$proposals];
    }

    /** Store one already reviewed range as part of the caller's atomic financial decision. */
    public static function store(PDO $db,array $allocation,int $face,array $ranges,string $actor,string $created,string $basis):array
    {
        if(!$db->inTransaction())throw new DomainException('Centnachweis zusammen mit der tatsächlichen Zahlungszuordnung speichern.',409);
        if(!PaymentFunding::available($db))throw new DomainException('Teilrückzahlungen benötigen die vollständig abgeschlossene Paketwartung.',503);
        if(!in_array($basis,['legacy-current','fill-after-return'],true)||$allocation['voided_at']!==null||($allocation['reversal_of']??null)!==null||($allocation['returned_id']??null)!==null)throw new DomainException('Eine aktive tatsächliche ursprüngliche Zahlungszuordnung auswählen.',409);
        $ranges=PaymentFundingRanges::normalize($ranges,$face);if(PaymentFundingRanges::cents($ranges,$face)!==(int)$allocation['cents'])throw new DomainException('Centbereiche decken die ursprüngliche Zahlung nicht vollständig.',409);
        $rangesJson=self::json($ranges);$identity=['club_id'=>$allocation['club_id'],'allocation_id'=>$allocation['id'],'source_id'=>$allocation['transaction_id'],'entry_id'=>$allocation['entry_id'],'cents'=>(int)$allocation['cents'],'face_cents'=>$face,'ranges_sha256'=>hash('sha256',$rangesJson),'created_by'=>$actor,'created_at'=>$created];
        $raw=array_intersect_key($allocation,array_fill_keys(['id','club_id','transaction_id','entry_id','cents','request_key','voided_at','void_reason'],true));$raw['cents']=(int)$raw['cents'];$original=self::json(['format'=>'dvm-payment-funding/1','basis'=>$basis,'identity'=>$identity,'allocation'=>$raw,'ranges'=>$ranges]);
        $db->prepare('INSERT INTO finance_allocation_funding(club_id,allocation_id,source_id,entry_id,cents,face_cents,ranges_json,ranges_sha256,created_by,created_at,original_json,original_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$identity['club_id'],$identity['allocation_id'],$identity['source_id'],$identity['entry_id'],$identity['cents'],$face,$rangesJson,$identity['ranges_sha256'],$actor,$created,$original,hash('sha256',$original)]);
        return self::read($db,$identity['club_id'],$identity['allocation_id'],true)??throw new DomainException('Gespeicherten Centnachweis erneut prüfen.',503);
    }
}
