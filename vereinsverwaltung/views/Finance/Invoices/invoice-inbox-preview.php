<?php declare(strict_types=1);
if($inboxSelected['mime']==='application/pdf'):
 $previewUrl=url(['document_file'=>$inboxId,'revision'=>$inboxSelected['revision'],'preview'=>1]); ?>
<section class="card"><h3>Rechnungsvorschau</h3><?php require __DIR__.'/../../Documents/pdf-viewer.php'; ?></section>
<?php $inspection=\Kulturhus\Verein\EInvoiceInspection::document($documents,$inboxId);if(($inspection['source']??'')==='embedded_xml'&&($inspection['state']??'')!=='unchecked'){$xmlDocumentId=$inboxId;require __DIR__.'/einvoice-inspection.php';} ?>
<?php elseif(in_array($inboxSelected['mime'],['image/png','image/jpeg','image/heic','image/heif'],true)): ?>
<section class="card"><h3>Rechnungsvorschau</h3><?php $imagePreviewUrl=url(['document_file'=>$inboxId,'revision'=>$inboxSelected['revision'],'preview'=>1]);$imagePreviewAlt='Rechnung: '.$inboxSelected['title'];require __DIR__.'/../../Communication/image-preview.php'; ?></section>
<?php elseif($inboxSelected['mime']==='application/xml'):$xmlDocumentId=$inboxId;require __DIR__.'/einvoice-inspection.php';endif ?>
