<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Allocation changes classify an existing movement; they never move money. */
trait PaymentMatching
{
    private function allocatedSql():string{return PaymentFunding::paidSql($this->db,'e');}
    private function transactionAllocatedSql():string{return PaymentFunding::consumedSql($this->db);}

    /** Preserve the stored legacy enum; a bank confirmation is separate, auditable provenance. */
    private function transactionSourceColumns(bool $current=false):string
    {
        if(!Database::tableExists($this->db,'bank_transaction_sources'))return '';
        return ',t.source AS recorded_source';
    }

    private function bankProofRows(array $rows,string $club):array
    {
        if(!$rows||!array_key_exists('recorded_source',$rows[0]))return $rows;$out=[];
        foreach(array_chunk($rows,200)as$page){$proofs=BankEvidence::proofs($this->db,$club,array_column($page,'id'));foreach($page as$row)$out[]=array_replace($row,BankEvidence::describe($row,$proofs[$row['id']]??[]));}return $out;
    }

    public static function decimal(int $cents):string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    private function paymentState(array $row):array
    {
        $row['allocated_cents']=(int)$row['allocated_cents'];
        $row['remaining_cents']=(int)$row['amount_cents']-$row['allocated_cents'];
        $row['payment_state']=$row['reversed_on']?'reversed':($row['status']==='cancelled'?'cancelled':($row['remaining_cents']===0?'paid':($row['allocated_cents']>0?'partial':'open')));
        return $row;
    }
    public function transactionDetail(string $id,bool $current=false):array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuelle Zahlungsprüfung benötigt einen gemeinsamen Speichervorgang.');
        $a=$this->actor();
        $q=$this->db->prepare('SELECT t.*,a.name AS account_name,a.kind AS account_kind,'.($current?'0':$this->transactionAllocatedSql()).' AS allocated_cents,r.booked_on AS reversed_on'.$this->transactionSourceColumns(true).' FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE t.club_id=? AND t.id=?'.($current?' FOR SHARE NOWAIT':''));
        $q->execute([$a['club_id'],$id]);$t=$q->fetch();if(!$t)throw new DomainException('Kontotransaktion nicht verfügbar.');
        if(array_key_exists('recorded_source',$t)){$proofs=BankEvidence::proofs($this->db,$a['club_id'],[$id],$current)[$id]??[];$t=array_replace($t,BankEvidence::describe($t,$proofs));}
        if($current){$q=$this->db->prepare('SELECT cents,voided_at FROM finance_allocations WHERE club_id=? AND transaction_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);foreach($q as$allocation)if($allocation['voided_at']===null)$t['allocated_cents']+=(int)$allocation['cents'];}
        if($current){$q=$this->db->prepare('SELECT cents FROM finance_internal_movements WHERE club_id=? AND (outgoing_id=? OR incoming_id=?) AND voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id,$id]);foreach($q as $movement)$t['allocated_cents']+=(int)$movement['cents'];}
        if($current&&self::excessReturnsAvailable($this->db)){$q=$this->db->prepare('SELECT cents FROM finance_excess_returns WHERE club_id=? AND (source_id=? OR returned_id=?) AND voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id,$id]);foreach($q as $movement)$t['allocated_cents']+=(int)$movement['cents'];}
        if($current){$partial=PaymentFundingLedger::counter($this->db,$a['club_id'],$id,true);$t['allocated_cents']+=$partial['cents'];}
        $t['remaining_cents']=abs((int)$t['cents'])-(int)$t['allocated_cents'];return $t;
    }
    public function transactions(array $filter=[]):array
    {
        $a=$this->actor();$where=['t.club_id=?'];$params=[$a['club_id']];
        if(($filter['account']??'')!==''){$this->account($filter['account']);$where[]='t.account_id=?';$params[]=$filter['account'];}
        if(!empty($filter['available']))$where[]='t.reversal_of IS NULL AND r.id IS NULL AND abs(t.cents)>'.$this->transactionAllocatedSql();
        if(($filter['direction']??'')!==''){
            if(!in_array($filter['direction'],['income','expense'],true))throw new DomainException('Ungültige Zahlungsrichtung.');
            $where[]=$filter['direction']==='expense'?'t.cents<0':'t.cents>0';
        }
        if(($filter['q']??'')!==''){$q=self::field($filter,'q',150);$where[]='(instr(lower(t.description),lower(?))>0 OR instr(lower(t.counterparty),lower(?))>0)';array_push($params,$q,$q);}
        $q=$this->db->prepare('SELECT t.*,a.name AS account_name,'.$this->transactionAllocatedSql().' AS allocated_cents,r.booked_on AS reversed_on'.$this->transactionSourceColumns().' FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE '.implode(' AND ',$where).' ORDER BY t.booked_on DESC,t.id LIMIT 1001');
        $q->execute($params);$rows=$q->fetchAll();if(count($rows)>1000)throw new DomainException('Mehr als 1.000 Umsätze. Bitte Konto oder Suche eingrenzen.');
        foreach($rows as &$t)$t['remaining_cents']=abs((int)$t['cents'])-(int)$t['allocated_cents'];unset($t);return $this->bankProofRows($rows,$a['club_id']);
    }
    public function allocations(string $id,bool $byTransaction=false,bool $current=false):array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuelle Zahlungsprüfung benötigt einen gemeinsamen Speichervorgang.');
        $a=$this->actor();if($byTransaction)$this->transactionDetail($id,$current);elseif($current)$this->currentEntry($id);else $this->entry($id);
        $q=$this->db->prepare('SELECT l.*,e.number,e.display_number,e.description AS entry_description,e.kind,e.version AS entry_version,t.booked_on,t.account_id,t.description AS transaction_description,t.version AS transaction_version,t.reversal_of,a.name AS account_name,r.booked_on AS reversed_on FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE l.club_id=? AND l.'.($byTransaction?'transaction_id':'entry_id').'=? ORDER BY t.booked_on,l.id'.($current?' FOR SHARE NOWAIT':''));
        $q->execute([$a['club_id'],$id]);return $q->fetchAll();
    }
    private static function requestKey(string $key):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
    }
    public function createTransaction(array $data,string $key):string
    {
        $id=$this->transaction(function()use($data,$key){$id=$this->insertTransaction($data,$key);$this->autoMatchLocked($id);return $id;});
        DonationPreparation::afterTransaction($this->db,$this->actorId,$id);return $id;
    }
    private function insertTransaction(array $data,string $key,?string $reversalOf=null):string
    {
        $a=$this->actor();self::requestKey($key);
        $accountId=self::field($data,'account',100,true);$this->lockMovementAccount($accountId);$account=$this->account($accountId);$date=self::field($data,'booked_on',10,true);self::date($date);
        $direction=self::field($data,'direction',10,true);$amount=self::cents(self::field($data,'amount',30,true));
        $description=self::field($data,'description',500,true);$counterparty=self::field($data,'counterparty',200);
        if(!$amount||!in_array($direction,['income','expense'],true))throw new DomainException('Positiven Betrag und Zahlungsrichtung prüfen.');
        $cents=$direction==='expense'?-$amount:$amount;
        $fingerprint=hash('sha256',json_encode([$account['id'],$date,$cents,$description,$counterparty,$reversalOf],JSON_THROW_ON_ERROR));
        $q=$this->db->prepare('SELECT id,fingerprint FROM finance_transactions WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);
        if($old=$q->fetch()){if(!hash_equals($old['fingerprint'],$fingerprint))throw new DomainException('Dieses Formular wurde bereits mit anderen Daten gespeichert. Bitte neu öffnen.');return $old['id'];}
        if($reversalOf===null)$this->requireActiveAccount($account['id']);
        if($date<$account['opened_on']||$date>$this->businessDay())throw new DomainException('Buchungsdatum muss zwischen Kontoeröffnung und heute liegen.');
        if($account['kind']==='cash'){$this->assertUnclosed($account['id'],$date);$this->assertCashBalance($account,$date,$cents);}
        $id=bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO finance_transactions(id,club_id,account_id,booked_on,counterparty,description,cents,source,reversal_of,request_key,fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$a['club_id'],$account['id'],$date,$counterparty,$description,$cents,$reversalOf?'reversal':'manual',$reversalOf,$key,$fingerprint]);
        $this->audit($a,'finance.transaction.created',$id,1);ManagerNotifications::paymentReceived($this->db,$a['club_id'],$id);return $id;
    }
    public function allocate(string $transactionId,int $transactionVersion,string $entryId,int $entryVersion,string $amount,string $key):string
    {
        $id=$this->transaction(fn()=>$this->insertAllocation($transactionId,$transactionVersion,$entryId,$entryVersion,$amount,$key));
        DonationPreparation::afterSource($this->db,$this->actorId,'entry',$entryId);return $id;
    }
    /** Classify an existing movement inside a composed, atomic financial command. */
    public function allocateWithinTransaction(string $transactionId,int $transactionVersion,string $entryId,int $entryVersion,string $amount,string $key):string
    {
        if(!$this->db->inTransaction())throw new DomainException('Zuordnung benötigt einen gemeinsamen Speichervorgang.',503);
        $this->actor(true);
        return $this->insertAllocation($transactionId,$transactionVersion,$entryId,$entryVersion,$amount,$key);
    }
    private function insertAllocation(string $transactionId,int $transactionVersion,string $entryId,int $entryVersion,string $amount,string $key):string
    {
        $a=$this->actor();self::requestKey($key);$cents=self::cents($amount);
        $this->lockMovements([$transactionId]);$t=$this->transactionDetail($transactionId,true);$e=$this->currentEntry($entryId);
        $fundingBefore=null;if(PaymentFundingLedger::hasBasis($this->db,$a['club_id'],$entryId,true)){$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$entryId]);$e=$this->currentEntry($entryId);$fundingBefore=PaymentFundingLedger::entry($this->db,$a['club_id'],$entryId,(int)$e['amount_cents'],true);}
        $q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);
        if($old=$q->fetch()){
            if($old['transaction_id']!==$transactionId||$old['entry_id']!==$entryId||(int)$old['cents']!==$cents||$old['voided_at'])throw new DomainException('Dieses Zuordnungsformular wurde bereits verwendet. Bitte neu öffnen.');
            return $old['id'];
        }
        if((int)$t['version']!==$transactionVersion||(int)$e['version']!==$entryVersion)throw new DomainException('Transaktion oder Beleg inzwischen geändert. Bitte neu laden.');
        if($t['reversal_of']||$t['reversed_on']||$e['reversed_on']||$e['status']==='cancelled')throw new DomainException('Korrigierte Transaktionen und verworfene Belege können nicht zugeordnet werden.');
        if(($t['cents']<0)!==($e['kind']==='expense'))throw new DomainException('Eine Geldspende benötigt einen Zahlungseingang. Einnahmen und Ausgaben dürfen nicht vermischt werden.');
        if($cents<=0||$cents>$t['remaining_cents']||$cents>$e['remaining_cents'])throw new DomainException('Der Zuordnungsbetrag übersteigt den offenen Betrag der Transaktion oder des Belegs.');
        if($t['account_kind']==='cash')$this->assertUnclosed($t['account_id'],$t['booked_on']);
        $id=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO finance_allocations(id,club_id,transaction_id,entry_id,cents,request_key) VALUES(?,?,?,?,?,?)')->execute([$id,$a['club_id'],$transactionId,$entryId,$cents,$key]);
        if($fundingBefore!==null){$ranges=PaymentFundingRanges::fill($fundingBefore['occupied'],$cents,(int)$e['amount_cents']);$q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);PaymentFundingOriginals::store($this->db,$q->fetch(),(int)$e['amount_cents'],$ranges,$a['id'],(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),'fill-after-return');}
        $this->refreshPayment($entryId,true);$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$transactionId]);
        $this->audit($a,'finance.allocation.created',$id,1);ManagerNotifications::contributionPaid($this->db,$a['club_id'],$entryId);return $id;
    }
    private function refreshPayment(string $id,bool $current=false):void
    {
        $a=$this->actor();$funded=PaymentFundingLedger::hasBasis($this->db,$a['club_id'],$id,$this->db->inTransaction());if($funded)$current=true;$e=$current?$this->currentEntry($id):$this->entry($id);$paid=$e['remaining_cents']===0;
        $sql=$current?'SELECT t.account_id,t.booked_on FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE l.club_id=? AND l.entry_id=? AND l.voided_at IS NULL AND returned.id IS NULL ORDER BY t.booked_on DESC,t.id LIMIT 1 FOR SHARE NOWAIT':'SELECT t.account_id,t.booked_on FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id WHERE l.club_id=? AND l.entry_id=? AND l.voided_at IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions returned WHERE returned.club_id=t.club_id AND returned.reversal_of=t.id) ORDER BY t.booked_on DESC,t.id LIMIT 1';
        if($funded){
            $ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$id,(int)$e['amount_cents'],true);$last=null;$order=null;
            foreach($ledger['allocations']as$funding)if($funding['remaining_cents']>0){$original=$funding['allocation'];$candidate=[$original['source_booked_on'],$original['transaction_id'],$original['id']];if($order===null||$candidate>$order){$order=$candidate;$last=['account_id'=>$original['source_account_id'],'booked_on'=>$original['source_booked_on']];}}
            if($paid&&$last===null)throw new DomainException('Für den vollständigen Zahlungsstand fehlt eine aktive ursprüngliche Zahlung.',503);
        }else{$q=$this->db->prepare($sql);$q->execute([$a['club_id'],$id]);$last=$q->fetch();}
        // Legacy columns retain the last payment only when fully paid. The allocations are authoritative.
        $this->db->prepare('UPDATE finance_entries SET status=?,account_id=?,paid_on=?,version=version+1 WHERE club_id=? AND id=?')->execute([$paid?'paid':'open',$paid?$last['account_id']:null,$paid?$last['booked_on']:null,$a['club_id'],$id]);
    }
    public function releaseAllocation(string $id,int $transactionVersion,int $entryVersion,string $reason):void
    {
        try{$entry=$this->transaction(function()use($id,$transactionVersion,$entryVersion,$reason){
            $a=$this->actor(true);$reason=trim($reason);if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500)throw new DomainException('Bitte einen Korrekturgrund angeben (höchstens 500 Zeichen).',422);
            $q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$l=$q->fetch();if(!$l)throw new DomainException('Zuordnung nicht verfügbar.',403);
            if($l['voided_at']){if($l['void_reason']!==$reason)throw new DomainException('Diese Zuordnung ist bereits mit einem anderen Grund aufgehoben. Den ursprünglichen Verlauf prüfen.',409);return $l['entry_id'];}
            $this->lockMovements([$l['transaction_id']]);$t=$this->transactionDetail($l['transaction_id'],true);$e=$this->currentEntry($l['entry_id']);
            if((int)$t['version']!==$transactionVersion||(int)$e['version']!==$entryVersion||$t['reversed_on']||$t['reversal_of']||$e['reversed_on'])throw new DomainException('Zuordnung inzwischen geändert oder bereits gegengebucht. Aktuellen Stand prüfen.',409);
            PaymentFunding::assertAllocationMutable($this->db,$a['club_id'],$id);DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$id);
            if($t['account_kind']==='cash')$this->assertUnclosed($t['account_id'],$t['booked_on']);
            $this->db->prepare('UPDATE finance_allocations SET voided_at=?,void_reason=? WHERE club_id=? AND id=?')->execute([gmdate('Y-m-d\TH:i:s\Z'),$reason,$a['club_id'],$id]);
            $this->refreshPayment($e['id'],true);$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$t['id']]);
            $this->audit($a,'finance.allocation.released',$id,2);return $e['id'];
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Zuordnung oder Konto wird gerade bearbeitet. Den aktuellen Stand erneut prüfen.',503,$failure);throw $failure;}
        DonationPreparation::afterSource($this->db,$this->actorId,'entry',$entry);
    }
    public function canReverseEntry(string $id,bool $current=false):bool
    {
        $e=$current?$this->currentEntry($id):$this->entry($id);if($e['payment_state']!=='paid')return false;
        $q=$this->db->prepare("SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? AND state<>'cancelled'".($current?' FOR SHARE NOWAIT':''));$q->execute([$e['club_id'],$id]);
        if($q->fetchColumn())return false;
        $allocations=array_values(array_filter($this->allocations($id,false,$current),fn($l)=>!$l['voided_at']));
        if(PaymentFundingLedger::hasBasis($this->db,$e['club_id'],$id,$current)){$funding=PaymentFundingLedger::entry($this->db,$e['club_id'],$id,(int)$e['amount_cents'],$current);$allocations=array_values(array_filter($allocations,static fn(array$l):bool=>($funding['allocations'][$l['id']]['remaining_cents']??0)>0));}
        if(count(array_unique(array_column($allocations,'transaction_id')))!==1)return false;$t=$this->transactionDetail($allocations[0]['transaction_id'],$current);
        return !$t['reversal_of']&&!$t['reversed_on']&&!PaymentFunding::movementHasExecution($this->db,$e['club_id'],$t['id'],$current)&&(int)$t['allocated_cents']===(int)$e['amount_cents']&&abs((int)$t['cents'])===(int)$e['amount_cents'];
    }

    /** Link two actual movements; a failed debit reopens its demand without inventing money. */
    public function reconcileReturnedDebitLocked(string $entryId,string $positiveTxId,string $negativeTxId,string $reason):void
    {
        $a=$this->actor(true);
        if(!$this->db->inTransaction())throw new DomainException('Rücklastschrift benötigt einen gemeinsamen Speichervorgang.');
        $reason=trim($reason);
        if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500)throw new DomainException('Rücklastschriftgrund mit höchstens 500 Zeichen angeben.');
        $e=$this->currentEntry($entryId);$original=$this->transactionDetail($positiveTxId,true);$returned=$this->transactionDetail($negativeTxId,true);
        if($e['kind']!=='contribution'||$e['payment_state']!=='paid'||$original['account_kind']==='cash'
            ||$original['account_id']!==$returned['account_id']||$positiveTxId===$negativeTxId
            ||(int)$original['cents']!==(int)$e['amount_cents']||(int)$returned['cents']!==-(int)$e['amount_cents']
            ||$original['reversal_of']||$original['reversed_on']||$returned['reversal_of']||$returned['reversed_on']
            ||(int)$returned['allocated_cents']!==0||$returned['booked_on']<$original['booked_on']){
            throw new DomainException('Nur eine vollständige ungeteilte Beitragszahlung und ihre gleich hohe Rücklastschrift auf demselben Konto können zusammengeführt werden.');
        }
        $links=array_values(array_filter($this->allocations($positiveTxId,true,true),static fn($l)=>!$l['voided_at']));
        if(count($links)!==1||$links[0]['entry_id']!==$entryId||(int)$links[0]['cents']!==(int)$e['amount_cents'])throw new DomainException('Geteilte Beitragszahlung bitte gesondert prüfen.');
        if(DocumentCatalog::query($this->db,"SELECT 1 FROM donation_receipt_claims WHERE club_id=? AND source_type='payment' AND source_id=? FOR SHARE NOWAIT",[$a['club_id'],$links[0]['id']])->fetchColumn())throw new DomainException('Zuerst die zugehörige Zuwendungsbescheinigung begründet stornieren.');
        $this->db->prepare('UPDATE finance_transactions SET reversal_of=?,version=version+1 WHERE club_id=? AND id=?')->execute([$positiveTxId,$a['club_id'],$negativeTxId]);
        $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$positiveTxId]);
        $this->refreshPayment($entryId,true);
        $this->audit($a,'finance.debit.returned',$entryId,(int)$e['version']+1);
    }
}
