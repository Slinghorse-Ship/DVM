<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Actual returned private payments share the existing immutable financial funding ledger. */
trait ReimbursementPaymentReturns
{
    private static function reimbursementReturnSourceInput(array $source):array
    {
        if(array_keys($source)!==['entry','claim_id','entry_version','claim_version']||!is_string($source['entry'])||!is_string($source['claim_id'])||!is_int($source['entry_version'])||!is_int($source['claim_version'])||$source['entry_version']<1||$source['claim_version']<1)throw new DomainException('Ursprüngliche Erstattung und Versionsangaben vollständig prüfen.',422);
        ReimbursementActionOriginals::id($source['entry']);ReimbursementActionOriginals::id($source['claim_id']);return$source;
    }
    private function reimbursementReturnSource(array $source,bool $funded=true):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Private Erstattung und tatsächliche Rückzahlung gemeinsam prüfen.',409);
        if(!PaymentFunding::available($this->db)||(int)$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT')->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Erstattungsrückzahlungen benötigen den ausdrücklich gewarteten aktuellen Datenbankstand.',503);
        $source=self::reimbursementReturnSourceInput($source);$a=$this->actor(true);$e=$this->currentEntry($source['entry']);
        $q=$this->db->prepare('SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND id=? AND entry_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$source['claim_id'],$source['entry']]);$id=$q->fetchColumn();
        $claim=(new ExpenseReimbursements($this->db,$this->actorId))->forEntry($source['entry']);
        if($id===false||!$claim||$claim['id']!==$source['claim_id']||$e['id']!==$source['entry']||(int)$e['version']!==$source['entry_version']||(int)$claim['version']!==$source['claim_version'])throw new DomainException('Erstattungsfall oder ursprüngliche Rechnung inzwischen geändert. Angaben erneut prüfen.',409);
        if($e['kind']!=='expense'||$e['status']==='cancelled'||$e['reversed_on']||!in_array($claim['state'],['open','approved','paid'],true)||(int)$claim['amount_cents']!==(int)$e['amount_cents']||($funded&&(int)$e['allocated_cents']<=0)||(new ExpenseReimbursements($this->db,$this->actorId))->isCredit($source['entry']))throw new DomainException('Eine gültige private Erstattung mit ursprünglichen tatsächlichen Zahlungen auswählen.',409);
        return ['source'=>$source,'claim'=>ReimbursementCommands::claim($claim)];
    }
    private function reimbursementReturnFacts(array $source,string $counter,array $amounts,string $reason):array
    {
        $context=$this->reimbursementReturnSource($source);$facts=$this->partialReturnFacts($counter,$amounts,$reason,null,$context);
        if((int)$facts['review']['counter']['cents']<=0||(count($facts['entries'])!==1||!isset($facts['entries'][$source['entry']])))throw new DomainException('Einen tatsächlichen Zahlungseingang genau dieser privaten Erstattung zuordnen.',409);
        return$facts;
    }
    public function reimbursementReturnReview(array $source,string $counter,array $amounts,string $reason):array
    {
        return $this->bankCatalogueRead(function()use($source,$counter,$amounts,$reason):array{$facts=$this->reimbursementReturnFacts($source,$counter,$amounts,$reason);return ['review'=>$facts['review'],'hash'=>hash('sha256',PaymentFundingOriginals::json($facts['review']))];});
    }
    private function reimbursementReturnedClaim(array $context,string $state,?string $paidTransaction):array
    {
        $a=$this->actor(true);$claim=$context['claim'];$q=$this->db->prepare('UPDATE finance_reimbursement_claims SET state=?,approved_by=NULL,approved_at=NULL,paid_transaction_id=?,version=version+1 WHERE club_id=? AND id=? AND entry_id=? AND version=?');$q->execute([$state,$paidTransaction,$a['club_id'],$claim['id'],$context['source']['entry'],(int)$claim['version']]);
        if($q->rowCount()!==1)throw new DomainException('Erstattungsfall oder ursprüngliche Rechnung inzwischen geändert. Angaben erneut prüfen.',409);
        $this->audit($a,$state==='paid'?'finance.reimbursement.return-restored':'finance.reimbursement.return-open',$claim['id'],(int)$claim['version']+1);
        return ['source'=>$context['source'],'claim'=>ReimbursementCommands::claim((new ExpenseReimbursements($this->db,$this->actorId))->forEntry($context['source']['entry']))];
    }
    private function reimbursementReturnLocks(array $source,string $counter,array $amounts,?string $return=null):void
    {
        $a=$this->actor(true);$this->lockReturnOrderClub($a['club_id']);$movements=[$counter];
        foreach(self::partialReturnAmounts($amounts)['raw']as$item){$q=$this->db->prepare('SELECT transaction_id,entry_id FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$item['allocation_id']]);$row=$q->fetch();if(!$row||$row['entry_id']!==$source['entry'])throw new DomainException('Nur ursprüngliche Zahlungen genau dieser privaten Erstattung auswählen.',409);$movements[]=$row['transaction_id'];}
        $this->lockMovements($movements);$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$source['entry']]);$q=$this->db->prepare('SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND id=? AND entry_id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$source['claim_id'],$source['entry']]);if($q->fetchColumn()===false)throw new DomainException('Ursprünglichen privaten Erstattungsfall auswählen.',404);
        if($return!==null){$q=$this->db->prepare('SELECT id FROM finance_partial_returns WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$return]);}
    }
    private function reimbursementReturnReadKey(string $entry,string $key,bool $void=false):?array
    {
        $a=$this->actor();try{$this->entry($entry);}catch(DomainException$failure){throw new DomainException('Ursprüngliche eigene Erstattung nicht verfügbar.',404,$failure);}self::requestKey($key);if(!PaymentFunding::available($this->db))throw new DomainException('Ursprüngliche Rückzahlungsnachweise sind noch nicht verfügbar.',503);
        $q=$this->db->prepare('SELECT id,counter_id FROM finance_partial_returns WHERE club_id=? AND '.($void?'void_request_key':'request_key').'=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$key]);$row=$q->fetch();if(!$row)return null;
        $first=$this->partialReturnOriginal($row['counter_id'],$row['id']);if(($first['request']['reimbursement']['entry']??null)!==$entry)throw new DomainException('Diese Rückzahlungskennung gehört zu einer anderen ursprünglichen Erstattung.',409);return$first;
    }
    public function reimbursementReturnSaved(string $entry,string $key,bool $void=false):?array
    {
        return $this->bankCatalogueRead(fn():array=>['first'=>$this->reimbursementReturnReadKey($entry,$key,$void)])['first'];
    }
    public function returnReimbursement(array $source,string $counter,int $version,array $amounts,string $reason,string $key,string $hash,bool $confirmed):array
    {
        $source=self::reimbursementReturnSourceInput($source);$a=$this->actor(true);self::requestKey($key);self::partialReturnAmounts($amounts);self::partialReturnReason($reason);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Ursprüngliche Erstattung und tatsächliche Rückzahlung prüfen und gesondert bestätigen.',422);
        $request=['format'=>'dvm-partial-return-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'counter'=>$counter,'counter_version'=>$version,'amounts'=>$amounts,'reason'=>$reason,'review_hash'=>$hash,'confirmed'=>true,'reimbursement'=>$source];
        return Transactions::run($this->db,function()use($source,$counter,$version,$amounts,$reason,$key,$hash,$request):array{
            $this->actor(true);$first=$this->reimbursementReturnReadKey($source['entry'],$key);if($first){if($first['request']!==$request)throw new DomainException('Diese Rückzahlungskennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Ausgang lesen.',409);return$first;}
            $this->reimbursementReturnLocks($source,$counter,$amounts);$facts=$this->reimbursementReturnFacts($source,$counter,$amounts,$reason);
            if((int)$facts['review']['counter']['version']!==$version||!hash_equals(hash('sha256',PaymentFundingOriginals::json($facts['review'])),$hash))throw new DomainException('Erstattung, Zahlung oder ursprünglicher Nachweis inzwischen geändert. Angaben erneut prüfen.',409);
            $result=$this->reimbursementReturnedClaim($facts['review']['reimbursement'],'open',null);$id=$this->storePartialReturnLocked($facts,$request,$key,null,null,$result);
            return$this->partialReturnOriginal($counter,$id);
        });
    }
    private function reimbursementReturnVoidFacts(array $source,string $transaction,string $id,string $reason):array
    {
        $context=$this->reimbursementReturnSource($source,false);$first=$this->partialReturnOriginal($transaction,$id);
        if($first['counter_id']!==$transaction)throw new DomainException('Den ursprünglichen Rückzahlungseingang dieses Erstattungsfalls öffnen.',409);
        if(($first['request']['reimbursement']['entry']??null)!==$source['entry']||($first['request']['reimbursement']['claim_id']??null)!==$source['claim_id'])throw new DomainException('Nur die ursprüngliche Rückzahlung genau dieses Erstattungsfalls zurücknehmen.',409);
        foreach(['contact_id','amount_cents','invoice_recipient']as$field)if(($context['claim'][$field]??null)!==($first['review']['reimbursement']['claim'][$field]??null))throw new DomainException('Erstattungsempfänger oder Anspruch inzwischen geändert. Den ursprünglichen Zahlungsweg gesondert klären.',409);
        return$this->partialReturnRevocationFacts($transaction,$id,$reason,null,$context);
    }
    public function reimbursementReturnVoidReview(array $source,string $transaction,string $id,string $reason):array
    {
        return$this->bankCatalogueRead(function()use($source,$transaction,$id,$reason):array{$review=$this->reimbursementReturnVoidFacts($source,$transaction,$id,$reason);return ['review'=>$review,'hash'=>hash('sha256',PaymentFundingOriginals::json($review))];});
    }
    private function reimbursementRestoredPointer(array $review,array $first):?string
    {
        $entry=$review['reimbursement']['source']['entry'];$face=(int)$review['entries'][$entry]['amount_cents'];if(PaymentFundingRanges::cents($review['restored_ranges'][$entry],$face)!==$face)return null;
        $a=$this->actor();$ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry,$face,true);$restored=[];foreach($first['items']as$item)$restored[$item['allocation_id']]=($restored[$item['allocation_id']]??0)+(int)$item['cents'];$active=[];
        foreach($this->allocations($entry,false,true)as$row)if(!$row['voided_at']&&!$row['reversed_on']&&!$row['reversal_of']&&(($ledger['allocations'][$row['id']]['remaining_cents']??0)+($restored[$row['id']]??0))>0)$active[]=$row;
        usort($active,static fn(array$a,array$b):int=>[$a['booked_on'],$a['transaction_id'],$a['id']]<=>[$b['booked_on'],$b['transaction_id'],$b['id']]);if(!$active)throw new DomainException('Ursprüngliche Zahlungsnachweise fehlen.',409);return end($active)['transaction_id'];
    }
    public function revokeReimbursementReturn(array $source,string $transaction,string $id,string $reason,string $key,string $hash,bool $confirmed):array
    {
        $source=self::reimbursementReturnSourceInput($source);$a=$this->actor(true);self::requestKey($key);self::partialReturnReason($reason);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Rücknahme der Erstattungszuordnung prüfen und gesondert bestätigen.',422);
        $request=['format'=>'dvm-partial-return-unlink-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'review_hash'=>$hash,'confirmed'=>true,'reimbursement'=>$source];
        return Transactions::run($this->db,function()use($source,$transaction,$id,$reason,$key,$hash,$request):array{
            $this->actor(true);$saved=$this->reimbursementReturnReadKey($source['entry'],$key,true);if($saved){if($saved['void']['request']!==$request)throw new DomainException('Diese Rücknahmekennung gehört zu anderen ursprünglichen Angaben.',409);return$saved;}
            $first=$this->partialReturnOriginal($transaction,$id);$amounts=$first['request']['amounts'];$this->reimbursementReturnLocks($source,$first['counter_id'],$amounts,$id);$review=$this->reimbursementReturnVoidFacts($source,$transaction,$id,$reason);
            if(!hash_equals(hash('sha256',PaymentFundingOriginals::json($review)),$hash))throw new DomainException('Erstattung, Zahlung oder ursprünglicher Nachweis inzwischen geändert. Angaben erneut prüfen.',409);
            $pointer=$this->reimbursementRestoredPointer($review,$first);$result=$this->reimbursementReturnedClaim($review['reimbursement'],$pointer===null?'open':'paid',$pointer);$this->writePartialReturnVoidLocked($review,$key,$result);$this->refreshPayment($source['entry'],true);$a=$this->actor(true);$this->audit($a,'finance.partial-return.entry-restored',$source['entry'],(int)$review['entries'][$source['entry']]['version']+1);
            $q=$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$a['club_id'],$first['counter_id'],(int)$review['counter']['version']]);if($q->rowCount()!==1)throw new DomainException('Rückzahlungsumsatz inzwischen geändert.',409);$this->audit($a,'finance.partial-return.revoked',$id,2);
            $current=$this->currentEntry($source['entry']);if(((int)$current['remaining_cents']===0)!==($pointer!==null))throw new DomainException('Erstattungsfall und wiederhergestellter Zahlungsstand stimmen nicht überein.',503);
            return$this->partialReturnOriginal($transaction,$id);
        });
    }
    public function reimbursementReturnCounters(array $source,string $search='',int $page=1):array
    {
        if(!mb_check_encoding($search,'UTF-8')||strlen($search)>200||$page<1||$page>1000000)throw new DomainException('Rückzahlungssuche und Seite vollständig prüfen.',422);
        return$this->bankCatalogueRead(function()use($source,$search,$page):array{
            $context=$this->reimbursementReturnSource($source);$a=$this->actor(true);$used=PaymentFunding::consumedSql($this->db);
            $where="t.club_id=? AND t.cents>0 AND t.reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id) AND t.booked_on<=? AND t.cents-$used>0 AND (a.kind<>'cash' OR NOT EXISTS(SELECT 1 FROM cash_closures c WHERE c.club_id=t.club_id AND c.account_id=t.account_id AND c.closed_through>=t.booked_on)) AND (INSTR(LOWER(t.counterparty),LOWER(?))>0 OR INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(t.id,?)>0)";$args=[$a['club_id'],Inventory::today(),$search,$search,$search];$from=' FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE '.$where;
            $q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);$q=$this->db->prepare('SELECT t.id,t.version,t.booked_on,t.counterparty,t.description,t.cents,a.name AS account_name,a.kind AS account_kind,t.cents-'.$used.' AS remaining_cents'.$from.' ORDER BY t.booked_on DESC,t.id LIMIT 6 OFFSET '.(($page-1)*6));$q->execute($args);$rows=$q->fetchAll();return compact('rows','count','page','pages');
        });
    }
    public function reimbursementReturnSources(array $source,string $counter,string $search='',int $page=1):array
    {
        if(!mb_check_encoding($search,'UTF-8')||strlen($search)>200||$page<1||$page>1000000)throw new DomainException('Ursprüngliche Zahlungssuche und Seite vollständig prüfen.',422);
        return$this->bankCatalogueRead(function()use($source,$counter,$search,$page):array{
            $context=$this->reimbursementReturnSource($source);$a=$this->actor(true);$money=$this->partialReturnMoney($counter);if($money['id']!==$counter||(int)$money['cents']<=0||$money['reversal_of']!==null||$money['reversed_on']!==null||(int)$money['remaining_cents']<=0||$money['booked_on']>Inventory::today())throw new DomainException('Einen vorhandenen noch verfügbaren Rückzahlungseingang auswählen.',409);
            if($money['account_kind']==='cash')$this->assertUnclosed($money['account_id'],$money['booked_on']);$net=PaymentFunding::remainingSql($this->db,'l');$from=" FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE l.club_id=? AND l.entry_id=? AND l.voided_at IS NULL AND t.cents<0 AND t.reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id) AND t.booked_on<=? AND $net>0 AND (INSTR(LOWER(t.counterparty),LOWER(?))>0 OR INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(l.id,?)>0)";$args=[$a['club_id'],$source['entry'],$money['booked_on'],$search,$search,$search];$q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);$q=$this->db->prepare('SELECT l.id AS allocation_id,l.transaction_id,l.cents AS original_cents,t.version AS transaction_version,t.booked_on,t.counterparty,t.description,a.name AS account_name,'.$net.' AS paid_cents'.$from.' ORDER BY t.booked_on,t.id,l.id LIMIT 6 OFFSET '.(($page-1)*6));$q->execute($args);$rows=$q->fetchAll();return compact('rows','count','page','pages','money');
        });
    }
    public function reimbursementReturnHistory(string $entry,mixed $page='1'):array
    {
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Seite der Erstattungsrückzahlungen vollständig prüfen.',422);
        return$this->bankCatalogueRead(function()use($entry,$page):array{
            $a=$this->actor();$e=$this->entry($entry);if($e['id']!==$entry||$e['kind']!=='expense')throw new DomainException('Rückzahlungen an der ursprünglichen privaten Ausgabe öffnen.',409);
            if(!PaymentFunding::available($this->db))return['rows'=>[],'count'=>0,'page'=>1,'pages'=>1];
            $where="club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.reimbursement.entry'))=?";$q=$this->db->prepare('SELECT COUNT(*) FROM finance_partial_returns WHERE '.$where);$q->execute([$a['club_id'],$entry]);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/6));$number=min((int)$page,$pages);$q=$this->db->prepare('SELECT id,counter_id FROM finance_partial_returns WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($number-1)*6));$q->execute([$a['club_id'],$entry]);$ids=$q->fetchAll();$rows=[];foreach($ids as$row)$rows[]=$this->partialReturnOriginal($row['counter_id'],$row['id']);return['rows'=>$rows,'count'=>$count,'page'=>$number,'pages'=>$pages];
        });
    }

}
