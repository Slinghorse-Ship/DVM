<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Explicitly prove execution of one submitted invoice-bound order using existing actual money. */
trait TransferSettlements
{
    public static function transferSettlementDraftIds(mixed $ids):array
    {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>40)throw new DomainException('Höchstens vierzig ursprüngliche Zahlungszuordnungen auswählen.',422);$seen=[];
        foreach($ids as$id){if(!is_string($id)||$id===''||strlen($id)>128||isset($seen[$id]))throw new DomainException('Jede ursprüngliche Zahlungszuordnung genau einmal auswählen.',422);$seen[$id]=true;}return $ids;
    }

    private static function transferSettlementIds(array $ids):array
    {
        $ids=self::transferSettlementDraftIds($ids);
        if(!array_is_list($ids)||count($ids)<1||count($ids)>40)throw new DomainException('Eine bis vierzig ursprüngliche Zahlungszuordnungen für diesen Bankauftrag auswählen.',422);$seen=[];
        foreach($ids as$id){if(!is_string($id)||$id===''||strlen($id)>128||isset($seen[$id]))throw new DomainException('Jede ursprüngliche Zahlungszuordnung genau einmal auswählen.',422);$seen[$id]=true;}return $ids;
    }

    public function canConfirmTransferSettlement():bool{return $this->canLinkReturnedBankOrder();}

    private function transferSettlementEntries(string $transaction):array
    {
        $a=$this->actor();$money=$this->transactionDetail($transaction,true);if($money['id']!==$transaction)throw new DomainException('Eigene tatsächliche Geldbewegung auswählen.',404);if($money['reversal_of']!==null||$money['reversed_on']!==null)return [];
        $q=$this->db->prepare('SELECT entry_id FROM finance_allocations WHERE club_id=? AND transaction_id=? AND voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$transaction]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(PaymentFunding::available($this->db)){$q=$this->db->prepare('SELECT part.entry_id FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=? AND refund.counter_id=? AND refund.voided_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$transaction]);$ids=[...$ids,...$q->fetchAll(PDO::FETCH_COLUMN)];}$ids=array_values(array_unique($ids));if(!$ids)return [];
        $q=$this->db->prepare("SELECT e.id FROM finance_entries e LEFT JOIN finance_reversals reversed ON reversed.club_id=e.club_id AND reversed.entry_id=e.id WHERE e.club_id=? AND e.id IN(".implode(',',array_fill(0,count($ids),'?')).") AND e.kind='expense' AND e.status<>'cancelled' AND reversed.entry_id IS NULL ORDER BY e.id FOR SHARE NOWAIT");$q->execute([$a['club_id'],...$ids]);return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function transferSettlementOrdersPage(string $transaction,mixed $page=1,mixed $search=''):array
    {
        $page=self::cataloguePage($page);if(!is_string($search))throw new DomainException('Ursprüngliche Bankauftragssuche prüfen.',422);$search=self::field(['q'=>$search],'q',150);
        return $this->returnOrderRead(function()use($transaction,$page,$search):array{
            $a=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$ids=$this->transferSettlementEntries($transaction);$size=20;if(!$ids)return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'size'=>$size,'search'=>$search,'selectable_total'=>0];$where="o.club_id=? AND o.state='submitted' AND o.entry_id IN(".implode(',',array_fill(0,count($ids),'?')).')';$args=[$a['club_id'],...$ids];if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),o.id,o.execution_date,o.bank_reference,e.display_number,e.description)))>0';$args[]=$search;}$from=' FROM finance_transfer_orders o JOIN finance_entries e ON e.club_id=o.club_id AND e.id=o.entry_id WHERE '.$where;$q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$confirmed=TransferSettlementOriginals::available($this->db)?'(SELECT proof.id FROM finance_transfer_settlements proof WHERE proof.club_id=o.club_id AND proof.active_order_id=o.id)':'NULL';$returned=$this->returnOrderLinksAvailable()?'(SELECT link.id FROM finance_payment_return_order_links link WHERE link.club_id=o.club_id AND link.active_order_id=o.id)':'NULL';$q=$this->db->prepare('SELECT o.id,o.entry_id,o.account_id,o.execution_date,o.bank_reference,o.total_cents,o.version,e.display_number,e.description,'.$confirmed.' AS execution_id,'.$returned.' AS returned_order_id'.$from.' ORDER BY o.execution_date DESC,o.created_at DESC,o.id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=$q->fetchAll();$selectableWhere=$where;if(TransferSettlementOriginals::available($this->db))$selectableWhere.=' AND NOT EXISTS(SELECT 1 FROM finance_transfer_settlements proof WHERE proof.club_id=o.club_id AND proof.active_order_id=o.id)';if($this->returnOrderLinksAvailable())$selectableWhere.=' AND NOT EXISTS(SELECT 1 FROM finance_payment_return_order_links link WHERE link.club_id=o.club_id AND link.active_order_id=o.id)';$q=$this->db->prepare('SELECT COUNT(*) FROM finance_transfer_orders o JOIN finance_entries e ON e.club_id=o.club_id AND e.id=o.entry_id WHERE '.$selectableWhere);$q->execute($args);$selectable_total=(int)$q->fetchColumn();return compact('rows','total','page','pages','size','search','selectable_total');
        });
    }

    public function transferSettlementOrder(string $transaction,string $id):array
    {
        return $this->returnOrderRead(function()use($transaction,$id):array{
            $a=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$entries=$this->transferSettlementEntries($transaction);$q=$this->db->prepare('SELECT id,entry_id,account_id,execution_date,bank_reference,total_cents,state,version FROM finance_transfer_orders WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$order=$q->fetch();if(!$order||$order['id']!==$id||$order['state']!=='submitted'||!in_array($order['entry_id'],$entries,true))throw new DomainException('Einen ursprünglichen eingereichten Bankauftrag zu dieser tatsächlichen Zahlung auswählen.',404);$entry=$this->currentEntry($order['entry_id']);return compact('order','entry');
        });
    }

    public function transferSettlementAllocationsPage(string $transaction,string $order,mixed $page=1,mixed $search=''):array
    {
        $page=self::cataloguePage($page);if(!is_string($search))throw new DomainException('Ursprüngliche Zahlungssuche prüfen.',422);$search=self::field(['q'=>$search],'q',150);
        return $this->returnOrderRead(function()use($transaction,$order,$page,$search):array{
            $a=$this->actor(true);$selected=$this->transferSettlementOrder($transaction,$order);$raw=$selected['order'];$where='l.club_id=? AND l.entry_id=? AND l.voided_at IS NULL AND t.account_id=? AND t.booked_on>=? AND t.cents<0 AND t.reversal_of IS NULL AND returned.id IS NULL';$args=[$a['club_id'],$raw['entry_id'],$raw['account_id'],$raw['execution_date']];if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),l.id,t.id,t.booked_on,t.description,t.counterparty,account.name)))>0';$args[]=$search;}$from=' FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts account ON account.club_id=t.club_id AND account.id=t.account_id LEFT JOIN finance_transactions returned ON returned.club_id=t.club_id AND returned.reversal_of=t.id WHERE '.$where;$q=$this->db->prepare('SELECT COUNT(*)'.$from);$q->execute($args);$total=(int)$q->fetchColumn();$size=20;$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$claimed=TransferSettlementOriginals::available($this->db)?'(SELECT item.settlement_id FROM finance_transfer_settlement_items item WHERE item.club_id=l.club_id AND item.active_allocation_id=l.id)':'NULL';$q=$this->db->prepare('SELECT l.id AS allocation_id,l.cents,l.transaction_id,t.booked_on,t.description,t.counterparty,account.name AS account_name,'.$claimed.' AS execution_id'.$from.' ORDER BY t.booked_on DESC,t.id,l.id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($args);$rows=$q->fetchAll();return compact('rows','total','page','pages','size','search');
        });
    }

    public function transferSettlementAllocationChoice(string $transaction,string $order,string $id):array
    {
        return $this->returnOrderRead(function()use($transaction,$order,$id):array{
            $a=$this->actor(true);$selected=$this->transferSettlementOrder($transaction,$order);$q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$allocation=$q->fetch();if(!$allocation||$allocation['id']!==$id||$allocation['voided_at']!==null||$allocation['entry_id']!==$selected['entry']['id'])throw new DomainException('Aktive ursprüngliche Zahlungszuordnungen genau dieser Rechnung auswählen.',404);$source=$this->transactionDetail($allocation['transaction_id'],true);if($source['account_id']!==$selected['order']['account_id']||$source['booked_on']<$selected['order']['execution_date']||$source['reversal_of']!==null||$source['reversed_on']!==null||(int)$source['cents']>=0)throw new DomainException('Tatsächliche ursprüngliche Zahlungen auf dem Auftragskonto ab dem Ausführungstag auswählen.',409);return compact('allocation','source')+$selected;
        });
    }

    /** Current physical parents, independent of when the execution was originally confirmed. */
    private function transferSettlementReturns(array $ids):array
    {
        $a=$this->actor();if(!PaymentFunding::available($this->db))return [];$q=$this->db->prepare('SELECT part.return_id FROM finance_partial_return_items part JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE part.club_id=? AND part.allocation_id IN('.implode(',',array_fill(0,count($ids),'?')).') AND refund.voided_at IS NULL ORDER BY part.return_id FOR SHARE NOWAIT');$q->execute([$a['club_id'],...$ids]);$parents=array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));$returns=[];
        foreach($parents as$id){$first=PartialReturnOriginals::read($this->db,$a['club_id'],$id,true);$items=[];foreach($first['items']as$item)if(in_array($item['allocation_id'],$ids,true))$items[]=array_intersect_key($item,array_flip(['id','allocation_id','source_id','entry_id','cents','ranges_sha256']));if(!$items)throw new DomainException('Ursprüngliche Rückzahlung und Bankauftrag stimmen nicht überein.',503);$returns[$id]=array_intersect_key($first,array_flip(['id','counter_id','cents','request_sha256','review_sha256','result_sha256']))+['items'=>$items];}
        return $returns;
    }

    /** A cash return needs a real counted closure; a bank return needs its whole actual BOOK evidence. */
    private function transferSettlementMoneyProof(string $id,bool $cashAllowed):array
    {
        $a=$this->actor();$money=$this->transactionDetail($id,true);
        if($money['reversal_of']!==null||$money['reversed_on']!==null||!BankCounterpayments::actualMovement($this->db,$this->actorId,$id,true))throw new DomainException('Den tatsächlichen ursprünglichen Geldfluss zuerst klären.',409);
        if($money['account_kind']==='cash'){
            if(!$cashAllowed||(int)$money['remaining_cents']!==0)throw new DomainException('Eine Bargeldrückzahlung zuerst vollständig zuordnen und anhand des gezählten Kassenabschlusses bestätigen.',409);
            $q=$this->db->prepare('SELECT * FROM cash_closures WHERE club_id=? AND account_id=? AND closed_through>=? ORDER BY closed_through,id LIMIT 1 FOR SHARE NOWAIT');$q->execute([$a['club_id'],$money['account_id'],$money['booked_on']]);$closure=$q->fetch();if(!$closure)throw new DomainException('Die tatsächliche Bargeldrückzahlung benötigt ihren gezählten Kassenabschluss.',409);
            return ['money'=>$money,'bank_proofs'=>[],'cash_closure'=>$closure];
        }
        if((int)($money['bank_proved_cents']??0)!==abs((int)$money['cents'])||(int)($money['bank_unproved_cents']??abs((int)$money['cents']))!==0)throw new DomainException('Ursprüngliche Zahlung und jede tatsächliche Bankrückzahlung zuerst vollständig mit ihren Banknachweisen verbinden.',409);
        $proofs=BankEvidence::proofs($this->db,$a['club_id'],[$id],true)[$id]??[];if(!$proofs)throw new DomainException('Den vollständigen tatsächlichen Banknachweis zuerst zuordnen.',409);foreach($proofs as$proof)if(!in_array($proof['bank_state'],['imported','ignored'],true))throw new DomainException('Eine Bankreferenz ist noch ungeklärt. Tatsächlichen Bankstand zuerst prüfen und bestätigen.',409);
        return ['money'=>$money,'bank_proofs'=>$proofs,'cash_closure'=>null];
    }

    private function transferSettlementFacts(string $transaction,string $order,array $ids,string $reason,string $evidence):array
    {
        $a=$this->actor(true);$authority=FinancePaymentAuthority::require($this->db,$this->actorId);$ids=self::transferSettlementIds($ids);self::returnOrderText($reason,500);self::returnOrderText($evidence,2000);$money=$this->transactionDetail($transaction,true);if($money['id']!==$transaction)throw new DomainException('Eigene tatsächliche Geldbewegung auswählen.',404);
        $q=$this->db->prepare('SELECT * FROM finance_transfer_orders WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);$raw=$q->fetch();if(!$raw||$raw['id']!==$order||$raw['state']!=='submitted')throw new DomainException('Den tatsächlich eingereichten ursprünglichen Bankauftrag auswählen.',409);$entry=$this->currentEntry($raw['entry_id']);if($entry['kind']!=='expense'||$entry['status']==='cancelled'||$entry['reversed_on']!==null)throw new DomainException('Die gültige ursprüngliche Rechnung des eingereichten Bankauftrags prüfen.',409);
        if($this->returnOrderLinksAvailable()){$q=$this->db->prepare('SELECT id FROM finance_payment_return_order_links WHERE club_id=? AND active_order_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);if($q->fetchColumn())throw new DomainException('Dieser Auftrag hat bereits eine bestätigte vollständige Rückzahlungszuordnung. Deren ursprünglichen Nachweis zuerst klären.',409);}
        $available=TransferSettlementOriginals::available($this->db);if($available){$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND active_order_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);if($q->fetchColumn())throw new DomainException('Die Ausführung dieses Bankauftrags ist bereits ausdrücklich zugeordnet.',409);}
        $allocations=[];$sources=[];$total=0;
        foreach($ids as$id){
            $q=$this->db->prepare('SELECT * FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$allocation=$q->fetch();if(!$allocation||$allocation['id']!==$id||$allocation['voided_at']!==null||$allocation['entry_id']!==$entry['id'])throw new DomainException('Aktive ursprüngliche Zahlungszuordnungen genau dieser Rechnung auswählen.',404);
            if($available){$q=$this->db->prepare('SELECT id FROM finance_transfer_settlement_items WHERE club_id=? AND active_allocation_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);if($q->fetchColumn())throw new DomainException('Diese ursprüngliche Zahlung belegt bereits einen anderen bestätigten Bankauftrag.',409);}
            $source=$this->transactionDetail($allocation['transaction_id'],true);if((int)$source['cents']>=0||$source['account_id']!==$raw['account_id']||$source['booked_on']<$raw['execution_date']||$source['reversal_of']!==null||$source['reversed_on']!==null)throw new DomainException('Tatsächliche ursprüngliche Zahlungen auf dem Auftragskonto ab dem Ausführungstag auswählen.',409);
            $allocations[]=['allocation'=>$allocation,'source'=>$source];$sources[$source['id']]=true;$total+=(int)$allocation['cents'];
        }
        if($total!==(int)$raw['total_cents'])throw new DomainException('Die gewählten ursprünglichen Zahlungszuordnungen müssen genau den eingereichten Auftragsbetrag belegen.',409);
        $returns=$this->transferSettlementReturns($ids);$related=array_keys($sources);$bankProofs=[];$cashClosures=[];$payments=[];
        foreach(array_keys($sources)as$id){$proof=$this->transferSettlementMoneyProof($id,false);$payments[$id]=$proof['money'];$bankProofs[$id]=$proof['bank_proofs'];}
        foreach($returns as$return){$id=$return['counter_id'];$related[]=$id;if(isset($payments[$id]))continue;$proof=$this->transferSettlementMoneyProof($id,true);$payments[$id]=$proof['money'];if($proof['cash_closure']!==null)$cashClosures[$id]=$proof['cash_closure'];else $bankProofs[$id]=$proof['bank_proofs'];}
        $related=array_values(array_unique($related));sort($related,SORT_STRING);if(!in_array($transaction,$related,true))throw new DomainException('Diesen Bankauftrag von einer seiner tatsächlichen ursprünglichen Zahlungen oder Rückzahlungen aus öffnen.',404);ksort($payments,SORT_STRING);ksort($bankProofs,SORT_STRING);ksort($cashClosures,SORT_STRING);
        $vault=MemberBankVault::fromEnvironment($this->db,$this->actorId,ProductionRuntime::enabled());$snapshot=(new TransferOrders($this->db,$this->actorId,$vault))->decisionSnapshot($order);
        if(($snapshot['transport']['pending']??false)===true)throw new DomainException('Den noch offenen FinTS-Ausgang anhand des tatsächlichen Bankstatus zuerst klären.',409);
        return ['format'=>'dvm-transfer-settlement-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'order'=>$snapshot,'entry'=>$entry,'allocations'=>$allocations,'related_transactions'=>$related,'returns'=>$returns,'payments'=>$payments,'bank_proofs'=>$bankProofs,'cash_closures'=>$cashClosures,'originals'=>$this->groupReversalDocuments($entry['id']),'authority'=>$authority,'reason'=>$reason,'evidence'=>$evidence,'available'=>$available];
    }

    public function transferSettlementReview(string $transaction,string $order,array $ids,string $reason,string $evidence):array
    {
        return $this->returnOrderRead(function()use($transaction,$order,$ids,$reason,$evidence):array{$review=$this->transferSettlementFacts($transaction,$order,$ids,$reason,$evidence);return ['review'=>$review,'hash'=>hash('sha256',PaymentFundingOriginals::json($review))];});
    }

    public function confirmTransferSettlement(string $transaction,string $order,array $ids,string $reason,string $evidence,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Der Ausführungsnachweis benötigt einen eigenen abgeschlossenen Speichervorgang.',409);$a=$this->actor();$ids=self::transferSettlementIds($ids);self::requestKey($key);self::returnOrderText($reason,500);self::returnOrderText($evidence,2000);if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprünglichen Bankauftrag, Zahlungen und sämtliche Rückzahlungen aktuell prüfen und dessen Ausführung anschließend gesondert bestätigen.',422);if(!TransferSettlementOriginals::available($this->db))throw new DomainException('Der Ausführungsnachweis benötigt die vollständig abgeschlossene Paketwartung.',503);
        $request=['format'=>'dvm-transfer-settlement-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'order_id'=>$order,'allocation_ids'=>$ids,'reason'=>$reason,'evidence'=>$evidence,'review_hash'=>$reviewHash,'confirmed'=>true];$json=PaymentFundingOriginals::json($request);$fingerprint=hash('sha256',$json);
        $first=function(bool$current)use($a,$key,$fingerprint):?array{$now=$this->actor();if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$now['club_id'],$key]);if(!$id=$q->fetchColumn())return null;$original=TransferSettlementOriginals::read($this->db,$now['club_id'],$id,$current);if(!hash_equals($original['request_sha256'],$fingerprint))throw new DomainException('Diese Ausführungskennung gehört zu anderen ursprünglichen Zahlungen. Gespeicherten Nachweis prüfen.',409);return ['id'=>$id,'active'=>$original['active'],'replayed'=>true];};
        if($old=$first(false))return $old;
        try{
            $this->db->beginTransaction();
            try{
                if($old=$first(true)){$this->db->commit();return $old;}$now=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$this->lockReturnOrderClub($now['club_id']);if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT id,entry_id FROM finance_transfer_orders WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$order]);$raw=$q->fetch();if(!$raw||$raw['id']!==$order)throw new DomainException('Eigenen ursprünglichen Bankauftrag auswählen.',404);$movements=[$transaction];
                foreach($ids as$id){$q=$this->db->prepare('SELECT id,transaction_id FROM finance_allocations WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$now['club_id'],$id]);$allocation=$q->fetch();if(!$allocation||$allocation['id']!==$id)throw new DomainException('Eigene ursprüngliche Zahlungszuordnung auswählen.',404);$movements[]=$allocation['transaction_id'];}
                foreach($this->transferSettlementReturns($ids)as$return)$movements[]=$return['counter_id'];$this->lockMovements($movements);$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$raw['entry_id']]);$review=$this->transferSettlementFacts($transaction,$order,$ids,$reason,$evidence);$reviewJson=PaymentFundingOriginals::json($review);if(!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Bankauftrag, ursprüngliche Zahlungen, Rückzahlungen oder Zahlungsberechtigung geändert. Erneut prüfen und anschließend gesondert bestätigen.',409);
                $id=bin2hex(random_bytes(16));$created=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$items=[];foreach($review['allocations']as$selection){$allocation=$selection['allocation'];$items[]=['club_id'=>$now['club_id'],'id'=>bin2hex(random_bytes(16)),'settlement_id'=>$id,'allocation_id'=>$allocation['id'],'source_id'=>$allocation['transaction_id'],'entry_id'=>$allocation['entry_id'],'cents'=>(int)$allocation['cents']];}$result=PaymentFundingOriginals::json(['format'=>'dvm-transfer-settlement-result/1','id'=>$id,'club_id'=>$now['club_id'],'order_id'=>$order,'created_by'=>$now['id'],'created_at'=>$created,'request_key'=>$key,'items'=>$items]);
                $this->db->prepare('INSERT INTO finance_transfer_settlements(club_id,id,order_id,reason,evidence,request_key,created_by,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$now['club_id'],$id,$order,$reason,$evidence,$key,$now['id'],$created,$json,$fingerprint,$reviewJson,$reviewHash,$result,hash('sha256',$result)]);$q=$this->db->prepare('INSERT INTO finance_transfer_settlement_items(club_id,id,settlement_id,allocation_id,source_id,entry_id,cents)VALUES(?,?,?,?,?,?,?)');foreach($items as$item)$q->execute(array_values($item));$this->audit($now,'finance.transfer-execution.confirmed',$id,1);TransferSettlementOriginals::read($this->db,$now['club_id'],$id,true);$this->db->commit();return ['id'=>$id,'active'=>true,'replayed'=>false];
            }catch(\Throwable$failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
        }catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Ausgang des Ausführungsnachweises noch unbestätigt. Dieselben Angaben mit derselben Kennung erneut prüfen.',503,$unknown);}throw new DomainException('Ausführungsnachweis nicht bestätigt. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);}
    }

    public function transferSettlementHistory(string $transaction):array
    {
        return $this->returnOrderRead(function()use($transaction):array{
            $a=$this->actor();$money=$this->transactionDetail($transaction,true);if($money['id']!==$transaction)throw new DomainException('Eigene tatsächliche Geldbewegung auswählen.',404);if(!TransferSettlementOriginals::available($this->db))return [];
            $q=$this->db->prepare('SELECT DISTINCT proof.id,proof.created_at FROM finance_transfer_settlements proof JOIN finance_transfer_settlement_items item ON item.club_id=proof.club_id AND item.settlement_id=proof.id LEFT JOIN finance_partial_return_items part ON part.club_id=item.club_id AND part.allocation_id=item.allocation_id LEFT JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id WHERE proof.club_id=? AND (item.source_id=? OR refund.counter_id=?) ORDER BY proof.created_at,proof.id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$transaction,$transaction]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            return array_map(fn(string$id):array=>TransferSettlementOriginals::read($this->db,$a['club_id'],$id,true),$ids);
        });
    }

    public function transferSettlementOriginal(string $transaction,string $id):array
    {
        return $this->returnOrderRead(function()use($transaction,$id):array{
            $a=$this->actor();$first=null;foreach($this->transferSettlementHistory($transaction)as$row)if($row['id']===$id)$first=$row;if(!$first)throw new DomainException('Ursprünglicher Ausführungsnachweis nicht verfügbar.',404);$docs=new Documents($this->db,$this->actorId);
            foreach($first['review']['originals']as$original){$document=$original['document']['id'];$revision=(int)$original['file']['revision'];$read=$docs->currentFinancialOriginal($document,$revision);$q=$this->db->prepare('SELECT bytes,sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$document,$revision]);$file=$q->fetch();if(!$file||$read['trashed']||!hash_equals($original['file']['sha256'],$read['file']['sha256'])||!hash_equals($original['file']['sha256'],$file['sha256'])||!hash_equals($original['file']['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Eine ursprüngliche Belegdatei ist nicht unverändert zugänglich. Den Ausführungsnachweis zuerst klären.',409);}
            return $first;
        });
    }

    /** Current resolution includes later financial returns of the covered original allocations. */
    private function explicitlySettledTransfer(string $order):bool
    {
        return $this->returnOrderRead(function()use($order):array{
            $a=$this->actor();if(!TransferSettlementOriginals::available($this->db))return ['resolved'=>false];$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND active_order_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);if(!$id=$q->fetchColumn())return ['resolved'=>false];$first=TransferSettlementOriginals::read($this->db,$a['club_id'],$id,true);$q=$this->db->prepare('SELECT * FROM finance_transfer_orders WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);$raw=$q->fetch();if(!$raw||$raw['id']!==$order||$raw['state']!=='submitted')return ['resolved'=>false];$old=$first['review']['order'];foreach($old['order']as$key=>$value)if(($raw[$key]??null)!==$value)return ['resolved'=>false];if(!hash_equals($old['sealed_sha256'],hash('sha256',$raw['sealed_snapshot'])))return ['resolved'=>false];
            $ids=array_column($first['items'],'allocation_id');$returns=$this->transferSettlementReturns($ids);foreach($first['review']['returns']as$id=>$expected)if(($returns[$id]??null)!==$expected)return ['resolved'=>false];$proofs=[];$closures=[];$sources=array_values(array_unique(array_column($first['items'],'source_id')));
            try{
                foreach($sources as$id){$proof=$this->transferSettlementMoneyProof($id,false);$proofs[$id]=$proof['bank_proofs'];}
                foreach($returns as$return){$id=$return['counter_id'];if(isset($proofs[$id])||isset($closures[$id]))continue;$proof=$this->transferSettlementMoneyProof($id,true);if($proof['cash_closure']!==null)$closures[$id]=$proof['cash_closure'];else $proofs[$id]=$proof['bank_proofs'];}
            }catch(DomainException$failure){if($failure->getCode()===409)return ['resolved'=>false];throw $failure;}
            foreach($first['review']['bank_proofs']as$id=>$expected){if(!$expected)return ['resolved'=>false];foreach($expected as$oldProof)if(!in_array($oldProof,$proofs[$id]??[],true))return ['resolved'=>false];}foreach($first['review']['cash_closures']as$id=>$expected)if(($closures[$id]??null)!==$expected)return ['resolved'=>false];return ['resolved'=>true];
        })['resolved'];
    }

    /** Called with physical movement IDs by every actual bank-reference release/correction path. */
    private function assertSettlementBankReferencesMutable(array $ids):void
    {
        if(!$ids||!TransferSettlementOriginals::available($this->db))return;$a=$this->actor();$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->prepare('SELECT proof.id FROM finance_transfer_settlements proof JOIN finance_transfer_settlement_items item ON item.club_id=proof.club_id AND item.settlement_id=proof.id LEFT JOIN finance_partial_return_items part ON part.club_id=item.club_id AND part.allocation_id=item.allocation_id LEFT JOIN finance_partial_returns refund ON refund.club_id=part.club_id AND refund.id=part.return_id AND refund.voided_at IS NULL WHERE proof.club_id=? AND proof.voided_at IS NULL AND item.released_at IS NULL AND (item.source_id IN('.$marks.') OR refund.counter_id IN('.$marks.')) FOR SHARE NOWAIT');$q->execute([$a['club_id'],...$ids,...$ids]);if($q->fetchColumn())throw new DomainException('Dieser Bankbezug belegt eine ausdrücklich bestätigte Auftragsausführung oder ihre Rückzahlung. Zuerst den Ausführungsnachweis prüfen und zurücknehmen; laufende Folgeaufträge vorher klären.',409);
    }

    private function transferSettlementRevocationFacts(string $transaction,string $id,string $reason):array
    {
        $a=$this->actor(true);$authority=FinancePaymentAuthority::require($this->db,$this->actorId);self::returnOrderText($reason,500);$first=$this->transferSettlementOriginal($transaction,$id);if(!$first['active'])throw new DomainException('Dieser Ausführungsnachweis wurde bereits ausdrücklich zurückgenommen.',409);$entry=$first['review']['order']['order']['entry_id'];
        $q=$this->db->prepare("SELECT id,state,version FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND id<>? AND state IN ('prepared','approved','exported','submitted') ORDER BY id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry,$first['order_id']]);$others=$q->fetchAll();foreach($others as$order)if($order['state']!=='submitted'||!$this->paymentReturnResolvesTransfer($order['id']))throw new DomainException('Ein Folgeauftrag läuft bereits. Noch nicht exportierte Vorbereitungen bewusst verwerfen; exportierte oder eingereichte Aufträge anhand ihres tatsächlichen Ausgangs klären.',409);
        return ['format'=>'dvm-transfer-settlement-revoke-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'version'=>(int)$first['version'],'request_sha256'=>$first['request_sha256'],'review_sha256'=>$first['review_sha256'],'result_sha256'=>$first['result_sha256'],'other_orders'=>$others,'authority'=>$authority];
    }

    public function transferSettlementRevocationReview(string $transaction,string $id,string $reason):array
    {
        return $this->returnOrderRead(function()use($transaction,$id,$reason):array{$review=$this->transferSettlementRevocationFacts($transaction,$id,$reason);return ['review'=>$review,'hash'=>hash('sha256',PaymentFundingOriginals::json($review))];});
    }

    public function revokeTransferSettlement(string $transaction,string $id,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die Rücknahme des Ausführungsnachweises benötigt einen eigenen abgeschlossenen Speichervorgang.',409);$a=$this->actor();self::requestKey($key);self::returnOrderText($reason,500);if(!$confirmed||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Rücknahme aktuell prüfen und anschließend gesondert bestätigen.',422);if(!TransferSettlementOriginals::available($this->db))throw new DomainException('Der Ausführungsnachweis benötigt die vollständig abgeschlossene Paketwartung.',503);
        $request=['format'=>'dvm-transfer-settlement-revoke-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true];
        $first=function(bool$current)use($a,$key,$request):?array{$now=$this->actor();if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND void_request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$now['club_id'],$key]);if(!$found=$q->fetchColumn())return null;$original=TransferSettlementOriginals::read($this->db,$now['club_id'],$found,$current);if($original['void']['request']!==$request)throw new DomainException('Diese Rücknahmekennung gehört zu anderen ursprünglichen Angaben.',409);return ['id'=>$found,'active'=>false,'replayed'=>true];};
        if($old=$first(false))return $old;
        try{
            $this->db->beginTransaction();
            try{
                if($old=$first(true)){$this->db->commit();return $old;}$now=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$this->lockReturnOrderClub($now['club_id']);if($now['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$original=$this->transferSettlementOriginal($transaction,$id);$q=$this->db->prepare('SELECT id FROM finance_transfer_orders WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$original['order_id']]);$this->lockMovements(array_values(array_unique(array_column($original['items'],'source_id'))));$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$now['club_id'],$id]);$review=$this->transferSettlementRevocationFacts($transaction,$id,$reason);if(!hash_equals($reviewHash,hash('sha256',PaymentFundingOriginals::json($review))))throw new DomainException('Ausführungsnachweis, Folgeauftrag oder Zahlungsberechtigung geändert. Rücknahme erneut prüfen und anschließend gesondert bestätigen.',409);
                $created=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=['id'=>$id,'club_id'=>$now['club_id'],'voided_at'=>$created,'voided_by'=>$now['id'],'void_reason'=>$reason,'void_request_key'=>$key];$json=PaymentFundingOriginals::json(compact('request','review','result'));$q=$this->db->prepare('UPDATE finance_transfer_settlements SET version=2,voided_at=?,voided_by=?,void_reason=?,void_request_key=?,void_json=?,void_sha256=? WHERE club_id=? AND id=? AND version=1 AND voided_at IS NULL');$q->execute([$created,$now['id'],$reason,$key,$json,hash('sha256',$json),$now['club_id'],$id]);if($q->rowCount()!==1)throw new DomainException('Ausführungsnachweis inzwischen geändert.',409);$q=$this->db->prepare('UPDATE finance_transfer_settlement_items SET released_at=? WHERE club_id=? AND settlement_id=? AND released_at IS NULL');$q->execute([$created,$now['club_id'],$id]);if($q->rowCount()!==count($original['items']))throw new DomainException('Ursprüngliche Ausführungszuordnungen stimmen nicht mehr überein.',503);$this->audit($now,'finance.transfer-execution.revoked',$id,2);TransferSettlementOriginals::read($this->db,$now['club_id'],$id,true);$this->db->commit();return ['id'=>$id,'active'=>false,'replayed'=>false];
            }catch(\Throwable$failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
        }catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Ausgang der Rücknahme noch unbestätigt. Dieselben Angaben mit derselben Kennung erneut prüfen.',503,$unknown);}throw new DomainException('Rücknahme nicht bestätigt. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);}
    }
}
