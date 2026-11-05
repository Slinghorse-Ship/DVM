<?php declare(strict_types=1);
if(!$workflowManager)throw new DomainException('Gefährdungsbeurteilungen sind nicht eingerichtet.');
if(str_starts_with($action,'document-workflow-inspection-')){
    $inspectionRoute=\Kulturhus\Verein\InspectionPage::handle($workflowManager->inspections,$_POST);
    $_SESSION['notice']='Prüfauftrag gespeichert.';redirectTo($inspectionRoute);
}
if(in_array($action,['document-workflow-document-save','document-workflow-document-compose'],true)&&array_key_exists('wf_edit',$_POST)){
    $workflowDocumentDraft=\Kulturhus\Verein\Documents\WorkflowDocumentForm::compose($workflowStore,$_POST);$_POST=$workflowDocumentDraft['post'];$action='document-workflow-document-compose';
}elseif(in_array($action,['document-workflow-template-save','document-workflow-template-compose'],true)&&array_key_exists('wf_edit',$_POST)){
    $workflowTemplateDraft=\Kulturhus\Verein\Documents\WorkflowTemplateForm::compose($workflowStore,$_POST);$_POST=$workflowTemplateDraft['post'];$action='document-workflow-template-compose';
}elseif($action==='document-workflow-mail-preview'){
    $workflowMailPreview=$workflowReminder->preview(input($_POST,'wf_id'),(int)input($_POST,'wf_version'));
    $_SESSION['workflow_mail_preview']=['actor'=>$_SESSION['actor'],'expires'=>time()+900,'hash'=>$workflowMailPreview['hash'],'document'=>$workflowMailPreview['document_id'],'revision'=>$workflowMailPreview['revision'],'key'=>bin2hex(random_bytes(16)),'preview'=>$workflowMailPreview,'content'=>$workflowMailPreview['recipients']?\Kulturhus\Verein\WorkflowReminderMail::content($workflowMailPreview['recipients'][0],(string)getenv('VEREIN_PUBLIC_URL')):null];
}elseif($action==='document-workflow-mail-confirm'){
    $wfPending=\Kulturhus\Verein\Documents\WorkflowReminderForm::pending($_SESSION['workflow_mail_preview']??null,(string)$_SESSION['actor'],$_POST,time());
    if(input($_POST,'wf_id')===''){$_POST['wf_id']=$wfPending['document'];$_POST['wf_version']=(string)$wfPending['revision'];}
    $workflowMailPreview=\Kulturhus\Verein\Documents\WorkflowReminderForm::original($wfPending,(string)$_SESSION['actor'],$wfPending['document'],(int)$wfPending['revision'],time());
    if(input($_POST,'wf_mail_confirm')!=='1')throw new DomainException('Erinnerung erneut prüfen und Versand bestätigen.');
    $wfCount=$workflowReminder->queue($wfPending['document'],(int)$wfPending['revision'],$wfPending['hash'],$wfPending['key']);unset($_SESSION['workflow_mail_preview']);
    $_SESSION['notice']=\Kulturhus\Verein\Documents\HseInterfaceText::format('{count} Erinnerungen zum Versand vorgemerkt.',['count'=>$wfCount],$documentActionMessages??[]);redirectTo(['view'=>'documents','tab'=>'workflows','wf_doc'=>$wfPending['document']]);
}elseif($action==='document-workflow-signature-archive'){
    $workflowManager->archive(input($_POST,'wf_sign'));$_SESSION['notice']=\Kulturhus\Verein\Documents\HseInterfaceText::text('PDF in Meine Belege abgelegt.',$documentActionMessages??[]);redirectTo(['view'=>'portal','tab'=>'documents']);
}else{
    if($action==='document-workflow-signature-sign'){$_POST=\Kulturhus\Verein\Documents\WorkflowSignatureForm::prepare($_POST,$_FILES);unset($_FILES['wf_signature_file']);}
    $wfRoute=\Kulturhus\Verein\DocumentWorkflowPage::handle($workflowStore,$_POST,$_FILES,$workflowSignatures);
    if($action==='document-workflow-signature-sign'){
        try{$workflowManager->archive(input($_POST,'wf_sign'));$_SESSION['notice']=\Kulturhus\Verein\Documents\HseInterfaceText::text('Unterschrieben. Das PDF liegt in Meine Belege.',$documentActionMessages??[]);}
        catch(Throwable){$_SESSION['notice']=\Kulturhus\Verein\Documents\HseInterfaceText::text('Unterschrift gespeichert. Das PDF konnte noch nicht abgelegt werden. Bitte „PDF ablegen“ erneut wählen.',$documentActionMessages??[]);}
    }else $_SESSION['notice']='Gespeichert.';
    redirectTo(array_replace($workflowBase,$wfRoute));
}
