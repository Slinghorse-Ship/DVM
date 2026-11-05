<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Correct a documented native return and its current funding in the caller's one transaction. */
trait NativeDebitReturnCorrections
{
    private function appendNativeDebitEvent(array $record,string $key,string $kind,?string $parent,array $review,array $financials=[],?string $legacy=null,array $extra=[]):array
    {
        $a=$this->actor(true);$q=$this->db->prepare('SELECT order_id FROM sepa_order_items WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$record['item_id']]);$order=$q->fetchColumn();
        $previous=NativeDebitReturnHistory::latest($this->db,$a['club_id'],$record['item_id'],true);
        $identity=NativeDebitReturnHistory::identity($a['club_id'],$record['id'],$record['item_id'],$order,$previous?(int)$previous['ordinal']+1:1,$kind,$previous['id']??null,$parent,$record['transaction_id'],$financials[0]['id']??null,$financials[0]['request_key']??null,$legacy,$key,$a['id'],$record['created_at']);
        $request=['format'=>'dvm-native-return-event-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'native_request_key'=>$key,'kind'=>$kind,'item_id'=>$record['item_id'],'counter_id'=>$record['transaction_id'],'reason'=>$review['reason']??$record['reason']];
        return NativeDebitReturnHistory::append($this->db,$a['id'],$identity,$request,$review,$kind==='undo'?null:$record,$financials,$extra);
    }

    /** Only called after the ordinary full-return producer has validated and locked both actual movements. */
    public function recordNativeDebitLegacyEventLocked(array $record,string $key,array $review,?string $legacy):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Rücklastschrift und ursprüngliche Zahlung gemeinsam speichern.',409);if((int)$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT')->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Rücklastschriftänderungen benötigen die ausdrücklich abgeschlossene aktuelle Paketwartung.',503);$a=$this->actor(true);$q=$this->db->prepare('SELECT item.*,orders.account_id,orders.state AS order_state FROM sepa_order_items item JOIN sepa_orders orders ON orders.club_id=item.club_id AND orders.id=item.order_id WHERE item.club_id=? AND item.id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$record['item_id']]);$item=$q->fetch();$money=$this->transactionDetail($record['transaction_id'],true);
        if($legacy===null||!$item||$item['state']!=='active'||!in_array($item['order_state'],['exported','submitted'],true)||$money['account_id']!==$item['account_id']||(int)$money['cents']!==-(int)$item['cents']||$money['reversal_of']!==$legacy||$record['actor_id']!==$a['id']||$record['club_id']!==$a['club_id'])throw new DomainException('Den tatsächlich geprüften eigenen Lastschriftposten und Rückumsatz gemeinsam speichern.',409);
        if($record['document_id']!==null)$review['return_document']=(new Documents($this->db,$a['id']))->currentFinancialDocument($record['document_id']);
        return $this->appendNativeDebitEvent($record,$key,'return',null,$review,[],$legacy);
    }

    private function nativeDebitCorrectionFacts(string $item,string $kind,array $allocations,array $financialIds,string $reason,string $evidence,?MemberBankVault $vault):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Rücklastschriftänderung gemeinsam prüfen.',409);
        $a=$this->actor(true);$office=FinancePaymentAuthority::require($this->db,$a['id']);self::partialReturnReason($reason);
        if(!in_array($kind,['attach','attach_existing','undo'],true)||trim($evidence)===''||!mb_check_encoding($evidence,'UTF-8')||mb_strlen($evidence,'UTF-8')>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$evidence))throw new DomainException('Rücklastschriftänderung mit tatsächlichem Banknachweis und ganzem Grund prüfen.',422);
        if(!NativeDebitReturnHistory::available($this->db)||(int)$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT')->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Rücklastschriftänderungen benötigen die ausdrücklich abgeschlossene aktuelle Paketwartung.',503);
        $effect=NativeDebitReturnHistory::current($this->db,$a['club_id'],$item,true);$record=$effect['record'];if($record===null||$effect['item']['state']!=='returned')throw new DomainException('Genau eine aktuell dokumentierte Rücklastschrift auswählen.',409);
        $vault??=MemberBankVault::fromEnvironment($this->db,$a['id'],ProductionRuntime::enabled());$bank=(new SepaOrders($this->db,$a['id'],$vault))->decisionSnapshot($effect['item']['order_id']);
        if(!in_array($bank['order']['state'],['exported','submitted'],true)||($bank['transport']['pending']??false))throw new DomainException('Zuerst den tatsächlichen offenen Bankausgang dieses Lastschriftauftrags klären.',409);
        $counter=$this->partialReturnMoney($record['transaction_id']);$entry=$this->currentEntry($effect['item']['entry_id']);
        if($counter['account_kind']==='cash'||$counter['booked_on']>$this->businessDay()||$counter['reversed_on']!==null||$entry['kind']!=='contribution'||$entry['status']==='cancelled'||$entry['reversed_on']!==null)throw new DomainException('Die tatsächliche Rücklastschrift und die weiterhin gültige Beitragsrechnung prüfen.',409);
        $documents=['originals'=>$this->groupReversalDocuments($entry['id']),'issued_original'=>$this->paymentReturnIssuedFacts($entry['id'])];
        $q=$this->db->prepare('SELECT document_id FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$entry['id']]);if($q->fetchColumn()){$invoice=(new ContributionInvoices($this->db,$a['id']))->currentOriginal($entry['id']);if($invoice['invoice']['state']==='draft')throw new DomainException('Die ursprünglich ausgegebene Beitragsrechnung prüfen.',409);$documents['contribution_invoice']=$invoice['original'];}
        if($record['document_id']!==null){
            $proof=(new Documents($this->db,$a['id']))->currentFinancialDocument($record['document_id']);$q=$this->db->prepare('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$record['document_id'],(int)$proof['file']['revision']]);$file=$q->fetch();
            if($proof['trashed']||$proof['document']['state']!=='active'||!$file||!hash_equals($proof['file']['sha256'],$file['sha256'])||!hash_equals($file['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Den ursprünglichen Rücklastschriftnachweis wiederherstellen und erneut prüfen.',409);$documents['return_document']=$proof;
        }
        $financials=[];$funding=null;$voids=[];$restored=null;$legacy=null;
        if($kind==='attach'){
            if($financialIds||$effect['financials']||$effect['legacy_source_id']!==null)throw new DomainException('Diese Rücklastschrift hat bereits eine finanzielle Zuordnung. Den vorhandenen Nachweis bewusst auswählen.',409);
            $funding=$this->nativeDebitReturnFacts($item,$counter['id'],$allocations,$reason,$vault,$record['document_id'],$record);
        }elseif($kind==='attach_existing'){
            if($allocations||$effect['financials']||$effect['legacy_source_id']!==null)throw new DomainException('Nur die bereits vorhandenen finanziellen Rückzahlungsnachweise dieser Rücklastschrift auswählen.',409);
            $ids=self::transferSettlementIds($financialIds);$sum=0;
            foreach($ids as$id){$first=$this->partialReturnOriginal($counter['id'],$id);if(!$first['active']||$first['counter_id']!==$counter['id']||isset($first['request']['native_debit'])||NativeDebitReturnHistory::financialBound($this->db,$a['club_id'],$id,true))throw new DomainException('Aktive noch nicht verknüpfte Rückzahlungsnachweise dieses tatsächlichen Rückumsatzes auswählen.',409);foreach($first['items']as$part){if($part['entry_id']!==$entry['id'])throw new DomainException('Die vorhandenen Rückzahlungsnachweise müssen vollständig zum ursprünglichen Lastschriftbeleg gehören.',422);}$sum+=(int)$first['cents'];$financials[]=$first;}
            if($sum!==(int)$effect['item']['cents']||(int)$counter['remaining_cents']!==0)throw new DomainException('Die vorhandenen Rückzahlungsnachweise müssen genau den ganzen Einzugsbetrag erklären.',422);
        }else{
            if($allocations||$financialIds)throw new DomainException('Die Rücknahme verwendet ausschließlich die tatsächlich vorhandene Verknüpfung.',422);
            foreach($effect['financials']as$ref){$first=$this->partialReturnOriginal($counter['id'],$ref['id']);$financials[]=$first;$voids[]=$this->partialReturnRevocationFacts($counter['id'],$ref['id'],$reason,$item);}
            if($financials){
                $ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry['id'],(int)$entry['amount_cents'],true);$restored=$ledger['occupied'];
                foreach($financials as$first)foreach($first['items']as$part){DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$part['allocation_id']);$restored=PaymentFundingRanges::restore($restored,$part['ranges'],(int)$entry['amount_cents']);}
            }elseif($effect['legacy_source_id']!==null){
                $source=$this->transactionDetail($effect['legacy_source_id'],true);$links=array_values(array_filter($this->allocations($source['id'],true,true),static fn(array $l):bool=>$l['voided_at']===null));
                if($source['account_id']!==$counter['account_id']||(int)$source['cents']!==-(int)$counter['cents']||$source['reversal_of']!==null||count($links)!==1||$links[0]['entry_id']!==$entry['id']||(int)$links[0]['cents']!==(int)$entry['amount_cents']||(int)$entry['allocated_cents']!==0||$this->paymentReturnEvidence($source['id'],$counter['id'],true))throw new DomainException('Den ursprünglichen vollständigen Zahlungszusammenhang zuerst klären.',409);
                DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$links[0]['id']);
                $all=array_values(array_filter($this->allocations($entry['id'],false,true),static fn(array $l):bool=>$l['voided_at']===null));if(count($all)!==1||$all[0]['id']!==$links[0]['id'])throw new DomainException('Eine spätere Zahlung oder Zuordnung vor der Rücknahme zuerst klären.',409);
                $legacy=compact('source','links');
            }elseif((int)$counter['allocated_cents']!==0)throw new DomainException('Die bisher getrennte Zuordnung dieses Rückumsatzes zuerst ausdrücklich prüfen.',409);
            $q=$this->db->prepare("SELECT id FROM sepa_order_items WHERE club_id=? AND entry_id=? AND state='active' FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry['id']]);if($q->fetchColumn())throw new DomainException('Eine spätere laufende Lastschrift vor der Rücknahme zuerst klären.',409);
            $q=$this->db->prepare("SELECT id FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND state IN ('prepared','approved','exported','submitted') FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry['id']]);if($q->fetchColumn())throw new DomainException('Einen späteren Bankauftrag vor der Rücknahme zuerst klären.',409);
        }
        $review=['format'=>'dvm-native-return-correction-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'kind'=>$kind,'item_id'=>$item,'reason'=>$reason,'evidence'=>$evidence,'authorization'=>$office,'effect'=>$effect,'bank'=>['order_id'=>$bank['order']['id'],'state'=>$bank['order']['state'],'version'=>$bank['order']['version'],'sealed_sha256'=>$bank['sealed_sha256'],'xml_sha256'=>$bank['xml_sha256'],'transport'=>$bank['transport']],'counter'=>$counter,'entry'=>$entry,'documents'=>$documents,'financials'=>array_map(static fn(array $f):array=>array_intersect_key($f,array_fill_keys(['id','request_key','request_sha256','review_sha256','result_sha256','version'],true)),$financials),'funding'=>$funding['review']??null,'voids'=>$voids,'restored_ranges'=>$restored,'legacy'=>$legacy];
        return compact('review','effect','financials','funding','voids','legacy');
    }

    public function nativeDebitCorrectionReview(string $item,string $kind,array $allocations,array $financials,string $reason,string $evidence,?MemberBankVault $vault=null):array
    {
        return $this->bankCatalogueRead(function()use($item,$kind,$allocations,$financials,$reason,$evidence,$vault):array{$facts=$this->nativeDebitCorrectionFacts($item,$kind,$allocations,$financials,$reason,$evidence,$vault);return ['review'=>$facts['review'],'hash'=>hash('sha256',PaymentFundingOriginals::json($facts['review']))];});
    }

    public function correctNativeDebitLocked(string $item,string $kind,array $allocations,array $financialIds,string $reason,string $evidence,string $key,string $hash,?MemberBankVault $vault=null):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Rücklastschrift und Finanzzuordnung gemeinsam speichern.',409);self::requestKey($key);$a=$this->actor(true);$this->lockReturnOrderClub($a['club_id']);
        $facts=$this->nativeDebitCorrectionFacts($item,$kind,$allocations,$financialIds,$reason,$evidence,$vault);$review=$facts['review'];$effect=$facts['effect'];$entry=$review['entry']['id'];$counter=$review['counter']['id'];
        $movements=[$counter];foreach($facts['financials']as$first)foreach($first['items']as$part)$movements[]=$part['source_id'];foreach($allocations as$id){$q=$this->db->prepare('SELECT transaction_id FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);if($source=$q->fetchColumn())$movements[]=$source;}if($facts['legacy'])$movements[]=$facts['legacy']['source']['id'];$this->lockMovements($movements);
        $q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$entry]);
        $facts=$this->nativeDebitCorrectionFacts($item,$kind,$allocations,$financialIds,$reason,$evidence,$vault);$review=$facts['review'];if(!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals($hash,hash('sha256',PaymentFundingOriginals::json($review))))throw new DomainException('Rücklastschrift, Finanzzuordnung oder Banknachweis geändert. Erneut prüfen und gesondert bestätigen.',409);
        if($kind==='attach'){$financial=$this->storeNativeDebitFundingLocked($facts['funding'],$reason,$effect['record']['document_id'],$key,$effect['record'],$review);return ['event'=>NativeDebitReturnHistory::latest($this->db,$a['club_id'],$item,true)['id'],'financial'=>$financial];}
        $record=['club_id'=>$a['club_id'],'id'=>bin2hex(random_bytes(16)),'item_id'=>$item,'transaction_id'=>$counter,'reason'=>trim($reason),'document_id'=>$effect['record']['document_id'],'actor_id'=>$a['id'],'created_at'=>gmdate('c')];$refs=array_map(static fn(array $f):array=>['id'=>$f['id'],'request_key'=>$f['request_key']],$facts['financials']);$keys=[];
        if($kind==='undo'){
            foreach($facts['voids']as$void){$voidKey=substr(hash('sha256',"dvm-native-return-undo/1\0".$a['club_id']."\0".$key."\0".$void['id']),0,32);$keys[$void['id']]=$voidKey;$this->writePartialReturnVoidLocked($void,$voidKey);}
            if($facts['legacy']){$source=$facts['legacy']['source'];$q=$this->db->prepare('UPDATE finance_transactions SET reversal_of=NULL,version=version+1 WHERE club_id=? AND id=? AND reversal_of=? AND version=?');$q->execute([$a['club_id'],$counter,$source['id'],(int)$review['counter']['version']]);if($q->rowCount()!==1)throw new DomainException('Ursprüngliche Rücklastschrift inzwischen geändert.',409);$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=? AND version=?')->execute([$a['club_id'],$source['id'],(int)$source['version']]);}
            $q=$this->db->prepare("UPDATE sepa_order_items SET state='active' WHERE club_id=? AND id=? AND state='returned'");$q->execute([$a['club_id'],$item]);if($q->rowCount()!==1)throw new DomainException('Lastschriftposten inzwischen geändert.',409);
        }
        $event=$this->appendNativeDebitEvent($record,$key,$kind,$effect['record']['id'],$review,$refs,$kind==='undo'?null:$effect['legacy_source_id'],['void_keys'=>$keys]);
        if($kind==='undo'){
            if($facts['financials']||$facts['legacy'])$this->refreshPayment($entry,true);
            if($facts['financials']){$q=$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$a['club_id'],$counter,(int)$review['counter']['version']]);if($q->rowCount()!==1)throw new DomainException('Rückumsatz inzwischen geändert.',409);}
            foreach($facts['financials']as$first)PartialReturnOriginals::read($this->db,$a['club_id'],$first['id'],true);
        }
        NativeDebitReturnHistory::current($this->db,$a['club_id'],$item,true);$this->audit($a,'sepa.return.'.$kind,$event['id'],1);return ['event'=>$event['id']];
    }
}
