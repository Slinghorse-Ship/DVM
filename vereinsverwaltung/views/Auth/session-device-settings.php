<?php declare(strict_types=1);
require __DIR__.'/account-language-context.php';
$deviceNative=\Kulturhus\Verein\ProductionRuntime::nativeManager();
$deviceSession=[];
$deviceModes=['day'=>'24 Stunden','month'=>'30 Tage','logout'=>'Bis ich mich abmelde'];
if($deviceNative){
 $deviceModes=\Kulturhus\Native\Auth\Sessions::LABELS;
 $deviceSession=$nativeAuth->sessionDuration($_SESSION['auth_token'])+['csrf_name'=>'csrf','csrf_value'=>$_SESSION['csrf'],'saved'=>!empty($_SESSION['device_duration_saved'])];
 unset($_SESSION['device_duration_saved']);
}
$deviceMode=is_string($deviceSession['mode']??null)&&isset($deviceModes[$deviceSession['mode']])?$deviceSession['mode']:'month';
$deviceReady=$deviceNative&&is_string($deviceSession['csrf_name']??null)&&$deviceSession['csrf_name']!==''&&is_string($deviceSession['csrf_value']??null)&&$deviceSession['csrf_value']!=='';
?>
<section class="card" aria-labelledby="device-session-title" id="device-session">
 <h2 id="device-session-title"><?= e($accountText('Auf diesem Gerät angemeldet bleiben')) ?></h2>
 <p><?= e($accountText('Diese Auswahl gilt für deine aktuelle Anmeldung in diesem Browser oder dieser installierten Web-App. Andere Geräte und andere Mitglieder bleiben unverändert.')) ?></p>
 <?php if($deviceReady): ?>
 <?php if(!empty($deviceSession['saved'])): ?><p class="notice" role="status"><?= e($accountText('Anmeldedauer für dieses Gerät gespeichert:')) ?> <?= e($accountText($deviceModes[$deviceMode])) ?>.</p><?php endif ?>
 <p><?= e($accountText('Aktuell ausgewählt:')) ?> <strong><?= e($accountText($deviceModes[$deviceMode])) ?></strong></p>
 <form method="post" action="<?= $deviceNative?'/account':'/login/' ?>" data-device-session-form>
  <?= ($deviceNative?hidden('operation','session-duration'):hidden('portal_action','session-duration')).hidden($deviceSession['csrf_name'],$deviceSession['csrf_value']) ?>
  <label for="device-session-mode"><?= e($accountText('Wie lange möchtest du angemeldet bleiben?')) ?></label>
  <select class="form-control" id="device-session-mode" name="mode" required aria-describedby="device-session-help">
   <?php foreach($deviceModes as $mode=>$label): ?><option value="<?= e($mode) ?>" <?= $mode===$deviceMode?'selected':'' ?>><?= e($accountText($label)) ?></option><?php endforeach ?>
  </select>
  <p id="device-session-help"><?= e($accountText('Bei 24 Stunden und 30 Tagen beginnt die Frist mit deiner Nutzung jeweils neu. „Bis ich mich abmelde“ hat keine automatische Frist wegen Nichtbenutzung. Auf gemeinsam genutzten Geräten bitte anschließend ausdrücklich abmelden.')) ?></p>
  <p class="muted"><?= e($accountText('Wenn du Browserdaten oder Cookies löschst, kann eine neue Anmeldung nötig sein. Passwort, eingeschaltete Zwei-Faktor-Anmeldung und Sicherheitsprüfungen bleiben bestehen.')) ?></p>
  <button class="btn btn-solid theme-primary" type="submit"><?= e($accountText('Anmeldedauer speichern')) ?></button>
 </form>
 <?php else: ?><p><?= e($accountText('Die Geräteauswahl steht mit deiner persönlichen Anmeldung zur Verfügung.')) ?></p><?php endif ?>
</section>
