<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
/** Changes to a payment source must not race an actual payment or a pending order. */
final class UnpaidExpenseChanges
{
    public static function assertIdle(PDO $db,string $actor,string $entry,bool $requireUnfunded=true):array
    {
        if(!$db->inTransaction())throw new DomainException('Den ursprünglichen Zahlungsstand gemeinsam prüfen.',503);
        $who=Permissions::finance($db,$actor,true);$finance=new Finance($db,$actor);$source=$finance->currentEntry($entry);
        if($source['kind']!=='expense'||($requireUnfunded&&(int)$source['allocated_cents']!==0)||$source['reversed_on'])throw new DomainException('Bereits zugeordnete oder gegengebuchte Zahlungen zuerst im ursprünglichen Fachweg klären.',409);
        $report=(new InvoicePaymentReports($db,$actor))->summary($entry,true);
        if(($report['state']??'')==='reported')throw new DomainException('Die vorhandene Zahlungsmeldung zuerst prüfen oder zurücknehmen.',409);
        $q=$db->prepare("SELECT id,state FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND state IN ('prepared','approved','exported','submitted') ORDER BY id FOR SHARE NOWAIT");$q->execute([$who['club_id'],$entry]);
        $orders=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($orders as$order)if($order['state']!=='submitted'||!$finance->paymentReturnResolvesTransfer($order['id']))throw new DomainException('Den laufenden Überweisungsauftrag zuerst klären. Die ursprüngliche Zahlungsquelle bleibt unverändert.',409);
        return ['payment_report_state'=>(string)($report['state']??''),'pending_transfers'=>0];
    }
}
