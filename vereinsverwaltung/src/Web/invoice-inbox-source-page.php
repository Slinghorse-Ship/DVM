<?php declare(strict_types=1);
$invoiceInboxSourceState=null;
if($error!==''&&\Kulturhus\Verein\Web\InvoiceInboxSource::commandRequested($_POST)&&isset($nativeAccess)){
    $invoiceEntryErrorMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    $error=\Kulturhus\Native\Language\InvoiceEntryMessages::error(\Kulturhus\Native\Language\InvoiceInboxSourceMessages::text($error,$invoiceEntryErrorMessages),$invoiceEntryErrorMessages);
}
// A rejected save must retain its complete original input before permission/source views return.
if(!isset($invoiceDraftReadState)&&\Kulturhus\Verein\Web\InvoiceDraftRead::failedSaveRequested($_GET,$_POST,$_SERVER['REQUEST_METHOD']??'GET',$error,$_SESSION['csrf']??null)){
    $invoiceDraftReadState=\Kulturhus\Verein\Web\InvoiceDraftRead::inspect($db,(string)$_SESSION['actor'],$_GET,$_POST);
    $invoiceDraftReadState['legacy_failure']=true;
    if($invoiceDraftReadState['status']===403||$invoiceDraftReadState['state']==='conflict')http_response_code($invoiceDraftReadState['status']);
}
if($view!=='finance'||$tab!=='inbox'||!$canFinance||!$workspaceAllowed)return;
if(isset($_GET['new_entry'])&&!\Kulturhus\Verein\Web\InvoiceInboxSource::commandRequested($_POST))return;
if(input($_POST,'action')==='create-entry'&&!\Kulturhus\Verein\Web\InvoiceInboxSource::commandRequested($_POST))return;
if(isset($invoiceDraftReadState)){
    $invoiceInboxSourceState=$invoiceDraftReadState['source'];
    if(empty($invoiceDraftReadState['legacy_failure']))http_response_code($invoiceDraftReadState['status']);
    elseif($invoiceInboxSourceState!==null&&!in_array($invoiceInboxSourceState['state'],['list','selected'],true))http_response_code($invoiceInboxSourceState['status']===403?403:422);
    return;
}
$invoiceInboxSourceState=\Kulturhus\Verein\Web\InvoiceInboxSource::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($invoiceInboxSourceState['state'],['list','selected'],true)){$invoiceInboxSourceState['command_error']=$error;$error='';http_response_code($invoiceInboxSourceState['status']===403?403:422);}
