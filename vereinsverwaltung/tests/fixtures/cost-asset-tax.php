<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCostAssetTax.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$out=[];foreach(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR) as $request){
    try{$out[]=['value'=>Kulturhus\Verein\BalanceCostAssetTax::item($request['item'],$request['chart'],$request['period'])];}
    catch(DomainException $error){$out[]=['rejected'=>$error->getMessage()];}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
