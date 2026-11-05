<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;

use Kulturhus\Native\Integration\JsonHttp;

/** Hosted online payments only. Reader callbacks and browser redirects never constitute payment proof. */
final class TicketCheckout
{
    private \Closure $request;
    public const STRIPE_VERSION='2025-09-30.clover';
    public function __construct(private string $provider,#[\SensitiveParameter] private array $account,?callable $request=null)
    {
        if(!in_array($provider,['sumup','stripe'],true))throw new \DomainException('Onlinezahlungsanbieter auswählen.');
        if($provider==='sumup'){
            if(!is_string($account['merchant_code']??null)||!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$account['merchant_code'])||!is_string($account['token']??null)||$account['token']===''||preg_match('/[\x00-\x20\x7f]/',$account['token']))throw new \DomainException('SumUp-Onlinezugang prüfen.');
        }elseif(!is_string($account['account_id']??null)||!preg_match('/^acct_[A-Za-z0-9]{1,80}$/D',$account['account_id'])||!in_array($account['mode']??null,['test','live'],true)||!is_string($account['secret_key']??null)||!preg_match('/^(?:sk|rk)_'. $account['mode'].'_[A-Za-z0-9]{1,200}$/D',$account['secret_key']))throw new \DomainException('Stripe-Konto, Modus und privaten Schlüssel prüfen.');
        $origin=$provider==='sumup'?'sumup-online':'stripe';
        $this->request=$request===null?static fn($method,$path,$headers,$body,$encoded)=>JsonHttp::request($origin,$method,$path,$headers,$body,$encoded):\Closure::fromCallable($request);
    }
    public static function reference(array $order):string
    {
        if(!is_string($order['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$order['id'])||!is_int($order['amount_cents']??null)||$order['amount_cents']<1||$order['amount_cents']>100000000)throw new \DomainException('Ursprüngliche Ticketbestellung und Zahlungsbetrag prüfen.');
        return 'KH-T-'.$order['id'];
    }
    private static function url(mixed $url,?string $host=null):string
    {
        if(!is_string($url))throw new \DomainException('HTTPS-Checkoutadresse prüfen.');
        $parts=parse_url($url);
        if(strlen($url)>4000||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||(isset($parts['port'])&&$parts['port']!==443)||($host!==null&&$parts['host']!==$host)||filter_var($url,FILTER_VALIDATE_URL)===false)throw new \DomainException('HTTPS-Checkoutadresse prüfen.');
        return $url;
    }
    private function call(string $method,string $path,?array $body=null,?string $encoded=null,?string $idempotency=null):array
    {
        $headers=['Authorization: Bearer '.($this->provider==='sumup'?$this->account['token']:$this->account['secret_key'])];
        if($this->provider==='stripe')$headers[]='Stripe-Version: '.self::STRIPE_VERSION;
        if($encoded!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
        if($idempotency!==null)$headers[]='Idempotency-Key: '.$idempotency;
        $response=($this->request)($method,$path,$headers,$body,$encoded);
        if(!in_array($response['status']??null,[200,201],true)||!is_array($response['body']??null))throw new \RuntimeException('Onlinezahlung vom Anbieter nicht eindeutig bestätigt.');
        return $response['body'];
    }
    private function stripeAccount():void
    {
        if($this->provider==='stripe'&&($this->call('GET','/v1/account')['id']??null)!==$this->account['account_id'])throw new \DomainException('Stripe-Schlüssel gehört nicht zum ursprünglichen Händlerkonto.');
    }
    /** Must be called after the durable original order/attempt has been committed. Never retries a POST. */
    public function create(array $order,string $returnUrl,string $callbackUrl):array
    {
        $reference=self::reference($order);self::url($returnUrl);self::url($callbackUrl);
        if(!is_int($order['expires_at']??null)||$order['expires_at']<=time())throw new \DomainException('Ticketreservierung abgelaufen.');
        if($this->provider==='sumup'){
            $response=$this->call('POST','/v0.1/checkouts',['checkout_reference'=>$reference,'amount'=>(float)Money::decimal($order['amount_cents']),'currency'=>'EUR','merchant_code'=>$this->account['merchant_code'],'description'=>'Tickets & Gutscheine '.$reference,'hosted_checkout'=>['enabled'=>true],'valid_until'=>gmdate('Y-m-d\TH:i:s\Z',$order['expires_at']),'redirect_url'=>$returnUrl,'return_url'=>$callbackUrl]);
            $this->sumupIdentity($order,$response);
            $id=self::sumupId($response['id']??null);
            return ['provider'=>'sumup','checkout_id'=>$id,'checkout_url'=>self::url($response['hosted_checkout_url']??'','checkout.sumup.com'),'reference'=>$reference];
        }
        $this->stripeAccount();
        $fields=['mode'=>'payment','client_reference_id'=>$reference,'success_url'=>$returnUrl,'cancel_url'=>$returnUrl,'expires_at'=>$order['expires_at'],
            'line_items'=>[['price_data'=>['currency'=>'eur','unit_amount'=>$order['amount_cents'],'product_data'=>['name'=>'Tickets & Gutscheine '.$reference]],'quantity'=>1]],
            'metadata'=>['ticket_order_id'=>$order['id']], 'payment_intent_data'=>['metadata'=>['ticket_order_id'=>$order['id']]]];
        $response=$this->call('POST','/v1/checkout/sessions',null,http_build_query($fields,'','&',PHP_QUERY_RFC3986),$reference);
        $this->stripeIdentity($order,$response);
        return ['provider'=>'stripe','checkout_id'=>self::stripeId($response['id']??null,'cs_'),'checkout_url'=>self::url($response['url']??'','checkout.stripe.com'),'reference'=>$reference];
    }
    private static function sumupId(mixed $id):string
    {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id))throw new \DomainException('SumUp-Checkoutkennung prüfen.');return $id;
    }
    private static function stripeId(mixed $id,string $prefix):string
    {
        if(!is_string($id)||!str_starts_with($id,$prefix)||!preg_match('/^[A-Za-z0-9_]{4,200}$/D',$id))throw new \DomainException('Stripe-Zahlungskennung prüfen.');return $id;
    }
    private function sumupIdentity(array $order,array $response):void
    {
        if(($response['checkout_reference']??null)!==self::reference($order)||($response['merchant_code']??null)!==$this->account['merchant_code']||($response['currency']??null)!=='EUR'||Money::cents($response['amount']??null)!==$order['amount_cents'])throw new \DomainException('SumUp-Checkout passt nicht zum ursprünglichen Händler, Auftrag und Betrag.');
    }
    private function stripeIdentity(array $order,array $response):void
    {
        if(($response['client_reference_id']??null)!==self::reference($order)||($response['mode']??null)!=='payment'||($response['currency']??null)!=='eur'||($response['amount_total']??null)!==$order['amount_cents']||($response['livemode']??null)!==($this->account['mode']==='live')||($response['metadata']['ticket_order_id']??null)!==$order['id'])throw new \DomainException('Stripe-Checkout passt nicht zum ursprünglichen Auftrag, Modus und Betrag.');
    }
    /** A callback merely requests this fixed-origin GET verification. It never supplies authoritative amounts. */
    public function status(array $order,string $checkout):array
    {
        self::reference($order);
        if($this->provider==='sumup'){
            $id=self::sumupId($checkout);$response=$this->call('GET','/v0.1/checkouts/'.$id);$this->sumupIdentity($order,$response);
            if(($response['id']??null)!==$id)throw new \DomainException('SumUp-Checkoutkennung stimmt nicht überein.');
            $state=$response['status']??null;
            if(!in_array($state,['PENDING','PAID','FAILED','EXPIRED'],true))throw new \DomainException('SumUp-Checkoutstatus unbekannt.');
            $transactions=$response['transactions']??[];
            if(!is_array($transactions)||!array_is_list($transactions)||count($transactions)>100)throw new \DomainException('SumUp-Zahlungsnachweis unvollständig.');
            if($state!=='PAID'){
                foreach($transactions as$tx)if(is_array($tx)&&($tx['status']??null)==='SUCCESSFUL')return ['status'=>'review','provider'=>'sumup','checkout_id'=>$id];
                return ['status'=>$state==='EXPIRED'?'expired':'pending','provider'=>'sumup','checkout_id'=>$id];
            }
            $paid=array_values(array_filter($transactions,static fn($tx)=>is_array($tx)&&($tx['status']??null)==='SUCCESSFUL'));
            if(count($paid)!==1)throw new \DomainException('SumUp-Zahlung nicht eindeutig.');
            $tx=$paid[0];$txId=self::sumupId($tx['id']??null);
            if(($tx['merchant_code']??null)!==$this->account['merchant_code']||($tx['currency']??null)!=='EUR'||Money::cents($tx['amount']??null)!==$order['amount_cents']||Money::cents($tx['tip_amount']??0)!==0||($tx['payment_type']??null)!=='ECOM')throw new \DomainException('SumUp-Onlinezahlung passt nicht zur ursprünglichen Ticketbestellung.');
            $full=$this->call('GET','/v2.1/merchants/'.Money::identifier($this->account['merchant_code']).'/transactions?id='.$txId);
            if(($full['id']??null)!==$txId||($full['merchant_code']??null)!==$this->account['merchant_code']||($full['currency']??null)!=='EUR'||Money::cents($full['amount']??null)!==$order['amount_cents']||($full['status']??null)!=='SUCCESSFUL'||($full['payment_type']??null)!=='ECOM'||Money::cents($full['tip_amount']??0)!==0)throw new \DomainException('SumUp-Transaktion nicht als ursprüngliche Onlinezahlung bestätigt.');
            foreach(['events','transaction_events']as$field)if(isset($full[$field])&&(!is_array($full[$field])||!array_is_list($full[$field])))throw new \DomainException('SumUp-Korrekturstatus unvollständig.');
            foreach(array_merge($full['events']??[],$full['transaction_events']??[])as$event)if(!is_array($event)||in_array(strtoupper((string)($event['event_type']??$event['type']??'')),['REFUND','REFUNDED','CHARGEBACK','CHARGE_BACK','CANCELLED'],true))return ['status'=>'review','provider'=>'sumup','checkout_id'=>$id];
            return ['status'=>'paid','provider'=>'sumup','checkout_id'=>$id,'transaction_id'=>$txId,'amount_cents'=>$order['amount_cents'],'currency'=>'EUR','merchant'=>$this->account['merchant_code'],'reference'=>self::reference($order)];
        }
        $id=self::stripeId($checkout,'cs_');$this->stripeAccount();$response=$this->call('GET','/v1/checkout/sessions/'.$id);$this->stripeIdentity($order,$response);
        if(($response['id']??null)!==$id||!in_array($response['status']??null,['open','complete','expired'],true)||!in_array($response['payment_status']??null,['paid','unpaid','no_payment_required'],true))throw new \DomainException('Stripe-Checkoutstatus unvollständig.');
        if($response['status']==='expired'){
            if($response['payment_status']==='paid')throw new \DomainException('Stripe-Zahlungs- und Ablaufstatus widersprechen sich.');
            return ['status'=>'expired','provider'=>'stripe','checkout_id'=>$id];
        }
        if($response['status']!=='complete'||$response['payment_status']!=='paid')return ['status'=>'pending','provider'=>'stripe','checkout_id'=>$id];
        $intent=self::stripeId($response['payment_intent']??null,'pi_');$payment=$this->call('GET','/v1/payment_intents/'.$intent.'?expand%5B%5D=latest_charge');
        if(($payment['id']??null)!==$intent||($payment['status']??null)!=='succeeded'||($payment['amount']??null)!==$order['amount_cents']||($payment['amount_received']??null)!==$order['amount_cents']||($payment['currency']??null)!=='eur'||($payment['livemode']??null)!==($this->account['mode']==='live')||($payment['metadata']['ticket_order_id']??null)!==$order['id'])throw new \DomainException('Stripe-Zahlung nicht als vollständige ursprüngliche Zahlung bestätigt.');
        $charge=$payment['latest_charge']??null;
        if(!is_array($charge)||!is_string($charge['id']??null)||($charge['paid']??null)!==true||($charge['captured']??null)!==true||($charge['amount']??null)!==$order['amount_cents']||($charge['amount_captured']??null)!==$order['amount_cents']||($charge['currency']??null)!=='eur'||($charge['livemode']??null)!==($this->account['mode']==='live')||($charge['payment_intent']??null)!==$intent||!is_int($charge['amount_refunded']??null)||!is_bool($charge['refunded']??null)||!is_bool($charge['disputed']??null))throw new \DomainException('Stripe-Belastungsnachweis unvollständig.');
        self::stripeId($charge['id'],'ch_');
        if($charge['refunded']||$charge['amount_refunded']!==0||($charge['disputed']??false)!==false)return ['status'=>'review','provider'=>'stripe','checkout_id'=>$id];
        return ['status'=>'paid','provider'=>'stripe','checkout_id'=>$id,'transaction_id'=>$intent,'charge_id'=>$charge['id'],'amount_cents'=>$order['amount_cents'],'currency'=>'EUR','merchant'=>$this->account['account_id'],'reference'=>self::reference($order),'test_mode'=>$this->account['mode']==='test'];
    }
    /** Read-only reconciliation after an unknown SumUp creation; never creates a second checkout. */
    public function find(array $order):?array
    {
        if($this->provider!=='sumup')throw new \DomainException('Stripe-Wiederaufnahme verwendet den ursprünglichen Idempotenzschlüssel.');
        $rows=$this->call('GET','/v0.1/checkouts?checkout_reference='.rawurlencode(self::reference($order)));
        if(!array_is_list($rows)||count($rows)>100)throw new \DomainException('SumUp-Suche unvollständig.');
        if($rows===[])return null;
        if(count($rows)!==1||!is_array($rows[0]))throw new \DomainException('SumUp-Checkout nicht eindeutig zugeordnet.');
        $response=$rows[0];$this->sumupIdentity($order,$response);
        return ['provider'=>'sumup','checkout_id'=>self::sumupId($response['id']??null),'checkout_url'=>self::url($response['hosted_checkout_url']??'','checkout.sumup.com'),'reference'=>self::reference($order)];
    }
    /** A signed notification supplies an id hint; this independent read establishes its original order identity. */
    public function locateStripe(array $order,string $checkout):array
    {
        if($this->provider!=='stripe')throw new \DomainException('Stripe-Checkout auswählen.');
        $id=self::stripeId($checkout,'cs_');$this->stripeAccount();$response=$this->call('GET','/v1/checkout/sessions/'.$id);$this->stripeIdentity($order,$response);
        if(($response['id']??null)!==$id||!in_array($response['status']??null,['open','complete','expired'],true))throw new \DomainException('Stripe-Checkout nicht eindeutig.');
        $url=($response['url']??null)===null?null:self::url($response['url'],'checkout.stripe.com');
        if($response['status']==='open'&&$url===null)throw new \DomainException('Stripe-Checkoutadresse fehlt.');
        return ['provider'=>'stripe','checkout_id'=>$id,'checkout_url'=>$url,'reference'=>self::reference($order)];
    }
}
