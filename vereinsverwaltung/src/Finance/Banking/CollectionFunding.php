<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** A pure, physically current source for collecting only the unpaid invoice cents. */
final class CollectionFunding
{
    public static function read(PDO $db,string $actor,string $id):array
    {
        if(!$db->inTransaction())throw new DomainException('Restbetrag und ursprüngliche Zahlungen gemeinsam aktuell prüfen.',409);
        $authority=Permissions::finance($db,$actor,true);FinancePaymentAuthority::require($db,$actor);
        $q=$db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT');
        if((int)$q->fetchColumn()!==Database::SCHEMA_VERSION||!PaymentFunding::available($db))throw new DomainException('Restbetragseinzüge benötigen die vollständig abgeschlossene Paketwartung.',503);
        $entry=(new Finance($db,$actor))->currentEntry($id);
        if(!in_array($entry['payment_state'],['open','partial'],true)||(int)$entry['remaining_cents']<=0||(int)$entry['allocated_cents']<0)throw new DomainException('Nur eine offene ursprüngliche Restforderung zum Einzug auswählen.',409);
        $ledger=PaymentFundingLedger::entry($db,$authority['club_id'],$id,(int)$entry['amount_cents'],true);
        if($ledger['paid_cents']!==(int)$entry['allocated_cents'])throw new DomainException('Restbetrag und tatsächliche ursprüngliche Zahlungen stimmen nicht überein.',503);
        $sources=[];$ids=array_values(array_unique(array_map(static fn(array $part):string=>$part['allocation']['transaction_id'],$ledger['allocations'])));sort($ids,SORT_STRING);
        foreach($ids as$source){
            $q=$db->prepare('SELECT * FROM finance_transactions WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$authority['club_id'],$source]);$row=$q->fetch();
            if(!$row||(int)$row['cents']<=0||$row['reversal_of']!==null)throw new DomainException('Tatsächliche ursprüngliche Zahlung vor dem Resteinzug klären.',409);
            $sources[$source]=$row;
        }
        return ['format'=>'dvm-collection-funding/1','entry_id'=>$id,'face_cents'=>(int)$entry['amount_cents'],'paid_cents'=>$ledger['paid_cents'],'remaining_cents'=>(int)$entry['remaining_cents'],'ledger'=>$ledger,'sources'=>$sources];
    }

    /** Full historical funding stays in the encrypted bank snapshot, never in personal notice JSON. */
    public static function summary(array $proof):array
    {
        return ['format'=>'dvm-collection-funding-summary/1','entry_id'=>$proof['entry_id'],'face_cents'=>$proof['face_cents'],'paid_cents'=>$proof['paid_cents'],'remaining_cents'=>$proof['remaining_cents'],'source_sha256'=>hash('sha256',PaymentFundingOriginals::json($proof))];
    }
}
