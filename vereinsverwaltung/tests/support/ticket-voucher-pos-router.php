<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,3).'/vendor/autoload.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\{PublicAssets,BootstrapAssets,Html};
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-ticket-voucher-pos'||(TestSupport::config()['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own isolated common-sales browser required.');
$f=json_decode(file_get_contents(getenv('DVM_TICKET_POS_DATA')),true,32,JSON_THROW_ON_ERROR);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$assets=PublicAssets::sources();
$assets['/vereinsverwaltung/pos.css']=dirname(__DIR__,2).'/public/pos.css';$assets['/vereinsverwaltung/pos.js']=dirname(__DIR__,2).'/public/pos.js';
if(isset($assets[$path])){header('Content-Type: '.PublicAssets::mime($assets[$path]));readfile($assets[$path]);return;}
$db=TestSupport::db();$access=(new ManagerBridge($db,'ticket-pos-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile($f['key']);
$http=static function($method,$path,$headers,$body,$encoded)use($db,$box,$club,$f):array{
 $file=dirname($f['key']).'/browser-provider.json';$ledger=is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];
 if($method==='POST'){$reference=$body['checkout_reference']??'';if(!preg_match('/^KH-T-([a-f0-9]{32})$/D',$reference,$m))throw new RuntimeException('Own checkout reference required.');$id=$m[1];$uuid=substr($id,0,8).'-'.substr($id,8,4).'-'.substr($id,12,4).'-'.substr($id,16,4).'-'.substr($id,20);$ledger[$uuid]=['order'=>$id,'creates'=>($ledger[$uuid]['creates']??0)+1];file_put_contents($file,json_encode($ledger,JSON_THROW_ON_ERROR));chmod($file,0600);}
 else {$uuid=basename(parse_url($path,PHP_URL_PATH));if(str_contains($path,'/transactions?')){parse_str(parse_url($path,PHP_URL_QUERY),$params);$uuid=$params['id']??'';}if(!isset($ledger[$uuid]))throw new RuntimeException('Own provider original missing.');$id=$ledger[$uuid]['order'];}
 $row=Db::run($db,'SELECT * FROM native_ticket_orders WHERE club_id=? AND id=?',[$club,$id])->fetch();if(!$row)throw new RuntimeException('Own native original missing.');$original=$box->open('ticket-order:'.$club.':'.$id,$row['ciphertext']);$merchant=$original['account']['merchant_code'];
 if($headers!==['Authorization: Bearer '.$original['account']['token']])throw new RuntimeException('Own captured account required.');
 $tx=['id'=>$uuid,'merchant_code'=>$merchant,'currency'=>'EUR','amount'=>(int)$row['amount_cents']/100,'status'=>'SUCCESSFUL','payment_type'=>'ECOM','tip_amount'=>0];
 return ['status'=>$method==='POST'?201:200,'body'=>str_contains($path,'/transactions?')?$tx:['id'=>$uuid,'checkout_reference'=>'KH-T-'.$id,'merchant_code'=>$merchant,'currency'=>'EUR','amount'=>(int)$row['amount_cents']/100,'status'=>'PAID','hosted_checkout_url'=>'https://checkout.sumup.com/pay/'.$uuid,'transactions'=>[$tx]]];
};
if(str_starts_with($path,'/tickets/')){Kulturhus\Native\Web\Tickets\PublicRoutes::serve($db,$club,$box,'http://'.$_SERVER['HTTP_HOST'],$access,$http);return;}
if(session_status()!==PHP_SESSION_ACTIVE)session_start();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'");
$ownedManager=$path==='/owned-registers';$actor=$ownedManager?'ticket-pos-admin':'ticket-pos-helper';$register=new Kulturhus\Native\Pos\Register($db,$actor);$web=new Kulturhus\Native\Pos\Web($db,$register,$actor,$f['csrf']);$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{$result=$web->action($_POST);header('Location: '.($ownedManager?'/owned-registers':'/').'?'.http_build_query($result),true,303);return;}catch(DomainException|InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
echo $web->render($_GET,$error);
