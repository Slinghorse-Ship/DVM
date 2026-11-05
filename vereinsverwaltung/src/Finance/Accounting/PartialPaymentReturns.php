<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Classify existing actual returned money while the original demands remain valid. */
trait PartialPaymentReturns
{
    /** Preserve raw form values through page/search/error requests; amounts are validated at review. */
    public static function partialReturnDraftAmounts(mixed $amounts):array
    {
        if(!is_array($amounts)||!array_is_list($amounts)||count($amounts)>40)throw new DomainException('Höchstens vierzig ursprüngliche Zahlungszuordnungen auswählen.',422);$seen=[];
        foreach($amounts as$item){
            if(!is_array($item)||array_keys($item)!==['allocation_id','amount']||!is_string($item['allocation_id'])||$item['allocation_id']===''||strlen($item['allocation_id'])>128||!is_string($item['amount'])||strlen($item['amount'])>60||isset($seen[$item['allocation_id']]))throw new DomainException('Jede ursprüngliche Zahlungszuordnung genau einmal mit ihrem ganzen Teilbetrag angeben.',422);$seen[$item['allocation_id']]=true;
        }
        return $amounts;
    }

    private static function partialReturnAmounts(array $amounts):array
    {
        $amounts=self::partialReturnDraftAmounts($amounts);
        if(!array_is_list($amounts)||count($amounts)<1||count($amounts)>40)throw new DomainException('Eine bis vierzig ursprüngliche Zahlungszuordnungen mit ihren tatsächlichen Teilbeträgen wählen.',422);
        $seen=[];$total=0;
        foreach($amounts as$item){
            if(!is_array($item)||array_keys($item)!==['allocation_id','amount']||!is_string($item['allocation_id'])||$item['allocation_id']===''||strlen($item['allocation_id'])>512||!is_string($item['amount'])||strlen($item['amount'])>60||isset($seen[$item['allocation_id']]))throw new DomainException('Jede ursprüngliche Zahlungszuordnung genau einmal mit ihrem ganzen Teilbetrag angeben.',422);
            $cents=self::cents($item['amount']);if($cents<1)throw new DomainException('Für jede gewählte ursprüngliche Zahlung einen positiven tatsächlichen Rückzahlungsbetrag angeben.',422);$seen[$item['allocation_id']]=true;$total+=$cents;
        }
        if($total>PaymentFundingRanges::MAX_CENTS)throw new DomainException('Der gesamte Rückzahlungsbetrag ist zu groß.',422);return ['raw'=>$amounts,'cents'=>$total];
    }

    private static function partialReturnReason(string $reason):void
    {
        if(trim($reason)===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Die tatsächliche Rückzahlung und das Fortbestehen der Forderungen mit höchstens 500 Zeichen begründen.',422);
    }

    /** A decision always uses physical current counter consumption, including other accepted parts. */
    private function partialReturnMoney(string $id):array
    {
        $a=$this->actor();$t=$this->transactionDetail($id,true);$used=0;
        $q=$this->db->prepare('SELECT cents,voided_at FROM finance_allocations WHERE club_id=? AND transaction_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);foreach($q as$row)if($row['voided_at']===null)$used+=(int)$row['cents'];
        $q=$this->db->prepare('SELECT cents FROM finance_internal_movements WHERE club_id=? AND (outgoing_id=? OR incoming_id=?) AND voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id,$id]);foreach($q as$row)$used+=(int)$row['cents'];
        if(self::excessReturnsAvailable($this->db)){$q=$this->db->prepare('SELECT cents FROM finance_excess_returns WHERE club_id=? AND (source_id=? OR returned_id=?) AND voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id,$id]);foreach($q as$row)$used+=(int)$row['cents'];}
        $returns=PaymentFundingLedger::counter($this->db,$a['club_id'],$id,true);$used+=$returns['cents'];$t['allocated_cents']=$used;$t['remaining_cents']=abs((int)$t['cents'])-$used;$t['partial_return_cents']=$returns['cents'];
        if($t['remaining_cents']<0)throw new DomainException('Die vorhandene Geldbewegung ist über ihren Betrag hinaus zugeordnet. Den ursprünglichen Stand prüfen lassen.',503);return $t;
    }

    private function partialReturnFacts(string $counter,array $amounts,string $reason,?array $nativeDebit=null,?array $reimbursement=null):array
    {
        if($nativeDebit!==null&&$reimbursement!==null)throw new DomainException('Rückzahlung genau einem ursprünglichen Fachweg zuordnen.',409);$a=$this->actor(true);$input=self::partialReturnAmounts($amounts);self::partialReturnReason($reason);$t=$this->partialReturnMoney($counter);
        if($t['id']!==$counter||$t['reversal_of']!==null||$t['reversed_on']!==null||!BankCounterpayments::actualMovement($this->db,$a['id'],$counter,true)||$t['booked_on']>$this->businessDay())throw new DomainException('Eine vorhandene tatsächliche Rückzahlung mit noch verfügbarem Betrag öffnen.',409);
        if($input['cents']>$t['remaining_cents'])throw new DomainException('Die gewählten Teilbeträge übersteigen die noch verfügbare tatsächliche Rückzahlung.',409);
        if($t['account_kind']==='cash')$this->assertUnclosed($t['account_id'],$t['booked_on']);
        $ledgers=[];$entries=[];$selected=[];$guards=[];
        foreach($input['raw']as$item){
            $q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$item['allocation_id']]);$allocation=$q->fetch();
            if(!$allocation||$allocation['id']!==$item['allocation_id']||$allocation['voided_at']!==null)throw new DomainException('Eine aktive eigene ursprüngliche Zahlungszuordnung auswählen.',404);
            $entry=$allocation['entry_id'];if($reimbursement!==null&&$entry!==($reimbursement['source']['entry']??null))throw new DomainException('Nur ursprüngliche Zahlungen genau dieser privaten Erstattung auswählen.',409);$source=$this->partialReturnMoney($allocation['transaction_id']);
            if($source['reversal_of']!==null||$source['reversed_on']!==null||$source['booked_on']>$t['booked_on']||$source['id']===$counter||($source['cents']>0)===($t['cents']>0)||!BankCounterpayments::actualMovement($this->db,$a['id'],$source['id'],true))throw new DomainException('Eine tatsächliche frühere ursprüngliche Zahlung in der Gegenrichtung auswählen.',409);
            if(!isset($ledgers[$entry])){
                $e=$this->currentEntry($entry);if($e['status']==='cancelled'||$e['reversed_on']!==null)throw new DomainException('Die ursprüngliche Forderung muss weiterhin gültig sein. Storno oder Gutschrift zuerst klären.',409);
                $ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry,(int)$e['amount_cents'],true);if((int)$e['allocated_cents']!==$ledger['paid_cents'])throw new DomainException('Ursprüngliche Zahlungszuordnungen und Belegstand stimmen nicht überein. Den gespeicherten Stand prüfen lassen.',503);$e['allocated_cents']=$ledger['paid_cents'];$e=$this->paymentState($e);$entries[$entry]=$e;$ledgers[$entry]=$ledger;
                $q=$this->db->prepare("SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? AND state<>'cancelled' FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry]);$privateClaim=$q->fetchColumn();if($privateClaim!==false&&($reimbursement===null||$privateClaim!==($reimbursement['claim']['id']??null)||$entry!==($reimbursement['source']['entry']??null)))throw new DomainException('Private Auslagen und Erstattungen über ihren ursprünglichen freigegebenen Ablauf klären.',409);
                $q=$this->db->prepare('SELECT i.id,i.order_id,i.cents,i.state,o.state AS order_state,o.version AS order_version FROM sepa_order_items i JOIN sepa_orders o ON o.club_id=i.club_id AND o.id=i.order_id WHERE i.club_id=? AND i.entry_id=? ORDER BY i.id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$entry]);$debits=$q->fetchAll();foreach($debits as$debit)if($debit['state']==='active'&&($nativeDebit['item']['id']??null)!==$debit['id'])throw new DomainException('Für eine laufende Lastschrift den ursprünglichen Rücklastschriftweg verwenden.',409);
                $q=$this->db->prepare('SELECT id,entry_id,account_id,execution_date,state,version,total_cents,created_at,bank_reference FROM finance_transfer_orders WHERE club_id=? AND entry_id=? ORDER BY id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$entry]);$orders=$q->fetchAll();foreach($orders as$order)if(in_array($order['state'],['prepared','approved','exported'],true))throw new DomainException('Noch laufende Zahlungsvorbereitungen vor der Rückzahlungszuordnung anhand des tatsächlichen Bankausgangs klären.',409);
                $guards[$entry]=['originals'=>$this->groupReversalDocuments($entry),'issued_original'=>$this->paymentReturnIssuedFacts($entry),'positions'=>$this->positions('entry',$entry,true),'orders'=>$orders,'debit_orders'=>$debits];
            }
            $e=$entries[$entry];if(($source['cents']<0)!==($e['kind']==='expense'))throw new DomainException('Ursprüngliche Zahlung und Art des gültigen Belegs stimmen nicht überein.',409);
            DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$allocation['id']);$funding=$ledgers[$entry]['allocations'][$allocation['id']]??null;$cents=self::cents($item['amount']);
            if(!$funding||$cents>$funding['remaining_cents'])throw new DomainException('Der Teilbetrag übersteigt die noch nicht zurückgezahlte ursprüngliche Zahlungszuordnung.',409);
            $ranges=PaymentFundingRanges::take($funding['remaining_ranges'],$cents,(int)$e['amount_cents'],true)['taken'];$prior=[];foreach($funding['returned_items']as$old)$prior[]=['id'=>$old['return_id'],'item_id'=>$old['id'],'cents'=>(int)$old['cents'],'ranges_sha256'=>$old['ranges_sha256']];
            $selected[]=['allocation'=>$allocation,'source'=>$source,'entry'=>$e,'amount_cents'=>$cents,'ranges'=>$ranges,'funding'=>['existing_sha256'=>$funding['existing']['original_sha256']??null,'original_ranges'=>$funding['ranges'],'remaining_ranges'=>$funding['remaining_ranges'],'prior_returns'=>$prior]]+$guards[$entry];
        }
        $review=['format'=>'dvm-partial-return-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'counter'=>$t,'reason'=>$reason,'selected'=>$selected,'available'=>PaymentFunding::available($this->db)];if($nativeDebit!==null)$review['native_debit']=$nativeDebit;if($reimbursement!==null)$review['reimbursement']=$reimbursement;return ['review'=>$review,'ledgers'=>$ledgers,'entries'=>$entries];
    }

    public function partialReturnReview(string $counter,array $amounts,string $reason):array
    {
        return $this->bankCatalogueRead(function()use($counter,$amounts,$reason):array{$facts=$this->partialReturnFacts($counter,$amounts,$reason);$review=$facts['review'];return ['review'=>$review,'hash'=>hash('sha256',PaymentFundingOriginals::json($review))];});
    }

    /** Original positive funding, across all own accounts, never negative synthetic choices. */
    public function partialReturnCandidatesPage(string $counter,mixed $page=1,mixed $search=''):array
    {
        $page=self::cataloguePage($page);if(!is_string($search))throw new DomainException('Ursprüngliche Zahlungssuche prüfen.',422);$search=self::field(['q'=>$search],'q',150);
        return $this->bankCatalogueRead(function()use($counter,$page,$search):array{
            $a=$this->actor(true);$money=$this->partialReturnMoney($counter);if($money['id']!==$counter||$money['reversal_of']!==null||$money['reversed_on']!==null||!BankCounterpayments::actualMovement($this->db,$this->actorId,$counter,true))throw new DomainException('Eine tatsächliche vorhandene Rückzahlung öffnen.',409);
            $remaining=PaymentFunding::remainingSql($this->db,'l');$where='l.club_id=? AND l.voided_at IS NULL AND t.id<>? AND t.reversal_of IS NULL AND returned.id IS NULL AND t.booked_on<=? AND '.($money['cents']>0?"t.cents<0 AND e.kind='expense'":"t.cents>0 AND e.kind<>'expense'")." AND e.status<>'cancelled' AND reversed.entry_id IS NULL AND $remaining>0";$args=[$a['club_id'],$counter,$money['booked_on']];
            if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),l.id,t.id,t.booked_on,t.counterparty,t.description,e.display_number,e.description,e.counterparty,account.name)))>0';$args[]=$search;}
            $from=' FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts account ON account.club_id=t.club_id AND account.id=t.account_id JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id LEFT JOIN finance_reversals reversed ON reversed.club_id=e.club_id AND reversed.entry_id=e.id WHERE '.$where;
            $q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
            $q=$this->db->prepare('SELECT l.id AS allocation_id,l.transaction_id,l.entry_id,l.cents AS original_cents,'.$remaining.' AS refundable_cents,t.booked_on,t.description AS transaction_description,t.counterparty,account.name AS account_name,e.display_number,e.description AS entry_description,e.amount_cents,EXISTS(SELECT 1 FROM finance_reimbursement_claims claim WHERE claim.club_id=e.club_id AND claim.entry_id=e.id AND claim.state<>\'cancelled\') AS private_reimbursement,'.PaymentFunding::paidSql($this->db,'e').' AS paid_cents'.$from.' ORDER BY t.booked_on DESC,t.id,l.id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=$q->fetchAll();
            return compact('rows','total','size','page','pages','search');
        });
    }

    public function partialReturnChoice(string $counter,string $allocation):array
    {
        return $this->bankCatalogueRead(function()use($counter,$allocation):array{
            $a=$this->actor(true);$money=$this->partialReturnMoney($counter);$q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$allocation]);$original=$q->fetch();if(!$original||$original['id']!==$allocation||$original['voided_at']!==null)throw new DomainException('Ursprüngliche eigene Zahlungszuordnung nicht verfügbar.',404);
            $source=$this->partialReturnMoney($original['transaction_id']);$entry=$this->currentEntry($original['entry_id']);$ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry['id'],(int)$entry['amount_cents'],true);$funding=$ledger['allocations'][$allocation]??null;
            if($money['id']!==$counter||$money['reversal_of']!==null||$money['reversed_on']!==null||$source['id']===$counter||$source['reversal_of']!==null||$source['reversed_on']!==null||$source['booked_on']>$money['booked_on']||($source['cents']>0)===($money['cents']>0)||!$funding||$funding['remaining_cents']<1)throw new DomainException('Eine noch nicht vollständig zurückgezahlte ursprüngliche Zahlung in der Gegenrichtung auswählen.',409);
            return ['allocation'=>$original,'source'=>$source,'entry'=>$entry,'refundable_cents'=>$funding['remaining_cents'],'originals'=>$this->groupReversalDocuments($entry['id'])];
        });
    }

    public function partialReturnHistory(string $transaction):array
    {
        return $this->bankCatalogueRead(function()use($transaction):array{
            $a=$this->actor();$this->transactionDetail($transaction,true);if(!PaymentFunding::available($this->db))return [];
            $q=$this->db->prepare('SELECT DISTINCT refund.id,refund.created_at FROM finance_partial_returns refund LEFT JOIN finance_partial_return_items part ON part.club_id=refund.club_id AND part.return_id=refund.id WHERE refund.club_id=? AND (refund.counter_id=? OR part.source_id=?) ORDER BY refund.created_at,refund.id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$transaction,$transaction]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            return array_map(fn(string$id):array=>PartialReturnOriginals::read($this->db,$a['club_id'],$id,true),$ids);
        });
    }

    /** Current paid portions alongside immutable positive history; no change to allocations() DTOs. */
    public function allocationFundingHistory(string $id,bool $byTransaction=false):array
    {
        return $this->bankCatalogueRead(function()use($id,$byTransaction):array{
            $a=$this->actor();$rows=$this->allocations($id,$byTransaction,true);$states=[];$ledgers=[];if(!PaymentFunding::available($this->db))return [];
            foreach($rows as$row){if($row['voided_at']!==null||$row['reversed_on']!==null||$row['reversal_of']!==null)continue;$entry=$row['entry_id'];if(!array_key_exists($entry,$ledgers)){$ledgers[$entry]=null;if(PaymentFundingLedger::hasBasis($this->db,$a['club_id'],$entry,true)){$e=$this->currentEntry($entry);$ledgers[$entry]=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry,(int)$e['amount_cents'],true);}}
                $funding=$ledgers[$entry]['allocations'][$row['id']]??null;if(!$funding||!$funding['returned_items'])continue;$returns=[];foreach($funding['returned_items']as$part){$first=$ledgers[$entry]['returns'][$part['return_id']];$returns[]=['id'=>$first['id'],'counter_id'=>$first['counter_id'],'counter_account_id'=>$first['review']['counter']['account_id'],'cents'=>(int)$part['cents'],'native_debit'=>isset($first['request']['native_debit'])||NativeDebitReturnHistory::financialBound($this->db,$a['club_id'],$first['id'],true)];}$states[$row['id']]=['original_cents'=>(int)$row['cents'],'returned_cents'=>(int)$row['cents']-$funding['remaining_cents'],'paid_cents'=>$funding['remaining_cents'],'returns'=>$returns,'execution'=>false,'protected'=>true];
            }
            if($rows&&TransferSettlementOriginals::available($this->db)){$ids=array_column($rows,'id');$q=$this->db->prepare('SELECT part.allocation_id FROM finance_transfer_settlement_items part JOIN finance_transfer_settlements settlement ON settlement.club_id=part.club_id AND settlement.id=part.settlement_id WHERE part.club_id=? AND part.allocation_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND part.released_at IS NULL AND settlement.voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],...$ids]);$claimed=$q->fetchAll(PDO::FETCH_COLUMN);foreach($rows as$row)if(in_array($row['id'],$claimed,true)){$states[$row['id']]??=['original_cents'=>(int)$row['cents'],'returned_cents'=>0,'paid_cents'=>(int)$row['cents'],'returns'=>[],'protected'=>true];$states[$row['id']]['execution']=true;}}
            return $states;
        });
    }

    /** Original financial metadata stays private under the current original-document rights. */
    public function partialReturnOriginal(string $transaction,string $id):array
    {
        return $this->bankCatalogueRead(function()use($transaction,$id):array{
            $a=$this->actor();$this->transactionDetail($transaction,true);$first=PartialReturnOriginals::read($this->db,$a['club_id'],$id,true);$related=$first['counter_id']===$transaction;
            foreach($first['items']as$item)if($item['source_id']===$transaction)$related=true;
            if(!$related)throw new DomainException('Ursprünglicher Teilrückzahlungsnachweis nicht verfügbar.',404);
            $docs=new Documents($this->db,$this->actorId);
            foreach($first['review']['selected']as$selection){
                $originals=$selection['originals'];if($selection['issued_original']!==null)$originals[]=$selection['issued_original'];
                foreach($originals as$original){
                    $document=$original['document']['id'];$revision=(int)$original['file']['revision'];$read=$docs->currentFinancialOriginal($document,$revision);
                    $q=$this->db->prepare('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$document,$revision]);$file=$q->fetch();
                    if(!$file||$read['trashed']||!hash_equals($original['file']['sha256'],$read['file']['sha256'])||!hash_equals($original['file']['sha256'],$file['sha256'])||!hash_equals($original['file']['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Eine gespeicherte Originaldatei ist nicht unverändert zugänglich. Den ursprünglichen Teilrückzahlungsnachweis zuerst klären.',409);
                }
            }
            return $first;
        });
    }

    private function partialReturnRevocationFacts(string $transaction,string $id,string $reason,?string $nativeItem=null,?array $reimbursement=null):array
    {
        $a=$this->actor(true);self::partialReturnReason($reason);$first=$this->partialReturnOriginal($transaction,$id);
        if(!$first['active'])throw new DomainException('Diese Teilrückzahlungszuordnung wurde bereits ausdrücklich zurückgenommen.',409);
        if(isset($first['request']['reimbursement'])&&$reimbursement===null)throw new DomainException('Die private Erstattungsrückzahlung im ursprünglichen Erstattungsfall zurücknehmen.',409);
        if($nativeItem===null&&(isset($first['request']['native_debit'])||NativeDebitReturnHistory::financialBound($this->db,$a['club_id'],$id,true)))throw new DomainException('Diese Rückzahlung gehört zu einer bereits dokumentierten Rücklastschrift. Den ursprünglichen Lastschriftvorgang prüfen; seine Finanzzuordnung kann hier nicht getrennt zurückgenommen werden.',409);
        $counter=$this->partialReturnMoney($first['counter_id']);if($counter['account_kind']==='cash')$this->assertUnclosed($counter['account_id'],$counter['booked_on']);$entries=[];$restore=[];$orders=[];$debits=[];$documents=[];
        foreach($first['items']as$item){
            $entry=$item['entry_id'];if(!isset($entries[$entry])){
                $e=$this->currentEntry($entry);if($e['status']==='cancelled'||$e['reversed_on']!==null)throw new DomainException('Die ursprüngliche Forderung ist inzwischen korrigiert oder verworfen. Den tatsächlichen Ausgang zuerst klären.',409);
                $q=$this->db->prepare("SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? AND state<>'cancelled' FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry]);$privateClaim=$q->fetchColumn();if($privateClaim!==false&&($reimbursement===null||$privateClaim!==($reimbursement['claim']['id']??null)||$entry!==($reimbursement['source']['entry']??null)))throw new DomainException('Die private Erstattungsrückzahlung im ursprünglichen Erstattungsfall zurücknehmen.',409);
                $ledger=PaymentFundingLedger::entry($this->db,$a['club_id'],$entry,(int)$e['amount_cents'],true);$entries[$entry]=$e;$restore[$entry]=$ledger['occupied'];$documents[$entry]=['originals'=>$this->groupReversalDocuments($entry),'issued_original'=>$this->paymentReturnIssuedFacts($entry)];
                $q=$this->db->prepare("SELECT id,state,version FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND state IN ('prepared','approved','exported','submitted') ORDER BY id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry]);$orders[$entry]=$q->fetchAll();
                $originalOrders=[];foreach($first['review']['selected']as$selection)if($selection['entry']['id']===$entry)foreach($selection['orders']as$originalOrder)$originalOrders[$originalOrder['id']]=$originalOrder;
                foreach($orders[$entry]as$order){$original=$originalOrders[$order['id']]??null;$preexisting=$original&&$order['state']==='submitted'&&$original['state']==='submitted'&&(int)$original['version']===(int)$order['version'];if(!$preexisting&&($order['state']!=='submitted'||!$this->paymentReturnResolvesTransfer($order['id'])))throw new DomainException('Ein Zahlungsauftrag läuft bereits. Vor der Rücknahme dessen tatsächlichen Ausgang klären; noch nicht exportierte Vorbereitungen bewusst verwerfen.',409);}
                $q=$this->db->prepare("SELECT id,order_id,state FROM sepa_order_items WHERE club_id=? AND entry_id=? AND state='active' ORDER BY id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry]);$debits[$entry]=$q->fetchAll();if($debits[$entry])throw new DomainException('Eine laufende Lastschrift vor der Rücknahme anhand ihres tatsächlichen Ausgangs klären.',409);
            }
            try{$restore[$entry]=PaymentFundingRanges::restore($restore[$entry],$item['ranges'],(int)$entries[$entry]['amount_cents']);}catch(DomainException$failure){throw new DomainException('Die zurückgezahlten Belegcents wurden inzwischen erneut bezahlt. Diese spätere Zuordnung zuerst anhand des tatsächlichen Geldflusses klären.',409,$failure);}
            if(Database::tableExists($this->db,'finance_transfer_settlement_items')){
                $q=$this->db->prepare('SELECT item.id FROM finance_transfer_settlement_items item JOIN finance_transfer_settlements proof ON proof.club_id=item.club_id AND proof.id=item.settlement_id WHERE item.club_id=? AND item.allocation_id=? AND item.released_at IS NULL AND proof.voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$item['allocation_id']]);if($q->fetchColumn())throw new DomainException('Zuerst den ausdrücklich bestätigten Ausführungsnachweis des ursprünglichen Bankauftrags prüfen und zurücknehmen.',409);
            }
        }
        ksort($entries,SORT_STRING);ksort($restore,SORT_STRING);ksort($orders,SORT_STRING);ksort($debits,SORT_STRING);ksort($documents,SORT_STRING);
        return ['format'=>'dvm-partial-return-unlink-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'version'=>(int)$first['version'],'request_sha256'=>$first['request_sha256'],'review_sha256'=>$first['review_sha256'],'result_sha256'=>$first['result_sha256'],'counter'=>$counter,'entries'=>$entries,'restored_ranges'=>$restore,'orders'=>$orders,'debit_orders'=>$debits,'documents'=>$documents]+($reimbursement===null?[]:['reimbursement'=>$reimbursement]);
    }

    public function partialReturnRevocationReview(string $transaction,string $id,string $reason):array
    {
        return $this->bankCatalogueRead(function()use($transaction,$id,$reason):array{$review=$this->partialReturnRevocationFacts($transaction,$id,$reason);return ['review'=>$review,'hash'=>hash('sha256',PaymentFundingOriginals::json($review))];});
    }

    /** Revoke the classification only; the actual counter money and every initial first remain. */
    public function revokePaymentParts(string $transaction,string $id,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die Rücknahme benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $a=$this->actor();self::partialReturnReason($reason);self::requestKey($key);if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Rücknahme aktuell prüfen und anschließend gesondert bestätigen.',422);
        if(!PaymentFunding::available($this->db))throw new DomainException('Teilrückzahlungen benötigen die vollständig abgeschlossene Paketwartung.',503);
        $request=['format'=>'dvm-partial-return-unlink-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true];
        $first=function(bool$current)use($a,$key,$request):?array{
            $now=$this->actor();if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT id FROM finance_partial_returns WHERE club_id=? AND void_request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$now['club_id'],$key]);if(!$found=$q->fetchColumn())return null;
            $original=PartialReturnOriginals::read($this->db,$now['club_id'],$found,$current);if($original['void']['request']!==$request)throw new DomainException('Diese Rücknahmekennung gehört zu anderen ursprünglichen Angaben.',409);return ['id'=>$found,'active'=>false,'replayed'=>true];
        };
        if($old=$first(false))return $old;
        try{
            $this->db->beginTransaction();
            try{
                if($old=$first(true)){$this->db->commit();return $old;}$now=$this->actor(true);$this->lockReturnOrderClub($now['club_id']);if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);
                $original=$this->partialReturnOriginal($transaction,$id);$movements=[$original['counter_id']];$entries=[];foreach($original['items']as$item){$movements[]=$item['source_id'];$entries[]=$item['entry_id'];}$this->lockMovements($movements);$entries=array_values(array_unique($entries));sort($entries,SORT_STRING);
                foreach($entries as$entry){$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$entry]);}$q=$this->db->prepare('SELECT id FROM finance_partial_returns WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$id]);
                $review=$this->partialReturnRevocationFacts($transaction,$id,$reason);if(!hash_equals($reviewHash,hash('sha256',PaymentFundingOriginals::json($review))))throw new DomainException('Rückzahlung, Zahlungsstand oder ursprünglicher Nachweis geändert. Rücknahme erneut prüfen und anschließend gesondert bestätigen.',409);
                $this->writePartialReturnVoidLocked($review,$key);
                foreach($entries as$entry){$this->refreshPayment($entry,true);$this->audit($now,'finance.partial-return.entry-restored',$entry,(int)$review['entries'][$entry]['version']+1);}
                $q=$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$now['club_id'],$original['counter_id'],(int)$review['counter']['version']]);if($q->rowCount()!==1)throw new DomainException('Rückzahlungsumsatz inzwischen geändert.',409);
                $this->audit($now,'finance.partial-return.revoked',$id,2);PartialReturnOriginals::read($this->db,$now['club_id'],$id,true);$this->db->commit();return ['id'=>$id,'active'=>false,'replayed'=>false];
            }catch(\Throwable$failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
        }catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Ausgang der Rücknahme noch unbestätigt. Dieselben Angaben mit derselben Kennung erneut prüfen.',503,$unknown);}throw new DomainException('Rücknahme nicht bestätigt. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);}
    }

    /** Write all joint void originals before any ledger reads the combined result. */
    private function writePartialReturnVoidLocked(array $review,string $key,?array $reimbursementResult=null):void
    {
        if(!$this->db->inTransaction())throw new DomainException('Rücknahmen gemeinsam speichern.',409);
        $id=$review['id'];$reason=$review['reason'];$reviewHash=hash('sha256',PaymentFundingOriginals::json($review));
        $request=['format'=>'dvm-partial-return-unlink-request/1','club_id'=>$review['club_id'],'actor'=>$review['actor'],'transaction'=>$review['transaction'],'id'=>$id,'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true];if(isset($review['reimbursement']))$request['reimbursement']=$review['reimbursement']['source'];
                $created=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=['id'=>$id,'club_id'=>$review['club_id'],'voided_at'=>$created,'voided_by'=>$review['actor'],'void_reason'=>$reason,'void_request_key'=>$key];if($reimbursementResult!==null)$result['reimbursement']=$reimbursementResult;$json=PaymentFundingOriginals::json(compact('request','review','result'));
                $q=$this->db->prepare('UPDATE finance_partial_returns SET version=2,voided_at=?,voided_by=?,void_reason=?,void_request_key=?,void_json=?,void_sha256=? WHERE club_id=? AND id=? AND version=1 AND voided_at IS NULL');$q->execute([$created,$review['actor'],$reason,$key,$json,hash('sha256',$json),$review['club_id'],$id]);if($q->rowCount()!==1)throw new DomainException('Teilrückzahlung inzwischen geändert.',409);
    }

    /** Store already locked and reviewed actual funding; the caller owns the complete decision transaction. */
    private function storePartialReturnLocked(array $facts,array $request,string $key,?array $nativeReturn=null,?string $reservedId=null,?array $reimbursementResult=null):string
    {
        if(!$this->db->inTransaction())throw new DomainException('Rückzahlung und ursprüngliche Forderungen gemeinsam speichern.',409);
        $input=self::partialReturnAmounts($request['amounts']);$now=$this->actor(true);$counter=$request['counter'];$version=$request['counter_version'];$reason=$request['reason'];$review=$facts['review'];$entries=array_keys($facts['entries']);sort($entries,SORT_STRING);$json=PaymentFundingOriginals::json($request);$fingerprint=hash('sha256',$json);$reviewJson=PaymentFundingOriginals::json($review);$reviewHash=hash('sha256',$reviewJson);
        if($request['club_id']!==$now['club_id']||$request['actor']!==$now['id']||!hash_equals($request['review_hash'],$reviewHash)||(int)$review['counter']['version']!==$version)throw new DomainException('Ursprüngliche Rückzahlungsprüfung stimmt nicht mehr überein.',409);
                $created=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$stored=[];foreach($facts['ledgers']as$ledger)$stored+=PaymentFundingLedger::storeBaselines($this->db,$ledger,$now['id'],$created);
                $id=$reservedId??bin2hex(random_bytes(16));$items=[];$funding=[];foreach($review['selected']as$i=>$selection){$allocation=$selection['allocation'];$rangesJson=PaymentFundingOriginals::json($selection['ranges']);$items[]=['club_id'=>$now['club_id'],'id'=>bin2hex(random_bytes(16)),'return_id'=>$id,'allocation_id'=>$allocation['id'],'source_id'=>$allocation['transaction_id'],'entry_id'=>$allocation['entry_id'],'ordinal'=>$i+1,'cents'=>$selection['amount_cents'],'ranges_json'=>$rangesJson,'ranges_sha256'=>hash('sha256',$rangesJson)];$funding[]=['allocation_id'=>$allocation['id'],'original_sha256'=>$stored[$allocation['id']]['original_sha256']];}
                $resultData=['format'=>'dvm-partial-return-result/1','id'=>$id,'club_id'=>$now['club_id'],'counter_id'=>$counter,'cents'=>$input['cents'],'created_by'=>$now['id'],'created_at'=>$created,'request_key'=>$key,'items'=>$items,'funding'=>$funding];if($nativeReturn!==null)$resultData['native_debit']=$nativeReturn;if($reimbursementResult!==null)$resultData['reimbursement']=$reimbursementResult;$result=PaymentFundingOriginals::json($resultData);
                $this->db->prepare('INSERT INTO finance_partial_returns(club_id,id,counter_id,cents,reason,request_key,created_by,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$now['club_id'],$id,$counter,$input['cents'],$reason,$key,$now['id'],$created,$json,$fingerprint,$reviewJson,$reviewHash,$result,hash('sha256',$result)]);
                $q=$this->db->prepare('INSERT INTO finance_partial_return_items(club_id,id,return_id,allocation_id,source_id,entry_id,ordinal,cents,ranges_json,ranges_sha256)VALUES(?,?,?,?,?,?,?,?,?,?)');foreach($items as$item)$q->execute(array_values($item));
                foreach($entries as$entry){$ledger=PaymentFundingLedger::entry($this->db,$now['club_id'],$entry,(int)$facts['entries'][$entry]['amount_cents'],true);$q=$this->db->prepare("UPDATE finance_entries SET status='open',account_id=NULL,paid_on=NULL,version=version+1 WHERE club_id=? AND id=? AND version=?");$q->execute([$now['club_id'],$entry,(int)$facts['entries'][$entry]['version']]);if($q->rowCount()!==1)throw new DomainException('Ursprünglicher Beleg inzwischen geändert.',409);$this->audit($now,'finance.partial-return.entry',$entry,(int)$facts['entries'][$entry]['version']+1);}
                $q=$this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$now['club_id'],$counter,$version]);if($q->rowCount()!==1)throw new DomainException('Rückzahlungsumsatz inzwischen geändert.',409);$this->audit($now,'finance.partial-return.accepted',$id,1);
        return $id;
    }

    public function returnPaymentParts(string $counter,int $version,array $amounts,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die Teilrückzahlung benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $a=$this->actor();$input=self::partialReturnAmounts($amounts);self::partialReturnReason($reason);self::requestKey($key);
        if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Zahlungen und vorhandene Rückzahlung aktuell prüfen und das Fortbestehen der Forderungen anschließend gesondert bestätigen.',422);
        if(!PaymentFunding::available($this->db))throw new DomainException('Teilrückzahlungen benötigen die vollständig abgeschlossene Paketwartung.',503);
        $request=['format'=>'dvm-partial-return-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'counter'=>$counter,'counter_version'=>$version,'amounts'=>$input['raw'],'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true];$json=PaymentFundingOriginals::json($request);$fingerprint=hash('sha256',$json);
        $first=function(bool$current)use($key,$fingerprint,$a):?array{$now=$this->actor();if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT id FROM finance_partial_returns WHERE club_id=? AND request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$now['club_id'],$key]);if(!$id=$q->fetchColumn())return null;$original=PartialReturnOriginals::read($this->db,$now['club_id'],$id,$current);if(!hash_equals($original['request_sha256'],$fingerprint))throw new DomainException('Diese Rückzahlungskennung gehört zu anderen ursprünglichen Teilbeträgen. Gespeicherten Verlauf prüfen.',409);return ['id'=>$id,'active'=>$original['active'],'replayed'=>true];};
        if($old=$first(false))return $old;
        try{
            $this->db->beginTransaction();
            try{
                if($old=$first(true)){$this->db->commit();return $old;}$now=$this->actor(true);$q=$this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT');if(Database::SCHEMA_VERSION<125||(int)$q->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Teilrückzahlungen benötigen den ausdrücklich gewarteten aktuellen Paketstand.',503);
                $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id']]);if(!$q->fetchColumn()||$now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$movements=[$counter];$entries=[];
                foreach($input['raw']as$item){$q=$this->db->prepare('SELECT id,transaction_id,entry_id FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$now['club_id'],$item['allocation_id']]);$row=$q->fetch();if(!$row||$row['id']!==$item['allocation_id'])throw new DomainException('Eine aktive eigene ursprüngliche Zahlungszuordnung auswählen.',404);$movements[]=$row['transaction_id'];$entries[]=$row['entry_id'];}
                $this->lockMovements($movements);$entries=array_values(array_unique($entries));sort($entries,SORT_STRING);foreach($entries as$id){$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$id]);}
                $facts=$this->partialReturnFacts($counter,$amounts,$reason);$review=$facts['review'];$reviewJson=PaymentFundingOriginals::json($review);if((int)$review['counter']['version']!==$version||!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Zahlung, Teilbeträge, Beleg oder ursprünglicher Nachweis geändert. Erneut prüfen und anschließend gesondert bestätigen.',409);
                $id=$this->storePartialReturnLocked($facts,$request,$key);$original=PartialReturnOriginals::read($this->db,$now['club_id'],$id,true);$this->db->commit();return ['id'=>$original['id'],'active'=>true,'replayed'=>false];
            }catch(\Throwable$failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
        }catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Ausgang der Teilrückzahlung noch unbestätigt. Dieselben Angaben mit derselben Kennung erneut prüfen; kein weiteres Geld erfassen.',503,$unknown);}throw new DomainException('Teilrückzahlung nicht bestätigt. Ursprüngliche Teilbeträge und dieselbe Kennung erneut prüfen.',503,$error);}
    }
}
