<?php declare(strict_types=1);
require __DIR__.'/../../../src/Web/invoice-draft-labels.php';
$draft=$invoiceDraftReadState;$draftRaw=$draft['raw'];
$draftCompletion=is_string($draftRaw['invoice_form_complete']??null)?hidden('invoice_form_complete',$draftRaw['invoice_form_complete']):'';
$draftNames=['inbox_doc'=>'Originalrechnung','inbox_version'=>'Ursprüngliche Dateiversion','request_key'=>'Ursprüngliche Vorgangskennung','supplier_contact'=>'Gewählte Firma','supplier_version'=>'Firmenversion','supplier_reference'=>'Rechnungsnummer','description'=>'Beschreibung','counterparty'=>'Rechnungssteller','amount'=>'Betrag','document_date'=>'Rechnungsdatum','due_date'=>'Fällig am','category'=>'Kategorie','notes'=>'Notiz','purpose'=>'Vorgang','payment_source'=>'Bezahlt von','reimbursement_contact'=>'Erstattung an','invoice_recipient'=>'Rechnungsempfänger','classification_area'=>'Ausgabenbereich','classification_event_kind'=>'Veranstaltungsart','classification_asset_kind'=>'Gegenstandsart','classification_special'=>'Sonderfall','classification_input_tax'=>'Vorsteuerabzug','classification_vat_rate'=>'Steuersatz','use_invoice_positions'=>'Positionen verwenden','positions_reviewed'=>'Positionsprüfung','positions_rounding_reviewed'=>'Rundungsprüfung','positions_tax_total'=>'Umsatzsteuer-Gesamtsumme','invoice_positions'=>'Positionen','item_refs'=>'Inventarverknüpfungen','item_positions'=>'Inventarpositionen','tax_rate'=>'Steuersatz','quantity'=>'Menge','unit'=>'Einheit','unit_price'=>'Einzelpreis','price_basis'=>'Preisgrundlage','calculation_note'=>'Berechnungshinweis','source_hint'=>'Originalhinweis','sphere'=>'Sphäre','account'=>'Steuerkonto','booking_key'=>'BU-Schlüssel','input_tax'=>'Vorsteuerabzug','items'=>'Inventarverknüpfungen','new_item'=>'Neuer Inventargegenstand','home_location'=>'Lagerort','location_version'=>'Lagerortversion','content_quantity'=>'Teile je Einheit','content_unit'=>'Einheit der enthaltenen Teile','new_inventory'=>'Neuer Inventargegenstand','enabled'=>'Ausgewählt','name'=>'Bezeichnung','invoice_form_version'=>'Formularfassung','invoice_form_complete'=>'Formularabschlusskennung'];
$draftCodes=[
    'payment_source'=>['none'=>'Noch nicht bezahlt','club'=>'Verein','private_advance'=>'Mitglied privat'],
    'invoice_recipient'=>['club'=>'Verein','member'=>'Mitglied','other'=>'Andere Person','unknown'=>'Noch unklar'],
    'purpose'=>['general'=>'Keine besondere Vorgangsverknüpfung','insurance'=>'Versicherung','new_item'=>'Inventar / Anschaffung','mixed'=>'Mehrere Zwecke'],
    'classification_area'=>['membership'=>'Mitgliedschaft / Vereinswerbung','event'=>'Veranstaltung','club'=>'Allgemeine Vereinsarbeit','asset'=>'Gegenstand / Inventar','rent'=>'Vermietung / Geldanlage','sales'=>'Verkauf / aktive Sponsorenwerbung','uncertain'=>'Mehrere Zwecke / nicht sicher'],
    'classification_event_kind'=>['ideell'=>'Ideelle Veranstaltung','purpose'=>'Zweckbetrieb','business'=>'Wirtschaftlicher Geschäftsbetrieb'],
    'classification_asset_kind'=>['consumable'=>'Verbrauchsmaterial','equipment'=>'Ausstattung / langlebiger Gegenstand'],
    'classification_special'=>['none'=>'Kein Sonderfall','mixed'=>'Mehrere Zwecke','foreign'=>'Ausland','credit'=>'Gutschrift','longterm'=>'Längerfristig / unklar','unclear'=>'Noch unklar'],
    'classification_input_tax'=>['unknown'=>'Noch klären','none'=>'Kein Vorsteuerabzug','deductible'=>'Vorsteuerabzug geklärt'],
    'price_basis'=>['gross'=>'Brutto','net'=>'Netto'],
    'category'=>\Kulturhus\Verein\Finance::CATEGORIES,
];$draftCodes['input_tax']=$draftCodes['classification_input_tax'];
foreach(['use_invoice_positions','positions_reviewed','positions_rounding_reviewed','enabled']as$flag)$draftCodes[$flag]=['1'=>'Ja','0'=>'Nein'];
$draftDisplay=static function(mixed$value,string$field='')use(&$draftDisplay,$draftNames,$draftCodes,$invoiceDraftText):void{
    if(is_string($value)){echo '<pre>'.e(isset($draftCodes[$field][$value])?$invoiceDraftText($draftCodes[$field][$value]):$value).'</pre>';return;}
    if(!is_array($value)){echo '<pre>'.e((string)$value).'</pre>';return;}
    echo '<dl>';foreach($value as$key=>$child){echo '<dt>'.e(is_int($key)?(string)($key+1):$invoiceDraftText($draftNames[$key]??'Weitere ursprüngliche Angabe')).'</dt><dd>';$draftDisplay($child,is_string($key)?$key:'');echo '</dd>';}echo '</dl>';
};
?>
<link rel="stylesheet" href="invoice-draft-read.css?v=3">
<section class="card invoice-draft-read" data-invoice-draft-state="<?=e($draft['state'])?>">
<h2><?=e($invoiceDraftText('Rechnungsentwurf fortsetzen'))?></h2><p role="status"><?=e($invoiceDraftText($draft['message']))?></p>
<?php if($draft['state']==='incomplete'):?><p><?=e($invoiceEntryText('Die übermittelten Angaben sind unvollständig. Gehe im Browser zu deinen vollständigen Originaleingaben zurück und lasse die Formulargrenze prüfen. Aus diesem Teilstand kann keine Ausgabe gespeichert werden.'))?></p><?php else:?><p><?=e($invoiceDraftText('Die Angaben und ihre ursprünglichen Versionen bleiben erhalten. Keine andere Rechnung und kein neuer Vorgang wird zur Bestätigung eingesetzt.'))?></p><?php endif?>
<?php if($draft['document']!==null):?><p><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e(url($draft['original']))?>"><?=e($invoiceDraftText('Dieselbe Originalrechnung in neuem Tab lesen'))?></a></p><?php endif?>
<?php if($draft['document']===null&&($draft['source']['status']??403)!==403&&is_array($draft['source']['submitted']??null)):?>
<p><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" data-invoice-draft-submitted href="<?=e(url($draft['source']['submitted']))?>"><?=e($invoiceDraftText('Formularrechnung separat im neuen Tab lesen'))?></a></p>
<?php if($finance->canWrite()&&\Kulturhus\Verein\Web\InvoiceDraftRead::canRepeat($draftRaw)):?><form method="post" action="<?=e(url($draft['source']['submitted']))?>" data-invoice-draft-source-review>
<?=csrf().hidden('invoice_read','refresh')?><?php foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls($draftRaw,['invoice_form_complete'])as[$field,$value])echo hidden($field,$value);?>
<button class="btn btn-outline theme-secondary" name="invoice_read" value="refresh" formnovalidate><?=e($invoiceDraftText('Formularrechnung mit diesem Entwurf neu prüfen'))?></button>
<p><?=e($invoiceDraftText('Der ursprüngliche Entwurf wird erneut geprüft. Anschließend die Rechnung bewusst speichern.'))?></p>
<?=$draftCompletion?></form><?php endif?>
<?php endif?>
<?php if($draft['document']!==null&&in_array($draft['state'],['supplier','blocked'],true)&&$finance->canWrite()&&\Kulturhus\Verein\Web\InvoiceDraftRead::canRepeat($draftRaw)):?>
<?php if(($draftRaw['supplier_contact']??'')!==''):?><form method="post" data-invoice-draft-supplier-clear><?=csrf().hidden('invoice_read','refresh')?><?php foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls($draftRaw,['invoice_form_complete'])as[$name,$value])echo hidden($name,$value);?><button name="invoice_read" value="supplier_clear" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Firmenverknüpfung im Entwurf zurücknehmen'))?></button><p><?=e($invoiceEntryText('Die Rechnungsangaben bleiben erhalten. Die Firma wird damit nicht aus der Kontaktverwaltung gelöscht.'))?></p><?=$draftCompletion?></form><?php endif?>
<?php foreach($draftRaw['invoice_positions']??[]as$index=>$row):if(!is_array($row)||!isset($row['new_item']))continue;?><form method="post" data-invoice-draft-inventory-remove><?=csrf().hidden('invoice_read','refresh')?><?php foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls($draftRaw,['invoice_form_complete'])as[$name,$value])echo hidden($name,$value);?><p><?=e($invoiceEntryText('Position {number}',['number'=>(int)$index+1]))?></p><button name="invoice_read" value="position_inventory_remove:<?=e((string)$index)?>" formnovalidate class="btn btn-outline theme-secondary"><?=e($invoiceEntryText('Neue Inventaranlage im Entwurf zurücknehmen'))?></button><p><?=e($invoiceEntryText('Die Inventarangaben bleiben bis zur ausdrücklichen Rücknahme im ursprünglichen Entwurf erhalten. Es wird kein vorhandener Gegenstand gelöscht.'))?></p><?=$draftCompletion?></form><?php endforeach?>
<?php endif?>
<?php if($draft['first']!==null):?><p><a class="btn btn-solid theme-primary" target="_blank" rel="noopener" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$draft['first']['id']]))?>"><?=e($invoiceDraftText('Früher gespeicherten Beleg ansehen'))?></a></p><?php endif?>
<?php if($draft['can_company_review']):?>
<form method="post" data-invoice-draft-recovery-form>
<?=csrf().hidden('invoice_read','refresh')?><?php foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls($draftRaw,['supplier_contact','invoice_choices','invoice_form_complete'])as[$field,$value])echo hidden($field,$value);?>
<?php foreach($draftRaw['invoice_choices']??[]as$kind=>$cursor)if($kind!=='company')foreach(\Kulturhus\Verein\Web\InvoiceDraftRead::controls(['invoice_choices'=>[$kind=>$cursor]])as[$name,$value])echo hidden($name,$value);?>
<?php if(($draft['company_page']??null)!==null):$choiceKind='company';$choicePage=$draft['company_page'];$choiceTitle='Firma suchen';require __DIR__.'/invoice-choice-navigation.php';endif?>
<label><?=e($invoiceDraftText('Firma auswählen'))?><select class="form-control" name="supplier_contact">
<option value="" <?=($draftRaw['supplier_contact']??'')===''?'selected':''?>><?=e($invoiceDraftText('Firma auswählen'))?></option>
<?php if(($draftRaw['supplier_contact']??'')!==''&&$draft['company']===null):?><option value="<?=e($draftRaw['supplier_contact'])?>" selected><?=e($invoiceDraftText('Ursprüngliche Auswahl derzeit nicht verfügbar'))?> · <?=e($draftRaw['supplier_contact'])?></option><?php endif?>
<?php foreach($draft['companies']as$company):?><option value="<?=e($company['id'])?>" <?=($draftRaw['supplier_contact']??'')===$company['id']?'selected':''?>><?=e($company['name'])?></option><?php endforeach?></select></label>
<div class="invoice-draft-controls"><button class="btn btn-outline theme-secondary" name="invoice_read" value="supplier" formnovalidate><?=e($invoiceDraftText('Gewählte Firma im aktuellen Stand lesen'))?></button><button class="btn btn-outline theme-secondary" name="invoice_read" value="refresh" formnovalidate><?=e($invoiceDraftText('Firmenliste neu lesen – Entwurf behalten'))?></button></div>
<p><?=e($invoiceDraftText('Das Lesen aktualisiert nur die Auswahl im Entwurf. Erst Rechnung speichern legt die Ausgabe an.'))?></p>
<?=$draftCompletion?></form><?php endif?>
<details data-invoice-draft-details><summary><?=e($invoiceDraftText('Rechnungsangaben'))?></summary><dl>
<?php foreach($draftRaw as$field=>$value):if(in_array($field,['action','inbox_guided','invoice_read','inbox_doc','inbox_version','request_key','supplier_contact','supplier_version','invoice_form_version','invoice_form_complete'],true))continue;?>
<dt><?=e($invoiceDraftText($draftNames[$field]??'Weitere ursprüngliche Angabe'))?></dt><dd><?php $draftDisplay($value,(string)$field)?></dd>
<?php endforeach?></dl></details>
<details><summary><?=e($invoiceDraftText('Vorgangskennungen und ursprüngliche Versionen'))?></summary><dl><?php foreach(['inbox_doc','inbox_version','request_key','supplier_contact','supplier_version','invoice_form_version','invoice_form_complete']as$field):if(!array_key_exists($field,$draftRaw))continue;?><dt><?=e($invoiceDraftText($draftNames[$field]))?></dt><dd><?php $draftDisplay($draftRaw[$field],(string)$field)?></dd><?php endforeach?></dl></details></section>
