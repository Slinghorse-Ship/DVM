<?php declare(strict_types=1);
if(!\Kulturhus\Verein\Web\InvoiceMailSource::commandRequested($_POST))return;
try{\Kulturhus\Verein\Web\InvoiceMailSource::command($db,(string)$_SESSION['actor'],$_GET,$_POST);}
catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,409,422],true)?$failure->getCode():422);throw$failure;}
