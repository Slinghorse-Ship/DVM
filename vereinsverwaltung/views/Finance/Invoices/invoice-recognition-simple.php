<?php declare(strict_types=1);
require __DIR__.'/../../../src/Web/invoice-draft-labels.php';
$structuredInvoice=($inspection['state']??'')==='recognized'&&in_array($inspection['source']??'xml',['xml','embedded_xml'],true);
$invalidXml=($inboxSelected['mime']??'')==='application/xml'&&($inspection['state']??'')==='invalid';
$recognition=null;
if(!$structuredInvoice&&$inboxSelected['mime']!=='application/xml'&&class_exists('Kulturhus\\Verein\\InvoiceRecognition')){try{$recognition=(new \Kulturhus\Verein\InvoiceRecognition($db,(string)$_SESSION['actor']))->result($inboxId);}catch(DomainException){}}
$recognitionSeed=$structuredInvoice?['fields'=>$inspection['fields']??[],'items'=>[],'bank_accounts'=>$inspection['bank_accounts']??[],'warnings'=>$inspection['warnings']??[],'state'=>'ready','method'=>'xml','source'=>$inspection['source']??'xml']:($recognition??[]);
?>
<section data-invoice-entry-messages="<?=e(json_encode(\Kulturhus\Native\Language\InvoiceEntryMessages::client($invoiceEntryMessages),JSON_THROW_ON_ERROR))?>" class="iv-piece-editor invoice-recognition-simple" data-invoice-recognition data-simple-invoice data-posted="<?= $guidedPost?'1':'0' ?>" data-url="<?= e(url(['view'=>'finance','tab'=>'inbox','invoice_recognition'=>1])) ?>" data-doc="<?= e($inboxId) ?>" data-revision="<?= (int)$inboxSelected['revision'] ?>" data-seed="<?= e(json_encode($recognitionSeed,JSON_THROW_ON_ERROR)) ?>">
<?php if($structuredInvoice): ?><p><?=e($invoiceEntryText('Die XML-Datei schlägt Lieferant, Rechnungsnummer, Datum, Fälligkeit und Gesamtbetrag vor. Bitte die Angaben am Original prüfen. Es wird nur der Gesamtbetrag erfasst.'))?></p><?php else: ?><p><?=e($invoiceEntryText('„Rechnung auslesen“ ergänzt nur Lieferant, Rechnungsnummer, Datum, Fälligkeit, IBAN und Gesamtbetrag. Es wird nur der Gesamtbetrag erfasst.'))?></p><?php endif ?>
<?php if(!$invalidXml&&(!$structuredInvoice||$inboxSelected['mime']==='application/xml')): ?><button type="button" hidden class="btn btn-outline theme-secondary secondary" data-recognition-run><?=e($invoiceEntryText('Rechnung auslesen'))?></button><?php endif ?>
<p role="status" data-recognition-status></p><div data-recognition-fields></div>
</section><script defer src="invoice-entry-text.js?v=1"></script><script defer src="invoice-recognition.js?v=31"></script>
