<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Accounting/FinancialReserveCalculation.php';
$source=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {echo json_encode(['valid'=>true,'proof'=>\Kulturhus\Verein\FinancialReserveCalculation::derive($source)],JSON_THROW_ON_ERROR);}
catch (DomainException) {echo json_encode(['valid'=>false],JSON_THROW_ON_ERROR);}
