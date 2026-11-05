<?php declare(strict_types=1);
$registrationText??=static fn(string $source):string=>$source;
$registrationService=new \Kulturhus\Verein\IntegrationRegistrations($db,(string)$_SESSION['actor']);if(!$registrationService->canManage())return;
$registrationProvider=$section==='tax'?'elster':'fints';$registrationState=$registrationService->state($registrationProvider);
$registrationValue=input($_POST,'action')==='settings-registration'&&input($_POST,'provider')===$registrationProvider?input($_POST,'registration_value'):$registrationState['value'];
$registrationTitle=$registrationProvider==='elster'?'ELSTER-Hersteller-ID':'FinTS-Registrierungsnummer';
?>
<section class="card" id="<?= e($registrationProvider) ?>-registration" data-registration-settings="<?= e($registrationProvider) ?>"><h2><?= e($registrationText($registrationTitle)) ?></h2>
 <form method="post" class="settings-form"><?= csrf().hidden('action','settings-registration').hidden('provider',$registrationProvider).hidden('registration_revision',$registrationState['revision']) ?>
  <label><?= e($registrationText($registrationTitle)) ?><input class="form-control" name="registration_value" type="text" value="<?= e($registrationValue) ?>" inputmode="<?= $registrationProvider==='elster'?'numeric':'text' ?>" pattern="<?= $registrationProvider==='elster'?'[0-9]{5}':'[A-Za-z0-9]{25}' ?>" minlength="<?= $registrationProvider==='elster'?5:25 ?>" maxlength="<?= $registrationProvider==='elster'?5:25 ?>" autocomplete="off" required></label>
  <label class="setting-check <?= (!empty($invoicebankNativeRoute)||!empty($taxsettingsNativeRoute))?'general-settings-check':'' ?>"><input class="check" type="checkbox" name="registration_confirm" value="1" required> <?= e($registrationText('Eigene Registrierung für diese Installation bestätigen')) ?></label>
  <button class="btn btn-solid theme-primary" type="submit"><?= e($registrationText('Kennung speichern')) ?></button>
 </form>
 <p class="muted"><?= e($registrationText($registrationProvider==='elster'?'Die amtliche ERiC-Laufzeit wird zusätzlich benötigt.':'Laufende Bankdialoge vor einer Änderung beenden.')) ?></p>
</section>
