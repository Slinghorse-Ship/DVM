<?php declare(strict_types=1);
$pieceText=$pieceMode==='create'?($ivItemText??static fn(string $s):string=>$s):($ivMoveText??static fn(string $s):string=>$s);
$pieceDraft=is_array($pieceData['purchase_units']??null)?array_values($pieceData['purchase_units']):[];
$pieceEnabled=isset($inventoryPieceDraft)?$inventoryPieceDraft['enabled']:($pieceDraft||input($_POST,'piece_enabled')==='1');
$pieceLimit=\Kulturhus\Verein\Web\InventoryPieceForm::limit($canFinance);
?>
<section class="iv-piece-editor" data-piece-editor data-piece-mode="<?= e($pieceMode) ?>" data-piece-required="<?= !empty($item['has_purchase_units'])&&$pieceMode==='move'?'1':'0' ?>" data-piece-finance="<?= $canFinance?'1':'0' ?>" data-piece-stocks="<?= e(json_encode(array_column($item['stock']??[],'shelf','location_id'),JSON_THROW_ON_ERROR)) ?>">
<label class="check iv-editor-choice"><input class="check" type="checkbox" name="piece_enabled" value="1" data-piece-toggle <?= $pieceEnabled?'checked':'' ?>><?= e($pieceText('Seriennummern oder unterschiedliche Stückpreise eintragen')) ?></label>
<p class="muted"><?= e($pieceText('Optional: Jedes Stück erhält eigene Angaben; der Gegenstand bleibt ein gemeinsamer Eintrag.')) ?> <?= e($pieceText('Stück je Zugang (Höchstzahl):')) ?> <span data-piece-limit><?= $pieceLimit ?></span>.</p>
<p><?= e($pieceText('Menge und Lagerort auswählen, Stückzeilen vorbereiten und die Angaben je Stück prüfen. Erst das anschließende Speichern bestätigt den Bestand.')) ?></p>
<?php if(!empty($item['has_purchase_units'])&&$pieceMode==='move'): ?><p><?= e($pieceText('Dieser Gegenstand hat Stückdetails. Für jeden zusätzlichen Zugang ist eine Zeile je neuem Stück erforderlich.')) ?></p><?php endif ?>
<?php if($canFinance): ?><p><?= e($pieceText('Ein Preis benötigt einen Wertnachweis: je Stück oder gemeinsam für diesen Zugang.')) ?></p><?php endif ?>
<div class="iv-piece-actions"><button type="submit" class="btn btn-outline theme-secondary" name="piece_step" value="prepare" formnovalidate><?= e($pieceText('Stückzeilen vorbereiten / ergänzen')) ?></button><?php if($pieceDraft): ?><button type="submit" class="btn btn-outline theme-secondary" name="piece_step" value="discard" formnovalidate><?= e($pieceText('Alle Stückangaben verwerfen')) ?></button><?php endif ?></div>
<div data-piece-rows><?php foreach($pieceDraft as$pieceIndex=>$pieceRow): ?>
<div class="iv-piece-row"><h4><?= e($pieceText('Stück {number}',['number'=>(string)($pieceIndex+1)])) ?></h4>
<label><?= e($pieceText('Seriennummer (falls vorhanden)')) ?><input class="form-control" name="data[purchase_units][<?= $pieceIndex ?>][serial_number]" maxlength="120" value="<?= e($pieceRow['serial_number']??'') ?>"></label>
<?php if($canFinance): ?><label><?= e($pieceText('Preis dieses Stücks (€)')) ?><input class="form-control" name="data[purchase_units][<?= $pieceIndex ?>][unit_value]" maxlength="40" inputmode="decimal" value="<?= e($pieceRow['unit_value']??'') ?>"></label>
<label class="iv-piece-note"><?= e($pieceText('Abweichender Wertnachweis (optional)')) ?><input class="form-control" name="data[purchase_units][<?= $pieceIndex ?>][value_note]" maxlength="1000" value="<?= e($pieceRow['value_note']??'') ?>"></label><?php endif ?>
</div><?php endforeach ?></div><p data-piece-message role="status"></p>
<?php if($pieceMode==='move'&&$canFinance): ?><label data-piece-common-note><?= e($pieceText('Wertnachweis für diesen Zugang')) ?><textarea class="form-control" name="data[value_note]" maxlength="1000"><?= e($pieceData['value_note']??'') ?></textarea></label><?php endif ?>
</section><link rel="stylesheet" href="inventory-pieces.css?v=2"><script defer src="inventory-pieces.js?v=2"></script>
