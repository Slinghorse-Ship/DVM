<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Pure execution firsts. Neither reading nor retrying can activate an old association. */
final class TransferSettlementOriginals
{
    private static function failure():never{throw new DomainException('Ursprünglichen Ausführungsnachweis des Bankauftrags prüfen lassen.',503);}

    public static function available(PDO $db):bool
    {
        $count=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('finance_transfer_settlements','finance_transfer_settlement_items')")->fetchColumn();
        if($count!==0&&$count!==2)throw new DomainException('Der Ausführungsnachweis benötigt die vollständig abgeschlossene Paketwartung.',503);return $count===2;
    }

    private static function decode(array $row,string $field):array
    {
        if(!isset($row[$field.'_json'],$row[$field.'_sha256'])||!hash_equals($row[$field.'_sha256'],hash('sha256',$row[$field.'_json'])))self::failure();
        try{$value=json_decode($row[$field.'_json'],true,128,JSON_THROW_ON_ERROR);}catch(\JsonException){self::failure();}if(!is_array($value))self::failure();return $value;
    }

    private static function same(array $expected,array $actual,array $fields,array $integers=[]):void
    {
        foreach($fields as$field){if(!array_key_exists($field,$expected)||!array_key_exists($field,$actual))self::failure();$value=$actual[$field];if(in_array($field,$integers,true)){if(!is_int($expected[$field]))self::failure();$value=(int)$value;}if($expected[$field]!==$value)self::failure();}
    }

    public static function read(PDO $db,string $club,string $id,bool $current=false):array
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Ausführungsnachweise gemeinsam prüfen.',409);
        if(!self::available($db))throw new DomainException('Ursprünglicher Ausführungsnachweis nicht verfügbar.',404);$lock=$current?' FOR SHARE NOWAIT':'';$q=$db->prepare('SELECT * FROM finance_transfer_settlements WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$id]);$row=$q->fetch();if(!$row||$row['id']!==$id)throw new DomainException('Ursprünglicher Ausführungsnachweis nicht verfügbar.',404);
        $request=self::decode($row,'request');$review=self::decode($row,'review');$result=self::decode($row,'result');$active=$row['voided_at']===null;
        if(($request['format']??null)!=='dvm-transfer-settlement-request/1'||($review['format']??null)!=='dvm-transfer-settlement-review/1'||($result['format']??null)!=='dvm-transfer-settlement-result/1'||($request['club_id']??null)!==$club||($review['club_id']??null)!==$club||($request['actor']??null)!==$row['created_by']||($review['actor']??null)!==$row['created_by']||($request['order_id']??null)!==$row['order_id']||($review['order']['order']['id']??null)!==$row['order_id']||($review['order']['order']['club_id']??null)!==$club||($review['order']['order']['state']??null)!=='submitted'||($request['transaction']??null)!==($review['transaction']??null)||($request['review_hash']??null)!==$row['review_sha256']||($request['confirmed']??false)!==true)self::failure();
        foreach(['reason','evidence']as$field)if(($request[$field]??null)!==$row[$field]||($review[$field]??null)!==$row[$field])self::failure();
        self::same($result,$row,['id','club_id','order_id','created_by','created_at','request_key']);
        if(!is_array($request['allocation_ids']??null)||!array_is_list($request['allocation_ids'])||!is_array($review['allocations']??null)||!array_is_list($review['allocations'])||!is_array($result['items']??null)||!array_is_list($result['items'])||!is_array($review['related_transactions']??null)||!array_is_list($review['related_transactions'])||!in_array($request['transaction'],$review['related_transactions'],true))self::failure();
        foreach([...$request['allocation_ids'],...$review['related_transactions']]as$identity)if(!is_string($identity)||$identity===''||strlen($identity)>128)self::failure();
        $q=$db->prepare('SELECT * FROM finance_transfer_settlement_items WHERE club_id=? AND settlement_id=? ORDER BY id'.$lock);$q->execute([$club,$id]);$physical=$q->fetchAll();$byId=[];foreach($physical as$item)$byId[$item['id']]=$item;$count=count($physical);if($count<1||$count>40||count($request['allocation_ids'])!==$count||count($review['allocations'])!==$count||count($result['items'])!==$count||count(array_unique($request['allocation_ids'],SORT_STRING))!==$count)self::failure();$items=[];$total=0;
        foreach($result['items']as$i=>$expected){
            if(!is_array($expected)||!isset($byId[$expected['id']??''])||!is_array($review['allocations'][$i]??null))self::failure();$item=$byId[$expected['id']];unset($byId[$expected['id']]);self::same($expected,$item,['club_id','id','settlement_id','allocation_id','source_id','entry_id','cents'],['cents']);
            if($item['club_id']!==$club||$item['settlement_id']!==$id||$request['allocation_ids'][$i]!==$item['allocation_id']||$item['entry_id']!==$review['order']['order']['entry_id']||(int)$item['cents']<1||($active?$item['released_at']!==null:$item['released_at']!==$row['voided_at']))self::failure();
            $q=$db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$item['allocation_id']]);$allocation=$q->fetch();if(!$allocation)self::failure();self::same($review['allocations'][$i]['allocation']??[],$allocation,['id','club_id','transaction_id','entry_id','cents','request_key'],['cents']);
            if($allocation['transaction_id']!==$item['source_id']||$allocation['entry_id']!==$item['entry_id']||(int)$allocation['cents']!==(int)$item['cents']||($active&&$allocation['voided_at']!==null))self::failure();
            $q=$db->prepare('SELECT t.*,returned.id AS returned_id FROM finance_transactions t LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE t.club_id=? AND t.id=?'.$lock);$q->execute([$club,$item['source_id']]);$source=$q->fetch();if(!$source)self::failure();self::same($review['allocations'][$i]['source']??[],$source,['id','club_id','account_id','booked_on','cents'],['cents']);
            if((int)$source['cents']>=0||$source['account_id']!==$review['order']['order']['account_id']||$source['booked_on']<$review['order']['order']['execution_date']||($active&&($source['reversal_of']!==null||$source['returned_id']!==null)))self::failure();$total+=(int)$item['cents'];$items[]=$item;
        }
        if($byId||$total!==(int)$review['order']['order']['total_cents'])self::failure();$void=null;
        if(!$active){
            if((int)$row['version']!==2)self::failure();$void=self::decode($row,'void');$vr=$void['request']??[];$vv=$void['review']??[];$vs=$void['result']??[];if(!is_array($vr)||!is_array($vv)||!is_array($vs))self::failure();
            if(($vr['format']??null)!=='dvm-transfer-settlement-revoke-request/1'||($vv['format']??null)!=='dvm-transfer-settlement-revoke-review/1'||($vr['id']??null)!==$id||($vv['id']??null)!==$id||($vr['club_id']??null)!==$club||($vv['club_id']??null)!==$club||($vr['actor']??null)!==$row['voided_by']||($vv['actor']??null)!==$row['voided_by']||($vr['reason']??null)!==$row['void_reason']||($vv['reason']??null)!==$row['void_reason']||($vr['transaction']??null)!==($vv['transaction']??null)||($vr['confirmed']??false)!==true||($vr['review_hash']??null)!==hash('sha256',PaymentFundingOriginals::json($vv)))self::failure();
            self::same($vs,$row,['id','club_id','voided_at','voided_by','void_reason','void_request_key']);
        }elseif((int)$row['version']!==1||$row['voided_by']!==null||$row['void_reason']!==''||$row['void_request_key']!==null||$row['void_json']!==null||$row['void_sha256']!==null)self::failure();
        return $row+compact('request','review','result','active','items','void');
    }
}
