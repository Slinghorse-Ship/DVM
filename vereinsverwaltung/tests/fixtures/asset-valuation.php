<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Assets/AssetValuation.php';
use Kulturhus\Verein\AssetValuation;
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$requests=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];
foreach($requests as $request){
    try{$out[]=['value'=>AssetValuation::calculate($request['plan'],$request['period'],$request['events'],$request['previous'])];}
    catch(DomainException $e){$out[]=['rejected'=>$e->getMessage()];}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
