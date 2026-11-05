<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Pure permanent coupling: a returned native item cannot lose its financial first. */
final class NativeDebitReturnOriginals
{
    private static function fail():never{throw new DomainException('Ursprüngliche Rücklastschrift und finanzielle Rückzahlungszuordnung gemeinsam prüfen lassen.',503);}
    public static function verify(PDO $db,array $first,bool $current=false):void
    {
        $request=$first['request'];$review=$first['review'];$result=$first['result'];$origin=$request['native_debit']??null;
        if($origin===null){if(isset($review['native_debit'])||isset($result['native_debit']))self::fail();return;}
        if(!is_array($origin)||array_keys($origin)!==['order_id','item_id','return_record_id','native_request_key'])self::fail();foreach($origin as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))self::fail();
        $native=$review['native_debit']??null;$stored=$result['native_debit']??null;if(!is_array($native)||!is_array($stored)||($stored['origin']??null)!==$origin||($native['item']['id']??null)!==$origin['item_id']||($native['item']['order_id']??null)!==$origin['order_id']||($native['order']['id']??null)!==$origin['order_id']||!in_array($native['item']['state']??null,['active','returned'],true)||(int)($native['item']['cents']??0)!==(int)$first['cents']||$first['request_key']!==substr(hash('sha256',"dvm-native-debit-financial/1\0".$first['club_id']."\0".$origin['native_request_key']),0,32))self::fail();
        $lock=$current?' FOR SHARE NOWAIT':'';$record=NativeDebitReturnHistory::record($db,$first['club_id'],$origin['return_record_id'],$current);if(!$record||$record!==($stored['return_record']??null)||$record['item_id']!==$origin['item_id']||$record['transaction_id']!==$first['counter_id']||$record['reason']!==trim($first['reason'])||$record['actor_id']!==$first['created_by'])self::fail();
        if(($native['item']['state']??null)==='returned'){ $event=NativeDebitReturnHistory::event($db,$first['club_id'],$record['id'],$current);if(($event['kind']??null)!=='attach')self::fail();}
        if($record['document_id']!==($native['return_document']['document']['id']??null))self::fail();
        $q=$db->prepare('SELECT * FROM sepa_order_items WHERE club_id=? AND id=?'.$lock);$q->execute([$first['club_id'],$origin['item_id']]);$item=$q->fetch();if(!$item)self::fail();
        if($first['active']){$effect=NativeDebitReturnHistory::current($db,$first['club_id'],$item['id'],$current);if($item['state']!=='returned'||($effect['record']['id']??null)!==$record['id'])self::fail();}
        elseif(!NativeDebitReturnHistory::financialUndo($db,$first['club_id'],$record['id'],$first,$current))self::fail();
        foreach($native['item']as$field=>$value)if($field!=='state'&&(!array_key_exists($field,$item)||$item[$field]!==$value))self::fail();
        $q=$db->prepare('SELECT id,club_id,account_id,collection_date,sealed_snapshot FROM sepa_orders WHERE club_id=? AND id=?'.$lock);$q->execute([$first['club_id'],$origin['order_id']]);$order=$q->fetch();if(!$order)self::fail();foreach(['id','club_id','account_id','collection_date']as$field)if(($native['order'][$field]??null)!==$order[$field])self::fail();if(!hash_equals((string)($native['order']['sealed_sha256']??''),hash('sha256',$order['sealed_snapshot'])))self::fail();
        foreach($first['items']as$i=>$part)if($part['entry_id']!==$item['entry_id']||($review['selected'][$i]['source']['account_id']??null)!==$order['account_id']||(int)$part['cents']!==(int)$review['selected'][$i]['allocation']['cents'])self::fail();
    }
}
