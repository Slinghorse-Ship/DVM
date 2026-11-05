<?php declare(strict_types=1);
// Existing authenticated manager session and its CSRF validation are authoritative.
require_once __DIR__.'/ManagerDocumentWorkflows.php';
if (($_GET['view']??'')==='documents'&&in_array($_GET['tab']??'',['workflows','inspections','checks'],true)) header('Referrer-Policy: same-origin');
require_once dirname(__DIR__, 1).'/Communication/WorkflowReminderMail.php';
$workflowManager=null;$workflowMailPreview=null;$workflowInspectionsReady=false;
if(\Kulturhus\Verein\ManagerDocumentWorkflows::installed($db)){
    $workflowGuard=($nativeManagerEnabled??false)?static function()use($nativeBridge):void{$nativeBridge->managerActor();}:null;
    $workflowManager=new \Kulturhus\Verein\ManagerDocumentWorkflows($db,(string)$_SESSION['actor'],$workflowGuard);
    $workflowStore=$workflowManager->store;$workflowSignatures=$workflowManager->signatures;
    $workflowInspectionsReady=\Kulturhus\Verein\DocumentWorkflow\InspectionStore::installed($db);
    $workflowReminder=new \Kulturhus\Verein\WorkflowReminderMail($db,(string)$_SESSION['actor'],(string)getenv('VEREIN_PUBLIC_URL'),$workflowGuard);
    $workflowBase=['view'=>'documents','tab'=>'workflows'];$workflowCsrf=$_SESSION['csrf'];
    if(($_GET['view']??'')==='documents'&&($_GET['tab']??'')==='workflows')$workflowBase=array_replace($workflowBase,\Kulturhus\Verein\Documents\WorkflowBrowseState::context($_GET));
    if(($_GET['view']??'')==='portal')$workflowBase=['view'=>'portal','tab'=>'signatures','wf_own'=>'1'];
}
if(isset($_GET['wf_creation_json'])||isset($_GET['wf_signature_pdf'])||isset($_GET['wf_archived_pdf'])||isset($_GET['wf_export'])||isset($_GET['wf_attachment'])){
    try{
        if(!$workflowManager)throw new DomainException('Beurteilungen nicht verfügbar.');
        if(isset($_GET['wf_creation_json'])){$receipt=$workflowStore->creationReceipt(input($_GET,'wf_creation_json'));if($receipt===null)throw new DomainException('Anlegevorgang nicht verfügbar.');$wfDownload=['mime'=>'application/json','name'=>'anlegevorgang-'.$receipt['request_key'].'.json','bytes'=>json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];}
        elseif(isset($_GET['wf_archived_pdf']))$wfDownload=$workflowManager->ownFile(input($_GET,'wf_archived_pdf'));
        elseif(isset($_GET['wf_signature_pdf']))$wfDownload=$workflowSignatures->ownPdf(input($_GET,'wf_signature_pdf'));
        elseif(isset($_GET['wf_attachment']))$wfDownload=$workflowStore->attachment(input($_GET,'wf_doc'),input($_GET,'wf_attachment'),(int)input($_GET,'wf_revision'));
        else $wfDownload=$workflowStore->export(input($_GET,'wf_export'),(int)input($_GET,'wf_revision'),input($_GET,'wf_format','pdf'));
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: '.$wfDownload['mime']);header('Content-Disposition: attachment; filename="beurteilung"; filename*=UTF-8\'\''.rawurlencode($wfDownload['name']));echo $wfDownload['bytes'];
    }catch(DomainException){http_response_code(403);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');$wfDownloadError='Dokument nicht verfügbar.';if(isset($nativeAccess))try{$wfDownloadError=\Kulturhus\Verein\Documents\HseInterfaceText::text($wfDownloadError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}echo $wfDownloadError;}exit;
}
