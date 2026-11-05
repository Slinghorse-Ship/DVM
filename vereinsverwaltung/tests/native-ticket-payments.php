<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Payments\{TicketCheckout,StripeWebhook};
use Kulturhus\Native\Integration\{Accounts,JsonHttp};
use Kulturhus\Native\Web\Settings\ApiPanel;

$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$deny=static function(callable $action,string $label)use($check):void{try{$action();}catch(DomainException|InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException($label);};
$order=['id'=>str_repeat('a',32),'amount_cents'=>2613,'expires_at'=>time()+3600];$ref=TicketCheckout::reference($order);
$sumupAccount=['merchant_code'=>'MCOWN','token'=>'synthetic-online-never-live'];$sumupId='11111111-2222-3333-4444-555555555555';$txId='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$checkout=['id'=>$sumupId,'checkout_reference'=>$ref,'merchant_code'=>'MCOWN','currency'=>'EUR','amount'=>26.13,'status'=>'PENDING','hosted_checkout_url'=>'https://checkout.sumup.com/pay/'.$sumupId,'transactions'=>[]];
$tx=['id'=>$txId,'merchant_code'=>'MCOWN','currency'=>'EUR','amount'=>26.13,'status'=>'SUCCESSFUL','payment_type'=>'ECOM','tip_amount'=>0];
$sumupCalls=[];$sumupResponse=$checkout;$transactionResponse=$tx;$searchResponse=[$checkout];
$sumupHttp=static function($method,$path,$headers,$body,$encoded)use(&$sumupCalls,&$sumupResponse,&$transactionResponse,&$searchResponse,$sumupId,$txId,$check,$order,$ref):array{
    $sumupCalls[]=[$method,$path,$body];$check($headers===['Authorization: Bearer synthetic-online-never-live']&&$encoded===null,'online SumUp uses only own token and JSON contract');
    if($method==='POST'){
        $check($path==='/v0.1/checkouts'&&$body['checkout_reference']===$ref&&$body['amount']===26.13&&$body['currency']==='EUR'&&$body['merchant_code']==='MCOWN'&&$body['hosted_checkout']===['enabled'=>true]&&$body['valid_until']===gmdate('Y-m-d\TH:i:s\Z',$order['expires_at']),'hosted SumUp creation uses exact original amount, merchant, expiry and reference');
        $check($body['redirect_url']==='https://club.example.invalid/tickets/result'&&$body['return_url']==='https://club.example.invalid/tickets/callback','SumUp browser and server callback addresses remain separate');
        return ['status'=>201,'body'=>$sumupResponse];
    }
    if($path==='/v0.1/checkouts?checkout_reference='.$ref)return ['status'=>200,'body'=>$searchResponse];
    if($path==='/v0.1/checkouts/'.$sumupId)return ['status'=>200,'body'=>$sumupResponse];
    if($path==='/v2.1/merchants/MCOWN/transactions?id='.$txId)return ['status'=>200,'body'=>$transactionResponse];
    throw new RuntimeException('Unexpected SumUp path.');
};
$sumup=new TicketCheckout('sumup',$sumupAccount,$sumupHttp);$prepared=$sumup->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback');
$check($prepared['checkout_id']===$sumupId&&$prepared['checkout_url']===$checkout['hosted_checkout_url']&&!isset($prepared['status']),'checkout creation does not invent a successful payment');
$check($sumup->status($order,$sumupId)['status']==='pending','unpaid checkout cannot issue ticket');
$sumupResponse=$checkout+[];$sumupResponse['status']='FAILED';$check($sumup->status($order,$sumupId)['status']==='pending','retryable failed SumUp attempt cannot release capacity');
$sumupResponse['status']='EXPIRED';$check($sumup->status($order,$sumupId)['status']==='expired','explicit provider expiration recognized');
$sumupResponse['transactions']=[$tx];$check($sumup->status($order,$sumupId)['status']==='review','contradictory expired but successful checkout retains review state');
$sumupResponse['status']='PAID';$proof=$sumup->status($order,$sumupId);$check($proof['status']==='paid'&&$proof['transaction_id']===$txId&&$proof['amount_cents']===2613&&$proof['merchant']==='MCOWN','SumUp paid proof independently reads matching canonical online transaction');
foreach(['checkout_reference'=>'foreign','merchant_code'=>'OTHER','currency'=>'USD','amount'=>26.12,'id'=>'66666666-2222-3333-4444-555555555555']as$field=>$value){$good=$sumupResponse;$sumupResponse[$field]=$value;$deny(fn()=>$sumup->status($order,$sumupId),'SumUp mismatched '.$field.' denied');$sumupResponse=$good;}
$sumupResponse['transactions']=[$tx,$tx];$deny(fn()=>$sumup->status($order,$sumupId),'multiple successful SumUp transactions cannot mint duplicate tickets');$sumupResponse['transactions']=[$tx];
foreach(['merchant_code'=>'OTHER','currency'=>'USD','amount'=>26.12,'payment_type'=>'POS','tip_amount'=>1,'status'=>'FAILED']as$field=>$value){$good=$transactionResponse;$transactionResponse[$field]=$value;$deny(fn()=>$sumup->status($order,$sumupId),'canonical SumUp '.$field.' mismatch denied');$transactionResponse=$good;}
$transactionResponse['events']=[['event_type'=>'REFUND']];$check($sumup->status($order,$sumupId)['status']==='review','refunded online payment is never treated as fresh paid ticket');unset($transactionResponse['events']);
$searchResponse=[$checkout];$found=$sumup->find($order);$check($found['checkout_id']===$sumupId&&end($sumupCalls)[0]==='GET','unknown SumUp creation can reconcile without repeating POST');
$searchResponse=[];$check($sumup->find($order)===null,'no discovered checkout is not automatic permission to create again');$searchResponse=[$checkout,$checkout];$deny(fn()=>$sumup->find($order),'ambiguous SumUp search refused');
$sumupResponse=$checkout;$sumupResponse['hosted_checkout_url']='https://checkout.sumup.com.evil.invalid/pay/'.$sumupId;$deny(fn()=>$sumup->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback'),'foreign hosted payment URL cannot receive customer redirect');
$sumupResponse['hosted_checkout_url']=[];$deny(fn()=>$sumup->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback'),'malformed hosted URL rejected');
$writeCalls=0;$unknown=new TicketCheckout('sumup',$sumupAccount,static function()use(&$writeCalls):array{$writeCalls++;throw new RuntimeException('synthetic uncertain transport');});try{$unknown->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback');}catch(RuntimeException){}$check($writeCalls===1,'unknown SumUp write is never automatically retried');

$stripeAccount=['account_id'=>'acct_OWN','mode'=>'test','secret_key'=>'sk_test_synthetic'];$sessionId='cs_test_OWN';$intentId='pi_OWN';
$session=['id'=>$sessionId,'client_reference_id'=>$ref,'mode'=>'payment','currency'=>'eur','amount_total'=>2613,'livemode'=>false,'metadata'=>['ticket_order_id'=>$order['id']],'url'=>'https://checkout.stripe.com/c/pay/'.$sessionId,'status'=>'open','payment_status'=>'unpaid','payment_intent'=>$intentId];
$charge=['id'=>'ch_OWN','paid'=>true,'captured'=>true,'amount'=>2613,'amount_captured'=>2613,'amount_refunded'=>0,'refunded'=>false,'disputed'=>false,'currency'=>'eur','livemode'=>false,'payment_intent'=>$intentId];
$payment=['id'=>$intentId,'status'=>'succeeded','amount'=>2613,'amount_received'=>2613,'currency'=>'eur','livemode'=>false,'metadata'=>['ticket_order_id'=>$order['id']],'latest_charge'=>$charge];
$stripeResponse=$session;$paymentResponse=$payment;$stripeCalls=[];$actualAccount='acct_OWN';
$stripeHttp=static function($method,$path,$headers,$body,$encoded)use(&$stripeCalls,&$stripeResponse,&$paymentResponse,&$actualAccount,$check,$ref,$order,$sessionId,$intentId):array{
    $stripeCalls[]=[$method,$path,$encoded];$check(in_array('Authorization: Bearer sk_test_synthetic',$headers,true)&&in_array('Stripe-Version: '.TicketCheckout::STRIPE_VERSION,$headers,true)&&$body===null,'Stripe uses own key, pinned API version and form contract');
    if($path==='/v1/account')return ['status'=>200,'body'=>['id'=>$actualAccount]];
    if($method==='POST'){
        parse_str($encoded,$fields);$check($path==='/v1/checkout/sessions'&&in_array('Idempotency-Key: '.$ref,$headers,true),'Stripe creation retains exact original idempotency key');
        $check($fields['client_reference_id']===$ref&&$fields['line_items'][0]['price_data']['unit_amount']==='2613'&&$fields['line_items'][0]['price_data']['currency']==='eur'&&$fields['line_items'][0]['quantity']==='1'&&$fields['payment_intent_data']['metadata']['ticket_order_id']===$order['id']&&$fields['expires_at']===(string)$order['expires_at'],'Stripe amount, expiry and both payment identities come from immutable original order');
        return ['status'=>200,'body'=>$stripeResponse];
    }
    if($path==='/v1/checkout/sessions/'.$sessionId)return ['status'=>200,'body'=>$stripeResponse];
    if($path==='/v1/payment_intents/'.$intentId.'?expand%5B%5D=latest_charge')return ['status'=>200,'body'=>$paymentResponse];
    throw new RuntimeException('Unexpected Stripe request.');
};
$stripe=new TicketCheckout('stripe',$stripeAccount,$stripeHttp);$prepared=$stripe->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback');
$check($prepared['checkout_id']===$sessionId&&!isset($prepared['status']),'Stripe session creation does not imply successful payment');
$check($stripe->status($order,$sessionId)['status']==='pending','open Stripe session cannot issue ticket');
$stripeResponse['status']='complete';$check($stripe->status($order,$sessionId)['status']==='pending','complete but unpaid asynchronous Stripe payment cannot issue ticket');
$stripeResponse['payment_status']='paid';$proof=$stripe->status($order,$sessionId);$check($proof['status']==='paid'&&$proof['transaction_id']===$intentId&&$proof['charge_id']==='ch_OWN'&&$proof['test_mode']===true,'Stripe proof requires canonical payment intent and fully captured charge');
foreach(['client_reference_id'=>'foreign','currency'=>'usd','amount_total'=>2612,'livemode'=>true,'id'=>'cs_test_OTHER']as$field=>$value){$good=$stripeResponse;$stripeResponse[$field]=$value;$deny(fn()=>$stripe->status($order,$sessionId),'Stripe checkout '.$field.' mismatch denied');$stripeResponse=$good;}
foreach(['status'=>'processing','amount_received'=>2612,'currency'=>'usd','livemode'=>true,'metadata'=>['ticket_order_id'=>str_repeat('b',32)]]as$field=>$value){$good=$paymentResponse;$paymentResponse[$field]=$value;$deny(fn()=>$stripe->status($order,$sessionId),'canonical Stripe payment '.$field.' mismatch denied');$paymentResponse=$good;}
$paymentResponse['latest_charge']['amount_captured']=2612;$deny(fn()=>$stripe->status($order,$sessionId),'partial captured Stripe charge cannot mint full ticket');$paymentResponse=$payment;
foreach(['amount_refunded'=>1,'refunded'=>true,'disputed'=>true]as$field=>$value){$paymentResponse['latest_charge'][$field]=$value;$check($stripe->status($order,$sessionId)['status']==='review','Stripe corrected '.$field.' needs review without ticket issuance');$paymentResponse=$payment;}
$stripeResponse['status']='expired';$deny(fn()=>$stripe->status($order,$sessionId),'contradictory paid expired Stripe session never releases reserved capacity');$stripeResponse['payment_status']='unpaid';$check($stripe->status($order,$sessionId)['status']==='expired','explicit unpaid Stripe session expiration recognized');
$actualAccount='acct_OTHER';$before=count($stripeCalls);$deny(fn()=>$stripe->create($order,'https://club.example.invalid/tickets/result','https://club.example.invalid/tickets/callback'),'other Stripe merchant cannot create original checkout');$check(count($stripeCalls)===$before+1&&end($stripeCalls)[0]==='GET','merchant mismatch prevents checkout POST');$actualAccount='acct_OWN';
foreach([0,-1,26.13,'2613',100000001]as$amount)$deny(fn()=>TicketCheckout::reference(array_replace($order,['amount_cents'=>$amount])),'invalid original payment amount rejected');
$deny(fn()=>$stripe->create($order,'http://club.example.invalid','https://club.example.invalid/callback'),'insecure customer return URL rejected before external write');

$now=time();$event=['id'=>'evt_OWN','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>$sessionId,'metadata'=>['ticket_order_id'=>$order['id']]]]];$body=json_encode($event,JSON_THROW_ON_ERROR);$secret='whsec_synthetic';$signature=hash_hmac('sha256',$now.'.'.$body,$secret);
$check(StripeWebhook::verify($body,'t='.$now.',v1='.$signature,$secret,$now)===$event,'exact raw signed Stripe notification accepted');
$check(StripeWebhook::verify($body,'t='.$now.',v1='.str_repeat('0',64).',v1='.$signature,$secret,$now)===$event,'rotating signatures accept matching v1 without ignoring body integrity');
$deny(fn()=>StripeWebhook::verify($body.' ','t='.$now.',v1='.$signature,$secret,$now),'modified raw webhook bytes rejected');
$deny(fn()=>StripeWebhook::verify($body,'t='.$now.',v1='.$signature,$secret,$now+301),'old Stripe signature cannot replay after tolerance');
$deny(fn()=>StripeWebhook::verify($body,'t='.$now.',v1='.$signature,$secret,$now-301),'future signature outside tolerance rejected');
$deny(fn()=>StripeWebhook::verify($body,'t='.$now.',t='.$now.',v1='.$signature,$secret,$now),'ambiguous signature timestamp rejected');
$deny(fn()=>StripeWebhook::verify($body,'t='.$now,$secret,$now),'unsigned webhook rejected');

$db=TestSupport::db();TestSupport::seed($db);Kulturhus\Native\Communication\Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);$access=new Access($db,'admin');$club=$access->club();$key=random_bytes(32);$box=new SecretBox($key);$accounts=new Accounts($db,$box);
$input=$stripeAccount+['webhook_secret'=>$secret];$accounts->save($access,'stripe',$input,0);$stored=$accounts->get($club,'stripe');
$row=Db::run($db,"SELECT * FROM native_private_settings WHERE club_id=? AND purpose='api-stripe'",[$club])->fetch();$check(!str_contains($row['ciphertext'],'sk_test_synthetic')&&!str_contains($row['ciphertext'],$secret)&&$stored['secret_key']===$input['secret_key'],'Stripe secrets are authenticated ciphertext with exact private recovery');
$status=$accounts->status($access,'stripe');$check($status['configured']&&!isset($status['details']['secret_key'])&&!isset($status['details']['webhook_secret']),'Stripe public status never exposes secrets');
$accounts->save($access,'stripe',array_replace($input,['secret_key'=>'','webhook_secret'=>'']),1);$check($accounts->get($club,'stripe')['secret_key']===$input['secret_key']&&$accounts->get($club,'stripe')['webhook_secret']===$secret,'blank secrets retain original same-mode same-target connection');
$deny(fn()=>$accounts->save($access,'stripe',array_replace($input,['mode'=>'live','secret_key'=>'','webhook_secret'=>'']),2),'switching payment mode requires deliberate new credentials');
$deny(fn()=>$accounts->save($access,'stripe',array_replace($input,['account_id'=>'acct_OTHER','secret_key'=>'','webhook_secret'=>'']),2),'switching merchant cannot inherit previous secrets');
$deny(fn()=>$accounts->save(new Access($db,'editor'),'stripe',$input,0),'editor cannot change payment secrets');
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/ticket-payment-runtime';mkdir($root,0700,true);file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
$html=(new ApiPanel($db,$access,$root.'/media','synthetic-csrf'))->render();$check(str_contains($html,'data-connection="stripe"')&&str_contains($html,'name="secret_key"')&&!str_contains($html,$input['secret_key'])&&!str_contains($html,$secret),'actual native connection panel exposes empty secret controls only');
$accounts->disconnect($access,'stripe',2);$deny(fn()=>$accounts->get($club,'stripe'),'disconnect removes active Stripe access');$deny(fn()=>$accounts->save($access,'stripe',$input,2),'stale form cannot restore disconnected Stripe account');
foreach(['stripe','sumup-online']as$provider)$deny(fn()=>JsonHttp::request($provider,'POST','/never-live'),'real online payments blocked by explicit synthetic CI even without mail coupling');
echo json_encode(['checks'=>$checks,'status'=>'passed','live_transport'=>false,'ticket_issuance'=>false],JSON_THROW_ON_ERROR)."\n";
