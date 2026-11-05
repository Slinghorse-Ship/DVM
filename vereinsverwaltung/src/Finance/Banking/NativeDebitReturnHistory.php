<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Append-only current effects, separate from every unchanged earlier bank/financial original. */
final class NativeDebitReturnHistory
{
    public const TABLE='finance_native_return_events';
    private const IDENTITY=['club_id','id','item_id','order_id','ordinal','kind','after_event_id','parent_return_id','counter_id','financial_id','financial_request_key','legacy_source_id','native_request_key','created_by','created_at'];
    private const RECORD=['club_id','id','item_id','transaction_id','reason','document_id','actor_id','created_at'];

    public static function available(PDO $db):bool{return Database::tableExists($db,self::TABLE);}
    private static function fail():never{throw new DomainException('Ursprüngliche Rücklastschrift, Änderung und Finanzzuordnung gemeinsam prüfen lassen.',503);}
    private static function lock(PDO $db,bool $current):string
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuellen Rücklastschriftverlauf gemeinsam prüfen.',409);return $current?' FOR SHARE NOWAIT':'';
    }

    public static function event(PDO $db,string $club,string $id,bool $current=false):?array
    {
        $lock=self::lock($db,$current);if(!self::available($db))return null;
        $q=$db->prepare('SELECT * FROM '.self::TABLE.' WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$id]);$row=$q->fetch();if(!$row)return null;if($row['id']!==$id||$row['club_id']!==$club)self::fail();
        try{
            $data=[];foreach(['request','review','result']as$name){if(!hash_equals($row[$name.'_sha256'],hash('sha256',$row[$name.'_json'])))self::fail();$data[$name]=json_decode($row[$name.'_json'],true,128,JSON_THROW_ON_ERROR);if(!is_array($data[$name]))self::fail();}
        }catch(\JsonException $error){throw new DomainException('Ursprünglichen Rücklastschriftverlauf prüfen lassen.',503,$error);}
        $request=$data['request'];$result=$data['result'];$identity=$result['identity']??null;
        if(($request['format']??null)!=='dvm-native-return-event-request/1'||($result['format']??null)!=='dvm-native-return-event-result/1'||!is_array($identity)||array_keys($identity)!==self::IDENTITY)self::fail();
        foreach(self::IDENTITY as$field){$value=$field==='ordinal'?(int)$row[$field]:$row[$field];if($identity[$field]!==$value)self::fail();}
        if(!in_array($row['kind'],['return','attach','attach_existing','undo'],true)||($request['kind']??null)!==$row['kind']||($request['club_id']??null)!==$club||($request['actor']??null)!==$row['created_by']||($request['native_request_key']??null)!==$row['native_request_key']||($request['item_id']??null)!==$row['item_id']||($request['counter_id']??null)!==$row['counter_id']||!is_string($request['reason']??null)||trim($request['reason'])==='')self::fail();
        if((int)$row['ordinal']===1){if($row['after_event_id']!==null)self::fail();}
        else{
            $q=$db->prepare('SELECT id FROM '.self::TABLE.' WHERE club_id=? AND item_id=? AND ordinal=?'.$lock);$q->execute([$club,$row['item_id'],(int)$row['ordinal']-1]);if($q->fetchColumn()!==$row['after_event_id'])self::fail();
        }
        $financials=$result['financials']??[];if(!is_array($financials)||!array_is_list($financials)||count($financials)>40)self::fail();$seen=[];
        foreach($financials as$financial){if(!is_array($financial)||array_keys($financial)!==['id','request_key']||!is_string($financial['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$financial['id'])||!is_string($financial['request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$financial['request_key'])||isset($seen[$financial['id']]))self::fail();$seen[$financial['id']]=true;}
        if(($financials[0]['id']??null)!==$row['financial_id']||($financials[0]['request_key']??null)!==$row['financial_request_key'])self::fail();
        $record=$result['record']??null;
        if($row['kind']==='undo'){if($record!==null||$row['parent_return_id']===null||$row['document_id']!==null)self::fail();}
        else{
            if(!is_array($record)||array_keys($record)!==self::RECORD)self::fail();
            foreach(['club_id'=>$club,'id'=>$id,'item_id'=>$row['item_id'],'transaction_id'=>$row['counter_id'],'reason'=>trim($request['reason']),'actor_id'=>$row['created_by'],'created_at'=>$row['created_at']]as$field=>$value)if($record[$field]!==$value)self::fail();
            if($record['document_id']!==$row['document_id']||($record['document_id']!==null&&!is_string($record['document_id'])))self::fail();
        }
        return $row+$data+['record'=>$record];
    }

    public static function latest(PDO $db,string $club,string $item,bool $current=false):?array
    {
        $lock=self::lock($db,$current);if(!self::available($db))return null;
        $q=$db->prepare('SELECT id FROM '.self::TABLE.' WHERE club_id=? AND item_id=? ORDER BY ordinal DESC LIMIT 1'.$lock);$q->execute([$club,$item]);$id=$q->fetchColumn();return $id===false?null:self::event($db,$club,$id,$current);
    }

    /** Any historical record; cancelled originals remain available to their immutable firsts. */
    public static function record(PDO $db,string $club,string $id,bool $current=false):?array
    {
        $lock=self::lock($db,$current);$q=$db->prepare('SELECT * FROM sepa_return_records WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$id]);$row=$q->fetch();
        if($row){if($row['id']!==$id||$row['club_id']!==$club)self::fail();return $row;}
        return self::event($db,$club,$id,$current)['record']??null;
    }

    /** The physical item and its effective record are checked together, never inferred from a counter amount. */
    public static function current(PDO $db,string $club,string $item,bool $current=false):array
    {
        $lock=self::lock($db,$current);$q=$db->prepare('SELECT * FROM sepa_order_items WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$item]);$row=$q->fetch();if(!$row||$row['id']!==$item)throw new DomainException('Ursprünglicher Lastschriftposten nicht verfügbar.',404);
        $event=self::latest($db,$club,$item,$current);$record=null;$financials=[];
        if($event){
            $expected=$event['kind']==='undo'?'active':'returned';if($row['state']!==$expected||$event['order_id']!==$row['order_id'])self::fail();
            if($event['kind']!=='undo'){$record=$event['record'];$financials=$event['result']['financials'];}
        }else{
            $q=$db->prepare('SELECT * FROM sepa_return_records WHERE club_id=? AND item_id=?'.$lock);$q->execute([$club,$item]);$record=$q->fetch()?:null;
            if($record&&$row['state']!=='returned')self::fail();if($row['state']==='returned'&&!$record)self::fail();
            if($record&&PaymentFunding::available($db)){$q=$db->prepare("SELECT id,request_key FROM finance_partial_returns WHERE club_id=? AND counter_id=? AND voided_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.native_debit.return_record_id'))=? ORDER BY id".$lock);$q->execute([$club,$record['transaction_id'],$record['id']]);$financials=$q->fetchAll();}
        }
        $legacy=null;
        if($record){
            $q=$db->prepare('SELECT orders.account_id,counter.account_id AS counter_account,counter.cents,counter.reversal_of FROM sepa_orders orders JOIN finance_transactions counter ON counter.club_id=orders.club_id AND counter.id=? WHERE orders.club_id=? AND orders.id=?'.$lock);$q->execute([$record['transaction_id'],$club,$row['order_id']]);$money=$q->fetch();if(!$money||$money['account_id']!==$money['counter_account']||(int)$money['cents']!==-(int)$row['cents'])self::fail();$legacy=$money['reversal_of'];
            if($event&&$legacy!==$event['legacy_source_id'])self::fail();
        }
        $total=0;
        foreach($financials as$financial){
            $q=$db->prepare('SELECT counter_id,cents,request_key,voided_at FROM finance_partial_returns WHERE club_id=? AND id=?'.$lock);$q->execute([$club,$financial['id']]);$part=$q->fetch();if(!$part||$part['voided_at']!==null||$part['counter_id']!==$record['transaction_id']||$part['request_key']!==$financial['request_key']||$legacy!==null)self::fail();
            $q=$db->prepare('SELECT entry_id,cents FROM finance_partial_return_items WHERE club_id=? AND return_id=?'.$lock);$q->execute([$club,$financial['id']]);$sum=0;foreach($q as$piece){if($piece['entry_id']!==$row['entry_id'])self::fail();$sum+=(int)$piece['cents'];}if($sum!==(int)$part['cents'])self::fail();$total+=$sum;
        }
        if($financials&&$total!==(int)$row['cents'])self::fail();
        return ['item'=>$row,'event'=>$event,'record'=>$record,'financial_id'=>$financials[0]['id']??null,'financials'=>$financials,'legacy_source_id'=>$legacy];
    }

    /** New financial originals reserve their ID before storage; the caller commits both records atomically. */
    public static function append(PDO $db,string $actor,array $identity,array $request,array $review,?array $record,array $financials=[],array $extraResult=[]):array
    {
        self::lock($db,true);$authority=Permissions::finance($db,$actor,true);FinancePaymentAuthority::require($db,$actor);
        if(!self::available($db)||array_keys($identity)!==self::IDENTITY||$identity['club_id']!==$authority['club_id']||$identity['created_by']!==$actor)throw new DomainException('Rücklastschriftänderung benötigt den aktuellen eigenen Paketstand.',503);
        $previous=self::latest($db,$identity['club_id'],$identity['item_id'],true);
        if($identity['ordinal']!==($previous?(int)$previous['ordinal']+1:1)||$identity['after_event_id']!==($previous['id']??null))throw new DomainException('Rücklastschriftverlauf inzwischen geändert. Erneut prüfen.',409);
        if(!$financials&&$identity['financial_id']!==null)$financials[]=['id'=>$identity['financial_id'],'request_key'=>$identity['financial_request_key']];
        $result=['format'=>'dvm-native-return-event-result/1','identity'=>$identity,'record'=>$record,'financials'=>$financials]+$extraResult;$columns=[...self::IDENTITY,'document_id'];$values=[...array_values($identity),$record['document_id']??null];
        foreach(['request'=>$request,'review'=>$review,'result'=>$result]as$name=>$value){$json=PaymentFundingOriginals::json($value);$columns[]=$name.'_json';$columns[]=$name.'_sha256';$values[]=$json;$values[]=hash('sha256',$json);}
        $db->prepare('INSERT INTO '.self::TABLE.'('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute($values);
        return self::event($db,$identity['club_id'],$identity['id'],true)??throw new DomainException('Gespeicherten Rücklastschriftverlauf erneut prüfen.',503);
    }

    public static function identity(string $club,string $id,string $item,string $order,int $ordinal,string $kind,?string $previous,?string $parent,string $counter,?string $financial,?string $financialKey,?string $legacySource,string $nativeKey,string $actor,string $created):array
    {
        return array_combine(self::IDENTITY,[$club,$id,$item,$order,$ordinal,$kind,$previous,$parent,$counter,$financial,$financialKey,$legacySource,$nativeKey,$actor,$created]);
    }

    /** Effective evidence only. A superseded legacy row must not prove a later unrelated return. */
    public static function effectiveSql(PDO $db):string
    {
        if(!self::available($db))return 'SELECT * FROM sepa_return_records';
        $fields=[];foreach(self::RECORD as$field)$fields[]="NULLIF(JSON_UNQUOTE(JSON_EXTRACT(event_row.result_json,'$.record.".$field."')),'null') AS ".$field;
        return 'SELECT legacy_return.* FROM sepa_return_records legacy_return WHERE NOT EXISTS(SELECT 1 FROM '.self::TABLE.' event_row WHERE event_row.club_id=legacy_return.club_id AND event_row.item_id=legacy_return.item_id) UNION ALL SELECT '.implode(',',$fields).' FROM '.self::TABLE." event_row WHERE event_row.kind<>'undo' AND NOT EXISTS(SELECT 1 FROM ".self::TABLE.' later_event WHERE later_event.club_id=event_row.club_id AND later_event.item_id=event_row.item_id AND later_event.ordinal>event_row.ordinal)';
    }

    public static function financialBound(PDO $db,string $club,string $id,bool $current=false):bool
    {
        $lock=self::lock($db,$current);if(!self::available($db))return false;$q=$db->prepare("SELECT DISTINCT item_id FROM ".self::TABLE." WHERE club_id=? AND (financial_id=? OR JSON_SEARCH(result_json,'one',?,NULL,'$.financials[*].id') IS NOT NULL)".$lock);$q->execute([$club,$id,$id]);$items=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($items as$item){$effect=self::current($db,$club,$item,$current);foreach($effect['financials']as$financial)if($financial['id']===$id)return true;}return false;
    }

    /** An inactive original is valid only when its exact immutable joint undo proves the void. */
    public static function financialUndo(PDO $db,string $club,string $record,array $first,bool $current=false):?array
    {
        $lock=self::lock($db,$current);if(!self::available($db))return null;$q=$db->prepare("SELECT id FROM ".self::TABLE." WHERE club_id=? AND parent_return_id=? AND kind='undo' ORDER BY item_id,ordinal".$lock);$q->execute([$club,$record]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as$id){$event=self::event($db,$club,$id,$current);foreach($event['result']['financials']as$financial)if($financial['id']===$first['id']){
            $key=$event['result']['void_keys'][$first['id']]??null;
            if($first['active']||!is_string($key)||($first['void']['result']['void_request_key']??null)!==$key||($first['void']['result']['voided_by']??null)!==$event['created_by']||($first['void']['request']['reason']??null)!==$event['request']['reason'])self::fail();return $event;
        }}return null;
    }
}
