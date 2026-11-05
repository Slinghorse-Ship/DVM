<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__,2).'/vendor/autoload.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,Identity,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Events\Workflow;
use Kulturhus\Native\Events\Tickets\{Offers,Orders,Qr};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Pos\Register;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$deny=static function(callable $f,string $label)use($check):void{try{$f();}catch(DomainException|InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException($label);};
Db::run($db,"INSERT INTO clubs VALUES('refund-a','Own club'),('refund-b','Other club')");Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('refund-admin','refund-a','members',1),('refund-helper','refund-a','member',0),('refund-other','refund-b','members',1)");
$access=(new ManagerBridge($db,'refund-admin','native',static fn()=>null))->access();$other=(new ManagerBridge($db,'refund-other','native',static fn()=>null))->access();$club=$access->club();
(new Kulturhus\Verein\Settings($db,'refund-admin'))->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own refunds']),0);(new Identity($db,$access))->save(['origin'=>'https://correction.example.invalid','club_version'=>1],0);
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/refunds-runtime';mkdir($root,0700,true);$key=$root.'/private.key';file_put_contents($key,random_bytes(32));chmod($key,0600);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$key);$box=SecretBox::fromFile($key);
(new Workflow($db,$access))->save(['source'=>'local','tickets'=>'native','payment'=>'stripe','automatic_publication'=>false],0);
$pages=new Pages($db,$access);$event=$pages->save(['slug'=>'veranstaltungen/own-corrections','title'=>'Original corrected sale','template'=>'event','event_start'=>'2050-10-25T19:00','event_timezone'=>'Europe/Berlin']);$event=$pages->publish($event['id'],$event['version']);$offers=new Offers($db,$access);$offers->save($event['id'],['capacity'=>10,'price_cents'=>1200,'active'=>true],0);
$benefit=bin2hex(random_bytes(16));Db::run($db,'INSERT INTO event_voucher_events(club_id,id,source_id,title,starts_at,location) VALUES(?,?,?,?,?,?)',['refund-a',$benefit,'website:'.$event['id'],'Original corrected sale','2050-10-25T18:00:00Z','Saal']);Db::run($db,'INSERT INTO event_voucher_helpers VALUES(?,?,?)',['refund-a',$benefit,'refund-helper']);
$register=new Register($db,'refund-admin');$helper=new Register($db,'refund-helper');$cash=(new Kulturhus\Verein\Finance($db,'refund-admin'))->createAccount('Own correction cash','cash','0','2026-01-01');$shift=$register->open($benefit,$cash,0);$gift=$register->saveProduct(['label'=>'Original 20 € gift','price'=>'20.00','kind'=>'value-voucher','sphere'=>'wirtschaftlich']);$goods=$register->saveProduct(['label'=>'Original goods','price'=>'3.00','sphere'=>'wirtschaftlich']);
$accounts=new Accounts($db,$box);$accounts->save($access,'stripe',['account_id'=>'acct_ORIGINAL','mode'=>'live','secret_key'=>'sk_live_original','webhook_secret'=>'whsec_original'],0);
$remote=[];$posts=0;$refundPosts=0;$lost=false;$badAmount=false;$onRefund=null;
$http=static function($method,$path,$headers,$body,$encoded)use(&$remote,&$posts,&$refundPosts,&$lost,&$badAmount,&$onRefund):array{
    if($headers[0]!=='Authorization: Bearer sk_live_original')throw new RuntimeException('Original merchant key required.');
    if($path==='/v1/account')return ['status'=>200,'body'=>['id'=>'acct_ORIGINAL']];
    if($method==='POST'&&$path==='/v1/checkout/sessions'){
        parse_str($encoded,$data);$id=$data['metadata']['ticket_order_id'];$remote[$id]=['amount'=>(int)$data['line_items'][0]['price_data']['unit_amount'],'refunded'=>false,'operation'=>null];$posts++;
    }elseif($method==='POST'&&$path==='/v1/refunds'){
        parse_str($encoded,$data);$id=substr($data['charge'],3);$refundPosts++;
        if((int)$data['amount']!==$remote[$id]['amount']||$data['metadata']['ticket_order_id']!==$id||!in_array('Idempotency-Key: KH-TR-'.$data['metadata']['ticket_refund_id'],$headers,true))throw new RuntimeException('Exact refund intent required.');
        $remote[$id]['refunded']=true;$remote[$id]['operation']=$data['metadata']['ticket_refund_id'];
        if($onRefund)$onRefund($id);if($lost)throw new RuntimeException('Synthetic accepted refund lost its response.');return ['status'=>200,'body'=>['id'=>'re_'.$id]];
    }elseif(str_starts_with($path,'/v1/refunds?')){parse_str(parse_url($path,PHP_URL_QUERY),$params);$id=substr($params['charge'],3);}
    else{$identifier=basename(parse_url($path,PHP_URL_PATH));$id=substr($identifier,strpos($identifier,'_')+1);}
    if(!isset($remote[$id]))throw new RuntimeException('Unknown original remote payment.');$r=$remote[$id];
    $charge=['id'=>'ch_'.$id,'payment_intent'=>'pi_'.$id,'paid'=>true,'captured'=>true,'amount'=>$r['amount'],'amount_captured'=>$r['amount'],'amount_refunded'=>$r['refunded']?$r['amount']:0,'refunded'=>$r['refunded'],'disputed'=>false,'currency'=>'eur','livemode'=>true];
    if(str_starts_with($path,'/v1/charges/'))$result=$charge;
    elseif(str_starts_with($path,'/v1/refunds?'))$result=['has_more'=>false,'data'=>$r['refunded']?[['id'=>'re_'.$id,'status'=>'succeeded','amount'=>$badAmount?1:$r['amount'],'currency'=>'eur','charge'=>'ch_'.$id,'payment_intent'=>'pi_'.$id,'metadata'=>['ticket_order_id'=>$id,'ticket_refund_id'=>$r['operation']]]]:[]];
    elseif(str_starts_with($path,'/v1/payment_intents/'))$result=['id'=>'pi_'.$id,'status'=>'succeeded','amount'=>$r['amount'],'amount_received'=>$r['amount'],'currency'=>'eur','livemode'=>true,'metadata'=>['ticket_order_id'=>$id],'latest_charge'=>$charge];
    else $result=['id'=>'cs_'.$id,'client_reference_id'=>'KH-T-'.$id,'mode'=>'payment','currency'=>'eur','amount_total'=>$r['amount'],'livemode'=>true,'metadata'=>['ticket_order_id'=>$id],'url'=>'https://checkout.stripe.com/c/pay/cs_'.$id,'status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_'.$id];
    return ['status'=>200,'body'=>$result];
};
$orders=new Orders($db,$club,$box,$access,$http);
$book=static function(string $name,bool $voucher=true)use($orders,$event,$gift):array{$v=$orders->book($event['id'],1,1,$name,strtolower($name).'@example.invalid',bin2hex(random_bytes(32)),$voucher?[['id'=>$gift,'quantity'=>1]]:[]);$orders->beginCheckout($v['access_token']);return $orders->reconcile($v['access_token']);};
$order=$book('Refund');$original=Db::run($db,'SELECT * FROM native_ticket_orders WHERE id=?',[$order['id']])->fetch();$pass=$order['passes'][0];$voucher=$order['vouchers'][0];
$deny(fn()=>(new Orders($db,$other->club(),$box,$other,$http))->refundOnline($event['id'],$order['id'],$order['version'],'foreign'),'foreign club cannot start refund');
$deny(fn()=>$orders->refundOnline($event['id'],$order['id'],$order['version']-1,'stale'),'stale order cannot start refund');
$deny(fn()=>$orders->refundOnline(str_repeat('a',32),$order['id'],$order['version'],'wrong event'),'order must belong to selected event');
$onRefund=static function($id)use($orders,$event,$pass,$helper,$shift,$voucher,$deny,$check):void{
    $deny(fn()=>$orders->checkIn($event['id'],$pass['code'],$pass['version']),'committed refund intent blocks admission during provider call');
    $balance=$helper->valueVoucher($shift,$voucher['code']);$check($balance['status']==='payment-review'&&$balance['remaining_cents']===2000,'pending refund blocks original full voucher balance without erasing it');
};
$result=$orders->refundOnline($event['id'],$order['id'],$order['version'],'Guest cancellation');$onRefund=null;
$check($result['status']==='refunded'&&$refundPosts===1,'full Stripe refund requires independent charge and exact refund reads');
$view=$orders->view($order['access_token']);$check($view['status']==='refunded'&&$view['passes'][0]['status']==='voided'&&$view['vouchers'][0]['status']==='refunded'&&$view['vouchers'][0]['remaining_cents']===0,'confirmed full refund invalidates admission and exact unused original voucher');
$after=Db::run($db,'SELECT * FROM native_ticket_orders WHERE id=?',[$order['id']])->fetch();$check($after['ciphertext']===$original['ciphertext']&&$after['proof_json']===$original['proof_json']&&$view['passes'][0]['code']===$pass['code'],'refund preserves original buyer, QR and payment proof bytes');
$orders->refundOnline($event['id'],$order['id'],$order['version'],'repeated');$orders->reconcileOnlineRefund($event['id'],$order['id']);$orders->reconcile($order['access_token']);$check($refundPosts===1&&$orders->view($order['access_token'])['status']==='refunded','repeated manager and public refresh never resubmit or reactivate refund');
$check($offers->get($event['id'])['sold']===0,'confirmed refund releases original admission capacity');
$pending=$book('Pending');$lost=true;$result=$orders->refundOnline($event['id'],$pending['id'],$pending['version'],'Lost acknowledgement');$lost=false;
$check($result['status']==='refund_pending'&&$refundPosts===2&&$offers->get($event['id'])['sold']===1,'accepted refund with lost response keeps original codes blocked and capacity held');
$check($orders->reconcile($pending['access_token'])['status']==='refund_pending','ordinary payment reconciliation cannot escape pending refund');
$badAmount=true;$check($orders->reconcileOnlineRefund($event['id'],$pending['id'])['status']==='refund_pending'&&$refundPosts===2,'incorrect refund amount cannot release capacity or create another provider call');$badAmount=false;
$check($orders->reconcileOnlineRefund($event['id'],$pending['id'])['status']==='refunded'&&$refundPosts===2,'read-only continuation confirms original lost refund without second POST');
$used=$book('Used');$orders->checkIn($event['id'],$used['passes'][0]['code'],1);
$deny(fn()=>$orders->refundOnline($event['id'],$used['id'],$used['version'],'Used ticket'),'first admission prevents full online refund');$check($refundPosts===2,'used admission makes no refund request');
$spent=$book('Spent');$sale=$helper->cart($shift,[['id'=>$goods,'quantity'=>1]],0,bin2hex(random_bytes(16)));$helper->tender($sale['id'],$sale['version'],0,$spent['vouchers'][0]['code'],300);
$deny(fn()=>$orders->refundOnline($event['id'],$spent['id'],$spent['version'],'Spent voucher'),'actual partial voucher redemption prevents full online refund');$check($orders->view($spent['access_token'])['vouchers'][0]['remaining_cents']===1700&&$refundPosts===2,'failed refund preserves actual partial balance and provider state');
$list=$orders->managementOrders($event['id']);$serialized=json_encode($list,JSON_THROW_ON_ERROR);$check(count($list['items'])===4&&!str_contains($serialized,$used['access_token'])&&!str_contains($serialized,'sk_live_')&&!str_contains($serialized,$used['passes'][0]['code']),'bounded management list exposes no bearer link, QR or merchant secret');
$deny(fn()=>$orders->managementOrders($event['id'],1),'management pagination rejects unaligned offset');
$manager=new Kulturhus\Native\Web\Manager($db,$access,$root,'own-csrf',ticketRequest:$http);
$webOrder=$book('Weborder',false);$post=['csrf'=>'own-csrf','operation'=>'ticket-refund','id'=>$event['id'],'ticket_order'=>$webOrder['id'],'version'=>(string)$webOrder['version'],'refund_reason'=>'Customer request'];
$deny(fn()=>$manager->action('events',$post,[]),'actual manager action requires explicit full-refund confirmation');
$post['refund_confirm']='1';$post['csrf']='forged';$deny(fn()=>$manager->action('events',$post,[]),'actual manager action rejects forged CSRF before transport');$post['csrf']='own-csrf';
$result=$manager->action('events',$post,[]);$check($result['notice']==='Vollständige Erstattung bestätigt.'&&$refundPosts===3,'real manager action completes independently verified full refund');
// Free and never-started reservations have a local cancellation, no provider action.
$offers->save($event['id'],['capacity'=>10,'price_cents'=>0,'active'=>true],1);
$free=$orders->book($event['id'],2,1,'Free','free@example.invalid',bin2hex(random_bytes(32)));
$check($orders->refundOnline($event['id'],$free['id'],$free['version'],'Free cancellation')['status']==='cancelled'&&$refundPosts===3,'free order cancellation voids original codes without payment provider');
$offers->save($event['id'],['capacity'=>10,'price_cents'=>1200,'active'=>true],2);
$reserved=$orders->book($event['id'],3,1,'Reserved','reserved@example.invalid',bin2hex(random_bytes(32)));
$check($orders->refundOnline($event['id'],$reserved['id'],$reserved['version'],'Not paid')['status']==='cancelled'&&$refundPosts===3,'never-started paid reservation cancels locally without refunding unreceived money');
(new Workflow($db,$access))->save(['source'=>'local','tickets'=>'native','payment'=>'sumup','automatic_publication'=>false],1);
$accounts->save($access,'sumup',['merchant_code'=>'MCREFUND','token'=>'synthetic-refund','affiliate_app_id'=>'','affiliate_key'=>''],0);
$sumupOrder=$orders->book($event['id'],3,1,'Sumup','sumup@example.invalid',bin2hex(random_bytes(32)),[['id'=>$gift,'quantity'=>1]]);
$uuid='11111111-2222-3333-4444-555555555555';$sumupRefunded=false;$sumupWrites=0;$sumupLose=true;$sumupInvalid=false;
$sumupHttp=static function($method,$path,$headers,$body,$encoded)use(&$sumupRefunded,&$sumupWrites,&$sumupLose,&$sumupInvalid,$sumupOrder,$uuid):array{
    if($headers!==['Authorization: Bearer synthetic-refund'])throw new RuntimeException('Original SumUp key required.');
    if($method==='POST'&&str_ends_with($path,'/refunds')){
        if($path!=='/v1.0/merchants/MCREFUND/payments/'.$uuid.'/refunds'||$body!==['amount'=>32.0])throw new RuntimeException('Exact original SumUp refund required.');
        $sumupWrites++;$sumupRefunded=true;if($sumupLose)throw new RuntimeException('Original SumUp refund response lost.');return ['status'=>201,'body'=>[]];
    }
    $tx=['id'=>$uuid,'merchant_code'=>'MCREFUND','amount'=>32.0,'currency'=>'EUR','payment_type'=>'ECOM','status'=>'SUCCESSFUL','simple_status'=>$sumupRefunded?'REFUNDED':'SUCCESSFUL','tip_amount'=>0,'transaction_events'=>$sumupRefunded?[['id'=>123,'amount'=>$sumupInvalid?1.0:32.0,'event_type'=>'REFUND','status'=>'REFUNDED']]:[]];
    return ['status'=>$method==='POST'?201:200,'body'=>str_contains($path,'/transactions?')?$tx:['id'=>$uuid,'checkout_reference'=>'KH-T-'.$sumupOrder['id'],'merchant_code'=>'MCREFUND','amount'=>32.0,'currency'=>'EUR','status'=>$method==='POST'?'PENDING':'PAID','hosted_checkout_url'=>'https://checkout.sumup.com/pay/'.$uuid,'transactions'=>$method==='POST'?[]:[$tx]]];
};
$sumupOrders=new Orders($db,$club,$box,$access,$sumupHttp);$sumupOrders->beginCheckout($sumupOrder['access_token']);$sumupPaid=$sumupOrders->reconcile($sumupOrder['access_token']);
$check($sumupOrders->refundOnline($event['id'],$sumupPaid['id'],$sumupPaid['version'],'SumUp original')['status']==='refund_pending'&&$sumupWrites===1,'lost SumUp refund response remains pending with one original ECOM refund call');
$sumupInvalid=true;$check($sumupOrders->reconcileOnlineRefund($event['id'],$sumupPaid['id'])['status']==='refund_pending'&&$sumupWrites===1,'partial SumUp event is not proof of full refund');$sumupInvalid=false;
$check($sumupOrders->reconcileOnlineRefund($event['id'],$sumupPaid['id'])['status']==='refunded'&&$sumupWrites===1,'full original SumUp ECOM refund recovers using merchant transaction GET only');
$sumupView=$sumupOrders->view($sumupPaid['access_token']);$check($sumupView['passes'][0]['status']==='voided'&&$sumupView['vouchers'][0]['remaining_cents']===0,'SumUp original refund invalidates both original QR types');
// Browser fixture below exercises real forms; never opens the external checkout.
if(getenv('DVM_TICKET_REFUND_BROWSER')==='1'){
    (new Workflow($db,$access))->save(['source'=>'local','tickets'=>'native','payment'=>'stripe','automatic_publication'=>false],2);
    $browserOrder=$orders->book($event['id'],3,1,'Browserrefund','browser@example.invalid',bin2hex(random_bytes(32)),[['id'=>$gift,'quantity'=>1]]);$orders->beginCheckout($browserOrder['access_token']);$browserOrder=$orders->reconcile($browserOrder['access_token']);
    $offers->save($event['id'],['capacity'=>10,'price_cents'=>0,'active'=>true],3);$browserFree=$orders->book($event['id'],4,1,'Browserfree','free-browser@example.invalid',bin2hex(random_bytes(32)));
    $data=$root.'/browser-data.json';file_put_contents($data,json_encode(['key'=>$key,'csrf'=>bin2hex(random_bytes(32)),'event'=>$event['id'],'order'=>$browserOrder['id'],'amount'=>$browserOrder['amount_cents']],JSON_THROW_ON_ERROR));chmod($data,0600);putenv('DVM_TICKET_REFUND_DATA='.$data);
    $originalPages=$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll();$originalAccounts=$db->query('SELECT * FROM native_private_settings ORDER BY club_id,purpose')->fetchAll();
    $sessions=$root.'/sessions';mkdir($sessions,0700);$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$log=$root.'/browser.log';
    $server=proc_open([...TestSupport::phpCommand(),'-d','session.save_path='.$sessions,'-S','127.0.0.1:'.$port,__DIR__.'/support/ticket-refunds-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Owned refund server unavailable.');fclose($pipes[0]);
    try{
        $ready=false;for($i=0;$i<50;$i++){$c=@fsockopen('127.0.0.1',$port,$errno,$error,0.1);if(is_resource($c)){fclose($c);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Refund server not ready.');
        $fixture=$root.'/browser.json';file_put_contents($fixture,json_encode(['origin'=>'http://127.0.0.1:'.$port,'access'=>$browserOrder['access_token']],JSON_THROW_ON_ERROR));chmod($fixture,0600);putenv('DVM_TICKET_REFUND_FIXTURE='.$fixture);
        $node=proc_open(['node',__DIR__.'/ticket-refunds-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual refund browser failed.');
        $ledger=json_decode(file_get_contents($root.'/browser-refund.json'),true,32,JSON_THROW_ON_ERROR);$check($ledger['posts']===1,'actual browser restart and confirmation send exactly one original refund');
        $check($orders->view($browserOrder['access_token'])['status']==='refunded'&&$orders->view($browserFree['access_token'])['status']==='cancelled','actual form actions reach original paid and free orders');
        $check($originalPages===$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll()&&$originalAccounts===$db->query('SELECT * FROM native_private_settings ORDER BY club_id,purpose')->fetchAll(),'real browser refund retains every page and encrypted merchant original');
    }finally{proc_terminate($server);proc_close($server);}
}
echo json_encode(['checks'=>$n,'status'=>'passed','live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
