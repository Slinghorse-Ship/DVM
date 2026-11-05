<?php declare(strict_types=1);
$wfMailPending=$_SESSION['workflow_mail_preview']??null;
$wfMayRestorePreview=($_SERVER['REQUEST_METHOD']??'GET')==='GET'||($wfPrepared['post_action']==='document-workflow-mail-confirm'&&is_array($wfMailPending)&&is_string($wfMailPending['hash']??null)&&hash_equals($wfMailPending['hash'],$wfText($_POST,'wf_mail_hash')));
if($workflowMailPreview===null&&$wfMayRestorePreview)$workflowMailPreview=\Kulturhus\Verein\Documents\WorkflowReminderForm::original($wfMailPending,$wfActor['id'],$wfDoc['id'],(int)$wfDoc['revision'],time());
if($workflowMailPreview!==null&&($workflowMailPreview['document_id']!==$wfDoc['id']||(int)$workflowMailPreview['revision']!==(int)$wfDoc['revision']))$workflowMailPreview=null;
if($workflowMailPreview!==null):$preview=$workflowMailPreview;
$wfPreviewPeople=array_map(static fn(array $recipient):array=>$recipient+['missing'=>false],$preview['recipients']);foreach($preview['missing']as$name)$wfPreviewPeople[]=['name'=>$name,'email'=>'','missing'=>true];
$wfPreviewPage=isset($wfPrepared['page_errors']['preview_recipients'])?null:\Kulturhus\Verein\DocumentWorkflow\WorkflowList::rows($wfPreviewPeople,$_GET['wf_preview_recipients_page']??1);
$wfPreviewPager=static function()use($wfPreviewPage,$workflowBase,$wfBrowseContext,$wfDoc,$wfI):void{echo \Kulturhus\Verein\Documents\WorkflowPager::render($wfPreviewPage,'wf_preview_recipients_page',array_replace($workflowBase,$wfBrowseContext,['wf_doc'=>$wfDoc['id']]),$wfI);};
?>
<section class="dwf-card dwf-reminder-preview" data-dwf-reminder-preview><h3><?= $wfEsc($wfI('Erinnerung prüfen')) ?></h3>
<p><?= $wfEsc($wfF('Geprüfte Dokumentfassung {revision}',['revision'=>$preview['revision']])) ?></p><p><?= $wfEsc($wfI('Diese Empfänger und diese Nachricht stammen aus deiner ausdrücklichen Prüfung. Geänderte Angaben erfordern eine neue Vorschau.')) ?></p>
<?php $wfPageError('preview_recipients',['wf_preview_recipients_page'=>1]);$wfPreviewPager(); ?>
<?php if($wfPreviewPage!==null&&$wfPreviewPage['rows']): ?><ul data-dwf-reminder-recipients><?php foreach($wfPreviewPage['rows']as$recipient): ?><li><?= $wfEsc($recipient['name']) ?> · <?= $wfEsc($recipient['missing']?$wfI('Ohne E-Mail-Adresse'):$recipient['email']) ?></li><?php endforeach ?></ul><?php endif ?>
<?php $wfPreviewPager(); ?>
<?php if(!$preview['recipients']): ?><p><?= $wfEsc($wfI('Keine offenen Unterschriften mit verfügbarer E-Mail-Adresse. Bereits vorgemerkte oder heute versendete Erinnerungen werden nicht doppelt versendet.')) ?></p>
<?php else:
$wfOriginalMail=is_array($wfMailPending)&&($wfMailPending['hash']??null)===$preview['hash']&&is_array($wfMailPending['content']??null)?$wfMailPending['content']:null;
if(is_array($wfOriginalMail)&&is_string($wfOriginalMail['subject']??null)&&is_string($wfOriginalMail['text']??null)): ?>
<h4><?= $wfEsc($wfI('Nachricht der Versandprüfung')) ?></h4><h5 data-dwf-original-mail-subject><?= $wfEsc($wfOriginalMail['subject']) ?></h5><p class="dwf-original-mail-text" data-dwf-original-mail-text><?= $wfEsc($wfOriginalMail['text']) ?></p><?php endif ?>
<?php if($wfCanRequestSignature&&$wfPreviewPage!==null): ?><form method="post" novalidate action="<?= $wfEsc($wfUrl(['wf_doc'=>$wfDoc['id']])) ?>" data-dwf-reminder-confirm><?php $wfForm('document-workflow-mail-confirm',$wfDoc['id'],(int)$preview['revision']);echo $wfHidden('wf_mail_hash',$preview['hash']).$wfHidden('wf_mail_context','1'); ?><label class="dwf-check"><input class="check" type="checkbox" name="wf_mail_confirm" value="1" required> <span><?= $wfEsc($wfI('Erinnerung an diese Personen versenden')) ?></span></label><button class="btn btn-solid theme-primary dwf-button"><?= $wfEsc($wfI('Versand bestätigen')) ?></button></form><?php endif ?>
<?php endif ?></section>
<?php elseif(is_array($wfMailPending)&&($wfMailPending['actor']??null)===$wfActor['id']&&($wfMailPending['document']??null)===$wfDoc['id']&&is_int($wfMailPending['expires']??null)&&$wfMailPending['expires']<time()): ?><p class="dwf-alert"><?= $wfEsc($wfI('Deine Erinnerungsvorschau ist abgelaufen. Bitte erneut prüfen.')) ?></p><?php endif ?>
<?php
$wfDeliveryPage=$wfPrepared['pages']['reminders']??null;$wfDeliveries=$wfDeliveryPage['rows']??[];
if($wfDeliveries||isset($wfPrepared['page_errors']['reminders'])):
$states=['pending'=>'Vorgemerkt','sending'=>'Versand läuft','sent'=>'Versendet','blocked'=>'Nicht versendet – erneut prüfen','uncertain'=>'Zustellung prüfen'];
$wfOwnedDeliveryErrors=['Zustellung vor erneutem Versand prüfen','Unterschrift, Empfänger oder Versandrecht erneut prüfen',...array_values(\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS)];
?>
<details class="dwf-reminder-deliveries" <?= isset($_GET['wf_reminders_page'])?'open':'' ?>><summary><?= $wfEsc($wfI('Mailversand')) ?></summary><?php $wfPageError('reminders',['wf_reminders_page'=>1]);$wfPager('reminders','wf_reminders_page',['wf_doc'=>$wfDoc['id'],'wf_revision'=>$wfRevision??'']); ?><ul><?php foreach($wfDeliveries as$delivery):$person=json_decode($delivery['snapshot_json'],true,64,JSON_THROW_ON_ERROR); ?><li><?= $wfEsc($person['name']) ?><?php if(is_string($person['email']??null)&&$person['email']!==''): ?> · <?= $wfEsc($person['email']) ?><?php endif ?> · <?= $wfEsc($wfI($states[$delivery['state']]??'Prüfen')) ?> · <?= $wfEsc($wfDate($delivery['updated_at'])) ?><?php if($delivery['error']!==''): ?> · <?= $wfEsc(in_array($delivery['error'],$wfOwnedDeliveryErrors,true)?$wfI($delivery['error']):$delivery['error']) ?><?php endif ?></li><?php endforeach ?></ul><?php $wfPager('reminders','wf_reminders_page',['wf_doc'=>$wfDoc['id'],'wf_revision'=>$wfRevision??'']); ?></details>
<?php endif ?>
