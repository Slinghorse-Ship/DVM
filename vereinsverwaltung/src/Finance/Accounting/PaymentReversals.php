<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Correct one whole receipt-bound movement, retaining both financial originals. */
trait PaymentReversals
{
    private function reversalFundingLinks(array $entry,bool $current=false):array
    {
        $links=array_values(array_filter($this->allocations($entry['id'],false,$current),static fn(array$row):bool=>$row['voided_at']===null));
        if(!PaymentFundingLedger::hasBasis($this->db,$entry['club_id'],$entry['id'],$current))return $links;
        if($entry['reversed_on']!==null)return array_values(array_filter($links,static fn(array$row):bool=>$row['reversed_on']===$entry['reversed_on']));
        $ledger=PaymentFundingLedger::entry($this->db,$entry['club_id'],$entry['id'],(int)$entry['amount_cents'],$current);
        return array_values(array_filter($links,static fn(array$row):bool=>($ledger['allocations'][$row['id']]['remaining_cents']??0)>0));
    }

    public function reversalSource(string $id):array
    {
        return $this->bankCatalogueRead(function()use($id):array{
            $a=$this->actor();$entry=$this->entry($id);$links=$this->reversalFundingLinks($entry);$ids=array_unique(array_column($links,'transaction_id'));$original=count($ids)===1?$this->transactionDetail(reset($ids)):null;$counter=null;
            if($original){$q=$this->db->prepare('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=?');$q->execute([$a['club_id'],$original['id']]);$counterId=$q->fetchColumn();if($counterId)$counter=$this->transactionDetail($counterId);}
            $issued=false;if(Database::tableExists($this->db,'outgoing_invoices')){$q=$this->db->prepare("SELECT id FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND state IN('issued','cancelled') LIMIT 1");$q->execute([$a['club_id'],$id]);$issued=(bool)$q->fetchColumn();}
            $eligible=!$issued&&$this->canReverseEntry($id);$review=$eligible&&$original?$this->reversalCounterReview($entry,$original):['hash'=>'','total'=>0];
            return ['entry'=>$entry,'allocations'=>$links,'original'=>$original,'counter'=>$counter,'issued'=>$issued,'eligible'=>$eligible,'counter_review'=>$review['hash'],'reviewed_movements'=>$review['total']];
        });
    }

    public function reversalCandidatesPage(string $id,mixed $page=1,mixed $search=''):array
    {
        return $this->bankCatalogueRead(function()use($id,$page,$search):array{
            $source=$this->reversalSource($id);if(!$source['eligible']||!$source['original'])throw new DomainException('Zuerst die aktuelle vollständige Zahlungszuordnung dieses Belegs prüfen.',409);
            $original=$source['original'];return $this->transactionsPage(['account'=>$original['account_id'],'direction'=>$original['cents']>0?'expense':'income','amount'=>self::decimal(abs((int)$original['cents'])),'from'=>$original['booked_on'],'to'=>$this->businessDay(),'assignment'=>'open','available'=>true,'q'=>$search],$page,20);
        });
    }

    /** Include assigned/corrected movements and later bank evidence, separately from selectable targets. */
    private function reversalCounterReview(array $entry,array $original,bool $current=false):array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuelle Gegenbewegungen gemeinsam mit der Korrektur prüfen.');
        $sql='SELECT t.id,t.version FROM finance_transactions t WHERE t.club_id=? AND t.account_id=? AND t.cents=? AND t.id>? ORDER BY t.id LIMIT 200'.($current?' FOR SHARE NOWAIT':'');
        $hash=hash_init('sha256');hash_update($hash,json_encode(['receipt-refund-review-v2',$entry['club_id'],$entry['id'],(int)$entry['version'],$original['id'],$original['account_id'],(int)$original['cents']],JSON_THROW_ON_ERROR)."\n");$total=0;$last='';
        do{$q=$this->db->prepare($sql);$q->execute([$entry['club_id'],$original['account_id'],-(int)$original['cents'],$last]);$rows=$q->fetchAll();$evidence=BankEvidence::proofs($this->db,$entry['club_id'],array_column($rows,'id'),$current);
            foreach($rows as$row){++$total;$proofs=$evidence[$row['id']]??[['record_id'=>'','kind'=>'','bank_version'=>0,'bank_state'=>'','bank_hash'=>'']];foreach($proofs as$proof)hash_update($hash,json_encode([$row['id'],(int)$row['version'],$proof['record_id'],$proof['kind'],(int)$proof['bank_version'],$proof['bank_state'],$proof['bank_hash']],JSON_THROW_ON_ERROR)."\n");$last=$row['id'];}
        }while(count($rows)===200);
        return ['hash'=>hash_final($hash),'total'=>$total];
    }

    private static function reversalCounterKey(array $entry,int $version,string $original,string $date,string $reason,?string $review):string
    {
        $input=$review===null?['receipt-counter-v1',$entry['club_id'],$entry['id'],$version,$original,$date,$reason]:['receipt-counter-v2',$entry['club_id'],$entry['id'],$version,$original,$date,$reason,$review];return substr(hash('sha256',json_encode($input,JSON_THROW_ON_ERROR)),0,32);
    }

    public function reverse(string $id,int $version,string $date,string $reason,string $existingCounter='',int $existingVersion=0,?string $counterReview=null):void
    {
        $changed=false;
        try{$this->transaction(function()use($id,$version,$date,$reason,$existingCounter,$existingVersion,$counterReview,&$changed):void{
            $a=$this->actor(true);$e=$this->currentEntry($id);$this->assertNotIssuedOutgoing($id);self::date($date);$reason=trim($reason);
            if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Korrekturgrund mit höchstens 500 Zeichen angeben.',422);
            if($counterReview!==null&&!preg_match('/^[a-f0-9]{64}$/D',$counterReview))throw new DomainException('Aktuellen Korrekturstand und passende Gegenbewegungen zuerst prüfen.',409);
            $links=$this->reversalFundingLinks($e,true);$ids=array_unique(array_column($links,'transaction_id'));
            if(count($ids)!==1)throw new DomainException('Nur eine vollständige, ungeteilte Zahlung korrigieren. Falsche Zuordnungen einzeln aufheben.',409);
            $originalId=(string)reset($ids);$q=$this->db->prepare('SELECT * FROM finance_reversals WHERE club_id=? AND entry_id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$previous=$q->fetch();
            $q=$this->db->prepare('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$originalId]);$acceptedCounter=$q->fetchColumn();
            $movementIds=[$originalId];if($existingCounter!=='')$movementIds[]=$existingCounter;if($acceptedCounter)$movementIds[]=$acceptedCounter;$this->lockMovements($movementIds);$original=$this->transactionDetail($originalId,true);
            if($previous){
                $q=$this->db->prepare("SELECT 1 FROM audit_events WHERE club_id=? AND actor_id=? AND action='finance.payment.reversed' AND object_id=? AND object_version=? FOR SHARE NOWAIT");$q->execute([$a['club_id'],$a['id'],$id,$version+1]);$sameVersion=(bool)$q->fetchColumn();
                $q=$this->db->prepare("SELECT * FROM audit_events WHERE club_id=? AND action='finance.transaction.return-linked' AND object_id=? ORDER BY sequence LIMIT 1 FOR SHARE NOWAIT");$q->execute([$a['club_id'],$acceptedCounter?:'']);$linked=$q->fetch();
                $counter=$acceptedCounter?$this->transactionDetail($acceptedCounter,true):null;
                if(!$sameVersion||$previous['actor_id']!==$a['id']||$previous['reversed_on']!==$date||$previous['reason']!==$reason||!$counter||$counter['booked_on']!==$date||$counter['account_id']!==$original['account_id']||(int)$counter['cents']!==-(int)$original['cents']||($linked?($existingCounter!==$counter['id']||(int)$linked['object_version']!==$existingVersion+1):$existingCounter!==''))throw new DomainException('Diese Zahlung wurde bereits anders korrigiert. Den ursprünglichen Verlauf prüfen.',409);
                if(!$linked&&$counterReview!==null&&!hash_equals($counter['request_key'],self::reversalCounterKey($e,$version,$originalId,$date,$reason,$counterReview)))throw new DomainException('Diese Zahlung wurde bereits mit einer anderen Gegenbewegungsprüfung korrigiert. Ursprünglichen Auftrag prüfen.',409);
                return;
            }
            if(PaymentFunding::movementHasExecution($this->db,$a['club_id'],$originalId,true))throw new DomainException('Die ursprüngliche Zahlung belegt einen bestätigten Bankauftrag. Den Ausführungsnachweis vor einer vollständigen Gegenbuchung bewusst zurücknehmen oder die vorhandene Rückzahlung als Teilbetrag zuordnen.',409);
            if(!$this->canReverseEntry($id,true)||(int)$e['version']!==$version||$date<$original['booked_on']||$date>$this->businessDay())throw new DomainException('Gegenbuchung nur für eine unveränderte vollständige Zahlung möglich. Aktuellen Stand prüfen.',409);
            foreach($links as $link)DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$link['id']);
            if($existingCounter!==''){
                $counter=$this->transactionDetail($existingCounter,true);
                if($existingVersion<=0||(int)$counter['version']!==$existingVersion||$counter['account_id']!==$original['account_id']||(int)$counter['cents']!==-(int)$original['cents']||$counter['reversal_of']||$counter['reversed_on']||(int)$counter['allocated_cents']!==0||$counter['booked_on']!==$date)throw new DomainException('Unveränderte, noch nicht zugeordnete Gegenbewegung auf demselben Konto mit gleichem Betrag und tatsächlichem Datum auswählen.',409);
                if($counter['account_kind']==='cash')$this->assertUnclosed($counter['account_id'],$date);
                $this->db->prepare('UPDATE finance_transactions SET reversal_of=?,version=version+1 WHERE club_id=? AND id=?')->execute([$originalId,$a['club_id'],$existingCounter]);
                $this->audit($a,'finance.transaction.return-linked',$existingCounter,$existingVersion+1);
            }else{
                $review=$this->reversalCounterReview($e,$original,true);
                if(($counterReview===null&&$review['total']>0)||($counterReview!==null&&!hash_equals($review['hash'],$counterReview)))throw new DomainException('Passende Gegenbewegungen wurden inzwischen geändert. Aktuellen Korrekturstand prüfen.',409);
                $request=self::reversalCounterKey($e,$version,$originalId,$date,$reason,$counterReview);
                $this->insertTransaction(['account'=>$original['account_id'],'booked_on'=>$date,'direction'=>$original['cents']>0?'expense':'income','amount'=>self::decimal(abs((int)$original['cents'])),'counterparty'=>$original['counterparty'],'description'=>'Korrektur: '.mb_substr($reason,0,500-mb_strlen('Korrektur: '),'UTF-8')],$request,$originalId);
            }
            $this->db->prepare('INSERT INTO finance_reversals VALUES(?,?,?,?,?)')->execute([$a['club_id'],$id,$date,$reason,$a['id']]);$this->db->prepare('UPDATE finance_entries SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$id]);$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$originalId]);$this->audit($a,'finance.payment.reversed',$id,$version+1);$changed=true;
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Beleg, Zahlung oder Konto wird gerade bearbeitet. Den aktuellen Stand erneut prüfen.',503,$failure);throw $failure;}
        if($changed)DonationPreparation::afterSource($this->db,$this->actorId,'entry',$id);
    }
}
