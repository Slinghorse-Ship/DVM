<?php declare(strict_types=1);
$inboxSourceMessages??=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$inboxSourceText=static fn(string $source):string=>\Kulturhus\Native\Language\InvoiceInboxSourceMessages::text($source,$inboxSourceMessages);
$inboxSourceLabels=['inbox_doc'=>'Ursprüngliche Rechnung','doc'=>'Ursprüngliche Rechnung','document_version'=>'Ursprüngliche Dateiversion','inbox_version'=>'Ursprüngliche Dateiversion','expense'=>'Ausgabe und Version','inbox_guided'=>'Rechnungserfassung','status'=>'Stand','inbox_q'=>'Rechnungssuche','inbox_year'=>'Belegjahr','inbox_page'=>'Rechnungsseite','archive'=>'Archivieren / Wiederherstellen','reason'=>'Grund','assign_mode'=>'Ausgabenansicht','assign_q'=>'Ausgabensuche','assign_page'=>'Ausgabenseite','assign_expense'=>'Ursprüngliche Ausgabe und Version','entry_id'=>'Ursprünglicher Beleg','entry_version'=>'Ursprüngliche Belegversion','report_version'=>'Ursprünglicher Meldungsstand','request_key'=>'Ursprüngliche Vorgangskennung','paid_on'=>'Gemeldetes Zahlungsdatum','note'=>'Ursprüngliche Zahlungsnotiz','transaction_id'=>'Ursprünglicher Kontoumsatz','transaction_version'=>'Ursprüngliche Umsatzversion','amount'=>'Ursprünglicher Zuordnungsbetrag'];
?>
<link rel="stylesheet" href="invoice-inbox-source.css?v=1">
<div class="page-heading"><h1><?=e($inboxSourceText('Rechnungseingang prüfen'))?></h1></div>
<div class="content invoice-inbox-source" data-invoice-inbox-unavailable><section class="card">
<h2><?=e($inboxSourceText('Die ursprüngliche Auswahl ist nicht verfügbar'))?></h2>
<p role="alert"><?=e($inboxSourceText($invoiceInboxSourceState['message']))?></p>
<p><?=e($inboxSourceText('Es wurde keine andere Rechnung und keine andere Auswahl geöffnet.'))?></p>
<?php if($invoiceInboxSourceState['raw']):?><h3><?=e($inboxSourceText('Übermittelte Angaben'))?></h3><dl>
<?php foreach($invoiceInboxSourceState['raw']as$original):?><dt><?=e($inboxSourceText($inboxSourceLabels[$original['field']]))?> · <?=e($inboxSourceText($original['from']==='link'?'Angabe im Link':'Angabe im Formular'))?></dt><dd><?php if(!is_string($original['value'])):?><span><?=e($inboxSourceText('Mehrteilige Angabe'))?></span><?php endif;?><pre><?=e(is_string($original['value'])?$original['value']:json_encode($original['value'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR))?></pre></dd><?php endforeach;?></dl><?php endif;?>
<div class="actions">
<?php if($invoiceInboxSourceState['retry']):?><a class="btn btn-outline theme-secondary" data-invoice-inbox-retry href="<?=e(url($invoiceInboxSourceState['retry']+['inbox_read'=>bin2hex(random_bytes(8))]))?>"><?=e($inboxSourceText('Ursprünglichen Rechnungslink erneut öffnen'))?></a><?php endif;?>
<?php if($invoiceInboxSourceState['submitted']):?><a class="btn btn-outline theme-secondary" data-invoice-inbox-submitted href="<?=e(url($invoiceInboxSourceState['submitted']))?>"><?=e($inboxSourceText('Übermittelte Rechnung gesondert prüfen'))?></a><?php endif;?>
<a class="btn btn-outline theme-secondary" data-invoice-inbox-list href="<?=e(url($invoiceInboxSourceState['list_route']))?>"><?=e($inboxSourceText($invoiceInboxSourceState['list_keeps_filters']?'Rechnungsliste mit dieser Auswahl öffnen':'Rechnungsliste ohne Filter ausdrücklich öffnen'))?></a>
</div></section></div>
