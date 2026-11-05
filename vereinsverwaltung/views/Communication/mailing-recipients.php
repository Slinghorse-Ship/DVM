<?php declare(strict_types=1); ?>
<link rel="stylesheet" href="mailing-recipients.css?v=1">
<fieldset class="mailing-recipients" data-mailing-recipients data-recipient-messages="<?= e(json_encode(\Kulturhus\Native\Language\MailingMessages::browser($managerLanguageMessages??\Kulturhus\Native\Language\MailingMessages::messages('de')),JSON_THROW_ON_ERROR)) ?>">
<legend><?= e($t('1. Empfänger auswählen')) ?></legend>
<p><?= e($t('Höchstens 250 Personen. Die Auswahl wird mit dem Entwurf gespeichert. Eine gemeinsame E-Mail-Adresse erhält nur eine Nachricht.')) ?></p>
<div class="mailing-recipient-controls" hidden>
<label><?= e($t('Empfängerart')) ?><select class="form-control" data-recipient-group><option value="members"><?= e($t('Nur Vereinsmitglieder')) ?></option><option value="contacts"><?= e($t('Nur Kontakte')) ?></option><option value="selected" selected><?= e($t('Einzelne Personen')) ?></option></select></label>
<button class="btn btn-solid theme-primary" type="button" data-recipient-apply><?= e($t('Auswählen')) ?></button>
<p><?= e($t('„Auswählen“ ersetzt die bisherige Auswahl durch die gewählte Gruppe. Mitglieder: aktuell aktiv oder passiv. Kontakte: ohne Mitgliedschaft. Ehemalige sind nur einzeln auswählbar.')) ?></p>
<label><?= e($t('Person suchen und auswählen')) ?><input class="form-control" type="search" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="mailing-recipient-options" autocomplete="off" data-recipient-search placeholder="<?= e($t('Name oder E-Mail eingeben')) ?>"></label>
<div id="mailing-recipient-options" role="listbox" aria-label="<?= e($t('Gefundene Personen')) ?>" hidden></div>
<p data-recipient-status role="status" aria-live="polite"></p>
<h3><?= e($t('Ausgewählte Personen')) ?></h3><div data-recipient-selected></div>
</div>
<div data-recipient-fallback><p><?= e($t('Einzelne Personen ankreuzen. Ohne JavaScript steht diese vollständige Liste zur Verfügung.')) ?></p>
<?php foreach($s['contacts'] as $person): ?><label class="check" data-recipient-row data-group="<?= e($person['recipient_group']) ?>"><input class="check" type="checkbox" name="people[]" value="<?= e($person['id']) ?>" <?= in_array($person['id'],$mailingPeople,true)?'checked':'' ?>><?= e(($person['name']??$t('Ausgewählte Person nicht mehr verfügbar')).' · '.($person['email']!==''?$person['email']:$t('ohne E-Mail'))) ?></label><?php endforeach ?>
</div></fieldset>
<script src="mailing-recipients.js?v=2" defer></script>
