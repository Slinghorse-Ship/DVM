<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Explicit read projections. Financial decisions must separately lock their physical source rows. */
final class PaymentFunding
{
    public static function available(PDO $db):bool
    {
        $found=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('finance_allocation_funding','finance_partial_returns','finance_partial_return_items')")->fetchColumn();
        if($found!==0&&$found!==3)throw new DomainException('Die Zuordnung von Teilrückzahlungen benötigt die vollständig abgeschlossene Paketwartung. Ursprünglichen Stand zuerst prüfen.',503);
        return $found===3;
    }

    private static function alias(string $alias):string
    {
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/D',$alias))throw new \LogicException('Fixed financial SQL alias required.');return $alias;
    }

    /** Raw original funding stays consumed; these are only its still-refundable cents. */
    public static function remainingSql(PDO $db,string $alias='l'):string
    {
        $a=self::alias($alias);if(!self::available($db))return $a.'.cents';
        return "($a.cents-COALESCE((SELECT SUM(part.cents) FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=$a.club_id AND part.allocation_id=$a.id AND refund.voided_at IS NULL),0))";
    }

    /** Current receipt paid amount subtracts actual active returned funding. */
    public static function entryReturnedSql(PDO $db,string $alias='e'):string
    {
        $a=self::alias($alias);if(!self::available($db))return '0';
        return "COALESCE((SELECT SUM(part.cents) FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=$a.club_id AND part.entry_id=$a.id AND refund.voided_at IS NULL),0)";
    }

    public static function paidSql(PDO $db,string $alias='e'):string
    {
        $a=self::alias($alias);
        return "(COALESCE((SELECT SUM(l.cents) FROM finance_allocations l WHERE l.club_id=$a.club_id AND l.entry_id=$a.id AND l.voided_at IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions returned WHERE returned.club_id=l.club_id AND returned.reversal_of=l.transaction_id)),0)-".self::entryReturnedSql($db,$a).')';
    }

    /** Use physical current rows when a mail, certificate or other decision needs the live balance. */
    public static function paid(PDO $db,string $club,string $entry,int $face,bool $current=false):int
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuellen Belegzahlungsstand gemeinsam prüfen.',409);
        if(PaymentFundingLedger::hasBasis($db,$club,$entry,$current))return PaymentFundingLedger::entry($db,$club,$entry,$face,$current)['paid_cents'];
        $q=$db->prepare('SELECT l.cents,l.voided_at,returned.id AS returned_id FROM finance_allocations l LEFT JOIN finance_transactions returned ON returned.club_id=l.club_id AND returned.reversal_of=l.transaction_id WHERE l.club_id=? AND l.entry_id=?'.($current?' FOR SHARE NOWAIT':''));$q->execute([$club,$entry]);$paid=0;foreach($q as$row)if($row['voided_at']===null&&$row['returned_id']===null)$paid+=(int)$row['cents'];return $paid;
    }

    /** Counter consumption is positive, even though its receipt posting is negative. */
    public static function returnedSql(PDO $db,string $alias='t'):string
    {
        $a=self::alias($alias);if(!self::available($db))return '0';
        return "COALESCE((SELECT SUM(refund.cents) FROM finance_partial_returns refund WHERE refund.club_id=$a.club_id AND refund.counter_id=$a.id AND refund.voided_at IS NULL),0)";
    }

    /** Whole original allocations stay consumed, plus neutral movements and actual refund parts. */
    public static function consumedSql(PDO $db):string
    {
        return '(COALESCE((SELECT SUM(l.cents) FROM finance_allocations l WHERE l.club_id=t.club_id AND l.transaction_id=t.id AND l.voided_at IS NULL),0)+'.Finance::neutralAllocatedSql($db).'+'.self::returnedSql($db,'t').')';
    }

    /**
     * Read-only net-paid/posting rows, including a distinct negative classification
     * for the existing actual counter. This never inserts a negative legacy allocation.
     * An outer locking clause on this UNION does not replace physical current reads.
     */
    public static function rows(PDO $db):string
    {
        $normal='SELECT original.id,original.club_id,original.transaction_id,original.entry_id,original.cents,original.request_key,original.voided_at,original.void_reason,original.id AS funding_allocation_id,NULL AS refund_id,NULL AS refund_item_id FROM finance_allocations original';
        if(!self::available($db))return '('.$normal.')';
        return '('.$normal." UNION ALL SELECT CONCAT('refund:',part.id),part.club_id,refund.counter_id,part.entry_id,-part.cents,CONCAT('refund:',refund.request_key,':',part.ordinal),refund.voided_at,IF(refund.voided_at IS NULL,NULL,refund.void_reason),part.allocation_id,refund.id,part.id FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id)";
    }

    /** Explicit posting facts for the balance journal, with physical current proof reads. */
    public static function postings(PDO $db,string $club,string $transaction,bool $current=false):array
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Geldzuordnungen gemeinsam prüfen.',409);
        $q=$db->prepare('SELECT id,entry_id,cents FROM finance_allocations WHERE club_id=? AND transaction_id=? AND voided_at IS NULL ORDER BY id'.($current?' FOR SHARE NOWAIT':''));$q->execute([$club,$transaction]);$rows=$q->fetchAll();$returns=[];
        foreach(PaymentFundingLedger::counter($db,$club,$transaction,$current)['returns']as$first){
            $returns[]=['id'=>$first['id'],'request_sha256'=>$first['request_sha256'],'review_sha256'=>$first['review_sha256'],'result_sha256'=>$first['result_sha256']];
            foreach($first['items']as$item)$rows[]=['id'=>'refund:'.$item['id'],'entry_id'=>$item['entry_id'],'cents'=>-(int)$item['cents']];
        }
        return compact('rows','returns');
    }

    public static function assertAllocationMutable(PDO $db,string $club,string $allocation):void
    {
        if(!$db->inTransaction())throw new DomainException('Ursprüngliche Zuordnung und Rückzahlungen gemeinsam prüfen.',409);
        $q=$db->prepare("SELECT c.id FROM finance_reimbursement_claims c JOIN finance_allocations l ON l.club_id=c.club_id AND l.entry_id=c.entry_id WHERE l.club_id=? AND l.id=? AND c.state='paid' FOR SHARE NOWAIT");$q->execute([$club,$allocation]);
        if($q->fetchColumn())throw new DomainException('Diese Zuordnung belegt eine bezahlte private Erstattung. Erstattungsfall und ursprüngliche Zahlungsnachweise zuerst gesondert klären.',409);
        if(!self::available($db))return;
        $q=$db->prepare('SELECT part.id FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=? AND part.allocation_id=? AND refund.voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$club,$allocation]);
        if($q->fetchColumn())throw new DomainException('Diese ursprüngliche Zahlungszuordnung belegt eine Teilrückzahlung. Deren gespeicherten Zusammenhang zuerst prüfen und gegebenenfalls ausdrücklich zurücknehmen.',409);
        if(Database::tableExists($db,'finance_transfer_settlement_items')&&Database::tableExists($db,'finance_transfer_settlements')){
            $q=$db->prepare('SELECT item.id FROM finance_transfer_settlement_items item JOIN finance_transfer_settlements proof ON proof.club_id=item.club_id AND proof.id=item.settlement_id WHERE item.club_id=? AND item.allocation_id=? AND item.released_at IS NULL AND proof.voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$club,$allocation]);
            if($q->fetchColumn())throw new DomainException('Diese Zahlungszuordnung belegt die bestätigte Ausführung eines Bankauftrags. Dessen ursprünglichen Nachweis zuerst prüfen und ausdrücklich zurücknehmen.',409);
        }
    }

    public static function movementHasExecution(PDO $db,string $club,string $transaction,bool $current=false):bool
    {
        if($current&&!$db->inTransaction())throw new DomainException('Ursprüngliche Bankausführung gemeinsam prüfen.',409);if(!TransferSettlementOriginals::available($db))return false;
        $q=$db->prepare('SELECT item.id FROM finance_transfer_settlement_items item JOIN finance_transfer_settlements proof ON proof.club_id=item.club_id AND proof.id=item.settlement_id WHERE item.club_id=? AND item.source_id=? AND item.released_at IS NULL AND proof.voided_at IS NULL LIMIT 1'.($current?' FOR SHARE NOWAIT':''));$q->execute([$club,$transaction]);return $q->fetchColumn()!==false;
    }
}
