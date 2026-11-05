<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,3).'/vendor/autoload.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\{PublicAssets,Manager};
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-ticket-refunds')throw new RuntimeException('Owned refund browser required.');
$f=json_decode(file_get_contents(getenv('DVM_TICKET_REFUND_DATA')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$assets=PublicAssets::sources();
foreach(glob(dirname(__DIR__,2).'/native/Web/assets/*.{css,js}',GLOB_BRACE)as$file)$assets['/assets/'.basename($file)]=$file;
foreach(Kulturhus\Native\Web\ImageStudioAssets::sources()as$name=>$file)$assets['/assets/'.$name]=dirname(__DIR__,2).'/native/Web/assets/'.$file;
if(isset($assets[$path])){header('Content-Type: '.PublicAssets::mime($assets[$path]));readfile($assets[$path]);return;}
if(str_starts_with($path,'/assets/')&&Kulturhus\Native\Web\PhotoImportAssets::serve(substr($path,8)))return;
if(str_starts_with($path,'/assets/')){http_response_code(404);return;}
$db=TestSupport::db();$access=(new ManagerBridge($db,'refund-admin','native',static fn()=>null))->access();$box=SecretBox::fromFile($f['key']);
$http=static function($method,$path,$headers,$body,$encoded)use($f):array{
    if($headers[0]!=='Authorization: Bearer sk_live_original')throw new RuntimeException('Original fixture key required.');
    $stateFile=dirname($f['key']).'/browser-refund.json';$state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR):['posts'=>0,'operation'=>null];$id=$f['order'];$amount=$f['amount'];
    if($method==='POST'){
        parse_str($encoded,$data);if($path!=='/v1/refunds'||$data['charge']!=='ch_'.$id||(int)$data['amount']!==$amount)throw new RuntimeException('Only own full refund allowed.');
        $state['posts']++;$state['operation']=$data['metadata']['ticket_refund_id'];file_put_contents($stateFile,json_encode($state,JSON_THROW_ON_ERROR));throw new RuntimeException('Own refund accepted, response deliberately lost.');
    }
    if($path==='/v1/account')return ['status'=>200,'body'=>['id'=>'acct_ORIGINAL']];
    $charge=['id'=>'ch_'.$id,'payment_intent'=>'pi_'.$id,'paid'=>true,'captured'=>true,'amount'=>$amount,'amount_captured'=>$amount,'amount_refunded'=>$state['posts']?$amount:0,'refunded'=>$state['posts']>0,'disputed'=>false,'currency'=>'eur','livemode'=>true];
    $result=match($path){
        '/v1/checkout/sessions/cs_'.$id=>['id'=>'cs_'.$id,'client_reference_id'=>'KH-T-'.$id,'mode'=>'payment','currency'=>'eur','amount_total'=>$amount,'livemode'=>true,'metadata'=>['ticket_order_id'=>$id],'url'=>'https://checkout.stripe.com/c/pay/cs_'.$id,'status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_'.$id],
        '/v1/payment_intents/pi_'.$id.'?expand%5B%5D=latest_charge'=>['id'=>'pi_'.$id,'status'=>'succeeded','amount'=>$amount,'amount_received'=>$amount,'currency'=>'eur','livemode'=>true,'metadata'=>['ticket_order_id'=>$id],'latest_charge'=>$charge],
        '/v1/charges/ch_'.$id=>$charge,
        '/v1/refunds?charge=ch_'.$id.'&limit=100'=>['has_more'=>false,'data'=>[['id'=>'re_'.$id,'status'=>'succeeded','amount'=>$amount,'currency'=>'eur','charge'=>'ch_'.$id,'payment_intent'=>'pi_'.$id,'metadata'=>['ticket_order_id'=>$id,'ticket_refund_id'=>$state['operation']]]]],
        default=>throw new RuntimeException('Only exact fixture provider reads allowed.'),
    };return ['status'=>200,'body'=>$result];
};
if(str_starts_with($path,'/tickets/')){Kulturhus\Native\Web\Tickets\PublicRoutes::serve($db,$access->club(),$box,'http://'.$_SERVER['HTTP_HOST'],$access,$http);return;}
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');$manager=new Manager($db,$access,dirname($f['key']),$f['csrf'],ticketRequest:$http);$notice=$_GET['notice']??'';$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
if($_SERVER['REQUEST_METHOD']==='POST')try{$result=$manager->action('events',$_POST,[]);$notice=$result['notice'];if(!$json){header('Location: /?'.http_build_query($result),true,303);return;}}catch(DomainException|InvalidArgumentException $e){http_response_code(422);$notice=$e->getMessage();if($json){header('Content-Type: application/json');echo json_encode(['error'=>$notice],JSON_THROW_ON_ERROR);return;}}
$main=$manager->main('events',$f['event']);
if($json){header('Content-Type: application/json');echo json_encode(['html'=>$main,'notice'=>$notice,'url'=>'/?view=events&item='.$f['event']],JSON_THROW_ON_ERROR);return;}
echo $manager->shell($main,$notice);
