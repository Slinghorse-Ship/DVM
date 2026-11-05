<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1')throw new RuntimeException('Own synthetic ticket process required.');
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,SecretBox};
use Kulturhus\Native\Events\Tickets\Orders;
$config=TestSupport::config();if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-tickets')throw new RuntimeException('Own isolated ticket suite required.');
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/ticket-runtime';$data=json_decode(file_get_contents($root.'/fault.json'),true,16,JSON_THROW_ON_ERROR);$db=TestSupport::db();$access=new Access($db,'admin');$box=SecretBox::fromFile($root.'/private.key');
$mode=$argv[1]??'';
if($mode==='race'){
    $index=$argv[2]??'';if(!in_array($index,['1','2'],true))throw new RuntimeException('Own race participant required.');
    file_put_contents($root.'/ready-'.$index,'ready');$until=microtime(true)+15;while(!is_file($root.'/go')&&microtime(true)<$until)usleep(20000);if(!is_file($root.'/go'))throw new RuntimeException('Race barrier unavailable.');
    try{$order=(new Orders($db,$access->club(),$box))->book($data['page'],1,1,'Race '.$index,'race'.$index.'@example.invalid',$data['requests'][(int)$index-1]);echo json_encode(['status'=>'booked','id'=>$order['id']],JSON_THROW_ON_ERROR)."\n";}catch(DomainException $e){echo json_encode(['status'=>'denied','reason'=>$e->getMessage()],JSON_THROW_ON_ERROR)."\n";}exit;
}
if($mode!=='crash')throw new RuntimeException('Known own fault mode required.');
$http=static function($method,$path,$headers,$body,$encoded)use($root):array{
    if($method!=='POST'||$path!=='/v0.1/checkouts')throw new RuntimeException('Only own hosted checkout creation required.');
    $checkout=['id'=>'99999999-2222-3333-4444-555555555555','checkout_reference'=>$body['checkout_reference'],'merchant_code'=>$body['merchant_code'],'currency'=>'EUR','amount'=>$body['amount'],'status'=>'PAID','hosted_checkout_url'=>'https://checkout.sumup.com/pay/99999999-2222-3333-4444-555555555555','transactions'=>[['id'=>'77777777-aaaa-bbbb-cccc-111111111111','merchant_code'=>$body['merchant_code'],'currency'=>'EUR','amount'=>$body['amount'],'status'=>'SUCCESSFUL','payment_type'=>'ECOM','tip_amount'=>0]]];
    file_put_contents($root.'/remote.json',json_encode($checkout,JSON_THROW_ON_ERROR));chmod($root.'/remote.json',0600);file_put_contents($root.'/created-marker','actual committed native attempt and synthetic provider object before HTTP result');
    usleep(30000000);return ['status'=>201,'body'=>$checkout];
};
(new Orders($db,$access->club(),$box,$access,$http))->beginCheckout($data['access']);
