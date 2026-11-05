<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceAssetAllocation.php';
use Kulturhus\Verein\{AssetValuation as V,BalanceCostAssetAllocation as A};
$requests=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];
foreach($requests as $r){try{$out[]=A::split($r['value'],$r['weights']);}catch(DomainException $e){$out[]=['error'=>$e->getMessage()];}}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
