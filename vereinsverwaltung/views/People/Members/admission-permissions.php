<?php declare(strict_types=1);if(!$admissionVotes->isSuperuser())return;$boardById=array_column($admissionVotes->board(),null,'actor_id'); ?>
<section class="card"><h2>Vorstand · Über Aufnahmen abstimmen</h2><p>Dieses persönliche Recht ausschließlich aktuellen Vorstandsmitgliedern geben. Es gewährt Zugriff auf die zur Abstimmung vorgelegten Anträge, keine allgemeine Mitglieder- oder Finanzbearbeitung.</p>
<?php foreach(isset($selectedBoardUser)?[$selectedBoardUser]:$users as $user):
 if($user['role']==='editor')continue;
 $boardEntry=$boardById[$user['id']]??null;$savedOffice=\Kulturhus\Verein\AdmissionVotes::officeName($boardEntry['office']??'');
 $posted=input($_POST,'action')==='admission-grant'&&input($_POST,'user_id')===$user['id'];
 $selectedOffice=$posted?\Kulturhus\Verein\AdmissionVotes::officeName(input($_POST,'office')):$savedOffice;
 $selectedEnabled=$posted?input($_POST,'enabled')==='1':(bool)($boardEntry['enabled']??false);$locked=!$user['active'];if($locked)$selectedEnabled=false;
 $selectedChecked=$posted?input($_POST,'board_confirmed')==='1':(!$locked&&(bool)($boardEntry['office_checked']??false));
 $savedCheck=($boardEntry['office_checked']??false)&&$selectedOffice===$savedOffice&&($locked||$selectedEnabled===(bool)($boardEntry['enabled']??false));
 ?>
<form method="post" class="filter-form" data-board-permission data-board-office="<?= e($savedOffice) ?>">
<?= csrf().hidden('action','admission-grant').hidden('user_id',$user['id']).hidden('version',$posted?(int)input($_POST,'version'):($boardEntry['version']??0)) ?>
<strong><?= e(isset($accountName)?$accountName($user['id']):$user['id']) ?></strong>
<?php if($locked): ?><p>Konto gesperrt · Stimmrecht entziehen oder Vorstandsamt entfernen</p><?php endif ?>
<label>Vorstandsamt<select class="form-control" name="office" data-board-office-select><option value=""><?= $locked||$savedOffice!==''?'Vorstandsamt entfernen':'Vorstandsamt auswählen' ?></option>
<?php foreach($locked?($savedOffice!==''?[$savedOffice]:[]):\Kulturhus\Verein\AdmissionVotes::OFFICES as $office): ?><option <?= $selectedOffice===$office?'selected':'' ?>><?= e($office) ?></option><?php endforeach ?>
</select></label>
<label class="check"><input class="check" type="checkbox" name="enabled" value="1" data-board-enabled<?= $locked?' disabled':'' ?> <?= $selectedEnabled?'checked':'' ?>>Über Aufnahmen abstimmen</label>
<label class="check"><input class="check" type="checkbox" name="board_confirmed" value="1" data-board-confirmed <?= $selectedChecked?'checked':'' ?> <?= !$locked&&($selectedEnabled||($selectedOffice!==''&&$selectedOffice!==$savedOffice))?'required':'' ?>>Aktuelles Vorstandsamt geprüft</label>
<p class="muted" data-board-check-status>
<?php if($savedCheck):$checkedAt=(new DateTimeImmutable($boardEntry['office_checked_at']))->setTimezone(new DateTimeZone('Europe/Berlin'));$checkedBy=isset($accountName)?$accountName($boardEntry['office_checked_by']):'Vereinsverwaltung'; ?>
<strong>Prüfung gespeichert:</strong> <?= e($savedOffice) ?> · <?= e($checkedAt->format('d.m.Y, H:i')) ?> Uhr · geprüft von <?= e($checkedBy) ?>.
<?php else: ?>Für dieses Amt ist noch keine aktuelle Prüfung gespeichert. Erst „Recht speichern“ übernimmt deine Bestätigung.<?php endif ?>
</p>
<p class="muted" data-board-check-changed hidden>Auswahl geändert. Prüfe das aktuelle Amt erneut und setze das Häkchen bewusst neu, bevor du das Recht freigibst.</p>
<button class="btn btn-solid theme-primary"><?= $locked?'Stimmrecht entziehen / Amt entfernen':'Recht speichern' ?></button></form>
<?php endforeach ?>
<p>Die Prüfung bleibt mit Amt, Zeitpunkt und prüfender Person gespeichert. Bei einem neuen Amt ist eine neue Bestätigung erforderlich. Unverändertes Speichern erhält die bestehende Prüfung.</p>
<p>Änderungen an der Vorstandsbesetzung sperren laufende Abstimmungen für weitere Stimmen. Bereits abgegebene Stimmen bleiben erhalten.</p><a href="?view=portal&amp;tab=application">Aufnahmen entscheiden →</a></section>
<script src="admission-permissions.js" defer></script>
