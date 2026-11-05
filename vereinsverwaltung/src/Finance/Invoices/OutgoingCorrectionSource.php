<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;

/** The current original, actual payment policy and all already issued credits. */
final class OutgoingCorrectionSource
{
    public static function read(PDO $db,string $actor,string $invoice):array
    {
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');
            $context=OutgoingMailContext::read($db,$actor,$invoice);
            if($context['invoice']['kind']!=='invoice')throw new DomainException('Nur eine Originalrechnung kann korrigiert werden.',422);
            $credits=Db::run($db,"SELECT * FROM outgoing_invoices WHERE club_id=? AND parent_id=? AND kind='credit' AND state='issued' ORDER BY id FOR SHARE NOWAIT",[$context['invoice']['club_id'],$invoice])->fetchAll();
            $source=array_intersect_key($context['source'],array_flip(['invoice','document','folders','file','payment']));$source['credits']=$credits;
            $entry=$context['payment']['entry']+['allocated_cents'=>$context['payment']['allocated_cents']];
            $result=['invoice'=>$context['invoice'],'entry'=>$entry,'credits'=>$credits,'source'=>$source,'original_pdf'=>$context['file']['bytes'],'snapshot'=>$context['snapshot']];
            if($own)$db->commit();return $result;
        }catch(\Throwable$failure){
            if($own&&$db->inTransaction())$db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnung oder Korrektur wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw $failure;
        }
    }
}
