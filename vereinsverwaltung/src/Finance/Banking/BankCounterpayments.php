<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Bank originals prove actual money on either side of a receipt-bound refund, never bookkeeping compensation. */
final class BankCounterpayments
{
    public static function candidateSql(?PDO $db=null):string
    {
        $returns=$db!==null&&Database::tableExists($db,'finance_payment_returns');
        return '('.self::counterSql('t',$returns,$db).' AND (NOT EXISTS(SELECT 1 FROM finance_transactions bank_return WHERE bank_return.club_id=t.club_id AND bank_return.reversal_of=t.id) OR EXISTS(SELECT 1 FROM finance_transactions bank_actual_return WHERE bank_actual_return.club_id=t.club_id AND bank_actual_return.reversal_of=t.id AND '.self::counterSql('bank_actual_return',$returns,$db).')))';
    }

    /** Aliases are private constants; original/proof aliases remain local to each subquery. */
    private static function counterSql(string $alias,bool $returns=false,?PDO $db=null):string
    {
        $sql=<<<'SQL'
(t.reversal_of IS NULL OR EXISTS(
 SELECT 1 FROM finance_transactions original
 WHERE original.club_id=t.club_id AND original.id=t.reversal_of
 AND original.account_id=t.account_id AND original.cents=-t.cents
 AND original.reversal_of IS NULL AND t.booked_on>=original.booked_on
 AND NOT EXISTS(SELECT 1 FROM finance_transactions further_return WHERE further_return.club_id=t.club_id AND further_return.reversal_of=t.id)
 AND EXISTS(SELECT 1 FROM finance_allocations l
  WHERE l.club_id=t.club_id AND l.transaction_id=original.id AND l.voided_at IS NULL)
 AND (SELECT SUM(l.cents) FROM finance_allocations l
  WHERE l.club_id=t.club_id AND l.transaction_id=original.id AND l.voided_at IS NULL)=ABS(original.cents)
 AND (SELECT COUNT(DISTINCT l.entry_id) FROM finance_allocations l
  WHERE l.club_id=t.club_id AND l.transaction_id=original.id AND l.voided_at IS NULL)<=40
 AND NOT EXISTS(SELECT 1 FROM finance_allocations l
  JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id
  WHERE l.club_id=t.club_id AND l.transaction_id=original.id AND l.voided_at IS NULL
  AND (e.amount_cents<>(SELECT SUM(part.cents) FROM finance_allocations part
   WHERE part.club_id=l.club_id AND part.entry_id=l.entry_id AND part.transaction_id=original.id AND part.voided_at IS NULL)
   OR (e.kind='expense')<>(original.cents<0)))
 AND (PAYMENT_RETURN_PROOF NOT EXISTS(SELECT 1 FROM finance_allocations l
  LEFT JOIN finance_reversals correction ON correction.club_id=l.club_id AND correction.entry_id=l.entry_id
  WHERE l.club_id=t.club_id AND l.transaction_id=original.id AND l.voided_at IS NULL
  AND (correction.reversed_on IS NULL OR correction.reversed_on<>t.booked_on))
 OR EXISTS(SELECT 1 FROM bank_transaction_sources proof
  JOIN bank_import_records r ON r.club_id=proof.club_id AND r.id=proof.record_id
  JOIN bank_account_mappings m ON m.club_id=r.club_id AND m.id=r.mapping_id
  WHERE proof.club_id=t.club_id AND proof.transaction_id=t.id AND m.account_id=t.account_id
  AND r.cents=t.cents AND r.bank_status='BOOK' AND r.currency='EUR')
 OR EXISTS(SELECT 1 FROM sepa_return_records returned
  JOIN sepa_order_items item ON item.club_id=returned.club_id AND item.id=returned.item_id
  JOIN sepa_orders orders ON orders.club_id=item.club_id AND orders.id=item.order_id
  JOIN finance_allocations l ON l.club_id=item.club_id AND l.entry_id=item.entry_id
  WHERE returned.club_id=t.club_id AND returned.transaction_id=t.id AND item.state='returned'
  AND orders.account_id=t.account_id AND item.cents=ABS(t.cents)
  AND l.transaction_id=original.id AND l.voided_at IS NULL))
))
SQL;
        $sql=str_replace('PAYMENT_RETURN_PROOF ',$returns?'EXISTS(SELECT 1 FROM finance_payment_returns demand_return WHERE demand_return.club_id=t.club_id AND demand_return.source_id=original.id AND demand_return.returned_id=t.id AND demand_return.cents=ABS(original.cents)) OR ':'',$sql);
        if($db!==null)$sql=str_replace('FROM sepa_return_records returned','FROM ('.NativeDebitReturnHistory::effectiveSql($db).') returned',$sql);
        return preg_replace('/(?<![A-Za-z0-9_])t\\./',$alias.'.',$sql);
    }

    /** Current locking counterpart of candidateSql, shared by assignment and duplicate repair. */
    public static function actualMovement(PDO $db,string $actor,string $transaction,bool $current=false):bool
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuellen Zahlungsnachweis gemeinsam mit der Bankzuordnung prüfen.');
        $row=(new Finance($db,$actor))->transactionDetail($transaction,$current);
        if($row['reversal_of'])return self::evidence($db,$actor,$transaction,$current)!==null;
        if(!$row['reversed_on'])return true;
        $q=$db->prepare('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=? ORDER BY id'.($current?' FOR SHARE NOWAIT':''));$q->execute([$row['club_id'],$transaction]);$returns=$q->fetchAll(PDO::FETCH_COLUMN);
        return count($returns)===1&&self::evidence($db,$actor,$returns[0],$current)!==null;
    }

    /** All decision callers already own the movement lock; current reads also lock its evidence. */
    public static function evidence(PDO $db,string $actor,string $transaction,bool $current=false):?array
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuellen Rückzahlungsnachweis gemeinsam mit der Bankzuordnung prüfen.');
        $finance=new Finance($db,$actor);$counter=$finance->transactionDetail($transaction,$current);
        if(!$counter['reversal_of']||$counter['reversed_on'])return null;
        $original=$finance->transactionDetail($counter['reversal_of'],$current);
        if($original['reversal_of']||$original['account_id']!==$counter['account_id']||(int)$original['cents']!==-(int)$counter['cents']||$counter['booked_on']<$original['booked_on'])return null;
        $suffix=$current?' FOR SHARE NOWAIT':'';
        $q=$db->prepare('SELECT l.entry_id,l.cents,e.amount_cents,e.kind,e.display_number,e.description,correction.reversed_on FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id LEFT JOIN finance_reversals correction ON correction.club_id=e.club_id AND correction.entry_id=e.id WHERE l.club_id=? AND l.transaction_id=? AND l.voided_at IS NULL ORDER BY l.id'.$suffix);$q->execute([$counter['club_id'],$original['id']]);$entries=[];$total=0;
        foreach($q as $row){$total+=(int)$row['cents'];if(!isset($entries[$row['entry_id']]))$entries[$row['entry_id']]=$row+['allocated_cents'=>0];$entries[$row['entry_id']]['allocated_cents']+=(int)$row['cents'];}
        if(!$entries||count($entries)>40||$total!==abs((int)$original['cents']))return null;$entry=reset($entries);$allCorrected=true;
        foreach($entries as$part){if((int)$part['amount_cents']!==(int)$part['allocated_cents']||(($original['cents']<0)!==($part['kind']==='expense')))return null;if($part['reversed_on']!==$counter['booked_on'])$allCorrected=false;}
        $bank=[];foreach(BankEvidence::proofs($db,$counter['club_id'],[$counter['id']],$current)[$counter['id']]??[]as$proof)if($proof['account_id']===$counter['account_id']&&(int)$proof['evidence_cents']===(int)$counter['cents']&&$proof['bank_status']==='BOOK'&&$proof['currency']==='EUR')$bank[]=['record_id'=>$proof['record_id'],'booked_on'=>$proof['booked_on']];
        $q=$db->prepare("SELECT item.id FROM sepa_order_items item JOIN sepa_orders orders ON orders.club_id=item.club_id AND orders.id=item.order_id WHERE item.club_id=? AND item.entry_id=? AND item.state='returned' AND orders.account_id=? AND item.cents=? ORDER BY item.id".$suffix);$q->execute([$counter['club_id'],$entry['entry_id'],$counter['account_id'],abs((int)$counter['cents'])]);$returns=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)as$item){$effect=NativeDebitReturnHistory::current($db,$counter['club_id'],$item,$current);if(($effect['record']['transaction_id']??null)===$counter['id'])$returns[]=$effect['record'];}
        $demandReturn=$finance->paymentReturnEvidence($original['id'],$counter['id'],$current);
        if($demandReturn&&((int)$demandReturn['cents']!==abs((int)$original['cents'])||$demandReturn['review']['source']['transaction']['account_id']!==$original['account_id']||(int)$demandReturn['review']['source']['transaction']['cents']!==(int)$original['cents']||$demandReturn['review']['returned']['account_id']!==$counter['account_id']||(int)$demandReturn['review']['returned']['cents']!==(int)$counter['cents']||$demandReturn['result']['date']!==$counter['booked_on']))throw new DomainException('Ursprünglichen Nachweis der zurückgekommenen Zahlung zuerst klären.',503);
        if(!$allCorrected&&!$bank&&!$returns&&!$demandReturn)return null;
        return ['transaction'=>$counter,'original'=>$original,'entry'=>$entry,'entries'=>$entries,'bank_proofs'=>$bank,'sepa_returns'=>$returns]+($demandReturn?['demand_return'=>$demandReturn]:[]);
    }
}
