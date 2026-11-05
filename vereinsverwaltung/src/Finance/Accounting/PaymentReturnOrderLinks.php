<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Explicit bank evidence associates a returned payment with one old submitted order. Never a wire. */
trait PaymentReturnOrderLinks
{
    private function returnOrderRead(callable $work):array
    {
        try{return $this->bankCatalogueRead($work);}catch(\PDOException$error){if(in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Bankauftrag oder Banknachweis wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }
    private function returnOrderLinksAvailable():bool{return Database::tableExists($this->db,'finance_payment_return_order_links');}
    private static function returnOrderJson(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function returnOrderText(string $value,int $maximum):void
    {
        if(trim($value)===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$maximum||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('Vollständigen Grund und tatsächlichen Banknachweis ohne Steuerzeichen angeben. Grenzen von 500 und 2000 Zeichen beachten.',422);
    }
    private function checkedReturnOrderLink(array $row):array
    {
        foreach(['request','review','result']as$key)if(!hash_equals($row[$key.'_sha256'],hash('sha256',$row[$key.'_json'])))throw new DomainException('Ursprünglichen Nachweis der Bankauftragszuordnung prüfen lassen.',503);
        $request=json_decode($row['request_json'],true,128,JSON_THROW_ON_ERROR);$review=json_decode($row['review_json'],true,128,JSON_THROW_ON_ERROR);$result=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);
        if(($request['format']??'')!=='dvm-returned-order-link-request/1'||($review['format']??'')!=='dvm-returned-order-link-review/1'||($result['format']??'')!=='dvm-returned-order-link-result/1'||($request['club_id']??'')!==$row['club_id']||($review['club_id']??'')!==$row['club_id']||($request['actor']??'')!==$row['created_by']||($review['actor']??'')!==$row['created_by']||($request['return_id']??'')!==$row['return_id']||($review['return']['id']??'')!==$row['return_id']||($request['order_id']??'')!==$row['order_id']||($review['order']['order']['id']??'')!==$row['order_id']||($request['review_hash']??'')!==$row['review_sha256']||($request['confirmed']??false)!==true)throw new DomainException('Identität des ursprünglichen Bankauftragsnachweises stimmt nicht überein.',503);
        foreach(['reason','evidence']as$key)if(($request[$key]??null)!==$row[$key]||($review[$key]??null)!==$row[$key])throw new DomainException('Ursprüngliche Angaben des Bankauftragsnachweises stimmen nicht überein.',503);
        foreach(['id','club_id','return_id','order_id','created_by','created_at','request_key']as$key)if(($result[$key]??null)!==$row[$key])throw new DomainException('Ergebnis des ursprünglichen Bankauftragsnachweises stimmt nicht überein.',503);
        if(($request['transaction']??null)!==($review['transaction']??null)||!in_array($request['transaction']??null,[$review['return']['source_id']??null,$review['return']['returned_id']??null],true)||($review['order']['order']['club_id']??null)!==$row['club_id'])throw new DomainException('Zahlung und Verein des ursprünglichen Bankauftragsnachweises stimmen nicht überein.',503);
        $void=null;
        if($row['voided_at']!==null){
            if((int)$row['version']!==2||!hash_equals($row['void_sha256']??'',hash('sha256',$row['void_json']??'')))throw new DomainException('Ursprüngliche Rücknahme der Bankauftragszuordnung prüfen lassen.',503);
            $void=json_decode($row['void_json'],true,128,JSON_THROW_ON_ERROR);
            foreach(['id','club_id','voided_at','voided_by','void_reason','void_request_key']as$field)if(($void['result'][$field]??null)!==$row[$field])throw new DomainException('Identität der ursprünglichen Rücknahme stimmt nicht überein.',503);
            if(($void['request']['format']??null)!=='dvm-returned-order-unlink-request/1'||($void['review']['format']??null)!=='dvm-returned-order-unlink-review/1'||($void['request']['club_id']??null)!==$row['club_id']||($void['review']['club_id']??null)!==$row['club_id']||($void['review']['id']??null)!==$row['id']||($void['review']['actor']??null)!==$row['voided_by']||($void['review']['reason']??null)!==$row['void_reason']||($void['request']['transaction']??null)!==($void['review']['transaction']??null)||!in_array($void['request']['transaction']??null,[$review['return']['source_id']??null,$review['return']['returned_id']??null],true)||($void['request']['id']??null)!==$row['id']||($void['request']['actor']??null)!==$row['voided_by']||($void['request']['reason']??null)!==$row['void_reason']||($void['request']['confirmed']??false)!==true||($void['request']['review_hash']??null)!==hash('sha256',self::returnOrderJson($void['review']??[])))throw new DomainException('Ursprüngliche Rücknahmeangaben stimmen nicht überein.',503);
        }elseif((int)$row['version']!==1)throw new DomainException('Aktuellen Stand der Bankauftragszuordnung prüfen lassen.',503);
        return $row+compact('request','review','result','void')+['active'=>$row['voided_at']===null];
    }
    private function returnOrderPayment(string $transaction,string $id,bool $documents=false):array
    {
        $a=$this->actor();$this->transactionDetail($transaction,true);
        $q=$this->db->prepare('SELECT * FROM finance_payment_returns WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch();
        if(!$row||$row['id']!==$id||!in_array($transaction,[$row['source_id'],$row['returned_id']],true))throw new DomainException('Ursprüngliche zurückgekommene Zahlung nicht verfügbar.',404);
        $first=$this->checkedPaymentReturn($row);
        if($documents)$this->paymentReturnOriginal($transaction,$id);
        return $first;
    }
    private function returnOrderReviewFacts(string $transaction,string $return,string $order,string $reason,string $evidence):array
    {
        $a=$this->actor(true);$authority=FinancePaymentAuthority::require($this->db,$this->actorId);$payment=$this->returnOrderPayment($transaction,$return,true);$source=$this->transactionDetail($payment['source_id'],true);$counter=$this->transactionDetail($payment['returned_id'],true);$actual=BankCounterpayments::evidence($this->db,$this->actorId,$counter['id'],true);
        if(($actual['demand_return']['id']??'')!==$payment['id'])throw new DomainException('Ursprüngliche Zahlung und vollständige tatsächliche Rückzahlung zuerst klären.',409);
        foreach([$source,$counter]as$money)if((int)($money['bank_proved_cents']??0)!==abs((int)$money['cents'])||(int)($money['bank_unproved_cents']??abs((int)$money['cents']))!==0)throw new DomainException('Originalzahlung und Rückzahlung zuerst jeweils vollständig mit ihren tatsächlichen Banknachweisen verbinden.',409);
        $q=$this->db->prepare('SELECT * FROM finance_transfer_orders WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);$row=$q->fetch();if(!$row||$row['id']!==$order||$row['state']!=='submitted')throw new DomainException('Den tatsächlich eingereichten ursprünglichen Bankauftrag auswählen.',409);
        if(TransferSettlementOriginals::available($this->db)){$q=$this->db->prepare('SELECT id FROM finance_transfer_settlements WHERE club_id=? AND active_order_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$order]);if($q->fetchColumn())throw new DomainException('Für diesen Bankauftrag besteht bereits ein ausdrücklicher Ausführungsnachweis. Dessen ursprünglichen Zusammenhang zuerst klären.',409);}
        $old=null;foreach($payment['review']['source']['orders'][$row['entry_id']]??[]as$candidate)if($candidate['id']===$row['id'])$old=$candidate;
        $entry=$payment['review']['source']['entries'][$row['entry_id']]??null;
        if(!$old||!$entry||(int)$row['total_cents']!==(int)$entry['amount_cents']||$row['account_id']!==$source['account_id']||$row['execution_date']>$source['booked_on'])throw new DomainException('Dieser Bankauftrag passt nicht vollständig zur ursprünglichen bezahlten Rechnung und ihrer Rückzahlung.',409);
        foreach($old as$key=>$value)if(($row[$key]??null)!==$value)throw new DomainException('Der ursprünglich eingereichte Bankauftrag wurde geändert. Den gespeicherten Nachweis zuerst klären.',409);
        $vault=MemberBankVault::fromEnvironment($this->db,$this->actorId,ProductionRuntime::enabled());$snapshot=(new TransferOrders($this->db,$this->actorId,$vault))->decisionSnapshot($order);$bankProofs=BankEvidence::proofs($this->db,$a['club_id'],[$source['id'],$counter['id']],true);ksort($bankProofs,SORT_STRING);foreach($bankProofs as$proofs)foreach($proofs as$proof)if(!in_array($proof['bank_state'],['imported','ignored'],true))throw new DomainException('Eine Bankreferenz ist noch ungeklärt. Tatsächlichen Bankstand zuerst prüfen und bestätigen.',409);
        return ['format'=>'dvm-returned-order-link-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'return'=>array_intersect_key($payment,array_flip(['id','source_id','returned_id','cents','created_by','created_at','request_sha256','review_sha256','result_sha256'])),'payment'=>compact('source','counter'),'bank_proofs'=>$bankProofs,'original_entry'=>$entry,'order'=>$snapshot,'authority'=>$authority,'reason'=>$reason,'evidence'=>$evidence,'available'=>$this->returnOrderLinksAvailable()];
    }
    public function paymentReturnOrderReview(string $transaction,string $return,string $order,string $reason,string $evidence):array
    {
        self::returnOrderText($reason,500);self::returnOrderText($evidence,2000);
        return $this->returnOrderRead(function()use($transaction,$return,$order,$reason,$evidence):array{$review=$this->returnOrderReviewFacts($transaction,$return,$order,$reason,$evidence);return ['review'=>$review,'hash'=>hash('sha256',self::returnOrderJson($review))];});
    }
    public function paymentReturnOrderHistory(string $transaction,string $return):array
    {
        return $this->returnOrderRead(function()use($transaction,$return):array{$a=$this->actor();$this->returnOrderPayment($transaction,$return);if(!$this->returnOrderLinksAvailable())return [];$q=$this->db->prepare('SELECT * FROM finance_payment_return_order_links WHERE club_id=? AND return_id=? ORDER BY created_at,id');$q->execute([$a['club_id'],$return]);return array_map($this->checkedReturnOrderLink(...),$q->fetchAll());});
    }
    public function paymentReturnOrderOriginal(string $transaction,string $id):array
    {
        return $this->returnOrderRead(function()use($transaction,$id):array{$a=$this->actor();$this->transactionDetail($transaction,true);if(!$this->returnOrderLinksAvailable())throw new DomainException('Ursprünglicher Bankauftragsnachweis nicht verfügbar.',404);$q=$this->db->prepare('SELECT * FROM finance_payment_return_order_links WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);if(!($row=$q->fetch())||$row['id']!==$id)throw new DomainException('Ursprünglicher Bankauftragsnachweis nicht verfügbar.',404);$row=$this->checkedReturnOrderLink($row);$this->returnOrderPayment($transaction,$row['return_id'],true);return $row;});
    }
    public function linkReturnedBankOrder(string $transaction,string $return,string $order,string $reason,string $evidence,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die Bankauftragszuordnung benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $a=$this->actor();self::requestKey($key);self::returnOrderText($reason,500);self::returnOrderText($evidence,2000);if(!$confirmed||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new DomainException('Bankauftrag und tatsächliche Banknachweise aktuell prüfen und ihre genaue Zuordnung anschließend gesondert bestätigen.',422);
        if(!$this->returnOrderLinksAvailable())throw new DomainException('Die ausdrückliche Bankauftragszuordnung benötigt das gewartete aktuelle Paket.',503);
        $request=['format'=>'dvm-returned-order-link-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'return_id'=>$return,'order_id'=>$order,'reason'=>$reason,'evidence'=>$evidence,'review_hash'=>$reviewHash,'confirmed'=>true];$json=self::returnOrderJson($request);$fingerprint=hash('sha256',$json);
        $first=function(bool$current)use($key,$fingerprint):?array{$a=$this->actor();$q=$this->db->prepare('SELECT * FROM finance_payment_return_order_links WHERE club_id=? AND request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$a['club_id'],$key]);if(!$row=$q->fetch())return null;$row=$this->checkedReturnOrderLink($row);if(!hash_equals($row['request_sha256'],$fingerprint))throw new DomainException('Diese Zuordnungskennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Verlauf prüfen.',409);return ['id'=>$row['id'],'active'=>$row['active'],'replayed'=>true];};
        if($old=$first(false))return $old;
        try{return $this->transaction(function()use($transaction,$return,$order,$reason,$evidence,$key,$reviewHash,$json,$fingerprint,$first):array{
            if($old=$first(true))return $old;$a=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$this->lockReturnOrderClub($a['club_id']);
            $payment=$this->returnOrderPayment($transaction,$return);$this->lockMovements([$payment['source_id'],$payment['returned_id']]);$q=$this->db->prepare('SELECT id FROM finance_transfer_orders WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$order]);$q=$this->db->prepare('SELECT id FROM finance_payment_return_order_links WHERE club_id=? AND active_order_id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$order]);if($q->fetchColumn())throw new DomainException('Dieser Bankauftrag ist bereits einer Rückzahlung zugeordnet. Den ursprünglichen Verlauf prüfen.',409);
            $review=$this->returnOrderReviewFacts($transaction,$return,$order,$reason,$evidence);$reviewJson=self::returnOrderJson($review);if(!hash_equals(hash('sha256',$reviewJson),$reviewHash))throw new DomainException('Bankauftrag, Banknachweis oder Zahlungsberechtigung hat sich geändert. Erneut prüfen und danach gesondert bestätigen.',409);
            $id=bin2hex(random_bytes(16));$now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=self::returnOrderJson(['format'=>'dvm-returned-order-link-result/1','id'=>$id,'club_id'=>$a['club_id'],'return_id'=>$return,'order_id'=>$order,'created_by'=>$a['id'],'created_at'=>$now,'request_key'=>$key]);
            $this->db->prepare('INSERT INTO finance_payment_return_order_links(club_id,id,return_id,order_id,request_key,reason,evidence,created_by,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$return,$order,$key,$reason,$evidence,$a['id'],$now,$json,$fingerprint,$reviewJson,$reviewHash,$result,hash('sha256',$result)]);$this->audit($a,'finance.returned-order.linked',$id,1);return ['id'=>$id,'active'=>true,'replayed'=>false];
        });}catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Speicherausgang der Bankauftragszuordnung noch unbestätigt. Dieselbe Kennung erneut prüfen; keinen weiteren Auftrag vorbereiten.',503,$unknown);}throw new DomainException('Bankauftragszuordnung konnte nicht bestätigt werden. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);}
    }
    public function canLinkReturnedBankOrder():bool
    {
        return FinancePaymentAuthority::find($this->db,$this->actorId)!==null;
    }

    /** All originally submitted choices; selecting one remains an explicit human decision. */
    public function paymentReturnOrderChoices(string $transaction,string $return):array
    {
        return $this->returnOrderRead(function()use($transaction,$return):array{
            $first=$this->returnOrderPayment($transaction,$return);$choices=[];
            foreach($first['review']['source']['orders']??[]as$entry=>$orders)foreach($orders as$order)if($order['state']==='submitted')$choices[]=$order+['entry'=>$first['review']['source']['entries'][$entry]];
            return $choices;
        });
    }

    /** Any active relation must be consciously revoked before its underlying bank evidence changes. */
    public function assertReturnOrderBankReferenceMutable(string $record):void
    {
        if(!$this->db->inTransaction())throw new DomainException('Bankbezug und Auftragszuordnung gemeinsam prüfen.',409);
        $a=$this->actor(true);$oldLinks=$this->returnOrderLinksAvailable();if(!$oldLinks&&!TransferSettlementOriginals::available($this->db))return;
        $q=$this->db->prepare('SELECT transaction_id FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$record]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(BankEvidence::groupsAvailable($this->db)){$q=$this->db->prepare('SELECT i.transaction_id FROM bank_payment_group_items i JOIN bank_payment_group_links b ON b.club_id=i.club_id AND b.group_id=i.group_id WHERE b.club_id=? AND b.record_id=? AND b.released_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$record]);$ids=[...$ids,...$q->fetchAll(PDO::FETCH_COLUMN)];}
        $ids=array_values(array_unique($ids));if(!$ids)return;$this->assertSettlementBankReferencesMutable($ids);if(!$oldLinks)return;
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->prepare('SELECT l.id FROM finance_payment_return_order_links l JOIN finance_payment_returns r ON r.club_id=l.club_id AND r.id=l.return_id WHERE l.club_id=? AND l.voided_at IS NULL AND (r.source_id IN ('.$marks.') OR r.returned_id IN ('.$marks.')) FOR SHARE NOWAIT');$q->execute([$a['club_id'],...$ids,...$ids]);
        if($q->fetchColumn())throw new DomainException('Dieser Bankbezug belegt eine bestätigte zurückgekommene Banküberweisung. Zuerst deren ausdrückliche Auftragszuordnung prüfen und zurücknehmen; laufende Folgeaufträge zuvor klären.',409);
    }

    private function returnOrderRevocationFacts(string $transaction,string $id,string $reason):array
    {
        $a=$this->actor(true);$authority=FinancePaymentAuthority::require($this->db,$this->actorId);$first=$this->paymentReturnOrderOriginal($transaction,$id);
        if(!$first['active'])throw new DomainException('Diese Auftragszuordnung wurde bereits ausdrücklich zurückgenommen.',409);
        $entry=$first['review']['order']['order']['entry_id'];$q=$this->db->prepare("SELECT id,state,version FROM finance_transfer_orders WHERE club_id=? AND entry_id=? AND id<>? AND state IN ('prepared','approved','exported','submitted') ORDER BY id FOR SHARE NOWAIT");$q->execute([$a['club_id'],$entry,$first['order_id']]);$others=$q->fetchAll();
        foreach($others as$order)if($order['state']!=='submitted'||!$this->paymentReturnResolvesTransfer($order['id']))throw new DomainException('Ein Folgeauftrag läuft bereits. Noch nicht exportierte Vorbereitungen zuerst bewusst verwerfen; eingereichte oder exportierte Aufträge anhand des tatsächlichen Bankausgangs klären.',409);
        return ['format'=>'dvm-returned-order-unlink-review/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'version'=>(int)$first['version'],'request_sha256'=>$first['request_sha256'],'review_sha256'=>$first['review_sha256'],'result_sha256'=>$first['result_sha256'],'other_orders'=>$others,'authority'=>$authority,'reason'=>$reason];
    }
    public function paymentReturnOrderRevocationReview(string $transaction,string $id,string $reason):array
    {
        self::returnOrderText($reason,500);
        return $this->returnOrderRead(function()use($transaction,$id,$reason):array{$review=$this->returnOrderRevocationFacts($transaction,$id,$reason);return ['review'=>$review,'hash'=>hash('sha256',self::returnOrderJson($review))];});
    }

    public function revokeReturnedBankOrder(string $transaction,string $id,string $reason,string $key,string $reviewHash,bool $confirmed):array
    {
        if($this->db->inTransaction())throw new DomainException('Die Rücknahme benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
        $a=$this->actor();self::requestKey($key);self::returnOrderText($reason,500);
        if(!$this->returnOrderLinksAvailable())throw new DomainException('Die ausdrückliche Bankauftragszuordnung benötigt das gewartete aktuelle Paket.',503);
        if(!$confirmed||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new DomainException('Rücknahme aktuell prüfen und anschließend gesondert bestätigen.',422);
        $request=['format'=>'dvm-returned-order-unlink-request/1','club_id'=>$a['club_id'],'actor'=>$a['id'],'transaction'=>$transaction,'id'=>$id,'reason'=>$reason,'review_hash'=>$reviewHash,'confirmed'=>true];
        $first=function(bool$current)use($key,$request):?array{
            $a=$this->actor();$q=$this->db->prepare('SELECT * FROM finance_payment_return_order_links WHERE club_id=? AND void_request_key=?'.($current?' FOR UPDATE NOWAIT':''));$q->execute([$a['club_id'],$key]);if(!$row=$q->fetch())return null;$row=$this->checkedReturnOrderLink($row);
            if($row['void']['request']!==$request)throw new DomainException('Diese Rücknahmekennung gehört zu anderen ursprünglichen Angaben.',409);
            return ['id'=>$row['id'],'active'=>false,'replayed'=>true];
        };
        if($old=$first(false))return $old;
        try{return $this->transaction(function()use($transaction,$id,$reason,$key,$reviewHash,$request,$first):array{
            if($old=$first(true))return $old;$a=$this->actor(true);FinancePaymentAuthority::require($this->db,$this->actorId);$this->lockReturnOrderClub($a['club_id']);
            $original=$this->paymentReturnOrderOriginal($transaction,$id);$payment=$this->returnOrderPayment($transaction,$original['return_id']);$this->lockMovements([$payment['source_id'],$payment['returned_id']]);
            $q=$this->db->prepare('SELECT id FROM finance_transfer_orders WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$original['order_id']]);$q=$this->db->prepare('SELECT id FROM finance_payment_return_order_links WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);
            $review=$this->returnOrderRevocationFacts($transaction,$id,$reason);if(!hash_equals($reviewHash,hash('sha256',self::returnOrderJson($review))))throw new DomainException('Auftragszuordnung oder Zahlungsberechtigung geändert. Rücknahme erneut prüfen und danach gesondert bestätigen.',409);
            $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$result=['id'=>$id,'club_id'=>$a['club_id'],'voided_at'=>$now,'voided_by'=>$a['id'],'void_reason'=>$reason,'void_request_key'=>$key];$json=self::returnOrderJson(compact('request','review','result'));
            $q=$this->db->prepare('UPDATE finance_payment_return_order_links SET version=2,voided_at=?,voided_by=?,void_reason=?,void_request_key=?,void_json=?,void_sha256=? WHERE club_id=? AND id=? AND version=1 AND voided_at IS NULL');$q->execute([$now,$a['id'],$reason,$key,$json,hash('sha256',$json),$a['club_id'],$id]);if($q->rowCount()!==1)throw new DomainException('Auftragszuordnung inzwischen geändert.',409);
            $this->audit($a,'finance.returned-order.unlinked',$id,2);return ['id'=>$id,'active'=>false,'replayed'=>false];
        });}catch(\PDOException$error){try{if($old=$first(false))return $old;}catch(\PDOException$unknown){throw new DomainException('Ausgang der Rücknahme noch unbestätigt. Dieselbe Kennung erneut prüfen.',503,$unknown);}throw new DomainException('Rücknahme nicht bestätigt. Ursprüngliche Angaben und dieselbe Kennung erneut prüfen.',503,$error);}
    }
    private function lockReturnOrderClub(string $club):void
    {
        $q=$this->db->prepare('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1 FOR SHARE NOWAIT');$q->execute();if((int)$q->fetchColumn()!==Database::SCHEMA_VERSION)throw new DomainException('Den aktuellen Paketstand vor der Bankauftragszuordnung ausdrücklich warten.',503);
        $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen aktuellen Verein auswählen.',403);
    }
    private function explicitlyReturnedTransfer(string $id):bool
    {
        return $this->returnOrderRead(function()use($id):array{$a=$this->actor();if(!$this->returnOrderLinksAvailable())return ['resolved'=>false];$q=$this->db->prepare('SELECT * FROM finance_payment_return_order_links WHERE club_id=? AND active_order_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);if(!$row=$q->fetch())return ['resolved'=>false];$link=$this->checkedReturnOrderLink($row);$q=$this->db->prepare('SELECT * FROM finance_transfer_orders WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$order=$q->fetch();if(!$order||$order['state']!=='submitted')return ['resolved'=>false];$old=$link['review']['order'];foreach($old['order']as$key=>$value)if(($order[$key]??null)!==$value)return ['resolved'=>false];if(!hash_equals($old['sealed_sha256'],hash('sha256',$order['sealed_snapshot'])))return ['resolved'=>false];$return=$this->returnOrderPayment($link['request']['transaction'],$link['return_id']);if($return['request_sha256']!==$link['review']['return']['request_sha256']||$return['review_sha256']!==$link['review']['return']['review_sha256']||$return['result_sha256']!==$link['review']['return']['result_sha256'])return ['resolved'=>false];$actual=BankCounterpayments::evidence($this->db,$this->actorId,$return['returned_id'],true);if(($actual['demand_return']['id']??'')!==$return['id'])return ['resolved'=>false];$proofs=BankEvidence::proofs($this->db,$a['club_id'],[$return['source_id'],$return['returned_id']],true);foreach($proofs as$rows)foreach($rows as$proof)if(!in_array($proof['bank_state'],['imported','ignored'],true))return ['resolved'=>false];foreach([$return['source_id'],$return['returned_id']]as$money){$expected=$link['review']['bank_proofs'][$money]??[];if(!$expected)return ['resolved'=>false];foreach($expected as$oldProof)if(!in_array($oldProof,$proofs[$money]??[],true))return ['resolved'=>false];$t=$this->transactionDetail($money,true);if((int)($t['bank_proved_cents']??0)!==abs((int)$t['cents'])||(int)($t['bank_unproved_cents']??abs((int)$t['cents']))!==0)return ['resolved'=>false];}return ['resolved'=>true];})['resolved'];
    }
}
