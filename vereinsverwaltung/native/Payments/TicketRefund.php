<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;
use Kulturhus\Native\Integration\JsonHttp;

/** Full online refunds bind the captured merchant and payment; POST results are never proof. */
final class TicketRefund
{
    private \Closure $request;
    public function __construct(private string $provider,private array $account,?callable $request=null)
    {
        new TicketCheckout($provider,$account,$request);
        $origin=$provider==='sumup'?'sumup-online':'stripe';
        $this->request=$request===null?static fn($method,$path,$headers,$body,$encoded)=>JsonHttp::request($origin,$method,$path,$headers,$body,$encoded):\Closure::fromCallable($request);
    }
    private function call(string $method,string $path,?array $body=null,?string $encoded=null,?string $key=null):array
    {
        $headers=['Authorization: Bearer '.($this->provider==='sumup'?$this->account['token']:$this->account['secret_key'])];
        if($this->provider==='stripe')$headers[]='Stripe-Version: '.TicketCheckout::STRIPE_VERSION;
        if($encoded!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
        if($key!==null)$headers[]='Idempotency-Key: '.$key;
        $r=($this->request)($method,$path,$headers,$body,$encoded);
        if(!in_array($r['status']??null,[200,201,202,204],true)||!is_array($r['body']??null))throw new \RuntimeException('Online-Erstattung noch nicht bestätigt.');
        return $r['body'];
    }
    private function authority(array $order,array $proof,string $operation):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$operation)||($proof['provider']??null)!==$this->provider||($proof['reference']??null)!==TicketCheckout::reference($order)||($proof['amount_cents']??null)!==$order['amount_cents']||($proof['currency']??null)!=='EUR'||($proof['merchant']??null)!==($this->account[$this->provider==='sumup'?'merchant_code':'account_id']??null))throw new \DomainException('Originaler Erstattungsauftrag stimmt nicht überein.');
        if($this->provider==='stripe'){
            self::stripeId($proof['transaction_id']??null,'pi_');self::stripeId($proof['charge_id']??null,'ch_');
            if(($proof['test_mode']??null)!==($this->account['mode']==='test')||($this->call('GET','/v1/account')['id']??null)!==$this->account['account_id'])throw new \DomainException('Ursprüngliches Stripe-Konto oder Modus geändert.');
        }elseif(!is_string($proof['transaction_id']??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$proof['transaction_id']))throw new \DomainException('Ursprüngliche SumUp-Transaktion fehlt.');
    }
    private static function stripeId(mixed $id,string $prefix):string
    {
        if(!is_string($id)||!str_starts_with($id,$prefix)||!preg_match('/^[A-Za-z0-9_]{4,200}$/D',$id))throw new \DomainException('Stripe-Erstattungskennung prüfen.');return $id;
    }
    /** Called once, only after durable refund intent. Never retries a provider write. */
    public function submit(array $order,array $proof,string $operation):void
    {
        $this->authority($order,$proof,$operation);
        if($this->provider==='sumup'){
            $this->call('POST','/v1.0/merchants/'.Money::identifier($proof['merchant']).'/payments/'.$proof['transaction_id'].'/refunds',['amount'=>(float)Money::decimal($order['amount_cents'])]);return;
        }
        $fields=['charge'=>$proof['charge_id'],'amount'=>$order['amount_cents'],'metadata'=>['ticket_order_id'=>$order['id'],'ticket_refund_id'=>$operation]];
        $this->call('POST','/v1/refunds',null,http_build_query($fields,'','&',PHP_QUERY_RFC3986),'KH-TR-'.$operation);
    }
    /** Repeated checks are read-only, even when the original POST response was lost. */
    public function verify(array $order,array $proof,string $operation):array
    {
        $this->authority($order,$proof,$operation);
        if($this->provider==='sumup'){
            $tx=$this->call('GET','/v2.1/merchants/'.Money::identifier($proof['merchant']).'/transactions?id='.$proof['transaction_id']);
            if(($tx['id']??null)!==$proof['transaction_id']||($tx['merchant_code']??null)!==$proof['merchant']||($tx['currency']??null)!=='EUR'||Money::cents($tx['amount']??null)!==$order['amount_cents']||($tx['payment_type']??null)!=='ECOM'||($tx['simple_status']??null)!=='REFUNDED'||Money::cents($tx['tip_amount']??0)!==0)throw new \DomainException('SumUp-Erstattung noch nicht als vollständiges Online-Original bestätigt.');
            $events=$tx['transaction_events']??null;$ids=[];$total=0;
            if(!is_array($events)||!array_is_list($events)||count($events)>1000)throw new \DomainException('SumUp-Erstattungsereignisse unvollständig.');
            foreach($events as$event){
                if(!is_array($event)||in_array($event['event_type']??null,['CHARGE_BACK','CHARGEBACK'],true))throw new \DomainException('Rückbelastung ist keine Erstattung.');
                if(($event['event_type']??null)!=='REFUND')continue;
                $id=$event['id']??null;$amount=Money::cents($event['amount']??null);
                if(!is_int($id)||$id<1||isset($ids[$id])||$amount<=0||!in_array($event['status']??null,['REFUNDED','SUCCESSFUL'],true))throw new \DomainException('SumUp-Erstattung noch nicht eindeutig abgeschlossen.');
                $ids[$id]=true;$total+=$amount;
            }
            if(!$ids||$total!==$order['amount_cents'])throw new \DomainException('Vollständige SumUp-Erstattung noch nicht bestätigt.');
            return ['provider'=>'sumup','transaction_id'=>$proof['transaction_id'],'amount_cents'=>$total,'event_ids'=>array_keys($ids),'confirmed'=>true];
        }
        $charge=$this->call('GET','/v1/charges/'.$proof['charge_id']);
        if(($charge['id']??null)!==$proof['charge_id']||($charge['payment_intent']??null)!==$proof['transaction_id']||($charge['amount']??null)!==$order['amount_cents']||($charge['amount_captured']??null)!==$order['amount_cents']||($charge['currency']??null)!=='eur'||($charge['livemode']??null)!==($this->account['mode']==='live')||($charge['paid']??null)!==true||($charge['captured']??null)!==true||($charge['disputed']??null)!==false||($charge['refunded']??null)!==true||($charge['amount_refunded']??null)!==$order['amount_cents'])throw new \DomainException('Stripe-Belastung noch nicht vollständig erstattet.');
        $list=$this->call('GET','/v1/refunds?charge='.rawurlencode($proof['charge_id']).'&limit=100');
        if(($list['has_more']??null)!==false||!is_array($list['data']??null)||!array_is_list($list['data'])||count($list['data'])>100)throw new \DomainException('Stripe-Erstattungsliste unvollständig.');
        $found=[];
        foreach($list['data']as$refund){
            if(!is_array($refund))throw new \DomainException('Stripe-Erstattung unlesbar.');
            if(($refund['metadata']['ticket_refund_id']??null)!==$operation)continue;
            self::stripeId($refund['id']??null,'re_');
            if(($refund['metadata']['ticket_order_id']??null)!==$order['id']||($refund['charge']??null)!==$proof['charge_id']||($refund['payment_intent']??null)!==$proof['transaction_id']||($refund['currency']??null)!=='eur'||($refund['amount']??null)!==$order['amount_cents']||($refund['status']??null)!=='succeeded')throw new \DomainException('Stripe-Erstattung gehört nicht zum abgeschlossenen Originalauftrag.');
            $found[]=$refund['id'];
        }
        if(count($found)!==1)throw new \DomainException('Originale Stripe-Erstattung noch nicht eindeutig bestätigt.');
        return ['provider'=>'stripe','transaction_id'=>$proof['transaction_id'],'charge_id'=>$proof['charge_id'],'amount_cents'=>$order['amount_cents'],'refund_id'=>$found[0],'confirmed'=>true];
    }
}
