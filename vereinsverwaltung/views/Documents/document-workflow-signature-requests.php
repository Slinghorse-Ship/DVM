<?php declare(strict_types=1);
$wfSignaturePage=$wfPrepared['pages']['signatures']??null;$wfSignatureRows=$wfSignaturePage['rows']??[];
$wfSignatureNames=$wfPrepared['signature_names']??[];
$wfCanRequestSignature=$wfWrite&&!$wfHistoric&&$wfDoc['status']==='approved';
?>
<section class="dwf-card dwf-signature-requests"><h3><?= $wfEsc($wfI('Unterschriften der Mitglieder')) ?></h3>
<?php $wfPageError('signatures',['wf_document_signatures_page'=>1]);$wfPager('signatures','wf_document_signatures_page',['wf_doc'=>$wfDoc['id'],'wf_revision'=>$wfRevision??'']); ?>
<?php if($wfSignatureRows): ?><ul data-dwf-signature-evidence><?php foreach($wfSignatureRows as$sig): ?><li><?= $wfEsc(($sig['signer_name']??'')!==''?$sig['signer_name']:($wfSignatureNames[$sig['recipient_id']]??$wfI('Nicht verfügbar'))) ?> · <?= $wfEsc($sig['signed_at']?$wfF('Unterschrieben am {date}',['date'=>$wfDate($sig['signed_at'])]):(((int)$wfDoc['version']===(int)$sig['revision']&&$wfDoc['status']==='approved'&&!$wfHistoric)?$wfI('Unterschrift offen'):$wfI('Frühere Fassung · nicht mehr unterschreibbar'))) ?></li><?php endforeach ?></ul><?php elseif($wfSignaturePage!==null): ?><p><?= $wfEsc($wfI('Noch keine Unterschriften angefordert.')) ?></p><?php endif ?>
<?php $wfPager('signatures','wf_document_signatures_page',['wf_doc'=>$wfDoc['id'],'wf_revision'=>$wfRevision??'']); ?>
<?php if($wfCanRequestSignature):
$wfSignatureMembersPage=$wfPrepared['pages']['signature_members']??null;
$wfRequestPost=$wfPosted('document-workflow-signature-request',$wfDoc['id']);
$wfSelected=$wfRequestPost&&is_array($_POST['wf_recipients']??null)?array_values(array_filter(array_slice($_POST['wf_recipients'],0,100),static fn(mixed $id):bool=>is_string($id)&&preg_match('/\A[A-Za-z0-9_.:@-]{1,128}\z/D',$id)===1)):[];
$wfMemberRows=$wfSignatureMembersPage['rows']??[];$wfOtherSelected=array_values(array_diff($wfSelected,array_column($wfMemberRows,'id')));
$wfPageError('signature_members',['wf_signature_members_page'=>1]);$wfPager('signature_members','wf_signature_members_page',['wf_doc'=>$wfDoc['id']]);
if($wfSignatureMembersPage!==null): ?>
<form method="post" action="<?= $wfEsc($wfUrl(['wf_doc'=>$wfDoc['id']])) ?>" data-dwf-edit-form data-dwf-signature-request-form><?php $wfForm('document-workflow-signature-request',$wfDoc['id'],(int)$wfDoc['version']); ?>
<fieldset><legend><?= $wfEsc($wfI('Mitglieder auswählen')) ?></legend><p><?= $wfEsc($wfF('Geprüfte Dokumentfassung {revision}',['revision'=>$wfVersion('document-workflow-signature-request',$wfDoc['id'],(int)$wfDoc['version'])])) ?></p><p><?= $wfEsc($wfI('Bis100 verschiedene Personen je Anforderung. Auswahl anfordern, bevor du die Seite wechselst.')) ?></p>
<?php if($wfMemberRows): ?><button type="button" hidden class="btn btn-outline theme-secondary dwf-button dwf-secondary" data-dwf-signature-all><?= $wfEsc($wfI('Alle auf dieser Seite auswählen')) ?></button><div class="dwf-signature-members"><?php foreach($wfMemberRows as$member): ?><label class="dwf-check"><input class="check" type="checkbox" name="wf_recipients[]" value="<?= $wfEsc($member['id']) ?>" <?= in_array($member['id'],$wfSelected,true)?'checked':'' ?>><span><?= $wfEsc($member['name']) ?></span></label><?php endforeach ?></div><?php else: ?><p><?= $wfEsc($wfI('Für diese Fassung sind keine weiteren verfügbaren Personen vorhanden.')) ?></p><?php endif ?>
<?php if($wfOtherSelected): ?><section class="dwf-signature-previous-selection"><h4><?= $wfEsc($wfI('Deine bisherige Auswahl')) ?></h4><p><?= $wfEsc($wfI('Diese Personen waren im abgewiesenen Formular ausgewählt. Bereits vorhandene Anforderungen werden nicht doppelt angelegt.')) ?></p><?php foreach($wfOtherSelected as$selected): ?><?php if(isset($wfSignatureNames[$selected])): ?><label class="dwf-check"><input class="check" type="checkbox" name="wf_recipients[]" value="<?= $wfEsc($selected) ?>" checked><span><?= $wfEsc($wfSignatureNames[$selected]) ?></span></label><?php else: ?><p><?= $wfEsc($wfI('Eine ausgewählte Person ist nicht mehr verfügbar. Bitte die Auswahl prüfen.')) ?></p><?php endif ?><?php endforeach ?></section><?php endif ?>
</fieldset><button class="btn btn-solid theme-primary dwf-button" <?= !$wfMemberRows&&!$wfOtherSelected?'disabled':'' ?>><?= $wfEsc($wfI('Unterschriften anfordern')) ?></button>
</form><?php endif;endif ?>
<?php if(isset($workflowReminder)&&$wfCanRequestSignature): ?><form method="post" action="<?= $wfEsc($wfUrl(['wf_doc'=>$wfDoc['id']])) ?>"><?php $wfForm('document-workflow-mail-preview',$wfDoc['id'],(int)$wfDoc['version']); ?><button class="btn btn-outline theme-secondary dwf-button dwf-secondary"><?= $wfEsc($wfI('Offene Unterschriften per Mail erinnern')) ?></button></form><?php endif ?>
<?php if(isset($workflowReminder)&&$wfWrite)require __DIR__.'/document-workflow-reminders.php'; ?>
</section>
