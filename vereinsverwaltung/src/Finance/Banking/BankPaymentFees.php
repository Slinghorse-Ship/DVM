<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** An explicitly documented fee is its own expense, never an inferred bank difference. */
trait BankPaymentFees
{
    public function previewFee(string $record,array $amounts,string $feeEntry,string $feeAmount,string $reason,string $duplicate=''):array
    {
        return $this->read(fn()=>$this->reviewFeeLocked($record,$amounts,$feeEntry,$feeAmount,$reason,$duplicate));
    }
    public function reviewFeeLocked(string $record,array $amounts,string $feeEntry,string $feeAmount,string $reason,string $duplicate=''):array
    {
        if(count($amounts)>39)throw new DomainException('Höchstens neununddreißig vorhandene Bewegungen zusammen mit einem eigenen Gebührenabzug prüfen.',422);
        $parts=$this->portionSourceLocked($record,$amounts,$reason,'',$duplicate);$source=$parts['source'];$input=$parts['input'];$a=$this->access();$fee=Finance::cents(self::amounts([$feeEntry=>$feeAmount])[$feeEntry]);$gross=(int)$source['total_cents'];$net=(int)$source['record']['cents'];
        if($gross-$fee!==$net)throw new DomainException('Vorhandene Zahlungen abzüglich der ausdrücklich belegten Gebühr müssen genau den Banknettobetrag ergeben.',422);
        $this->q('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$feeEntry]);$f=new Finance($this->db,$this->actor);$entry=$f->currentEntry($feeEntry);
        if($entry['kind']!=='expense'||$entry['status']!=='open'||$entry['payment_state']!=='open'||(int)$entry['allocated_cents']!==0||(int)$entry['amount_cents']!==$fee)throw new DomainException('Einen noch nicht bezahlten eigenen Ausgabebeleg über genau die tatsächlich einbehaltene Gebühr auswählen. Bereits erfasste Gebühren als vorhandene Bewegung verbinden.',422);
        foreach(['finance_reimbursement_claims','outgoing_invoices']as$table)if($this->q('SELECT id FROM '.$table.' WHERE club_id=? AND entry_id=?'.($table==='finance_reimbursement_claims'?" AND state<>'cancelled'":'').' FOR SHARE NOWAIT',[$a['club_id'],$feeEntry])->fetchColumn())throw new DomainException('Eine private Vorleistung oder ausgestellte Gutschrift ist kein einbehaltener Bankgebührenbeleg.',422);
        $orders=$this->q('SELECT id,entry_id,account_id,execution_date,state,version,total_cents,created_at,bank_reference FROM finance_transfer_orders WHERE club_id=? AND entry_id=? ORDER BY id FOR SHARE NOWAIT',[$a['club_id'],$feeEntry])->fetchAll();foreach($orders as$order)if($order['state']!=='cancelled')throw new DomainException('Für diesen Gebührenbeleg besteht bereits ein Überweisungsauftrag. Den wirklichen Bankstand und Auftrag zuerst klären.',409);
        $approval=(new InvoiceApprovals($this->db,$this->actor))->currentState($feeEntry);if($approval['status']!=='approved'||!$approval['documents_readable']||$approval['original_count']<1)throw new DomainException('Den ursprünglichen Gebührenbeleg zuerst hinzufügen, prüfen und freigeben. Eine Betragsdifferenz allein ist kein Gebührennachweis.',422);
        $documents=$this->q("SELECT d.id,d.origin,d.state,f.revision,f.mime,f.sha256 FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id LEFT JOIN document_files newer ON newer.club_id=f.club_id AND newer.document_id=f.document_id AND newer.revision>f.revision WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? AND newer.revision IS NULL ORDER BY d.id FOR SHARE NOWAIT",[$a['club_id'],$feeEntry])->fetchAll();$originals=[];$docs=new Documents($this->db,$this->actor);
        foreach($documents as$document)if($document['origin']==='upload'&&$document['state']==='active'&&in_array($document['mime'],['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true)){$read=$docs->currentFinancialOriginal($document['id'],(int)$document['revision']);$file=$this->q('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT',[$a['club_id'],$document['id'],$document['revision']])->fetch();if(!$file||$read['trashed']||!hash_equals($document['sha256'],hash('sha256',$file['bytes']))||!hash_equals($document['sha256'],$file['sha256']))throw new DomainException('Der ursprüngliche Gebührenbeleg ist nicht unverändert lesbar. Gespeicherten Nachweis zuerst klären.',409);$originals[]=$read;}
        if(!$originals)throw new DomainException('Einen aktiven hochgeladenen Originalbeleg für die tatsächliche Gebühr auswählen.',422);
        $source['gross_total_cents']=$gross;$source['total_cents']=$net;$source['fee']=['entry'=>$entry,'positions'=>$f->positions('entry',$feeEntry,true),'approval'=>$approval,'originals'=>$originals,'orders'=>$orders,'cents'=>$fee];$input['fee_entry']=$feeEntry;$input['fee_amount']=$feeAmount;
        return ['schema'=>2,'input'=>$input,'source'=>$source,'hash'=>hash('sha256',self::json(['bank-documented-fee-v1',$a['club_id'],$a['id'],$input,$source]))];
    }
    /** The exact human preview remains nested inside its actual accounting result. */
    public function completedFeeReviewLocked(array $review,array $transaction):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Gebührenabzug gemeinsam mit dem Banknachweis speichern.');$source=$review['source'];$fee=$source['fee'];$id=$transaction['id'];
        if($transaction['account_id']!==$source['mapping']['account_id']||(int)$transaction['cents']!==-$fee['cents'])throw new DomainException('Erfasster Gebührenabzug stimmt nicht mit dem geprüften Banknachweis überein.',409);
        $source['transactions'][$id]=$transaction;$source['portions'][$id]=-$fee['cents'];$source['proofs'][$id]=[];foreach(['transactions','portions','proofs']as$key)ksort($source[$key],SORT_STRING);$source['fee_first_review']=$review;
        $amounts=$review['input']['amounts'];$amounts[$id]=$review['input']['fee_amount'];ksort($amounts,SORT_STRING);$input=['record'=>$review['input']['record'],'transactions'=>array_map('strval',array_keys($amounts)),'reason'=>$review['input']['reason'],'reuse'=>'','duplicate'=>$review['input']['duplicate'],'amounts'=>$amounts,'bank_fee'=>['requested'=>$review['input'],'review_hash'=>$review['hash']]];
        return ['schema'=>2,'input'=>$input,'source'=>$source,'hash'=>hash('sha256',self::json(['bank-recorded-fee-v1',$input,$source]))];
    }
}
