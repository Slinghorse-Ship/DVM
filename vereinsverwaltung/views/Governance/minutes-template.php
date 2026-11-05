<?php declare(strict_types=1); ?>
<details class="minutes-template" data-minutes-template hidden>
 <summary><span><strong><?=e($minutesText('Mit einer Protokollvorlage beginnen'))?></strong><small><?=e($minutesText('Sitzungsangaben und Tagesordnungspunkte eingeben – daraus entsteht der bearbeitbare Protokolltext.'))?></small></span></summary>
 <div class="minutes-template-body">
  <p><?=e($minutesText('Wähle die passende Vorlage. Trage nur ein, was tatsächlich stattgefunden hat. Vorhandener Protokolltext bleibt erhalten; neue Angaben werden am Ende ergänzt.'))?></p>
  <label><?=e($minutesText('Vorlage'))?><select class="form-control" data-minutes-template-kind data-no-select-search><option value="assembly"><?=e($minutesText('Mitgliederversammlung'))?></option><option value="board"><?=e($minutesText('Vorstandsversammlung'))?></option><option value="meeting"><?=e($minutesText('Besprechung / Arbeitstreffen'))?></option></select></label>
  <div class="minutes-template-meta">
   <label><?=e($minutesText('Ort'))?><input class="form-control" data-minutes-meta="Ort" maxlength="300" placeholder="<?=e($minutesText('Zum Beispiel Vereinsraum'))?>"></label>
   <label><?=e($minutesText('Sitzungsleitung'))?><input class="form-control" data-minutes-meta="Sitzungsleitung" maxlength="200" placeholder="<?=e($minutesText('Vor- und Nachname'))?>"></label>
   <label><?=e($minutesText('Protokollführung'))?><input class="form-control" data-minutes-meta="Protokollführung" maxlength="200" placeholder="<?=e($minutesText('Vor- und Nachname'))?>"></label>
   <label><?=e($minutesText('Beginn'))?><input class="form-control" type="time" data-minutes-meta="Beginn"></label>
   <label><?=e($minutesText('Ende'))?><input class="form-control" type="time" data-minutes-meta="Ende"></label>
   <label class="wide"><?=e($minutesText('Anwesende / Hinweis auf Anwesenheitsliste'))?><textarea class="form-control" data-minutes-meta="Anwesende" rows="3" maxlength="5000" placeholder="<?=e($minutesText('Namen oder Hinweis auf eine separat aufbewahrte Anwesenheitsliste'))?>"></textarea></label>
  </div>
  <h3><?=e($minutesText('Tagesordnung und Ergebnisse'))?></h3><p><?=e($minutesText('Ein Feld pro Tagesordnungspunkt. Beschlüsse und Abstimmungsergebnisse nur ergänzen, wenn es sie gab.'))?></p>
  <div data-minutes-top-list></div>
  <button class="btn btn-outline theme-secondary secondary" type="button" data-minutes-add-top><?=e($minutesText('+ Tagesordnungspunkt hinzufügen'))?></button>
  <div class="minutes-template-actions"><button class="btn btn-solid theme-primary" type="button" data-minutes-apply-template><?=e($minutesText('Vorlage in den Protokolltext übernehmen'))?></button><span role="status" data-minutes-template-status><?=e($minutesText('Die Angaben werden beim Übernehmen oder Speichern in den Protokolltext eingefügt.'))?></span></div>
 </div>
</details>
<template data-minutes-top-template>
 <details class="minutes-top-entry">
  <summary class="minutes-top-heading"><span><strong data-minutes-top-label><?=e($minutesText('Tagesordnungspunkt'))?></strong><small data-minutes-top-summary><?=e($minutesText('Thema und Ergebnis eintragen'))?></small></span></summary>
  <div class="minutes-top-fields"><button type="button" class="btn btn-outline theme-secondary secondary minutes-remove-top" data-minutes-remove-top><?=e($minutesText('Diesen Tagesordnungspunkt entfernen'))?></button>
  <label><?=e($minutesText('Thema'))?><input class="form-control" data-minutes-top="title" maxlength="300" placeholder="<?=e($minutesText('Zum Beispiel Bericht des Kassenwarts'))?>"></label>
  <label><?=e($minutesText('Was wurde besprochen / festgestellt?'))?><textarea class="form-control" data-minutes-top="discussion" rows="3" maxlength="10000"></textarea></label>
  <details class="minutes-vote-fields"><summary><?=e($minutesText('Beschluss und Abstimmung ergänzen (optional)'))?></summary>
   <label><?=e($minutesText('Beschlusstext'))?><textarea class="form-control" data-minutes-top="decision" rows="3" maxlength="5000" placeholder="<?=e($minutesText('Den tatsächlich gefassten oder abgelehnten Beschluss festhalten'))?>"></textarea></label>
   <div class="minutes-vote-counts"><label><?=e($minutesText('Ja-Stimmen'))?><input class="form-control" type="number" min="0" max="100000" step="1" data-minutes-top="yes"></label><label><?=e($minutesText('Nein-Stimmen'))?><input class="form-control" type="number" min="0" max="100000" step="1" data-minutes-top="no"></label><label><?=e($minutesText('Enthaltungen'))?><input class="form-control" type="number" min="0" max="100000" step="1" data-minutes-top="abstain"></label></div>
   <label><?=e($minutesText('Ergebnis / weitere Anmerkung'))?><input class="form-control" data-minutes-top="result" maxlength="1000" placeholder="<?=e($minutesText('Zum Beispiel Beschluss angenommen oder vertagt'))?>"></label>
  </details>
  </div>
 </details>
</template>
