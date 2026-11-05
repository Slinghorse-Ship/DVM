<?php declare(strict_types=1);
$eventMoreOpen=in_array(input($_POST,'action'),['save-event','series-save','series-preview'],true)&&($error??'')!=='';
$eventStates=['planned'=>'Geplant','completed'=>'Durchgeführt','cancelled'=>'Abgesagt'];
?>
<form method="post" id="event-form" class="calendar-editor">
 <?= csrf().hidden('action',$eventSubmitAction??'save-event').hidden('id',$eventForm['id']).hidden('version',$eventForm['version']).($eventExtraFields??'') ?>
 <?php if(empty($seriesEditing)&&$eventForm['id']===''&&$registry->appointmentSeriesReady())echo hidden('series_key',input($_POST,'series_key',bin2hex(random_bytes(16)))); ?>
 <section class="card record-section" aria-labelledby="event-basics">
  <h2 id="event-basics"><?= e($calendarText('1. Termin festlegen')) ?></h2>
  <p><?= e($calendarText('Dieser Termin erscheint im Vereinskalender für die ausgewählten Teilnehmer. Er wird nicht auf der Website, bei Facebook oder Eventbrite veröffentlicht.')) ?></p>
  <?php if((new \Kulturhus\Verein\WorkspaceAccess($db,(string)$_SESSION['actor']))->allows(['view'=>'website','tab'=>'events'])&&(new \Kulturhus\Verein\WebsiteWorkspace($db,(string)$_SESSION['actor']))->canWrite('events')): ?><p><a class="btn btn-outline theme-secondary" href="?view=website&amp;tab=events&amp;native_item=new"><?= e($calendarText('Öffentliche Veranstaltung anlegen')) ?></a></p><?php endif ?>
  <p><?= e($calendarText('Wähle zuerst die Terminart. Bei einer Versammlung passt sich die Teilnehmerauswahl an.')) ?></p>
  <fieldset class="record-type-choices"><legend><?= e($calendarText('Welche Art von Termin?')) ?></legend>
   <?php foreach(['event'=>['Vereinstermin','Zum Beispiel eigene Probe oder interne Vorbereitung'],'external'=>['Hinweis auf einen externen Termin','Zum Beispiel eine Veranstaltung eines anderen Vereins'],'assembly'=>['Mitgliederversammlung','Alle aktuellen Vereinsmitglieder einladen'],'board'=>['Vorstandsversammlung','Den aktuellen Vorstand auswählen'],'other'=>['Sonstige Tätigkeit','Zum Beispiel Arbeitseinsatz oder Besprechung']] as $k=>[$label,$help]): ?>
    <label class="check"><input class="check" type="radio" name="activity_kind" value="<?= $k ?>" <?= $eventForm['activity_kind']===$k?'checked':'' ?>><span><strong><?= e($calendarText($label)) ?></strong><small><?= e($calendarText($help)) ?></small></span></label>
   <?php endforeach ?>
  </fieldset>
  <div class="form-grid">
   <label class="wide"><?= e($calendarText('Titel')) ?> <span class="required">*</span><input class="form-control" name="title" maxlength="200" required value="<?= e($eventForm['title']) ?>"></label>
   <label><?= e($calendarText('Beginn')) ?> <span class="required">*</span><input class="form-control" type="datetime-local" name="start" required value="<?= e($eventForm['start']) ?>"></label>
   <label><?= e($calendarText('Ende')) ?> <span class="required">*</span><input class="form-control" type="datetime-local" name="end" required value="<?= e($eventForm['end']) ?>"></label>
   <label class="wide"><?= e($calendarText('Ort')) ?><input class="form-control" name="location" maxlength="300" value="<?= e($eventForm['location']) ?>" placeholder="<?= e($calendarText('Zum Beispiel Vereinsraum, Straße und Ort')) ?>"></label>
   <label class="wide"><?= e($calendarText('Beschreibung / Tagesordnung')) ?><textarea class="form-control" name="description" rows="5" maxlength="10000"><?= e($eventForm['description']) ?></textarea><small><?= e($calendarText('Diese Angaben sehen die ausgewählten Teilnehmer. Sie werden auch in eine spätere Einladung übernommen.')) ?></small></label>
  </div>
  <p class="record-help"><?= e($calendarText('Alle Uhrzeiten gelten für Deutschland (Europe/Berlin).')) ?></p>
  <?php if(!empty($seriesEditing)): ?><label><?= e($calendarText('Standardtermine ändern ab Datum')) ?><input class="form-control" type="date" name="series_from" required value="<?= e($seriesEditPost?input($_POST,'series_from'):$seriesFromDefault) ?>"></label><p><?= e($calendarText('Das ursprüngliche Ankerdatum im Beginn beibehalten; Uhrzeiten, Angaben, Enddatum und Ausnahmen können geändert werden. Vergangene, beantwortete oder einzeln bearbeitete Termine werden geschützt.')) ?></p><label class="check"><input class="check" type="checkbox" name="series_revive" value="1"<?= $seriesEditPost&&input($_POST,'series_revive')==='1'?' checked':'' ?>><?= e($calendarText('Durch Serienregel abgesagte Standardtermine bewusst wieder aufnehmen')) ?></label><?php endif ?>
 </section>
 <?php if(($eventForm['id']===''||!empty($seriesEditing))&&$registry->appointmentSeriesReady())require __DIR__.'/calendar-series-fields.php'; ?>
 <section class="card record-section" aria-labelledby="event-people">
  <h2 id="event-people"><?= e($calendarText('2. Teilnehmer auswählen')) ?></h2>
  <?php $meetingAttendeeHeading=false;require __DIR__.'/calendar-meeting-attendees.php';unset($meetingAttendeeHeading); ?>
 </section>
 <details class="card record-more" <?= $eventMoreOpen?'open':'' ?> data-event-more>
  <summary><span><strong><?= e($calendarText('Weitere Angaben: Durchführung und Tätigkeitsbericht')) ?></strong><small><?= e($calendarText($eventStates[$eventForm['activity_state']]??'Stand prüfen')) ?> <?= e($calendarText('· Kategorie, Kurzbericht und Quellenlink bei Bedarf ergänzen')) ?></small></span></summary>
  <div class="record-more-body">
   <div class="form-grid">
    <label><?= e($calendarText('Durchführung')) ?><select class="form-control" name="activity_state"><?php foreach(['planned'=>'Geplant / noch nicht bestätigt','completed'=>'Tatsächlich durchgeführt','cancelled'=>'Abgesagt'] as $k=>$v): ?><option value="<?= $k ?>" <?= $eventForm['activity_state']===$k?'selected':'' ?>><?= e($calendarText($v)) ?></option><?php endforeach ?></select></label>
    <label><?= e($calendarText('Kategorie im Kalender')) ?><select class="form-control" name="category"><?php foreach(['verein'=>'Verein','vorstand'=>'Vorstand','kultur'=>'Kultur'] as $k=>$v): ?><option value="<?= $k ?>" <?= $eventForm['category']===$k?'selected':'' ?>><?= e($calendarText($v)) ?></option><?php endforeach ?></select></label>
    <label class="wide"><?= e($calendarText('Kurzbericht für den Tätigkeitsbericht')) ?><textarea class="form-control" name="report_summary" rows="4" maxlength="5000"><?= e($eventForm['report_summary']) ?></textarea><small><?= e($calendarText('Was hat stattgefunden? Titel, Datum, Ort und dieser Kurzbericht werden bei durchgeführten Terminen in den Steuerunterlagen zusammengefasst. Vertrauliche Sitzungsinhalte gehören ins Protokoll.')) ?></small></label>
    <label class="wide"><?= e($calendarText('Facebook-Veranstaltungslink (optional)')) ?><input class="form-control" name="source_reference" value="<?= e($eventForm['source_reference']) ?>" placeholder="https://www.facebook.com/events/…/"></label>
   </div>
  </div>
 </details>
 <div class="record-savebar">
  <p><?= e($calendarText('Speichern macht den Termin für die ausgewählten Teilnehmer sichtbar. E-Mail- und Push-Einladungen bereitest du anschließend gesondert vor.')) ?></p>
  <div class="actions"><button class="btn btn-solid theme-primary"><?= e($calendarText($eventSubmitLabel??'Termin speichern')) ?></button><a class="btn btn-outline theme-secondary button secondary" href="?view=calendar"><?= e($calendarText('Abbrechen')) ?></a></div>
 </div>
</form>
