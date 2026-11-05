<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Return one actual whole payment while keeping every original demand open. */
trait PaymentReturns
{
    private static function paymentReturnJson(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private function paymentReturnsAvailable():bool
    {
        return Database::tableExists($this->db,'finance_payment_returns');
    }

    private function paymentReturnSourceFacts(string $source,bool $current=false,bool $exclusive=false):array
    {
        return $this->groupReversalSourceFacts($source,$current,$exclusive,'reopen-demands');
    }

    /** An unchanged issued demand may reopen; legal corrections use their own refund lifecycle. */
    private function paymentReturnIssuedFacts(string $entry):?array
    {
        if(!$this->db->inTransaction())throw new DomainException('Ursprüngliche Rechnung gemeinsam mit ihrer Zahlung prüfen.');
        $a=$this->actor();$q=$this->db->prepare('SELECT id,kind,state,parent_id,number,version FROM outgoing_invoices WHERE club_id=? AND entry_id=? ORDER BY id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$entry]);$rows=$q->fetchAll();
        if(!$rows)return null;
        if(count($rows)!==1||$rows[0]['kind']!=='invoice'||$rows[0]['state']!=='issued'||$rows[0]['parent_id']!==null)throw new DomainException('Eine unveränderte ausgegebene Originalrechnung auswählen. Stornos und Gutschriften gesondert klären.',409);
        $invoice=$rows[0];$q=$this->db->prepare('SELECT id FROM outgoing_invoices WHERE club_id=? AND parent_id=? ORDER BY id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$invoice['id']]);if($q->fetchColumn())throw new DomainException('Zur Rechnung gibt es eine Gutschrift oder einen Storno. Ursprüngliche Zahlung und Rechnungskorrektur zuerst gemeinsam klären.',409);
        $context=\Kulturhus\Verein\Finance\Invoices\OutgoingMailContext::read($this->db,$this->actorId,$invoice['id']);
        return ['invoice'=>$invoice,'payload_sha256'=>hash('sha256',$context['invoice']['payload']),'document'=>$context['source']['document'],'folders'=>$context['source']['folders'],'file'=>$context['source']['file']];
    }

    public function paymentReturnSource(string $source):array
    {
        return $this->bankCatalogueRead(fn():array=>$this->paymentReturnSourceFacts($source,true));
    }

    public function paymentReturnCandidatesPage(string $source,mixed $page=1,mixed $search=''):array
    {
        return $this->bankCatalogueRead(function()use($source,$page,$search):array{
            $s=$this->paymentReturnSourceFacts($source,true);if(!$s['eligible'])throw new DomainException('Zuerst alle durch diese Zahlung vollständig bezahlten Originalbelege prüfen.',409);
            $t=$s['transaction'];return $this->transactionsPage(['account'=>$t['account_id'],'direction'=>$t['cents']>0?'expense':'income','amount'=>self::decimal(abs((int)$t['cents'])),'from'=>$t['booked_on'],'to'=>$this->businessDay(),'assignment'=>'open','available'=>true,'q'=>$search],$page,20);
        });
    }

    private function paymentReturnReviewFacts(string $source,string $returned,string $reason,bool $current=false,bool $exclusive=false):array
    {
        $s=$this->paymentReturnSourceFacts($source,$current,$exclusive);if(!$s['eligible'])throw new DomainException('Die Zahlung und alle ursprünglichen Belege müssen vollständig zusammenpassen. Teilrückzahlungen, weitere Zahlungsquellen, offene Restbeträge, bescheinigte Spenden, Rechnungskorrekturen und laufende Bankaufträge zuerst klären.',409);
        $t=$s['transaction'];$r=$this->transactionDetail($returned,$current);
        if($source===$returned||$r['account_id']!==$t['account_id']||(int)$r['cents']!==-(int)$t['cents']||$r['reversal_of']||$r['reversed_on']||(int)$r['allocated_cents']!==0||$r['booked_on']<$t['booked_on']||$r['booked_on']>$this->businessDay())throw new DomainException('Eine bereits erfasste, noch nicht zugeordnete vollständige Gegenbewegung auf demselben Konto nach der ursprünglichen Zahlung auswählen.',409);
        return ['format'=>'dvm-payment-return-review/1','club_id'=>$this->actor()['club_id'],'actor'=>$this->actorId,'reason'=>$reason,'source'=>$s,'returned'=>$r];
    }

    public function paymentReturnReview(string $source,string $returned,string $reason=''):array
    {
        return $this->bankCatalogueRead(function()use($source,$returned,$reason):array{if(!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Korrekturgrund mit höchstens 500 Zeichen angeben.',422);$review=$this->paymentReturnReviewFacts($source,$returned,$reason,true);return ['review'=>$review,'hash'=>hash('sha256',self::paymentReturnJson($review))];});
    }

    private function checkedPaymentReturn(array $row):array
    {
        foreach(['request','review','result']as$key)if(!hash_equals($row[$key.'_sha256'],hash('sha256',$row[$key.'_json'])))throw new DomainException('Ursprünglichen Nachweis der zurückgekommenen Zahlung prüfen lassen.',503);
        $request=json_decode($row['request_json'],true,128,JSON_THROW_ON_ERROR);$review=json_decode($row['review_json'],true,128,JSON_THROW_ON_ERROR);$result=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);
        if(($request['format']??'')!=='dvm-payment-return-request/1'||($review['format']??'')!=='dvm-payment-return-review/1'||($result['format']??'')!=='dvm-payment-return-result/1'||($request['club_id']??'')!==$row['club_id']||($review['club_id']??'')!==$row['club_id']||($request['actor']??'')!==$row['created_by']||($request['reason']??'')!==$row['reason']||($review['reason']??'')!==$row['reason']||($review['actor']??'')!==$row['created_by']||($request['source']??'')!==$row['source_id']||($request['returned']??'')!==$row['returned_id']||($request['review_hash']??'')!==$row['review_sha256']||($review['source']['transaction']['id']??'')!==$row['source_id']||($review['returned']['id']??'')!==$row['returned_id']||abs((int)($review['source']['transaction']['cents']??0))!==(int)$row['cents']||($result['source_id']??'')!==$row['source_id']||($result['returned_id']??'')!==$row['returned_id']||($result['entries']??[])!==array_keys($review['source']['entries']??[]))throw new DomainException('Identität und ursprünglicher Rückzahlungsnachweis stimmen nicht überein.',503);
        foreach(['id','club_id','created_by','created_at','request_key']as$field)if(($result[$field]??null)!==$row[$field])throw new DomainException('Identität des ursprünglichen Rückzahlungsnachweises stimmt nicht überein.',503);
        return $row+compact('request','review','result');
    }

    /** Current immutable provenance for bank classification, independent of later collection. */
    public function paymentReturnEvidence(string $source,string $returned,bool $current=false):?array
    {
        if($current&&!$this->db->inTransaction())throw new DomainException('Aktuellen Rückzahlungsnachweis gemeinsam prüfen.');
        $a=$this->actor();if(!$this->paymentReturnsAvailable())return null;
        $q=$this->db->prepare('SELECT * FROM finance_payment_returns WHERE club_id=? AND source_id=? AND returned_id=?'.($current?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$source,$returned]);
        return ($row=$q->fetch())?$this->checkedPaymentReturn($row):null;
    }

    /** Only a separately confirmed actual bank-order relation resolves a submission. */
    public function paymentReturnResolvesTransfer(string $id):bool
    {
        return $this->explicitlyReturnedTransfer($id)||$this->explicitlySettledTransfer($id);
    }

    public function paymentReturnHistory(string $source):array
    {
        return $this->bankCatalogueRead(function()use($source):array{$a=$this->actor();$this->transactionDetail($source);if(!$this->paymentReturnsAvailable())return [];$q=$this->db->prepare('SELECT * FROM finance_payment_returns WHERE club_id=? AND (source_id=? OR returned_id=?) ORDER BY created_at,id');$q->execute([$a['club_id'],$source,$source]);return array_map($this->checkedPaymentReturn(...),$q->fetchAll());});
    }

    /** Download the unchanged original only while its referenced private documents remain readable. */
    public function paymentReturnOriginal(string $source,string $id):array
    {
        return $this->bankCatalogueRead(function()use($source,$id):array{
            $a=$this->actor();$found=null;foreach($this->paymentReturnHistory($source)as$row)if($row['id']===$id)$found=$row;
            if(!$found)throw new DomainException('Ursprünglicher Rückzahlungsnachweis nicht verfügbar.',404);
            $docs=new Documents($this->db,$this->actorId);
            foreach($found['review']['source']['originals']as$originals)foreach($originals as$original){
                $document=$original['document']['id'];$revision=(int)$original['file']['revision'];$read=$docs->currentFinancialOriginal($document,$revision);
                $q=$this->db->prepare('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$document,$revision]);$file=$q->fetch();
                if(!$file||$read['trashed']||!hash_equals($original['file']['sha256'],$read['file']['sha256'])||!hash_equals($original['file']['sha256'],$file['sha256'])||!hash_equals($original['file']['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Eine gespeicherte Originaldatei ist nicht unverändert zugänglich. Den ursprünglichen Rückzahlungsnachweis zuerst klären.',409);
            }
            return $found;
        });
    }

    /** No new money: the supplied counter must already exist in the actual ledger. */
    public function returnPayment(string $source,int $version,string $returned,int $returnedVersion,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die zurückgekommene Zahlung benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $actor=$this->actor(true);self::requestKey($key);
        if(!$confirmed||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash)||trim($reason)===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Alle Originalbelege und die vorhandene vollständige Rückzahlung prüfen, begründen und das Fortbestehen der Forderungen gesondert bestätigen.',422);
        if(!$this->paymentReturnsAvailable())throw new DomainException('Zurückgekommene Zahlungen benötigen das ausdrücklich gewartete aktuelle Paket.',503);
        $request=['format'=>'dvm-payment-return-request/1','club_id'=>$actor['club_id'],'actor'=>$actor['id'],'source'=>$source,'source_version'=>$version,'returned'=>$returned,'returned_version'=>$returnedVersion,'reason'=>$reason,'review_hash'=>$reviewHash];$json=self::paymentReturnJson($request);$fingerprint=hash('sha256',$json);
        $first=function(bool $current)use($actor,$key,$fingerprint):?array{$a=$this->actor(true);if($a['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT * FROM finance_payment_returns WHERE club_id=? AND request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$a['club_id'],$key]);if(!$row=$q->fetch())return null;$row=$this->checkedPaymentReturn($row);if(!hash_equals($row['request_sha256'],$fingerprint))throw new DomainException('Diese Rückzahlungskennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Verlauf prüfen.',409);return ['id'=>$row['id'],'replayed'=>true];};
        try{$acceptedResult=$this->transaction(function()use($source,$returned,$version,$returnedVersion,$reason,$key,$reviewHash,$json,$fingerprint,$first):array{
            if($old=$first(true))return $old;
            $q=$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT');if((int)$q->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Zurückgekommene Zahlungen benötigen den ausdrücklich gewarteten aktuellen Paketstand.',503);
            $this->lockMovements([$source,$returned]);$review=$this->paymentReturnReviewFacts($source,$returned,$reason,true,true);$t=$review['source']['transaction'];$r=$review['returned'];$reviewJson=self::paymentReturnJson($review);
            if((int)$t['version']!==$version||(int)$r['version']!==$returnedVersion||!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Zahlung, Rückzahlung oder einer der Originalbelege hat sich geändert. Alle Quellen erneut prüfen und das Fortbestehen der Forderungen anschließend gesondert bestätigen.',409);
            foreach($review['source']['allocations']as$link)DonationReceipts::assertPaymentMutable($this->db,$t['club_id'],$link['id']);
            if($t['account_kind']==='cash')foreach([$t,$r]as$movement)$this->assertUnclosed($movement['account_id'],$movement['booked_on']);
            $a=$this->actor(true);$this->db->prepare('UPDATE finance_transactions SET reversal_of=?,version=version+1 WHERE club_id=? AND id=?')->execute([$source,$a['club_id'],$returned]);
            $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$source]);
            foreach($review['source']['entries']as$id=>$entry){$this->refreshPayment($id,true);$this->audit($a,'finance.payment.returned',$id,(int)$entry['version']+1);}
            $id=bin2hex(random_bytes(16));$now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=self::paymentReturnJson(['format'=>'dvm-payment-return-result/1','id'=>$id,'club_id'=>$a['club_id'],'created_by'=>$a['id'],'created_at'=>$now,'request_key'=>$key,'source_id'=>$source,'returned_id'=>$returned,'entries'=>array_keys($review['source']['entries']),'date'=>$r['booked_on']]);
            $this->db->prepare('INSERT INTO finance_payment_returns(club_id,id,source_id,returned_id,cents,reason,request_key,created_by,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$source,$returned,abs((int)$t['cents']),$reason,$key,$a['id'],$now,$json,$fingerprint,$reviewJson,$reviewHash,$result,hash('sha256',$result)]);
            $this->audit($a,'finance.payment-return.recorded',$id,1);return ['id'=>$id,'replayed'=>false];
        });}catch(\PDOException $error){
            try{if($old=$first(false))return $old;}catch(\PDOException $unknown){throw new DomainException('Speicherausgang noch unbestätigt. Dieselbe Rückzahlungskennung erneut prüfen; keinen weiteren Kontoumsatz erfassen.',503,$unknown);}
            throw new DomainException('Die zurückgekommene Zahlung konnte nicht bestätigt werden. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);
        }
        if(!$acceptedResult['replayed'])DonationPreparation::afterTransaction($this->db,$this->actorId,$source);
        return $acceptedResult;
    }
}
