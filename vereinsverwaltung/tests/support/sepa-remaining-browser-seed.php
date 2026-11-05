<?php declare(strict_types=1);
/** A nonce-bound fixture-only bridge after the actual browser has submitted its real forty order. */
if(($cfg['owner']??null)!=='isolated-native-ci'||($cfg['label']??null)!=='native-sepa-remaining-collection'||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_string($_POST['nonce']??null)||!hash_equals($f['data']['nonce'],$_POST['nonce'])||!is_string($_POST['case']??null)||!preg_match('/\A[0-7]\z/D',$_POST['case'])||($_POST['operation']??null)!=='actual-money'||!is_string($_POST['order']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$_POST['order'])){http_response_code(403);exit;}
$index=(int)$_POST['case'];$cases=array_values(array_filter($f['data']['cases'],static fn(array $case):bool=>$case['profile_index']===$index));if(count($cases)!==1){http_response_code(403);exit;}$case=$cases[0];$stateFile=$f['root'].'/remaining-money-'.$index.'.json';
if(is_file($stateFile)){$money=json_decode(file_get_contents($stateFile),true,16,JSON_THROW_ON_ERROR);if($money['order']!==$_POST['order']){http_response_code(409);exit;}}
else{
    require __DIR__.'/sepa-remaining-collection-case.php';$data=$f['data']['contract'];$database=\Kulturhus\Native\TestSupport::db();$vault=new \Kulturhus\Verein\MemberBankVault($database,$data['actor'],$data['path']);
    $contract=$data+['finance'=>new \Kulturhus\Verein\Finance($database,$data['actor']),'settings'=>new \Kulturhus\Verein\Settings($database,$data['admin']),'bankVault'=>$vault,'service'=>new \Kulturhus\Verein\SepaOrders($database,$data['actor'],$vault),'key'=>static fn():string=>bin2hex(random_bytes(16))];
    $money=ownSepaRemainingActualMoney($database,$contract,$case,$_POST['order']);file_put_contents($stateFile,json_encode($money,JSON_THROW_ON_ERROR));chmod($stateFile,0600);
}
header('Content-Type: application/json');header('Cache-Control: private, no-store');echo json_encode($money,JSON_THROW_ON_ERROR);exit;
