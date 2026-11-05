<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;
use Kulturhus\Native\Integration\JsonHttp;
/** SumUp REST contract pinned in docs; browser/device callbacks are hints only. */
final class SumUp
{
    private \Closure $request;
    public function __construct(private array $account,?callable $request=null)
    {$this->request=$request===null?static fn($method,$path,$headers,$body)=>JsonHttp::request('sumup',$method,$path,$headers,$body):\Closure::fromCallable($request);}
    private function call(string $method,string $path,?array $body=null):array
    {$r=($this->request)($method,$path,['Authorization: Bearer '.$this->account['token']],$body);if(!in_array($r['status']??0,[200,201,202,204],true)||!is_array($r['body']??null))throw new \RuntimeException('SumUp-Ergebnis nicht bestätigt.');return $r['body'];}
    private function merchant():string{return Money::identifier($this->account['merchant_code']);}
    public function reader(array $attempt,string $reader):array
    {
        if(empty($this->account['affiliate_app_id'])||empty($this->account['affiliate_key']))throw new \DomainException('Affiliate-Zuordnung für wiederaufnehmbare Leserzahlungen erforderlich.');
        $r=$this->call('POST','/v0.1/merchants/'.$this->merchant().'/readers/'.Money::identifier($reader).'/checkout',['total_amount'=>['currency'=>'EUR','minor_unit'=>2,'value'=>(int)$attempt['amount_cents']],'description'=>'Kulturhus '.$attempt['reference'],'affiliate'=>['app_id'=>$this->account['affiliate_app_id'],'key'=>$this->account['affiliate_key'],'foreign_transaction_id'=>$attempt['reference']]]);
        $client=$r['data']['client_transaction_id']??null;if(!is_string($client)||$client==='')throw new \RuntimeException('Leserreferenz fehlt.');
        return ['external_id'=>$client,'checkout_id'=>$r['data']['checkout_id']??null];
    }
    public function verify(array $attempt):array
    {
        $proof=$this->reconcile($attempt);if(($proof['outcome']??'paid')!=='paid')throw new \DomainException('SumUp-Zahlung wurde nicht abgeschlossen.');return $proof;
    }
    /** Read-only canonical outcome; only matching final FAILED/CANCELLED permits an unpaid retry. */
    public function reconcile(array $attempt):array
    {
        $r=$this->call('GET','/v2.1/merchants/'.$this->merchant().'/transactions?foreign_transaction_id='.rawurlencode($attempt['reference']));
        $id=$r['id']??'';
        if(!is_string($id)||$id===''||($r['foreign_transaction_id']??'')!==$attempt['reference']||($r['merchant_code']??'')!==$this->account['merchant_code']||($r['currency']??'')!=='EUR'||Money::cents($r['amount']??null)!==(int)$attempt['amount_cents']||($r['payment_type']??'')!=='POS'||!in_array($r['entry_mode']??'',$attempt['method']==='sumup-tap'?['CONTACTLESS','CONTACTLESS_MAGSTRIPE']:['CHIP','CONTACTLESS','CONTACTLESS_MAGSTRIPE','MAGSTRIPE'],true)||Money::cents($r['tip_amount']??0)!==0)throw new \DomainException('SumUp-Transaktion passt nicht zum Zahlungsversuch.');
        // Pinned SumUp TransactionStatus has FAILED/CANCELLED, not DECLINED. Missing or conflicting states never release a reader.
        $negative=in_array($r['status']??null,['FAILED','CANCELLED'],true)&&($r['simple_status']??null)===$r['status'];
        if(!$negative&&(($r['status']??'')!=='SUCCESSFUL'||!in_array($r['simple_status']??'SUCCESSFUL',['SUCCESSFUL','PAID_OUT'],true)))throw new \DomainException('SumUp-Zahlung noch nicht eindeutig abgeschlossen.');
        foreach(['events','transaction_events']as$field)if(isset($r[$field])&&(!is_array($r[$field])||!array_is_list($r[$field])))throw new \DomainException('SumUp-Korrekturstatus unvollständig.');
        foreach(array_merge($r['events']??[],$r['transaction_events']??[]) as $event)if(!is_array($event)||in_array(strtoupper((string)($event['event_type']??$event['type']??'')),array_merge(['REFUND','REFUNDED','CHARGEBACK','CHARGE_BACK'],$negative?[]:['CANCELLED']),true))throw new \DomainException('SumUp-Transaktion wurde korrigiert.');
        $proof=['provider'=>'sumup','transaction_id'=>$id,'reference'=>$attempt['reference'],'amount_cents'=>(int)$attempt['amount_cents'],'currency'=>'EUR','merchant'=>$this->account['merchant_code']];
        return $negative?$proof+['outcome'=>'unpaid','terminal_status'=>$r['status']]:$proof;
    }
    public function refund(string $transaction,int $amount,string $key):array
    {return $this->call('POST','/v1.0/merchants/'.$this->merchant().'/payments/'.Money::identifier($transaction).'/refunds',['amount'=>(float)Money::decimal($amount)]);}
    public function refundConfirmed(array $proof,string $key):array
    {
        $this->refundAuthority($proof);$this->refund($proof['transaction_id'],$proof['amount_cents'],$key);
        try{return $this->verifyRefund($proof);}catch(\Throwable $e){throw new RefundPending(['provider'=>'sumup','transaction_id'=>$proof['transaction_id']],$e);}
    }
    private function refundAuthority(array $proof):void
    {if(($proof['provider']??'')!=='sumup'||($proof['merchant']??'')!==$this->account['merchant_code']||($proof['currency']??'')!=='EUR'||!is_int($proof['amount_cents']??null)||$proof['amount_cents']<=0)throw new \DomainException('SumUp-Rückzahlung gehört nicht zu diesem Händler und Betrag.');}
    /** Read-only reconciliation of actual refund events; compact and detailed arrays are not added twice. */
    public function verifyRefund(array $proof):array
    {
        $this->refundAuthority($proof);$r=$this->call('GET','/v2.1/merchants/'.$this->merchant().'/transactions?foreign_transaction_id='.rawurlencode($proof['reference']));
        if(($r['id']??'')!==$proof['transaction_id']||($r['foreign_transaction_id']??'')!==$proof['reference']||($r['merchant_code']??'')!==$proof['merchant']||($r['currency']??'')!=='EUR'||Money::cents($r['amount']??null)!==$proof['amount_cents']||($r['payment_type']??'')!=='POS'||($r['simple_status']??'')!=='REFUNDED')throw new \DomainException('SumUp-Rückzahlung passt nicht zum Original.');
        $events=$r['transaction_events']??[];if(!is_array($events)||!array_is_list($events)||count($events)>1000)throw new \DomainException('Detaillierte Rückzahlungsereignisse fehlen.');$total=0;$ids=[];
        foreach($events as $event){$type=$event['event_type']??'';if(in_array($type,['CHARGE_BACK','CHARGEBACK'],true))throw new \DomainException('Rückbelastung ist keine bestätigte Rückzahlung.');if($type!=='REFUND')continue;
            if(!in_array($event['status']??'',['REFUNDED','SUCCESSFUL'],true))throw new \DomainException('Rückzahlungsereignis ist noch nicht abgeschlossen.');$id=$event['id']??null;if(!is_int($id)||$id<1||isset($ids[$id]))throw new \DomainException('Rückzahlungsereignis ist nicht eindeutig.');$amount=Money::cents($event['amount']??null);if($amount<=0)throw new \DomainException('Rückzahlungsbetrag fehlt.');$total+=$amount;$ids[$id]=true;
        }
        if(!$ids||$total!==$proof['amount_cents'])throw new \DomainException('Vollständiger Rückzahlungsbetrag nicht bestätigt.');
        return $proof+['confirmed'=>true,'external_id'=>(string)array_key_first($ids),'event_ids'=>array_keys($ids)];
    }
}
