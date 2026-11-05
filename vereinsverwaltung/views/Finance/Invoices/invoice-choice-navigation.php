<?php declare(strict_types=1);
// One original invoice form: buttons only request the existing read-only draft path.
$choiceCursor=\Kulturhus\Verein\Web\InvoiceEntryChoices::cursors($_POST)[$choiceKind];
$choiceName='invoice_choices['.$choiceKind.']';
?>
<fieldset class="invoice-choice-navigation" data-invoice-choice="<?=e($choiceKind)?>"><legend><?=e($invoiceEntryText($choiceTitle))?></legend>
<div class="invoice-draft-controls"><label><?=e($invoiceEntryText('Suche im vollständigen Bestand'))?><input class="form-control" type="search" name="<?=e($choiceName)?>[query]" maxlength="150" value="<?=e($choiceCursor['query'])?>"></label>
<?php if($choiceKind==='event'):?><label class="invoice-choice-card"><input class="check" type="checkbox" name="<?=e($choiceName)?>[archive]" value="1" <?=$choiceCursor['archive']==='1'?'checked':''?>><?=e($invoiceEntryText('Vergangene Veranstaltungen suchen'))?></label><?php endif?>
<button name="invoice_read" value="choice_<?=e($choiceKind)?>_search" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Suchen'))?></button></div>
<p role="status"><?=e($invoiceEntryText('{count} Treffer · Seite {page} von {pages}',['count'=>$choicePage['total'],'page'=>$choicePage['page'],'pages'=>$choicePage['pages']]))?></p>
<div class="invoice-draft-controls"><button name="invoice_read" value="choice_<?=e($choiceKind)?>_previous" formnovalidate class="btn btn-outline theme-secondary" <?=$choicePage['page']===1?'disabled':''?>><?=e($invoiceEntryText('Zurück'))?></button><button name="invoice_read" value="choice_<?=e($choiceKind)?>_next" formnovalidate class="btn btn-outline theme-secondary" <?=$choicePage['page']===$choicePage['pages']?'disabled':''?>><?=e($invoiceEntryText('Weiter'))?></button><label><?=e($invoiceEntryText('Seite öffnen'))?><input class="form-control" type="number" name="<?=e($choiceName)?>[page]" min="1" max="<?=e((string)$choicePage['pages'])?>" value="<?=e((string)$choicePage['page'])?>"></label><button name="invoice_read" value="choice_<?=e($choiceKind)?>_page" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Öffnen'))?></button></div>
<p><?=e($invoiceEntryText('Suchen und Blättern behalten alle Rechnungsangaben und die ursprüngliche Auswahl. Es wird noch nichts gespeichert.'))?></p></fieldset>
