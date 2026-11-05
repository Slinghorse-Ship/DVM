<?php declare(strict_types=1);
if(\Kulturhus\Verein\Web\InvoiceDraftRead::requested($_POST)||!\Kulturhus\Verein\Web\InvoiceInboxSource::commandRequested($_POST))return;
try{\Kulturhus\Verein\Web\InvoiceInboxSource::command($_GET,$_POST);}
catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,409,422],true)?$failure->getCode():422);throw$failure;}
