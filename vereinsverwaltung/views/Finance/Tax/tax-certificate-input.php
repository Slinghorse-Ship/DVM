<?php declare(strict_types=1);
$certificateInputText=$certificateText??($ft??static fn(string$s):string=>$s);
$storedCertificate=null;
if($filing['environment']==='production'&&$certificateVault->allowed()){try{$storedCertificate=$certificateVault->status();}catch(DomainException){}}
$hasStored=!empty($storedCertificate['saved']);
?>
<?php if($hasStored): ?><?= hidden('certificate_revision',$storedCertificate['revision']) ?><label class="check"><input class="check" type="radio" name="certificate_source" value="stored" checked><?=e($certificateInputText('Hinterlegtes Vereinszertifikat verwenden · gültig bis'))?> <?= e(date('d.m.Y',strtotime($storedCertificate['valid_until']))) ?></label><label class="check"><input class="check" type="radio" name="certificate_source" value="upload"><?=e($certificateInputText('Andere Datei einmalig verwenden'))?></label><?php else: ?><?= hidden('certificate_source','upload') ?><?php endif ?>
<label><?= e($certificateInputText($filing['environment']==='test'?'Amtliches Testzertifikat':($hasStored?'Andere Zertifikatsdatei (nur bei einmaliger Verwendung)':'ELSTER-Zertifikat des Vereins'))) ?><input class="form-control" type="file" name="tax_certificate" accept=".pfx,.p12" <?= $hasStored?'':'required' ?>></label>
<label><?=e($certificateInputText('Zertifikats-Passwort'))?><input class="form-control" type="password" name="certificate_pin" maxlength="256" autocomplete="new-password" required></label>
<p><?=e($certificateInputText('Das Passwort wird nicht gespeichert.'))?></p>
