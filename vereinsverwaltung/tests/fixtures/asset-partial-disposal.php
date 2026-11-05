<?php declare(strict_types=1);
// Synthetic, local CLI bridge for an independent arithmetic oracle. No DB or bootstrap.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/asset-partial-disposal-data.php';
use Kulturhus\Verein\AssetPartialDisposal;
if(($argv[1]??null)==='sample'){
    echo json_encode(partialDisposalFixture(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
$input=stream_get_contents(STDIN,4000001);
if(strlen($input)>4000000)throw new RuntimeException('Oversized synthetic fixture.');
$cases=json_decode($input,true,64,JSON_THROW_ON_ERROR);
if(!is_array($cases)||!array_is_list($cases)||count($cases)>2000)throw new RuntimeException('Synthetic fixture list required.');
$out=[];
foreach($cases as $case){
    try{$out[]=['result'=>AssetPartialDisposal::preview($case['plan'],$case['proof'],$case['year'],$case['tax_review']??null)];}
    catch(Throwable $e){$out[]=['error'=>get_class($e)];}
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
