<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** The native item and its real financial return form one caller-owned decision. */
trait NativeDebitPaymentReturns
{
    private function nativeDebitReturnFacts(string $item,string $counter,array $allocations,string $reason,?MemberBankVault $vault=null,?string $document=null,?array $attached=null):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Lastschriftposten und tatsächliche Rückzahlung gemeinsam prüfen.',409);
        $a=$this->actor(true);$office=FinancePaymentAuthority::require($this->db,$a['id']);$ids=self::transferSettlementIds($allocations);
        if(!PaymentFunding::available($this->db)||(int)$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT')->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Rücklastschriftzuordnungen benötigen die ausdrücklich abgeschlossene aktuelle Paketwartung.',503);
        $q=$this->db->prepare('SELECT i.*,o.account_id,o.state AS order_state FROM sepa_order_items i JOIN sepa_orders o ON o.club_id=i.club_id AND o.id=i.order_id WHERE i.club_id=? AND i.id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$item]);$i=$q->fetch();
        if(!$i||$i['id']!==$item||$i['state']!==($attached===null?'active':'returned')||!in_array($i['order_state'],['exported','submitted'],true))throw new DomainException('Genau einen aktiven eingereichten Lastschriftposten auswählen.',409);
        if($attached!==null){$effect=NativeDebitReturnHistory::current($this->db,$a['club_id'],$item,true);if($effect['record']!==$attached||$attached['transaction_id']!==$counter||$effect['financials']||$effect['legacy_source_id']!==null)throw new DomainException('Genau die noch nicht finanziell zugeordnete ursprüngliche Rücklastschrift prüfen.',409);}
        $vault??=MemberBankVault::fromEnvironment($this->db,$a['id'],ProductionRuntime::enabled());$service=new SepaOrders($this->db,$a['id'],$vault);$bank=$service->decisionSnapshot($i['order_id']);if($bank['transport']['pending']??false)throw new DomainException('Den noch offenen FinTS-Ausgang anhand des tatsächlichen Bankstatus zuerst klären.',409);
        $native=['item'=>array_intersect_key($i,array_fill_keys(['club_id','id','order_id','entry_id','contact_id','cents','mandate_version','mandate_reference','state','end_to_end'],true)),'order'=>array_intersect_key($bank['order'],array_fill_keys(['id','club_id','account_id','collection_date','state','version','bank_reference'],true))+['sealed_sha256'=>$bank['sealed_sha256'],'xml_sha256'=>$bank['xml_sha256']],'authorization'=>$office];
        $amounts=[];$total=0;
        foreach($ids as$id){$q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$l=$q->fetch();if(!$l||$l['id']!==$id||$l['entry_id']!==$i['entry_id']||$l['voided_at']!==null||(int)$l['cents']<1)throw new DomainException('Aktive ursprüngliche Zahlungszuordnungen genau dieses Lastschriftbelegs auswählen.',422);$source=$this->partialReturnMoney($l['transaction_id']);if($source['account_id']!==$i['account_id']||$source['account_kind']==='cash'||(int)$source['cents']<1)throw new DomainException('Die tatsächlichen ursprünglichen Einzugszahlungen auf dem Lastschriftkonto auswählen.',422);$amounts[]=['allocation_id'=>$id,'amount'=>self::decimal((int)$l['cents'])];$total+=(int)$l['cents'];}
        $t=$this->partialReturnMoney($counter);if($t['id']!==$counter||$t['account_id']!==$i['account_id']||(int)$t['cents']!==-(int)$i['cents']||(int)$t['allocated_cents']!==0||$total!==(int)$i['cents'])throw new DomainException('Die gewählten ursprünglichen Zuordnungen und der freie tatsächliche Rückumsatz müssen genau dem Einzugsbetrag entsprechen. Gebühren separat erfassen.',422);
        $facts=$this->partialReturnFacts($counter,$amounts,$reason,$native);if($facts['entries'][$i['entry_id']]['kind']!=='contribution')throw new DomainException('Den ursprünglichen Beitragsbeleg dieses Lastschriftpostens prüfen.',409);
        $q=$this->db->prepare('SELECT document_id FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$i['entry_id']]);if($q->fetchColumn()){$invoice=(new ContributionInvoices($this->db,$a['id']))->currentOriginal($i['entry_id']);if($invoice['invoice']['state']==='draft')throw new DomainException('Den tatsächlich ausgegebenen ursprünglichen Beitragsbeleg prüfen.',409);foreach($facts['review']['selected']as&$selected)$selected['originals'][]=$invoice['original'];unset($selected);}
        if($document!==null){$proof=(new Documents($this->db,$a['id']))->currentFinancialDocument($document);if($proof['document']['id']!==$document)throw new DomainException('Eigenes zugängliches Finanzdokument auswählen.',404);$facts['review']['native_debit']['return_document']=$proof;foreach($facts['review']['selected']as&$selected)$selected['originals'][]=$proof;unset($selected);}
        return $facts;
    }

    public function nativeDebitReturnFundingReview(string $item,string $counter,array $allocations,string $reason,?MemberBankVault $vault=null,?string $document=null):array
    {
        return $this->bankCatalogueRead(function()use($item,$counter,$allocations,$reason,$vault,$document):array{$facts=$this->nativeDebitReturnFacts($item,$counter,$allocations,$reason,$vault,$document);return ['review'=>$facts['review'],'hash'=>hash('sha256',PaymentFundingOriginals::json($facts['review']))];});
    }

    /** Exact original positives of one actual native item; page/search never changes a selection. */
    public function nativeDebitReturnSourcesPage(string $item,mixed $page=1,mixed $search=''):array
    {
        $page=self::cataloguePage($page);if(!is_string($search))throw new DomainException('Ursprüngliche Einzugszahlungssuche prüfen.',422);$search=self::field(['q'=>$search],'q',150);
        return $this->bankCatalogueRead(function()use($item,$page,$search):array{
            $a=$this->actor(true);FinancePaymentAuthority::require($this->db,$a['id']);$q=$this->db->prepare("SELECT i.*,o.account_id FROM sepa_order_items i JOIN sepa_orders o ON o.club_id=i.club_id AND o.id=i.order_id WHERE i.club_id=? AND i.id=? AND i.state IN ('active','returned') AND o.state IN ('exported','submitted') FOR SHARE NOWAIT");$q->execute([$a['club_id'],$item]);$native=$q->fetch();if(!$native||$native['id']!==$item)throw new DomainException('Genau einen aktiven eingereichten Lastschriftposten auswählen.',409);
            $remaining=PaymentFunding::remainingSql($this->db,'l');$from=' FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE l.club_id=? AND l.entry_id=? AND t.account_id=? AND l.voided_at IS NULL AND l.cents>0 AND t.cents>0 AND t.reversal_of IS NULL AND returned.id IS NULL AND '.$remaining.'=l.cents';$args=[$a['club_id'],$native['entry_id'],$native['account_id']];if($search!==''){$from.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),l.id,t.id,t.booked_on,t.counterparty,t.description,a.name)))>0';$args[]=$search;}
            $q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));if($page>$pages)throw new DomainException('Diese Seite der ursprünglichen Einzugszahlungen ist nicht verfügbar. Suche und Seitenzahl prüfen.',422);$q=$this->db->prepare('SELECT l.id AS allocation_id,l.transaction_id,l.entry_id,l.cents,t.booked_on,t.counterparty,t.description,a.name AS account_name'.$from.' ORDER BY t.booked_on,t.id,l.id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=$q->fetchAll();return compact('rows','total','size','page','pages','search');
        });
    }

    public function nativeDebitReturnSourceChoice(string $item,string $allocation):array
    {
        return $this->bankCatalogueRead(function()use($item,$allocation):array{$a=$this->actor(true);FinancePaymentAuthority::require($this->db,$a['id']);$q=$this->db->prepare("SELECT l.id AS allocation_id,l.transaction_id,l.entry_id,l.cents,t.booked_on,t.counterparty,t.description,a.name AS account_name,i.id AS native_item FROM sepa_order_items i JOIN sepa_orders o ON o.club_id=i.club_id AND o.id=i.order_id JOIN finance_allocations l ON l.club_id=i.club_id AND l.entry_id=i.entry_id JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE i.club_id=? AND i.id=? AND l.id=? AND t.account_id=o.account_id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$item,$allocation]);$row=$q->fetch();if(!$row||$row['native_item']!==$item||$row['allocation_id']!==$allocation)throw new DomainException('Eigene ursprüngliche Einzugszuordnung nicht verfügbar.',404);return $row;});
    }

    /** Validate the active item before changing either side; no temporary state bypasses a guard. */
    public function recordNativeDebitFundingLocked(string $item,string $counter,array $allocations,string $reason,?string $document,string $nativeKey,string $reviewHash,?MemberBankVault $vault=null):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Rücklastschrift und Finanzzuordnung benötigen einen gemeinsamen Speichervorgang.',409);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$nativeKey))throw new DomainException('Eigene ursprüngliche Lastschriftkennung auswählen.',422);$a=$this->actor(true);$this->lockReturnOrderClub($a['club_id']);$ids=self::transferSettlementIds($allocations);$movements=[$counter];$entries=[];
        foreach($ids as$id){$q=$this->db->prepare('SELECT id,transaction_id,entry_id FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row||$row['id']!==$id)throw new DomainException('Eigene ursprüngliche Zahlungszuordnung auswählen.',404);$movements[]=$row['transaction_id'];$entries[]=$row['entry_id'];}
        $this->lockMovements($movements);$entries=array_values(array_unique($entries));sort($entries,SORT_STRING);foreach($entries as$entry){$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$entry]);}
        $facts=$this->nativeDebitReturnFacts($item,$counter,$ids,$reason,$vault,$document);$review=$facts['review'];if(!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash)||!hash_equals($reviewHash,hash('sha256',PaymentFundingOriginals::json($review))))throw new DomainException('Lastschriftposten, ursprüngliche Zahlungen oder Rückumsatz geändert. Erneut prüfen und gesondert bestätigen.',409);
        if($document!==null)(new Documents($this->db,$a['id']))->currentFinancialDocument($document);
        return $this->storeNativeDebitFundingLocked($facts,$reason,$document,$nativeKey);
    }

    private function storeNativeDebitFundingLocked(array $facts,string $reason,?string $document,string $nativeKey,?array $attached=null,?array $correctionReview=null):array
    {
        $a=$this->actor(true);$review=$facts['review'];$item=$review['native_debit']['item']['id'];$counter=$review['counter']['id'];$reviewHash=hash('sha256',PaymentFundingOriginals::json($review));
        $record=['club_id'=>$a['club_id'],'id'=>bin2hex(random_bytes(16)),'item_id'=>$item,'transaction_id'=>$counter,'reason'=>trim($reason),'document_id'=>$document,'actor_id'=>$a['id'],'created_at'=>gmdate('c')];$origin=['order_id'=>$review['native_debit']['order']['id'],'item_id'=>$item,'return_record_id'=>$record['id'],'native_request_key'=>$nativeKey];
        $key=substr(hash('sha256',"dvm-native-debit-financial/1\0".$a['club_id']."\0".$nativeKey),0,32);$reserved=null;
        if(NativeDebitReturnHistory::available($this->db)){
            $reserved=bin2hex(random_bytes(16));$this->appendNativeDebitEvent($record,$nativeKey,$attached===null?'return':'attach',$attached['id']??null,$correctionReview??$review,[['id'=>$reserved,'request_key'=>$key]]);
        }else $this->db->prepare('INSERT INTO sepa_return_records VALUES(?,?,?,?,?,?,?,?)')->execute(array_values($record));
        $q=$this->db->prepare("UPDATE sepa_order_items SET state='returned' WHERE club_id=? AND id=? AND state='active'");$q->execute([$a['club_id'],$item]);if($attached===null&&$q->rowCount()!==1)throw new DomainException('Lastschriftposten inzwischen geändert.',409);
        $request=['format'=>'dvm-partial-return-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'counter'=>$counter,'counter_version'=>(int)$review['counter']['version'],'amounts'=>array_map(static fn(array$s):array=>['allocation_id'=>$s['allocation']['id'],'amount'=>self::decimal((int)$s['amount_cents'])],$review['selected']),'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true,'native_debit'=>$origin];$key=substr(hash('sha256',"dvm-native-debit-financial/1\0".$a['club_id']."\0".$nativeKey),0,32);$id=$this->storePartialReturnLocked($facts,$request,$key,['origin'=>$origin,'return_record'=>$record],$reserved);$first=PartialReturnOriginals::read($this->db,$a['club_id'],$id,true);$this->audit($a,'sepa.return.recorded',$item,1);
        return ['id'=>$first['id'],'request_sha256'=>$first['request_sha256'],'review_sha256'=>$first['review_sha256'],'result_sha256'=>$first['result_sha256']];
    }
}
