<?php declare(strict_types=1);
$vmOperation=$vmDraft['action'];$vmOperationValues=$vmDraft['values'];
$vmOperationLabel=match($vmOperation){'event-voucher-redeem'=>'Gutschein einlösen','event-voucher-revoke'=>'Gutschein sperren','event-voucher-delete'=>'Gutschein ungültig machen','event-voucher-reverse'=>'Einlösung zurücknehmen',default=>'Gutscheinänderung prüfen'};
$vmOperationOpen=$vmOperation==='event-voucher-reverse'?empty($vmTarget['reversed'])&&($vmTarget['voucher_state']??'')==='active':($vmTarget['display_state']??'')==='active';
?>
<section class="voucher-manager-target" data-voucher-operation="<?= e($vmOperation) ?>" <?= ($vmOperationRestored??false)?'data-voucher-focused-operation':'' ?>><h3><?= e($vmI($vmOperationLabel)) ?></h3>
<?php if($vmTarget!==null):?><p><strong><?= e($vmTarget['name']) ?></strong> · <?= e($vmTarget['label']) ?></p>
<?php if($vmOperation==='event-voucher-reverse'):?><p><?= e($vmAmount((int)$vmTarget['amount'],$vmTarget['unit'])) ?> · <?= e($vmDate($vmTarget['created_at'])) ?></p>
<?php else:?><p><?= e($vmI('Aktuell verfügbar')) ?>: <?= e($vmAmount((int)$vmTarget['remaining'],$vmTarget['unit'])) ?></p><p><?= e($vmI('Ursprünglicher Code')) ?>: <strong class="personal-voucher-code"><?= e(implode('-',str_split($vmTarget['code'],4))) ?></strong></p><p><?= e($vmF('Aktuelle Fassung {version}',['version'=>$vmTarget['version']])) ?></p><?php endif ?>
<?php endif ?>
<?php if($vmDraft['version']!==''):?><p><?= e($vmF('Ursprüngliche Fassung {version}',['version'=>$vmDraft['version']])) ?></p><?php endif ?>
<form method="post" data-voucher-native-form <?= ($vmOperationRestored??false)?'data-unsaved-changes':'' ?>><?= $vmForm($vmDraft) ?>
<?php if($vmOperation==='event-voucher-redeem'):?>
<?php if(($vmTarget['unit']??'')==='piece'):echo hidden('amount',$vmOperationValues['amount']??'1');else:?><label><?= e($vmI('Betrag in Euro')) ?><input class="form-control" name="amount" inputmode="decimal" value="<?= e($vmOperationValues['amount']??'') ?>" required></label><?php endif ?>
<?php elseif(in_array($vmOperation,['event-voucher-revoke','event-voucher-reverse'],true)):?><label><?= e($vmI('Grund')) ?><textarea class="form-control" name="reason" rows="3" required><?= e($vmOperationValues['reason']??'') ?></textarea></label>
<?php elseif($vmOperation==='event-voucher-delete'):?><p><?= e($vmI('Diese Karte wird ungültig. Ihr ursprünglicher Verlauf bleibt erhalten.')) ?></p><?php endif ?>
<button class="btn btn-solid theme-primary dwf-button" <?= $vmTarget===null||!$vmOperationOpen||($vmOperation==='event-voucher-redeem'&&!$vmDisplay['available'])?'disabled':'' ?>><?= e($vmI($vmOperationLabel)) ?></button>
<a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url(['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'manage','voucher_task'=>'receipts','voucher_receipt'=>$vmDraft['key']])) ?>"><?= e($vmI('Ausgang dieses Vorgangs prüfen')) ?></a>
<?php if(in_array($vmOperation,['event-voucher-redeem','event-voucher-revoke','event-voucher-delete'],true)):?><fieldset><legend><?= e($vmI('Nach geändertem Stand neu vorbereiten')) ?></legend><p><?= e($vmI('Prüfe den jetzt angezeigten Kartenstand. Dein ursprünglicher Vorschlag bleibt bis zur bewussten Übernahme unverändert.')) ?></p><label class="voucher-manager-person"><input type="checkbox" name="voucher_review_current" value="1"><span><?= e($vmI('Ich habe den aktuellen Stand geprüft.')) ?></span></label><button class="btn btn-outline theme-secondary dwf-button" name="voucher_compose" value="review-version" formnovalidate <?= $vmTarget===null?'disabled':'' ?>><?= e($vmI('Aktuelle Fassung für einen neuen Vorgang übernehmen')) ?></button></fieldset><?php endif ?>
</form></section>
