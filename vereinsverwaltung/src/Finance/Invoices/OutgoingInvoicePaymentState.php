<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,Permissions};

/** Current read facts only; issuing a document never proves receipt or refund of money. */
final class OutgoingInvoicePaymentState
{
    public function __construct(private PDO $db,private string $actor){}

    /** The caller holds the invoice and current authority in the same read transaction. */
    public function forRow(array $invoice):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Zahlungsstand benötigt eine gemeinsame Leseprüfung.');
        $actor=Permissions::finance($this->db,$this->actor);
        if(($invoice['club_id']??null)!==$actor['club_id'])throw new DomainException('Ausgangsrechnung nicht verfügbar.',404);
        $empty=['state'=>'unavailable','allocated_cents'=>null,'remaining_cents'=>null];
        if(!array_key_exists('entry_id',$invoice)||!in_array($invoice['kind']??null,['invoice','credit','cancellation'],true)||!in_array($invoice['state']??null,['draft','reviewed','issued','cancelled'],true))return $empty;
        if($invoice['kind']==='cancellation'||$invoice['state']==='cancelled')return ['state'=>'inactive']+array_diff_key($empty,['state'=>true]);
        if(in_array($invoice['state'],['draft','reviewed'],true))return ($invoice['entry_id']===null||$invoice['entry_id']==='')?['state'=>'not_issued']+array_diff_key($empty,['state'=>true]):$empty;
        if(!is_string($invoice['entry_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$invoice['entry_id']))return $empty;
        try{$entry=(new Finance($this->db,$this->actor))->currentEntry($invoice['entry_id']);}
        catch(DomainException){return $empty;}
        if($entry['kind']!==($invoice['kind']==='credit'?'expense':'income')||!is_int($invoice['total_cents']??null)||(int)$entry['amount_cents']!==$invoice['total_cents'])return $empty;
        if(in_array($entry['payment_state'],['cancelled','reversed'],true))return ['state'=>'inactive']+array_diff_key($empty,['state'=>true]);
        if(!in_array($entry['payment_state'],['open','partial','paid'],true))return $empty;
        if(!is_int($entry['allocated_cents'])||!is_int($entry['remaining_cents'])||$entry['allocated_cents']<0||$entry['remaining_cents']<0||$entry['allocated_cents']+$entry['remaining_cents']!==$invoice['total_cents'])return $empty;
        return ['state'=>$entry['payment_state'],'allocated_cents'=>$entry['allocated_cents'],'remaining_cents'=>$entry['remaining_cents']];
    }

    /** Read a detail's current financial facts without changing its frozen invoice or PDF. */
    public function read(string $id,?int $expectedVersion=null):array
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$actor=Permissions::finance($this->db,$this->actor);
            $q=$this->db->prepare("SELECT club_id,id,kind,state,version,entry_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.total_cents')) AS SIGNED) AS total_cents FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT");$q->execute([$actor['club_id'],$id]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
            if(!$invoice)throw new DomainException('Ausgangsrechnung nicht verfügbar.',404);
            $result=$expectedVersion===null||(int)$invoice['version']===$expectedVersion?$this->forRow($invoice):['state'=>'unavailable','allocated_cents'=>null,'remaining_cents'=>null];if($own)$this->db->commit();
            if(Permissions::finance($this->db,$this->actor)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);
            return $result;
        }catch(\Throwable $failure){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnung oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw $failure;
        }
    }
}
