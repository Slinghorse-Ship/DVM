<?php declare(strict_types=1);
$app=dirname(__DIR__,2);require_once $app.'/src/bootstrap.php';
function url(array $p):string{return '/vereinsverwaltung/?'.http_build_query($p);}
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$r=$data['balance_report'];
$balanceReport=['year'=>$r['year'],'state'=>$r['state'],'version'=>$r['version'],'issues'=>$r['issues'],
 'calculation'=>$r['calculation'],'asset_schedule'=>['items'=>$r['assets'],'totals'=>$r['asset_totals'],'issues'=>$r['issues']]];
if($r['closed_sha256']!==null)$balanceReport['closed_sha256']=$r['closed_sha256'];
if(isset($data['_reported_native_ledger']))$balanceReport=$data['_reported_native_ledger'];
$before=serialize($balanceReport);ob_start();require $app.'/views/Finance/Balance/balance-report.php';$html=ob_get_clean();
if(serialize($balanceReport)!==$before)throw new RuntimeException('Read-only view mutated original source');
echo $html;
