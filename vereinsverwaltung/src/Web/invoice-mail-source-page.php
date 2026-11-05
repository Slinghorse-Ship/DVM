<?php declare(strict_types=1);
$invoiceMailSourceState=null;
if($view!=='finance'||$tab!=='inbox'||!$canFinance||!$workspaceAllowed||isset($_GET['new_entry']))return;
if(!isset($invoiceInboxSourceState)||$invoiceInboxSourceState['state']!=='list')return;
$invoiceMailSourceState=\Kulturhus\Verein\Web\InvoiceMailSource::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SERVER['REQUEST_METHOD']??'GET');
if(isset($nativeAccess)&&$error!==''&&\Kulturhus\Verein\Web\InvoiceMailSource::commandRequested($_POST))$error=\Kulturhus\Native\Language\InvoiceMailSourceMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if(!in_array($invoiceMailSourceState['state'],['list','selected'],true)){$invoiceMailSourceState['command_error']=$error;$error='';http_response_code($invoiceMailSourceState['status']===403?403:422);}
elseif($error!==''&&\Kulturhus\Verein\Web\InvoiceMailSource::commandRequested($_POST)&&http_response_code()<400)http_response_code(422);
