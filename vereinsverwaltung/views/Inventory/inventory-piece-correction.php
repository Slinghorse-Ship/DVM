<?php declare(strict_types=1);
if(!$item||!$item['purchase_units']||!$ivWrite)return;
$pc=\Kulturhus\Verein\Web\InventoryPieceCorrectionForm::class;$ct=$ivItemText;
$pieceEdits=is_array($d['purchase_unit_updates']??null)?$d['purchase_unit_updates']:[];$pieceEditing=(bool)$pieceEdits;
$piecePage=isset($inventoryPieceCorrectionDraft)?$inventoryPieceCorrectionDraft['page']:$pc::page($_GET,$item);$piecePages=max(1,(int)ceil(count($item['purchase_units'])/$pc::limit()));$visiblePieces=$pc::rows($item,$piecePage);
$pieceStock=isset($inventoryPieceCorrectionDraft)?$inventoryPieceCorrectionDraft['stock_version']:($itemPosted?input($_POST,'piece_correction_stock_version'):(string)$item['stock_version']);$pieceChosen=isset($inventoryPieceCorrectionDraft)?$inventoryPieceCorrectionDraft['enabled']:($pieceEditing||input($_POST,'piece_correction_enabled')==='1');
?>
<details class="iv-editor-subsection" id="inventory-piece-edit" data-piece-correction <?= $pieceChosen||isset($_GET['piece_page'])||isset($inventoryPieceCorrectionDraft)?'open':'' ?>>
<summary><?= e($ct('Stückangaben bearbeiten')) ?> <small><?= e($ct('{count} Stück · Seriennummern und dokumentierte Einzelpreise',['count'=>(string)count($item['purchase_units'])])) ?></small></summary>
<p><?= e($ct('Diese Angaben gehören zu den vorhandenen Stücken. Eine Korrektur ändert keine Menge, keinen Lagerort und keine Rechnung.')) ?> <a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'inventory','tab'=>'items','item'=>$itemId,'stock_edit'=>1])) ?>#inventory-stock-change"><?= e($ct('Menge und Lagerort ändern')) ?></a></p>
<?= hidden('piece_correction_page',(string)$piecePage).hidden('piece_correction_stock_version',$pieceStock) ?>
<label class="iv-editor-choice"><input class="check" type="checkbox" name="piece_correction_enabled" value="1" data-piece-correction-toggle <?= $pieceChosen?'checked':'' ?>><?= e($ct('Seriennummern und Preise zum Bearbeiten freigeben')) ?></label>
<div class="iv-piece-correction-actions"><button type="submit" class="btn btn-outline theme-secondary" name="piece_correction_step" value="open" formnovalidate><?= e($ct('Stückbearbeitung öffnen')) ?></button><?php if($pieceEditing||$pieceChosen): ?><button type="submit" class="btn btn-outline theme-secondary" name="piece_correction_step" value="discard" formnovalidate><?= e($ct('Stückkorrekturen verwerfen')) ?></button><?php endif ?></div>
<p><?= e($ct('Öffnen allein speichert nichts. Anschließend die Angaben prüfen, den Korrekturgrund eintragen und unten „Gegenstand speichern“ wählen.')) ?></p>
<fieldset data-piece-correction-fields <?= !$pieceEditing?'disabled hidden':'' ?>>
<?= hidden('data[purchase_stock_version]',$pieceEditing?(string)($d['purchase_stock_version']??''):$pieceStock) ?>
<?php foreach($visiblePieces as$idx=>$piece):$edit=$pieceEdits[$piece['id']]??[];$prefix='data[purchase_unit_updates]['.$piece['id'].']'; ?>
<fieldset class="iv-piece-correction-row" data-piece-id="<?= e($piece['id']) ?>"><legend><?= e($ct('Stück {number}',['number'=>(string)(($piecePage-1)*$pc::limit()+$idx+1)])) ?></legend><div class="iv-form-grid">
<?php ivField($prefix.'[serial_number]',$ct('Seriennummer (falls vorhanden)'),$edit['serial_number']??$piece['serial_number']);if($finance->canWrite()){ivField($prefix.'[unit_value]',$ct('Preis dieses Stücks (€; leer = unbekannt)'),$edit['unit_value']??($piece['unit_value_cents']===null?'':number_format($piece['unit_value_cents']/100,2,',','')));ivField($prefix.'[value_note]',$ct('Wertnachweis dieses Stücks'),$edit['value_note']??$piece['value_note'],'textarea');}elseif($canFinance){ ?><p class="iv-piece-saved-value"><?= e($ct('Gespeicherter Stückpreis:')) ?> <strong><?= $piece['unit_value_cents']===null?e($ct('Preis unbekannt')):euro((int)$piece['unit_value_cents']) ?></strong><?php if($piece['value_note']!==''): ?><span><?= e($piece['value_note']) ?></span><?php endif ?><small><?= e($ct('Für Preisänderungen ist Finanz-Bearbeitungsrecht erforderlich. Die Seriennummer kann separat geändert werden.')) ?></small></p><?php } ?>
</div></fieldset><?php endforeach ?>
<?php ivField('data[purchase_change_reason]',$ct('Grund der Korrektur'),$d['purchase_change_reason']??'','textarea',[],true); ?>
</fieldset>
<?php if($piecePages>1): ?><nav class="iv-piece-pages" aria-label="<?= e($ct('Stückseiten')) ?>"><p><?= e($ct('Stückangaben · Seite {page} von {pages}',['page'=>(string)$piecePage,'pages'=>(string)$piecePages])) ?></p>
<?php if($piecePage>1): ?><button type="submit" class="btn btn-outline theme-secondary" name="piece_correction_step" value="page:previous" formnovalidate><?= e($ct('Vorherige Stückseite')) ?></button><?php endif ?>
<?php if($piecePage<$piecePages): ?><button type="submit" class="btn btn-outline theme-secondary" name="piece_correction_step" value="page:next" formnovalidate><?= e($ct('Nächste Stückseite')) ?></button><?php endif ?>
<label><?= e($ct('Stückseite')) ?><input class="form-control" type="number" name="piece_correction_target_page" value="<?= e(isset($inventoryPieceCorrectionDraft)?(string)$piecePage:($itemPosted?input($_POST,'piece_correction_target_page',(string)$piecePage):(string)$piecePage)) ?>" min="1" max="<?= $piecePages ?>" step="1"></label><button type="submit" class="btn btn-outline theme-secondary" name="piece_correction_step" value="page:show" formnovalidate><?= e($ct('Stückseite anzeigen')) ?></button>
<p><?= e($ct('Vor dem Seitenwechsel Stückkorrekturen speichern oder ausdrücklich verwerfen. Übrige Gegenstandsangaben bleiben beim Blättern erhalten.')) ?></p></nav><?php endif ?>
<p class="muted"><?= e($ct('Erst „Gegenstand speichern“ bestätigt die Korrektur. Die Stückzahl bleibt erhalten.')) ?></p>
</details><script defer src="inventory-piece-correction.js?v=2"></script>
