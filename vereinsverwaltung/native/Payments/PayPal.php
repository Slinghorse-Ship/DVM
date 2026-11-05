<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;
use Kulturhus\Native\Integration\JsonHttp;
final class PayPal
{
    private \Closure $request;private ?string $token=null;
    public function __construct(private array $account,?callable $request=null)
    {$provider=($account['mode']??'')==='sandbox'?'paypal-sandbox':'paypal';$this->request=$request===null?static fn($method,$path,$headers,$body,$encoded=null)=>JsonHttp::request($provider,$method,$path,$headers,$body,$encoded):\Closure::fromCallable($request);}
    private function call(string $method,string $path,?array $body=null,?string $key=null):array
    {
        if($this->token===null){$r=($this->request)('POST','/v1/oauth2/token',['Authorization: Basic '.base64_encode($this->account['client_id'].':'.$this->account['client_secret']),'Content-Type: application/x-www-form-urlencoded'],null,'grant_type=client_credentials');$this->token=$r['body']['access_token']??null;if(($r['status']??0)!==200||!is_string($this->token)||$this->token==='')throw new \RuntimeException('PayPal-Anmeldung fehlgeschlagen.');}
        $headers=['Authorization: Bearer '.$this->token];if($key!==null)$headers[]='PayPal-Request-Id: '.Money::identifier($key);
        $r=($this->request)($method,$path,$headers,$body,null);if(!in_array($r['status']??0,[200,201,202,204],true)||!is_array($r['body']??null))throw new \RuntimeException('PayPal-Ergebnis nicht bestätigt.');return $r['body'];
    }
    public function create(array $attempt):array
    {
        if(empty($this->account['merchant_id']))throw new \DomainException('PayPal-Händlerkennung fehlt.');
        $r=$this->call('POST','/v2/checkout/orders',['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$attempt['reference'],'custom_id'=>$attempt['reference'],'invoice_id'=>$attempt['reference'],'payee'=>['merchant_id'=>$this->account['merchant_id']],'amount'=>['currency_code'=>'EUR','value'=>Money::decimal((int)$attempt['amount_cents'])]]]],$attempt['id'].'-create');
        $id=$r['id']??null;$link='';foreach($r['links']??[] as $l)if(($l['rel']??'')==='approve')$link=$l['href']??'';
        $host=parse_url($link,PHP_URL_HOST);$expected=$this->account['mode']==='sandbox'?'www.sandbox.paypal.com':'www.paypal.com';
        if(!is_string($id)||$id===''||parse_url($link,PHP_URL_SCHEME)!=='https'||$host!==$expected||parse_url($link,PHP_URL_USER)!==null||parse_url($link,PHP_URL_PORT)!==null)throw new \RuntimeException('PayPal-Freigabeadresse fehlt.');return ['external_id'=>$id,'approval_url'=>$link];
    }
    public function capture(array $attempt):void
    {$this->call('POST','/v2/checkout/orders/'.Money::identifier($attempt['external_id']).'/capture',[],$attempt['id'].'-capture');}
    public function verify(array $attempt):array
    {
        $r=$this->call('GET','/v2/checkout/orders/'.Money::identifier($attempt['external_id']));$units=$r['purchase_units']??[];
        if(($r['id']??'')!==$attempt['external_id']||($r['status']??'')!=='COMPLETED'||count($units)!==1)throw new \DomainException('PayPal-Zahlung nicht abgeschlossen.');$unit=$units[0];$captures=$unit['payments']['captures']??[];
        if(($unit['custom_id']??'')!==$attempt['reference']||($unit['invoice_id']??'')!==$attempt['reference']||($unit['payee']['merchant_id']??'')!==($this->account['merchant_id']??'')||count($captures)!==1)throw new \DomainException('PayPal-Zuordnung unklar.');$capture=$captures[0];
        if(($capture['status']??'')!=='COMPLETED'||($capture['amount']['currency_code']??'')!=='EUR'||Money::cents($capture['amount']['value']??null)!==(int)$attempt['amount_cents']||($capture['final_capture']??false)!==true||empty($capture['id']))throw new \DomainException('PayPal-Zahlungsbetrag stimmt nicht.');
        return ['provider'=>'paypal','transaction_id'=>$capture['id'],'reference'=>$attempt['reference'],'amount_cents'=>(int)$attempt['amount_cents'],'currency'=>'EUR','merchant'=>$unit['payee']['merchant_id']??''];
    }
    public function refund(string $capture,int $amount,string $key):array
    {return $this->call('POST','/v2/payments/captures/'.Money::identifier($capture).'/refund',['amount'=>['currency_code'=>'EUR','value'=>Money::decimal($amount)],'invoice_id'=>'KH-R-'.$key,'custom_id'=>$key],$key);}
    private function refundAuthority(array $proof):void
    {if(($proof['provider']??'')!=='paypal'||($proof['merchant']??'')!==($this->account['merchant_id']??'')||($proof['currency']??'')!=='EUR'||!is_int($proof['amount_cents']??null)||$proof['amount_cents']<=0)throw new \DomainException('PayPal-Rückzahlung gehört nicht zu diesem Händler und Betrag.');}
    public function refundConfirmed(array $proof,string $key):array
    {
        $this->refundAuthority($proof);$r=$this->refund($proof['transaction_id'],$proof['amount_cents'],$key);$checkpoint=['provider'=>'paypal','external_id'=>is_string($r['id']??null)?$r['id']:'','transaction_id'=>$proof['transaction_id']];
        try{return $this->verifyRefund($proof,$key,$checkpoint['external_id']);}catch(\Throwable $e){throw new RefundPending($checkpoint,$e);}
    }
    public function verifyRefund(array $proof,string $key,string $refundId='',string $orderId=''):array
    {
        $this->refundAuthority($proof);$captureId=Money::identifier($proof['transaction_id']);$key=Money::identifier($key);
        if($refundId===''&&$orderId!==''){
            $order=$this->call('GET','/v2/checkout/orders/'.Money::identifier($orderId));$units=$order['purchase_units']??[];
            if(($order['id']??'')!==$orderId||count($units)!==1||($units[0]['payee']['merchant_id']??'')!==$proof['merchant']||($units[0]['custom_id']??'')!==$proof['reference']||($units[0]['invoice_id']??'')!==$proof['reference'])throw new \DomainException('PayPal-Rückzahlungsauftrag nicht eindeutig.');
            $candidates=[];foreach($units[0]['payments']['refunds']??[] as $refund)if(($refund['invoice_id']??'')==='KH-R-'.$key&&($refund['custom_id']??'')===$key)$candidates[]=$refund['id']??'';
            if(count($candidates)!==1||!is_string($candidates[0])||$candidates[0]==='')throw new \DomainException('PayPal-Erstattungskennung nicht eindeutig abrufbar. Kennung aus dem Anbieterstatus prüfen.');$refundId=$candidates[0];
        }
        $refundId=Money::identifier($refundId);$r=$this->call('GET','/v2/payments/refunds/'.$refundId);
        if(($r['id']??'')!==$refundId||($r['status']??'')!=='COMPLETED'||($r['amount']['currency_code']??'')!=='EUR'||Money::cents($r['amount']['value']??null)!==$proof['amount_cents']||($r['invoice_id']??'')!=='KH-R-'.$key||($r['custom_id']??'')!==$key)throw new \DomainException('PayPal-Erstattung noch nicht vollständig und eindeutig bestätigt.');
        $host=($this->account['mode']??'')==='sandbox'?'api-m.sandbox.paypal.com':'api-m.paypal.com';$parents=[];foreach($r['links']??[] as $link)if(($link['rel']??'')==='up')$parents[]=$link['href']??'';
        if(count($parents)!==1||$parents[0]!=='https://'.$host.'/v2/payments/captures/'.$captureId)throw new \DomainException('PayPal-Erstattung gehört nicht zum Original-Capture.');
        $capture=$this->call('GET','/v2/payments/captures/'.$captureId);
        if(($capture['id']??'')!==$captureId||($capture['status']??'')!=='REFUNDED'||($capture['amount']['currency_code']??'')!=='EUR'||Money::cents($capture['amount']['value']??null)!==$proof['amount_cents'])throw new \DomainException('PayPal-Original nicht als vollständig erstattet bestätigt.');
        return $proof+['confirmed'=>true,'external_id'=>$refundId];
    }
    public function reporting(string $from,string $to):array
    {
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$from,new \DateTimeZone('UTC'));$end=\DateTimeImmutable::createFromFormat('!Y-m-d',$to,new \DateTimeZone('UTC'));
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$end<$start||$end>new \DateTimeImmutable('today',new \DateTimeZone('UTC'))||$start->diff($end)->days>=31||empty($this->account['merchant_id']))throw new \DomainException('PayPal-Kontokennung und Zeitraum bis 31 Tage erforderlich.');
        $all=[];$seen=[];$total=null;$pageCount=null;
        for($page=1;$page<=100;$page++){
            $query=http_build_query(['start_date'=>$from.'T00:00:00Z','end_date'=>$to.'T23:59:59Z','fields'=>'transaction_info,payer_info','balance_affecting_records_only'=>'Y','page_size'=>500,'page'=>$page],'','&',PHP_QUERY_RFC3986);
            $r=$this->call('GET','/v1/reporting/transactions?'.$query);
            if(($r['account_number']??'')!==$this->account['merchant_id']||($r['page']??null)!==$page||!is_int($r['total_pages']??null)||!is_int($r['total_items']??null)||$r['total_items']>10000||$r['total_pages']>100||!is_array($r['transaction_details']??null)||!array_is_list($r['transaction_details']))throw new \DomainException('Unvollständige PayPal-Kontoantwort.');
            $total??=$r['total_items'];$pageCount??=$r['total_pages'];if($total!==$r['total_items']||$pageCount!==$r['total_pages'])throw new \DomainException('PayPal-Seitenstand wurde geändert.');
            foreach($r['transaction_details'] as $row){$info=$row['transaction_info']??null;if(!is_array($info))throw new \DomainException('PayPal-Umsatzdetails fehlen.');$identity=($info['transaction_id']??'').':'.($info['transaction_event_code']??'').':'.($info['transaction_amount']['currency_code']??'');if(isset($seen[$identity]))throw new \DomainException('Doppelter PayPal-Umsatz im Abruf.');$seen[$identity]=true;$all[]=$row;}
            if($page>=max(1,$pageCount)){if(count($all)!==$total)throw new \DomainException('PayPal-Abruf enthält nicht alle Umsätze.');return $all;}
        }throw new \DomainException('PayPal-Abruf zu groß.');
    }
    public function balances():array
    {
        $r=$this->call('GET','/v1/reporting/balances');if(($r['account_id']??'')!==($this->account['merchant_id']??'')||empty($r['as_of_time'])||!is_array($r['balances']??null))throw new \DomainException('PayPal-Kontostand unvollständig.');return $r;
    }
}
