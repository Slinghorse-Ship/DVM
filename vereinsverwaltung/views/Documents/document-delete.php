<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Documents/InterfaceText.php';$documentText=\Kulturhus\Verein\Documents\InterfaceText::text(...);$documentFormat=\Kulturhus\Verein\Documents\InterfaceText::format(...);
if($deleteReceipt&&$documents->canTrashReceipt($deleteReceipt['id'])): ?>
<form method="post" class="card" data-confirm="<?= e($documentText('Diese nicht zugeordnete Rechnung in den Papierkorb verschieben?')) ?>" data-confirm-title="<?= e($documentText('Rechnung löschen')) ?>">
<?= csrf().hidden('action','document-receipt-delete').hidden('doc',$deleteReceipt['id']).hidden('version',$deleteReceipt['version']).hidden('return_context',$deleteReturn??'documents') ?>
<button type="submit" class="btn btn-outline theme-danger danger"><?= e($documentText('Rechnung löschen')) ?></button>
<small><?= e($documentText('Im Papierkorb wiederherstellbar. Die E-Mail im Postfach bleibt unverändert.')) ?></small>
</form>
<?php endif ?>
<?php if($deleteReceipt&&$documents->canPurgeReceipt($deleteReceipt['id'])): ?>
<form method="post" class="card" data-confirm="<?= e($documentText('Diese Rechnung mit allen Dateiversionen endgültig löschen? Sie kann nicht aus dem Papierkorb wiederhergestellt werden.')) ?>" data-confirm-title="<?= e($documentText('Endgültig löschen')) ?>" data-confirm-action="<?= e($documentText('Endgültig löschen')) ?>">
<?= csrf().hidden('action','document-receipt-purge').hidden('doc',$deleteReceipt['id']).hidden('version',$deleteReceipt['version']).hidden('return_context',$deleteReturn??'documents') ?>
<label class="checkbox"><input class="check" type="checkbox" name="delete_not_required" value="1" required> <?= e($documentText('Fehlimport oder Duplikat – kein benötigter Originalbeleg')) ?></label>
<button type="submit" class="btn btn-outline theme-danger danger"><?= e($documentText('Endgültig löschen')) ?></button>
<small><?= e($documentText('Das E-Mail-Postfach und gemeinsam genutzte Originalmails bleiben unverändert.')) ?></small>
</form>
<?php endif ?>
