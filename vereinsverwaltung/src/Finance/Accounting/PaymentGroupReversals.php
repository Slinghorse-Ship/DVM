<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Fully correct all receipts of one shared payment using one already recorded actual counter. */
trait PaymentGroupReversals
{
    private static function groupReversalJson(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private function groupReversalsAvailable():bool
    {
        return Database::tableExists($this->db,'finance_group_reversals');
    }

    private function groupReversalDocuments(string $entry):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Ursprüngliche Belege gemeinsam prüfen.');
        $a=$this->actor();$q=$this->db->prepare("SELECT d.id,f.revision,f.sha256,f.bytes FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id LEFT JOIN document_files newer ON newer.club_id=f.club_id AND newer.document_id=f.document_id AND newer.revision>f.revision WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? AND d.state='active' AND newer.revision IS NULL ORDER BY d.id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry]);$rows=$q->fetchAll();$out=[];$docs=new Documents($this->db,$this->actorId);
        foreach($rows as$row){$read=$docs->currentFinancialOriginal($row['id'],(int)$row['revision']);if($read['trashed']||!hash_equals($row['sha256'],hash('sha256',$row['bytes'])))throw new DomainException('Ein ursprünglicher Beleg ist nicht unverändert zugänglich. Vor der gemeinsamen Gegenbuchung klären.',409);$out[]=$read;}
        return $out;
    }

    private function groupReversalSourceFacts(string $source,bool $current=false,bool $exclusive=false,string $intent='correct-documents'):array
    {
        if(!in_array($intent,['correct-documents','reopen-demands'],true))throw new \LogicException('Explicit whole payment intent required.');
        $reopen=$intent==='reopen-demands';$issuedOriginals=[];$debitOrders=[];
        $a=$this->actor();$t=$this->transactionDetail($source,$current);
        $links=array_values(array_filter($this->allocations($source,true,$current),static fn(array $l):bool=>$l['voided_at']===null));
        $byEntry=[];foreach($links as $link)$byEntry[$link['entry_id']][]=$link;ksort($byEntry,SORT_STRING);
        $entries=[];$positions=[];$originals=[];$approvals=[];$orders=[];$eligible=count($byEntry)>=($reopen?1:2)&&count($byEntry)<=40;
        $eligible=$eligible&&!$t['reversal_of']&&!$t['reversed_on']&&(int)$t['remaining_cents']===0;
        if(PaymentFunding::movementHasExecution($this->db,$a['club_id'],$t['id'],$current))$eligible=false;
        $sum=0;$suffix=$current?' FOR SHARE NOWAIT':'';
        foreach($byEntry as $id=>$ownLinks){
            if($exclusive){$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);}
            $entry=$current?$this->currentEntry($id):$this->entry($id);$entries[$id]=$entry;
            $amount=array_sum(array_map(static fn(array $l):int=>(int)$l['cents'],$ownLinks));$sum+=$amount;
            if($entry['payment_state']!=='paid'||$amount!==(int)$entry['amount_cents']||(($t['cents']<0)!==($entry['kind']==='expense')))$eligible=false;
            $liveFunding=PaymentFundingLedger::hasBasis($this->db,$a['club_id'],$id,$current)?PaymentFundingLedger::entry($this->db,$a['club_id'],$id,(int)$entry['amount_cents'],$current):null;
            foreach($this->allocations($id,false,$current)as$link)if($link['voided_at']===null&&!$link['reversed_on']&&!$link['reversal_of']&&$link['transaction_id']!==$source&&($liveFunding===null||($liveFunding['allocations'][$link['id']]['remaining_cents']??0)>0))$eligible=false;
            if($reopen)$issuedOriginals[$id]=$this->paymentReturnIssuedFacts($id);
            else{$q=$this->db->prepare("SELECT id FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND state IN('issued','cancelled')".$suffix);$q->execute([$a['club_id'],$id]);if($q->fetchColumn())$eligible=false;}
            if($reopen){$q=$this->db->prepare('SELECT i.id,i.order_id,i.entry_id,i.cents,i.state,o.account_id,o.state AS order_state,o.version AS order_version FROM sepa_order_items i JOIN sepa_orders o ON o.club_id=i.club_id AND o.id=i.order_id WHERE i.club_id=? AND i.entry_id=? ORDER BY i.id'.$suffix);$q->execute([$a['club_id'],$id]);$debitOrders[$id]=$q->fetchAll();foreach($debitOrders[$id]as$debit)if($debit['state']==='active')$eligible=false;}
            // Private advances have their own approved reimbursement lifecycle.
            $q=$this->db->prepare("SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? AND state<>'cancelled'".$suffix);$q->execute([$a['club_id'],$id]);if($q->fetchColumn())$eligible=false;
            foreach($ownLinks as$link){$q=$this->db->prepare("SELECT receipt_id FROM donation_receipt_claims WHERE club_id=? AND source_type='payment' AND source_id=?".$suffix);$q->execute([$a['club_id'],$link['id']]);if($q->fetchColumn())$eligible=false;}
            $q=$this->db->prepare('SELECT id,entry_id,account_id,execution_date,state,version,total_cents,created_at,bank_reference FROM finance_transfer_orders WHERE club_id=? AND entry_id=? ORDER BY id'.$suffix);$q->execute([$a['club_id'],$id]);$orders[$id]=$q->fetchAll();foreach($orders[$id]as$order)if(in_array($order['state'],['prepared','approved','exported'],true)||($reopen&&$order['state']==='submitted'&&((int)$order['total_cents']!==(int)$entry['amount_cents']||$order['account_id']!==$t['account_id']||$order['execution_date']>$t['booked_on'])))$eligible=false;
            $positions[$id]=$this->positions('entry',$id,$current);
            $originals[$id]=$this->groupReversalDocuments($id);
            if($entry['kind']==='expense')$approvals[$id]=(new InvoiceApprovals($this->db,$this->actorId))->currentState($id);
        }
        if($sum!==abs((int)$t['cents'])||$sum!==(int)$t['allocated_cents'])$eligible=false;
        return ['transaction'=>$t,'allocations'=>$links,'entries'=>$entries,'positions'=>$positions,'originals'=>$originals,'approvals'=>$approvals,'orders'=>$orders,'eligible'=>$eligible,'available'=>$reopen?$this->paymentReturnsAvailable():$this->groupReversalsAvailable()]+($reopen?['issued_originals'=>$issuedOriginals,'debit_orders'=>$debitOrders]:[]);
    }

    public function groupReversalSource(string $source):array
    {
        return $this->bankCatalogueRead(fn():array=>$this->groupReversalSourceFacts($source,true));
    }

    public function groupReversalCandidatesPage(string $source,mixed $page=1,mixed $search=''):array
    {
        return $this->bankCatalogueRead(function()use($source,$page,$search):array{
            $s=$this->groupReversalSourceFacts($source,true);if(!$s['eligible'])throw new DomainException('Zuerst alle vollständig bezahlten Belege derselben gemeinsamen Zahlung prüfen.',409);
            $t=$s['transaction'];return $this->transactionsPage(['account'=>$t['account_id'],'direction'=>$t['cents']>0?'expense':'income','amount'=>self::decimal(abs((int)$t['cents'])),'from'=>$t['booked_on'],'to'=>$this->businessDay(),'assignment'=>'open','available'=>true,'q'=>$search],$page,20);
        });
    }

    private function groupReversalReviewFacts(string $source,string $returned,string $reason,bool $current=false,bool $exclusive=false):array
    {
        $s=$this->groupReversalSourceFacts($source,$current,$exclusive);if(!$s['eligible'])throw new DomainException('Alle beteiligten Belege müssen vollständig und ausschließlich durch diese eine gemeinsame Zahlung bezahlt sein. Teilrückzahlungen, ausgestellte Rechnungen und bescheinigte Spenden gesondert klären.',409);
        $t=$s['transaction'];$r=$this->transactionDetail($returned,$current);
        if($source===$returned||$r['account_id']!==$t['account_id']||(int)$r['cents']!==-(int)$t['cents']||$r['reversal_of']||$r['reversed_on']||(int)$r['allocated_cents']!==0||$r['booked_on']<$t['booked_on']||$r['booked_on']>$this->businessDay())throw new DomainException('Eine bereits erfasste, noch nicht zugeordnete vollständige Gegenbewegung auf demselben Konto nach der ursprünglichen Zahlung auswählen.',409);
        return ['format'=>'dvm-payment-group-reversal-review/1','club_id'=>$this->actor()['club_id'],'actor'=>$this->actorId,'reason'=>$reason,'source'=>$s,'returned'=>$r];
    }

    public function groupReversalReview(string $source,string $returned,string $reason=''):array
    {
        return $this->bankCatalogueRead(function()use($source,$returned,$reason):array{if(!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Korrekturgrund mit höchstens 500 Zeichen angeben.',422);$review=$this->groupReversalReviewFacts($source,$returned,$reason,true);return ['review'=>$review,'hash'=>hash('sha256',self::groupReversalJson($review))];});
    }

    private function checkedGroupReversal(array $row):array
    {
        foreach(['request','review','result']as$key)if(!hash_equals($row[$key.'_sha256'],hash('sha256',$row[$key.'_json'])))throw new DomainException('Ursprünglichen gemeinsamen Korrekturbeleg prüfen lassen.',503);
        $request=json_decode($row['request_json'],true,128,JSON_THROW_ON_ERROR);$review=json_decode($row['review_json'],true,128,JSON_THROW_ON_ERROR);$result=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);
        if(($request['format']??'')!=='dvm-payment-group-reversal-request/1'||($review['format']??'')!=='dvm-payment-group-reversal-review/1'||($result['format']??'')!=='dvm-payment-group-reversal-result/1'||($request['club_id']??'')!==$row['club_id']||($review['club_id']??'')!==$row['club_id']||($request['actor']??'')!==$row['created_by']||($request['reason']??'')!==$row['reason']||($review['reason']??'')!==$row['reason']||($review['actor']??'')!==$row['created_by']||($request['source']??'')!==$row['source_id']||($request['returned']??'')!==$row['returned_id']||($request['review_hash']??'')!==$row['review_sha256']||($review['source']['transaction']['id']??'')!==$row['source_id']||($review['returned']['id']??'')!==$row['returned_id']||abs((int)($review['source']['transaction']['cents']??0))!==(int)$row['cents']||($result['source_id']??'')!==$row['source_id']||($result['returned_id']??'')!==$row['returned_id']||($result['entries']??[])!==array_keys($review['source']['entries']??[]))throw new DomainException('Identität und ursprünglicher gemeinsamer Korrekturbeleg stimmen nicht überein.',503);
        foreach(['id','club_id','created_by','created_at','request_key']as$field)if(($result[$field]??null)!==$row[$field])throw new DomainException('Identität des ursprünglichen gemeinsamen Korrekturbelegs stimmt nicht überein.',503);
        return $row+compact('request','review','result');
    }

    public function groupReversalHistory(string $source):array
    {
        return $this->bankCatalogueRead(function()use($source):array{$a=$this->actor();$this->transactionDetail($source);if(!$this->groupReversalsAvailable())return [];$q=$this->db->prepare('SELECT * FROM finance_group_reversals WHERE club_id=? AND (source_id=? OR returned_id=?) ORDER BY created_at,id');$q->execute([$a['club_id'],$source,$source]);return array_map($this->checkedGroupReversal(...),$q->fetchAll());});
    }

    /** Download the unchanged original only while its referenced private documents remain readable. */
    public function groupReversalOriginal(string $source,string $id):array
    {
        return $this->bankCatalogueRead(function()use($source,$id):array{
            $a=$this->actor();$found=null;foreach($this->groupReversalHistory($source)as$row)if($row['id']===$id)$found=$row;
            if(!$found)throw new DomainException('Ursprünglicher gemeinsamer Korrekturbeleg nicht verfügbar.',404);
            $docs=new Documents($this->db,$this->actorId);
            foreach($found['review']['source']['originals']as$originals)foreach($originals as$original){
                $document=$original['document']['id'];$revision=(int)$original['file']['revision'];$read=$docs->currentFinancialOriginal($document,$revision);
                $q=$this->db->prepare('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$document,$revision]);$file=$q->fetch();
                if(!$file||$read['trashed']||!hash_equals($original['file']['sha256'],$read['file']['sha256'])||!hash_equals($original['file']['sha256'],$file['sha256'])||!hash_equals($original['file']['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Eine gespeicherte Originaldatei ist nicht unverändert zugänglich. Den ursprünglichen gemeinsamen Korrekturbeleg zuerst klären.',409);
            }
            return $found;
        });
    }

    /** No new money: the supplied counter must already exist in the actual ledger. */
    public function reversePaymentGroup(string $source,int $version,string $returned,int $returnedVersion,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Gemeinsame Gegenbuchung benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $actor=$this->actor(true);self::requestKey($key);
        if(!$confirmed||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash)||trim($reason)===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Alle gemeinsamen Originalbelege und die tatsächliche vollständige Gegenbewegung prüfen, begründen und anschließend gesondert bestätigen.',422);
        if(!$this->groupReversalsAvailable())throw new DomainException('Gemeinsame Gegenbuchungen benötigen das ausdrücklich gewartete aktuelle Paket.',503);
        $request=['format'=>'dvm-payment-group-reversal-request/1','club_id'=>$actor['club_id'],'actor'=>$actor['id'],'source'=>$source,'source_version'=>$version,'returned'=>$returned,'returned_version'=>$returnedVersion,'reason'=>$reason,'review_hash'=>$reviewHash];$json=self::groupReversalJson($request);$fingerprint=hash('sha256',$json);
        $first=function(bool $current)use($actor,$key,$fingerprint):?array{$a=$this->actor(true);if($a['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT * FROM finance_group_reversals WHERE club_id=? AND request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$a['club_id'],$key]);if(!$row=$q->fetch())return null;$row=$this->checkedGroupReversal($row);if(!hash_equals($row['request_sha256'],$fingerprint))throw new DomainException('Diese Korrekturkennung gehört zu anderen ursprünglichen Angaben. Gespeicherten gemeinsamen Verlauf prüfen.',409);return ['id'=>$row['id'],'replayed'=>true];};
        try{$acceptedResult=$this->transaction(function()use($source,$returned,$version,$returnedVersion,$reason,$key,$reviewHash,$json,$fingerprint,$first):array{
            if($old=$first(true))return $old;
            $q=$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT');if((int)$q->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Gemeinsame Gegenbuchungen benötigen den ausdrücklich gewarteten aktuellen Paketstand.',503);
            $this->lockMovements([$source,$returned]);$review=$this->groupReversalReviewFacts($source,$returned,$reason,true,true);$t=$review['source']['transaction'];$r=$review['returned'];$reviewJson=self::groupReversalJson($review);
            if((int)$t['version']!==$version||(int)$r['version']!==$returnedVersion||!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Zahlung, Gegenbewegung oder einer der Belege hat sich geändert. Alle Originale erneut prüfen und anschließend gesondert bestätigen.',409);
            foreach($review['source']['allocations']as$link)DonationReceipts::assertPaymentMutable($this->db,$t['club_id'],$link['id']);
            if($t['account_kind']==='cash')foreach([$t,$r]as$movement)$this->assertUnclosed($movement['account_id'],$movement['booked_on']);
            $a=$this->actor(true);$this->db->prepare('UPDATE finance_transactions SET reversal_of=?,version=version+1 WHERE club_id=? AND id=?')->execute([$source,$a['club_id'],$returned]);
            $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$source]);
            foreach($review['source']['entries']as$id=>$entry){$this->db->prepare('INSERT INTO finance_reversals VALUES(?,?,?,?,?)')->execute([$a['club_id'],$id,$r['booked_on'],$reason,$a['id']]);$this->db->prepare('UPDATE finance_entries SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$id]);$this->audit($a,'finance.payment.reversed',$id,(int)$entry['version']+1);}
            $id=bin2hex(random_bytes(16));$now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=self::groupReversalJson(['format'=>'dvm-payment-group-reversal-result/1','id'=>$id,'club_id'=>$a['club_id'],'created_by'=>$a['id'],'created_at'=>$now,'request_key'=>$key,'source_id'=>$source,'returned_id'=>$returned,'entries'=>array_keys($review['source']['entries']),'date'=>$r['booked_on']]);
            $this->db->prepare('INSERT INTO finance_group_reversals(club_id,id,source_id,returned_id,cents,reason,request_key,created_by,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$source,$returned,abs((int)$t['cents']),$reason,$key,$a['id'],$now,$json,$fingerprint,$reviewJson,$reviewHash,$result,hash('sha256',$result)]);
            $this->audit($a,'finance.group-reversal.recorded',$id,1);return ['id'=>$id,'replayed'=>false];
        });}catch(\PDOException $error){
            try{if($old=$first(false))return $old;}catch(\PDOException $unknown){throw new DomainException('Speicherausgang noch unbestätigt. Dieselbe gemeinsame Korrekturkennung erneut prüfen; keinen weiteren Kontoumsatz erfassen.',503,$unknown);}
            throw new DomainException('Gemeinsame Gegenbuchung konnte nicht bestätigt werden. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);
        }
        if(!$acceptedResult['replayed'])DonationPreparation::afterTransaction($this->db,$this->actorId,$source);
        return $acceptedResult;
    }
}
