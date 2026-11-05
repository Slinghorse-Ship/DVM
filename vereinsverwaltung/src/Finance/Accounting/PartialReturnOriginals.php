<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Pure financial firsts. Reading never changes the original decision or reactivates it. */
final class PartialReturnOriginals
{
    private static function failure():never{throw new DomainException('Ursprünglichen Nachweis der Teilrückzahlung prüfen lassen.',503);}
    private static function decode(array $row,string $field):array
    {
        if(!isset($row[$field.'_json'],$row[$field.'_sha256'])||!hash_equals($row[$field.'_sha256'],hash('sha256',$row[$field.'_json'])))self::failure();
        try{$data=json_decode($row[$field.'_json'],true,128,JSON_THROW_ON_ERROR);}catch(\JsonException){self::failure();}
        if(!is_array($data))self::failure();return $data;
    }
    private static function same(array $expected,array $actual,array $fields,array $integers=[]):void
    {
        foreach($fields as$field){if(!array_key_exists($field,$expected)||!array_key_exists($field,$actual))self::failure();$a=$actual[$field];$e=$expected[$field];if(in_array($field,$integers,true)){if(!is_int($e))self::failure();$a=(int)$a;}if($a!==$e)self::failure();}
    }

    public static function read(PDO $db,string $club,string $id,bool $current=false):array
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Teilrückzahlungen gemeinsam prüfen.',409);
        if(!PaymentFunding::available($db))throw new DomainException('Ursprünglicher Teilrückzahlungsnachweis nicht verfügbar.',404);
        $lock=$current?' FOR SHARE NOWAIT':'';$q=$db->prepare('SELECT * FROM finance_partial_returns WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$id]);$row=$q->fetch();if(!$row||$row['id']!==$id)throw new DomainException('Ursprünglicher Teilrückzahlungsnachweis nicht verfügbar.',404);
        $request=self::decode($row,'request');$review=self::decode($row,'review');$result=self::decode($row,'result');$active=$row['voided_at']===null;
        if(($request['format']??null)!=='dvm-partial-return-request/1'||($review['format']??null)!=='dvm-partial-return-review/1'||($result['format']??null)!=='dvm-partial-return-result/1'||($request['club_id']??null)!==$club||($review['club_id']??null)!==$club||($request['actor']??null)!==$row['created_by']||($review['actor']??null)!==$row['created_by']||($request['counter']??null)!==$row['counter_id']||($review['counter']['id']??null)!==$row['counter_id']||($request['counter_version']??null)!==($review['counter']['version']??null)||($request['reason']??null)!==$row['reason']||($review['reason']??null)!==$row['reason']||($request['review_hash']??null)!==$row['review_sha256']||($request['confirmed']??false)!==true)self::failure();
        self::same($result,$row,['id','club_id','counter_id','cents','created_by','created_at','request_key'],['cents']);
        if(!is_array($request['amounts']??null)||!array_is_list($request['amounts'])||!is_array($review['selected']??null)||!array_is_list($review['selected'])||!is_array($result['items']??null)||!array_is_list($result['items'])||(!is_array($result['funding']??null)||!array_is_list($result['funding'])))self::failure();
        $q=$db->prepare('SELECT * FROM finance_partial_return_items WHERE club_id=? AND return_id=? ORDER BY ordinal'.$lock);$q->execute([$club,$id]);$items=$q->fetchAll();$count=count($items);if($count<1||$count>40||count($request['amounts'])!==$count||count($review['selected'])!==$count||count($result['items'])!==$count)self::failure();
        $q=$db->prepare('SELECT t.*,returned.id AS returned_id FROM finance_transactions t LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE t.club_id=? AND t.id=?'.$lock);$q->execute([$club,$row['counter_id']]);if(!$counter=$q->fetch())self::failure();self::same($review['counter'],$counter,['id','club_id','account_id','booked_on','cents'],['cents']);
        $total=0;$fundingHashes=[];foreach($result['funding']as$hash){if(!is_array($hash)||!is_string($hash['allocation_id']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash['original_sha256']??'')||isset($fundingHashes[$hash['allocation_id']]))self::failure();$fundingHashes[$hash['allocation_id']]=$hash['original_sha256'];}
        foreach($items as$i=>$item){
            if(!is_array($result['items'][$i])||!is_array($review['selected'][$i])||!is_array($request['amounts'][$i]))self::failure();
            self::same($result['items'][$i],$item,['club_id','id','return_id','allocation_id','source_id','entry_id','ordinal','cents','ranges_json','ranges_sha256'],['ordinal','cents']);
            if((int)$item['ordinal']!==$i+1||$item['return_id']!==$id||$item['club_id']!==$club||($request['amounts'][$i]['allocation_id']??null)!==$item['allocation_id']||($review['selected'][$i]['amount_cents']??null)!==(int)$item['cents'])self::failure();
            try{if(!is_string($request['amounts'][$i]['amount']??null)||Finance::cents($request['amounts'][$i]['amount'])!==(int)$item['cents'])self::failure();}catch(DomainException){self::failure();}
            $funding=PaymentFundingOriginals::read($db,$club,$item['allocation_id'],$current);if(!$funding||($fundingHashes[$item['allocation_id']]??null)!==$funding['original_sha256']||$funding['source_id']!==$item['source_id']||$funding['entry_id']!==$item['entry_id'])self::failure();
            $q=$db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$item['allocation_id']]);if(!$allocation=$q->fetch())self::failure();self::same($review['selected'][$i]['allocation']??[],$allocation,['id','club_id','transaction_id','entry_id','cents','request_key'],['cents']);if($active&&$allocation['voided_at']!==null)self::failure();
            $q=$db->prepare('SELECT t.*,returned.id AS returned_id FROM finance_transactions t LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE t.club_id=? AND t.id=?'.$lock);$q->execute([$club,$item['source_id']]);if(!$source=$q->fetch())self::failure();self::same($review['selected'][$i]['source']??[],$source,['id','club_id','account_id','booked_on','cents'],['cents']);if(($source['cents']>0)===($counter['cents']>0)||($active&&($source['reversal_of']!==null||$source['returned_id']!==null||$counter['reversal_of']!==null||$counter['returned_id']!==null)))self::failure();
            $ranges=self::decode($item,'ranges');
            try{if($ranges!==PaymentFundingRanges::normalize($ranges,(int)$funding['face_cents'])||PaymentFundingRanges::cents($ranges,(int)$funding['face_cents'])!==(int)$item['cents']||($review['selected'][$i]['ranges']??null)!==$ranges)self::failure();PaymentFundingRanges::subtract($funding['ranges'],$ranges,(int)$funding['face_cents']);}catch(DomainException){self::failure();}
            $total+=(int)$item['cents'];$items[$i]=$item+compact('ranges','funding');
        }
        if($total!==(int)$row['cents']||$total<1||$total>abs((int)$counter['cents']))self::failure();
        $void=null;
        if(!$active){
            if((int)$row['version']!==2)self::failure();$void=self::decode($row,'void');$vr=$void['request']??[];$vv=$void['review']??[];$vs=$void['result']??[];if(!is_array($vr)||!is_array($vv)||!is_array($vs))self::failure();
            if(($vr['format']??null)!=='dvm-partial-return-unlink-request/1'||($vv['format']??null)!=='dvm-partial-return-unlink-review/1'||($vr['id']??null)!==$id||($vv['id']??null)!==$id||($vr['club_id']??null)!==$club||($vv['club_id']??null)!==$club||($vr['actor']??null)!==$row['voided_by']||($vv['actor']??null)!==$row['voided_by']||($vr['reason']??null)!==$row['void_reason']||($vv['reason']??null)!==$row['void_reason']||($vr['confirmed']??false)!==true||($vr['review_hash']??null)!==hash('sha256',PaymentFundingOriginals::json($vv)))self::failure();
            self::same($vs,$row,['id','club_id','voided_at','voided_by','void_reason','void_request_key']);
        }elseif((int)$row['version']!==1||$row['voided_by']!==null||$row['void_reason']!==''||$row['void_request_key']!==null||$row['void_json']!==null||$row['void_sha256']!==null)self::failure();
        $first=$row+compact('request','review','result','items','active','void');NativeDebitReturnOriginals::verify($db,$first,$current);ReimbursementReturnOriginals::verify($db,$first,$current);return $first;
    }
}
