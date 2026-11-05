<?php declare(strict_types=1);$it=$ivItemText; ?>
<?php if($ivWrite||$item): ?>
<details class="iv-editor-panel iv-item-metadata" id="inventory-item-metadata" data-inventory-panel="basics" <?= !$item||$itemPosted?'open':'' ?>>
 <summary><span><strong><?= e($it($item?'Angaben zum Gegenstand bearbeiten':'Gegenstand anlegen')) ?></strong><small><?= e($it('Bezeichnung, Einheit, Inhalt und weitere Angaben')) ?></small></span></summary>
 <div class="iv-editor-body">
 <form method="post" class="iv-no-print iv-item-form" action="<?= e(url($inventoryItemState['route']??(['view'=>'inventory','tab'=>'items','item'=>$itemId]))) ?>" <?= !$item&&!$cashCreate?'data-inventory-quantity-create':'' ?>>
 <?= isset($inventoryItemState['key'])?csrf().hidden('action','inventory-item').hidden('target',$itemId).hidden('version',$itemPosted?(string)$_POST['version']:(string)($item['version']??0)).hidden('request_key',$inventoryItemState['key']):ivPost('item',$itemId,(int)($item['version']??0)) ?>
 <?php if($cashCreate&&!$finance->cashEnabled())echo hidden('data[cash_account]',$cashSource); ?>
 <?php if($invoicePositionDraft)echo hidden('data[invoice_position]',(string)$invoicePositionDraft['ordinal']).hidden('data[invoice_entry_version]',$itemPosted?(string)($d['invoice_entry_version']??''):(string)$invoicePositionDraft['entry']['version']); ?>
 <fieldset <?= !$ivWrite?'disabled':'' ?>>
 <legend id="inventory-item-basics"><?= e($it('Bezeichnung und Einheit')) ?></legend>
 <div class="iv-form-grid">
 <?php ivField('data[name]',$it('Bezeichnung'),$d['name']??'','text',[],true);ivField('data[category]',$it('Kategorie'),$d['category']??'');ivField('data[unit]',$it('Einheit'),$d['unit']??'Stück','select',$unitOptions,true);
 if(!$item&&!$cashCreate){ivField('data[initial_quantity]',$it('Wie viele Einheiten sind vorhanden?'),$d['initial_quantity']??1,'number',[],true);ivField('data[home_location]',$it('Wo befinden sie sich jetzt?'),$d['home_location']??'','select',$locationOptions);}
 if(($item['tracking_mode']??'quantity')==='individual')ivField('data[serial_number]',$it('Seriennummer (falls vorhanden)'),$d['serial_number']??''); ?>
 </div>
 <?php if($item&&$ivWrite&&$item['active']): ?><p class="iv-editor-inline-help"><?= e($it('Die tatsächliche Anzahl oder den Lagerort ändern Sie über')) ?> <a href="#inventory-stock-change"><?= e($it('Menge und Lagerort ändern')) ?></a>.</p><?php endif ?>
 <details class="iv-editor-subsection" data-inventory-content <?= $itemPosted||(int)($d['content_quantity']??1)>1?'open':'' ?>>
  <summary><?= e($it('Inhalt eines Satzes oder einer Packung')) ?> <small data-inventory-content-summary><?= e($it('{quantity} {unit} je Einheit',['quantity'=>(string)($d['content_quantity']??1),'unit'=>$d['content_unit']??'Stück'])) ?></small></summary>
  <div class="iv-form-grid"><?php ivField('data[content_quantity]',$it('Wie viele Teile gehören zu einer Einheit?'),$d['content_quantity']??1,'number',[],true);ivField('data[content_unit]',$it('Einheit dieser Teile'),$d['content_unit']??'Stück','select',$contentUnitOptions); ?></div>
  <p class="muted"><?= e($it('Zum Beispiel: Ein Satz Podestfüße enthält 4 Stück. Bei 4 Sätzen werden insgesamt 16 Füße angezeigt. Ein einzelner Gegenstand enthält 1 Stück.')) ?></p>
 </details>
 <details class="iv-editor-subsection" data-inventory-extra <?= $itemPosted?'open':'' ?>>
  <summary><?= e($it('Weitere Angaben')) ?> <small><?= e($it('Zuständigkeit, Eigentum, Beschreibung und Warnschwelle')) ?></small></summary>
  <div class="iv-form-grid">
  <?php if($item)ivField('data[code]',$it('Inventarnummer'),$d['code']??'','text',[],true);ivField('data[kind]',$it('Verwendung'),$d['kind']??'reusable','select',['reusable'=>$it('Ausleihbarer Gegenstand'),'consumable'=>$it('Verbrauchsmaterial')]);ivField('data[minimum]',$it('Warnen, wenn weniger verfügbar als'),$d['minimum']??0,'number',[],true);ivField('data[acquired_on]',$it('Anschaffung / Zugang am'),$d['acquired_on']??'','date');ivField('data[ownership]',$it('Wem gehört der Gegenstand?'),$d['ownership']??'club','select',['club'=>$it('Unserem Verein'),'third_party'=>$it('Fremde Leihgabe')]);ivField('data[owner_id]',$it('Eigentümer bei fremder Leihgabe'),$d['owner_id']??'','select',\Kulturhus\Verein\Web\InventoryCatalogueForm::options($personOptions,(string)($d['owner_id']??''),$it('Ausgewählte Person nicht mehr verfügbar – bitte neu auswählen')));ivField('data[responsible_id]',$it('Zuständiges Mitglied / Kontakt'),$d['responsible_id']??'','select',\Kulturhus\Verein\Web\InventoryCatalogueForm::options($personOptions,(string)($d['responsible_id']??''),$it('Ausgewählte Person nicht mehr verfügbar – bitte neu auswählen')));ivField('data[active]',$it('Status'),$d['active']??1,'select',['1'=>$it('Aktiv'),'0'=>$it('Archiviert')]);ivField('data[condition_note]',$it('Zustand / Wartungshinweise'),$d['condition_note']??'','textarea');ivField('data[notes]',$it('Beschreibung / Zubehör'),$d['notes']??'','textarea'); ?>
  </div>
  <?php if(($item['tracking_mode']??'quantity')==='quantity'): ?><p class="muted"><?= e($it('Mengen werden in ganzen Einheiten erfasst. Für 1,5 Liter wähle Milliliter und erfasse 1500.')) ?></p><?php endif ?>
 </details>
 <?php if($canFinance): ?>
 <details class="iv-editor-subsection" id="inventory-purchase" <?= $itemPosted||$invoicePositionDraft?'open':'' ?>>
  <summary><?= e($it('Anschaffung & Preis')) ?> <small><?= e($it('Vorhandene Rechnung zuordnen und dokumentierten Wert eintragen')) ?></small></summary>
  <?php if($item)require __DIR__.'/inventory-purchase-sources.php'; ?>
  <div class="iv-form-grid" data-inventory-source-picker>
  <?php $sourcePickerForm='item';$sourcePickerName='data[source]';$sourcePickerValue=$selectedSource;require __DIR__.'/inventory-financial-source-picker.php';
  if(empty($item['has_purchase_units']))ivField('data[unit_value]',$it('Wert je vollständiger Einheit (€)'),$d['unit_value']??(isset($d['unit_value_cents'])?number_format((int)$d['unit_value_cents']/100,2,'.',''):''));else{ ?><p><?= e($it('Die Einzelpreise stehen unter')) ?> <a href="#inventory-piece-edit"><?= e($it('Stückangaben bearbeiten')) ?></a>.</p><?php }
  ivField('data[value_note]',$it('Wertnachweis / Aufteilung auf Einheiten'),$d['value_note']??'','textarea');
  if($finance->cashEnabled())ivField('data[cash_account]',$it('Verknüpftes Kassenkonto (physische Handkasse)'),$d['cash_account']??'','select',\Kulturhus\Verein\Web\InventoryCatalogueForm::options($cashOptions,(string)($d['cash_account']??''),$it('Ausgewählte Handkasse nicht mehr verfügbar – bitte neu auswählen')));elseif(!empty($item['cash_account'])){ ?><p><?= e($it('Die bisherige Kassenverknüpfung bleibt erhalten. Handkassen sind immer verfügbar.')) ?></p><?php } ?>
  <?php if(!$item&&!$cashCreate)ivField('data[counted]',$it('Nur bei Handkasse: bestätigter Zählbestand (€)'),$d['counted']??''); ?>
  </div>
  <details class="iv-editor-hint"><summary><?= e($it('Welchen Preis trage ich ein?')) ?></summary><p><?= e($it('Den Preis für eine vollständige Einheit laut Rechnung eintragen. Ein Satz mit 4 Füßen erhält den Preis dieses ganzen Satzes. Der Betrag in der Rechnungsauswahl ist dagegen der Gesamtbetrag des Belegs.')) ?></p><p><?= e($it('Wertnachweis zum Beispiel: „Rechnung vom 12.09.2026, Position 2: 4 Stück zu je 100 €“. Unterschiedliche Preise können Sie unter „Stückangaben“ eintragen.')) ?></p></details>
  <?php if(!empty($item['source_entry'])): ?><p><a href="<?= e(url(['view'=>'finance','tab'=>'receipts','entry'=>$item['source_entry']])) ?>"><?= e($it('Rechnung in Finanzen öffnen →')) ?></a></p><?php elseif(!empty($item['source_goods'])): ?><p><a href="<?= e(url(['view'=>'donations','tab'=>'goods','goods'=>$item['source_goods']])) ?>"><?= e($it('Sachspende öffnen →')) ?></a></p><?php endif ?>
 </details>
 <?php endif ?>
 <?php if(!$item&&!$cashCreate&&$ivWrite){$pieceMode='create';$pieceData=$d;require __DIR__.'/inventory-piece-editor.php';} ?>
 <?php if($item)require __DIR__.'/inventory-piece-correction.php'; ?>
 <?php if(!$item&&!$cashCreate&&$ivWrite)echo hidden('piece_form_complete','1'); ?>
 <?= hidden('item_form_complete','1') ?>
 <?php if($ivWrite): ?><div class="settings-actions iv-item-save-actions"><button class="btn btn-solid theme-primary"><?= e($it('Gegenstand speichern')) ?></button><?php if(!$item&&$finance->canWrite()): ?><button class="btn btn-outline theme-secondary secondary" name="after_save" value="invoice-upload"><?= e($it('Speichern und Rechnung hochladen')) ?></button><?php endif ?></div><p class="muted"><?= e($it($item?'Speichert die Angaben in diesem Abschnitt. Bestandsänderungen werden separat bestätigt.':'Die Inventarnummer wird automatisch vergeben. Der eingetragene Anfangsbestand erscheint anschließend unter „Wo ist was?“.')) ?></p><?php endif ?>
 </fieldset>
 </form>
 </div>
</details>
<?php endif ?>
