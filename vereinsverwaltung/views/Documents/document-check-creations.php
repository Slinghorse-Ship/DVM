<?php declare(strict_types=1);
if($ccState['mode']==='receipts'):$ccCreationPage=$ccState['receipt_page']; ?>
<h2><?= e($ccI('Meine Nachweis-Anlegevorgänge')) ?></h2><p><?= e($ccI('Prüfe hier zuerst das Ergebnis, wenn nach dem Speichern die Antwort fehlt. Dieselbe bestätigte Anfrage legt keinen zweiten Nachweis an.')) ?></p>
<?php $ccPager($ccCreationPage,'check_receipts_page',['check_receipts'=>1]); ?>
<?php if($ccCreationPage['rows']):?><ul class="check-creation-list" data-check-creation-list><?php foreach($ccCreationPage['rows']as$ccCreation):?><li><a href="<?= e($ccUrl(['check_receipt'=>$ccCreation['request_key']])) ?>"><?= e($ccCreation['title']??$ccI('Unbestätigter Anlegevorgang')) ?></a> · <?= e($ccTime($ccCreation['created_at'])) ?></li><?php endforeach ?></ul><?php else:?><p><?= e($ccI('Noch keine eigenen Nachweis-Anlegevorgänge.')) ?></p><?php endif ?>
<?php $ccPager($ccCreationPage,'check_receipts_page',['check_receipts'=>1]);return;endif;$ccReceipt=$ccState['receipt']; ?>
<h2><?= e($ccI('Anlegebestätigung')) ?></h2>
<?php if($ccReceipt===null):?><p role="status"><?= e($ccI('Für diese Kennung liegt noch keine bestätigte Anlage vor. Prüfe den Vorgang vor einem erneuten Anlegen.')) ?></p><?php else:?>
<section class="dwf-card" data-check-original-receipt><h3><?= e($ccReceipt['title']) ?></h3><p><?= e($ccTime($ccReceipt['created_at'])) ?> · <?= e($ccF('Fassung {version}',['version'=>1])) ?></p><?php $ccRecord($ccReceipt['original']); ?>
<?php if($ccReceipt['intent']['file']!==null):?><p><?= e($ccI('Ursprüngliche hochgeladene Datei')) ?>: <?= e($ccReceipt['intent']['file']['name']) ?></p><details><summary><?= e($ccI('Prüfsumme')) ?></summary><code><?= e($ccReceipt['intent']['file']['sha256']) ?></code></details><?php endif ?>
<p><?= e($ccI('Diese Bestätigung hält die zuerst gespeicherten Angaben fest. Spätere Bearbeitungen gehören zum aktuellen Nachweis und ändern diese Bestätigung nicht.')) ?></p><a class="btn btn-outline theme-secondary dwf-button" href="<?= e($ccUrl(['check'=>$ccReceipt['id']])) ?>"><?= e($ccI('Aktuellen gespeicherten Nachweis öffnen')) ?></a></section>
<?php endif ?>
