<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Explicit bank fee evidence; original fee receipt and original gross movements stay separate. */
trait BankImportFees
{
    public function feePreview(string $record,array $amounts,string $feeEntry,string $feeAmount,string $reason,string $duplicate=''):array
    {
        return $this->paymentGroups()->previewFee($record,$amounts,$feeEntry,$feeAmount,$reason,$duplicate);
    }
    private static function feeInput(string $record,array $amounts,string $feeEntry,string $feeAmount,string $reason,string $duplicate=''):array
    {
        $amounts=BankPaymentGroups::amounts($amounts);BankPaymentGroups::reason($reason);BankPaymentGroups::amounts([$feeEntry=>$feeAmount]);
        return ['record'=>$record,'transactions'=>array_map('strval',array_keys($amounts)),'reason'=>$reason,'reuse'=>'','duplicate'=>$duplicate,'amounts'=>$amounts,'fee_entry'=>$feeEntry,'fee_amount'=>$feeAmount];
    }
    public function decideFee(string $record,int $version,array $amounts,string $feeEntry,string $feeAmount,string $reason,string $hash,bool $confirmed):string
    {
        if($this->db->inTransaction())throw new DomainException('Gebührenabzug und Banknachweis benötigen einen eigenen abgeschlossenen Speichervorgang.',409);$requested=self::feeInput($record,$amounts,$feeEntry,$feeAmount,$reason);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Zuerst den ursprünglichen Gebührenbeleg und den ganzen Banknettobetrag prüfen. Den tatsächlichen Abzug anschließend gesondert bestätigen.',422);
        try{return $this->tx(function()use($record,$version,$amounts,$feeEntry,$feeAmount,$reason,$hash,$requested):string{
            $a=$this->access();$groups=$this->paymentGroups();$r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$r)throw new DomainException('Eigene Bankzeile nicht verfügbar.',404);$first=$groups->first($record,$version,true);
            if($first){$original=json_decode($first['original_json'],true,128,JSON_THROW_ON_ERROR);$fee=$original['input']['bank_fee']??null;if($first['actor_id']!==$a['id']||$first['operation']!=='existing'||!is_array($fee)||$fee['requested']!==$requested||!hash_equals($fee['review_hash'],$hash))throw new DomainException('Dieser Bankstand wurde bereits mit anderen Gebührenangaben angenommen. Sein ursprüngliches Original prüfen.',409);return $first['id'];}
            if((int)$r['version']!==$version||$r['state']!=='review'||$this->q('SELECT 1 FROM bank_import_decisions WHERE club_id=? AND record_id=? AND record_version=? FOR SHARE NOWAIT',[$a['club_id'],$record,$version])->fetchColumn())throw new DomainException('Der Bankstand wurde inzwischen anders entschieden. Ursprüngliche Gebührenangaben erhalten und aktuellen Stand gesondert prüfen.',409);
            $this->reviewOriginals($r);$review=$groups->reviewFeeLocked($record,$amounts,$feeEntry,$feeAmount,$reason);if(!hash_equals($review['hash'],$hash))throw new DomainException('Bankstand, Zahlungen oder ursprünglicher Gebührenbeleg haben sich geändert. Alles erneut bewusst prüfen und anschließend gesondert bestätigen.',409);
            $fee=$review['source']['fee'];$result=(new Finance($this->db,$this->actor))->recordBankFeeLocked($feeEntry,(int)$fee['entry']['version'],$review['source']['mapping']['account_id'],$r['booked_on'],(int)$fee['cents'],substr(hash('sha256','bank-documented-fee:'.$a['club_id'].':'.$record.':'.$version),0,32));$accepted=$groups->completedFeeReviewLocked($review,$result['transaction']);$linked=$groups->linkLocked($accepted,'existing');
            $this->q("UPDATE bank_import_records SET state='imported',reason='',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$record]);$this->q('INSERT INTO bank_import_decisions VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],bin2hex(random_bytes(16)),$record,$a['id'],'existing',$reason,$version,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.fee.reconciled',$record,$version+1);return $linked['link']['id'];
        });}catch(\PDOException$error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Gebührenbeleg oder Banknachweis wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }
    public function correctFeeDuplicate(string $record,int $version,int $sourceVersion,array $amounts,string $feeEntry,string $feeAmount,string $date,string $reason,string $key,string $hash,bool $confirmed):string
    {
        if($this->db->inTransaction())throw new DomainException('Gebührenabzug und Doppelkorrektur benötigen einen eigenen abgeschlossenen Speichervorgang.',409);$input=self::feeInput($record,$amounts,$feeEntry,$feeAmount,$reason);self::key($key);self::date($date);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Ursprünglichen Gebührenbeleg und doppelte Bankübernahme zuerst prüfen. Den tatsächlichen Abzug und genau eine Gegenbuchung gesondert bestätigen.',422);
        $requested=['record'=>$record,'version'=>$version,'source_version'=>$sourceVersion,'kind'=>'duplicate','target_mode'=>'fee','transactions'=>$input['transactions'],'amounts'=>$input['amounts'],'fee_entry'=>$feeEntry,'fee_amount'=>$feeAmount,'date'=>$date,'reason'=>$reason,'review_hash'=>$hash,'confirmed'=>$confirmed];$inputHash=hash('sha256',json_encode($requested,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try{return $this->tx(function()use($record,$version,$sourceVersion,$amounts,$feeEntry,$feeAmount,$date,$reason,$key,$hash,$requested,$inputHash):string{
            $a=$this->access();$groups=$this->paymentGroups();if(!$this->correctionsAvailable()||!$groups->writable())throw new DomainException('Gebührenabzug und Bankkorrektur benötigen den ausdrücklich gewarteten Paketstand.',503);
            $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$r)throw new DomainException('Eigene Bankzeile nicht verfügbar.',404);$first=$this->q('SELECT * FROM bank_import_corrections WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();
            if($first){self::verifyCorrection($first);if($first['actor_id']!==$a['id']||!hash_equals($first['input_hash'],$inputHash))throw new DomainException('Dieses Gebührenkorrekturformular wurde bereits mit anderen Angaben angenommen. Ursprünglichen Verlauf prüfen.',409);return $first['id'];}
            if($r['state']!=='imported'||(int)$r['version']!==$version)throw new DomainException('Der Bankstand wurde inzwischen geändert. Ursprüngliche Gebührenangaben erhalten und aktuellen Stand gesondert prüfen.',409);
            (new Finance($this->db,$this->actor))->assertReturnOrderBankReferenceMutable($record);$source=$this->q('SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR UPDATE NOWAIT',[$a['club_id'],$record])->fetch();if(!$source||$source['kind']!=='imported')throw new DomainException('Nur einen zusätzlich übernommenen Banknettobetrag mit dem tatsächlichen Gebührenbeleg korrigieren.',422);
            $this->reviewOriginals($r);$review=$groups->reviewFeeLocked($record,$amounts,$feeEntry,$feeAmount,$reason,$source['transaction_id']);if(!hash_equals($review['hash'],$hash))throw new DomainException('Bankstand, Zahlungen oder ursprünglicher Gebührenbeleg haben sich geändert. Alles erneut bewusst prüfen und anschließend gesondert bestätigen.',409);
            $f=new Finance($this->db,$this->actor);$before=['record'=>$r,'bank_source'=>$source,'transaction'=>$f->transactionDetail($source['transaction_id'],true)];if((int)$before['transaction']['version']!==$sourceVersion)throw new DomainException('Die zusätzliche Bankübernahme wurde geändert. Ursprünglichen Kontoumsatz erneut prüfen.',409);
            $fee=$review['source']['fee'];$result=$f->recordBankFeeLocked($feeEntry,(int)$fee['entry']['version'],$review['source']['mapping']['account_id'],$r['booked_on'],(int)$fee['cents'],substr(hash('sha256','bank-documented-fee-correction:'.$a['club_id'].':'.$key),0,32));$accepted=$groups->completedFeeReviewLocked($review,$result['transaction']);$counter=$f->correctDuplicateBankSharesLocked($source['transaction_id'],$sourceVersion,$accepted['source']['portions'],$date,substr(hash('sha256','bank-fee-duplicate-counter:'.$a['club_id'].':'.$key),0,32))['counter'];
            $this->q('DELETE FROM bank_transaction_sources WHERE club_id=? AND record_id=?',[$a['club_id'],$record]);$linked=$groups->linkLocked($accepted,'duplicate');$this->q('UPDATE bank_import_records SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$record]);$after=['record'=>$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch(),'bank_source'=>null,'payment_group'=>$linked,'counter'=>$counter,'recorded_fee'=>$result];$original=json_encode(['schema'=>2,'requested'=>$requested,'before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$id=bin2hex(random_bytes(16));
            $this->q('INSERT INTO bank_import_corrections VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$record,$version,$a['id'],'duplicate',$reason,$key,$inputHash,$original,hash('sha256',$original),(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.review.corrected',$record,$version+1);return $id;
        });}catch(\PDOException$error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Gebührenbeleg oder Bankkorrektur wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }
    public function feeCandidatesPage(string $record,mixed $page=1,mixed $search=''):array
    {
        $page=self::importPage($page);$search=self::importText($search,150);
        return $this->importRead(function()use($record,$page,$search):array{
            $r=$this->record($record);$where=["e.club_id=?","e.kind='expense'","e.status='open'","NOT EXISTS(SELECT 1 FROM finance_allocations used_fee WHERE used_fee.club_id=e.club_id AND used_fee.entry_id=e.id AND used_fee.voided_at IS NULL)","NOT EXISTS(SELECT 1 FROM finance_reversals closed_fee WHERE closed_fee.club_id=e.club_id AND closed_fee.entry_id=e.id)","NOT EXISTS(SELECT 1 FROM finance_reimbursement_claims private_fee WHERE private_fee.club_id=e.club_id AND private_fee.entry_id=e.id AND private_fee.state<>'cancelled')","NOT EXISTS(SELECT 1 FROM outgoing_invoices credit_fee WHERE credit_fee.club_id=e.club_id AND credit_fee.entry_id=e.id)"];$args=[$r['club_id']];
            if($search!==''){$where[]='(INSTR(LOWER(e.description),LOWER(?))>0 OR INSTR(LOWER(e.counterparty),LOWER(?))>0 OR INSTR(LOWER(COALESCE(e.display_number,\'\')),LOWER(?))>0 OR e.id=?)';array_push($args,$search,$search,$search,$search);}
            $from=' FROM finance_entries e WHERE '.implode(' AND ',$where);$bounds=self::importBounds((int)$this->q('SELECT COUNT(*)'.$from,$args)->fetchColumn(),$page,20);$rows=$this->q('SELECT e.*'.$from.' ORDER BY e.document_date DESC,e.number DESC,e.id LIMIT 20 OFFSET '.(($bounds['page']-1)*20),$args)->fetchAll();return $bounds+['rows'=>$rows,'q'=>$search];
        });
    }
}
