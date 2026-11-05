<?php declare(strict_types=1);
// Included from the authorized tax workspace. No certificate bytes enter the view.
$ct=$certificateText??static fn(string $source):string=>$source;
$vault=new \Kulturhus\Verein\TaxCertificateVault($db,(string)$_SESSION['actor']);
$back=url($base+($fid!==''?['filing'=>$fid]:[]));
?>
<section class="card" id="elster-certificate"><h2><?= e($ct('ELSTER-Zertifikat des Vereins')) ?></h2>
<?php if(empty($certificateSettings)): ?><p><a href="<?= e($back) ?>"><?= e($ct('← Zurück zur Steuererklärung')) ?></a></p><?php endif ?>
<?php if(!$vault->allowed()): ?><p><?= e($ct('Nur ein berechtigter Vorstand kann das Vereinszertifikat hinterlegen oder ändern.')) ?></p>
<?php else: try{$saved=$vault->status();}catch(DomainException $ex){echo '<p role="alert">'.e($ct($ex->getMessage())).'</p></section>';return;} ?>
<?php if($saved['saved']): ?><p><strong><?= e($ct('Vereinszertifikat hinterlegt')) ?></strong> · <?= e($ct('gültig bis')) ?> <?= e(date('d.m.Y',strtotime($saved['valid_until']))) ?></p>
<?php else: ?><p><?= e($ct('Noch kein Vereinszertifikat hinterlegt.')) ?></p><?php endif ?>
<p><?= e($ct('Optional verschlüsselt speichern. Das Passwort wird bei jeder Abgabe neu eingegeben und niemals gespeichert.')) ?></p>
<form method="post" enctype="multipart/form-data" autocomplete="off">
<?= csrf().hidden('action','tax-filing-certificate-save').hidden('filing',$fid).hidden('tax_year',(string)$year).hidden('certificate_revision',$saved['revision']) ?>
<?= !empty($certificateSettings)?hidden('certificate_return','tax-settings'):'' ?>
<label><?= e($ct('Organisationszertifikat (.pfx oder .p12, höchstens 1 MB)')) ?><input class="form-control" type="file" name="tax_certificate" accept=".pfx,.p12" required></label>
<label><?= e($ct('Zertifikats-Passwort')) ?><input class="form-control" type="password" name="certificate_pin" maxlength="256" autocomplete="new-password" required></label>
<label class="check <?= !empty($taxsettingsNativeRoute)?'general-settings-check':'' ?>"><input class="check" type="checkbox" name="organization_confirmed" value="1" required><?= e($ct('Dies ist das Organisationszertifikat dieses Vereins. Ich bin berechtigt, es für die Abgabe durch berechtigte Vorstände zu hinterlegen.')) ?></label>
<button class="btn btn-solid theme-primary"><?= e($ct($saved['saved']?'Zertifikat prüfen und ersetzen':'Zertifikat prüfen und speichern')) ?></button>
</form>
<?php if($saved['saved']): ?><details><summary><?= e($ct('Zertifikat entfernen')) ?></summary>
<form method="post"><?= csrf().hidden('action','tax-filing-certificate-remove').hidden('filing',$fid).hidden('tax_year',(string)$year).hidden('certificate_revision',$saved['revision']) ?>
<?= !empty($certificateSettings)?hidden('certificate_return','tax-settings'):'' ?>
<label class="check <?= !empty($taxsettingsNativeRoute)?'general-settings-check':'' ?>"><input class="check" type="checkbox" name="remove_confirmed" value="1" required><?= e($ct('Hinterlegtes Zertifikat entfernen.')) ?></label><button class="btn btn-outline theme-secondary secondary"><?= e($ct('Zertifikat entfernen')) ?></button></form></details><?php endif ?>
<p><?= e($ct('Die ursprüngliche Zertifikatsdatei bitte weiterhin sicher aufbewahren. Entfernen widerruft das Zertifikat nicht bei ELSTER.')) ?></p>
<?php endif ?></section>
