<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Accounting/FinancialReserveXml.php';
use Kulturhus\Verein\FinancialReserveXml;
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$account=$input['account'];$choices=FinancialReserveXml::choices($account,$input['targets']);
try {FinancialReserveXml::assertExportable([$account]);$valid=true;}catch(DomainException){$valid=false;}
echo json_encode(['choices'=>(object)$choices,'valid'=>$valid],JSON_THROW_ON_ERROR);
