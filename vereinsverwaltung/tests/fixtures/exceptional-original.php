<?php declare(strict_types=1);
$app=realpath(__DIR__.'/../..');
require_once $app.'/src/autoload.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try{$v=Kulturhus\Verein\OriginalExceptionalDepreciation::calculate($in['plan'],$in['proof']);echo json_encode(['passed'=>true,'value'=>$v],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
catch(DomainException $e){echo json_encode(['passed'=>false,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
