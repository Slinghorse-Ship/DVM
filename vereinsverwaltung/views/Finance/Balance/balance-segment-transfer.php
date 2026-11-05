<?php declare(strict_types=1);
// Included in the selected original voucher, after all surrounding forms are closed.
$savedTransfer=$allocation['transfers'][$voucher]??null;$transferParts=[];
if($voucher!==''&&$voucher!=='opening'){
    try{$transferParts=\Kulturhus\Verein\BalanceSegmentCalculation::transferParts($s['journal'],$units,$allocation['allocations'],$voucher);}catch(DomainException){}
}
if(!$savedTransfer&&!$transferParts)return;
$transferAccounts=array_filter($s['journal']['accounts'],static fn($account)=>$account['group']==='equity');
?>
<details id="segment-transfer" <?= $savedTransfer||str_starts_with(input($post,'action'),'balance-segment-transfer')?'open':'' ?>>
<summary><?= e($segmentText('Ein Bereich bezahlt für einen anderen')) ?></summary>
<p><?= e($segmentText('Nur für eine endgültige interne Mittelübertragung. Bei geplanter Rückzahlung oder unklarer steuerlicher Zulässigkeit zuerst die Buchhaltung fragen.')) ?></p>
<dl><?php foreach(($savedTransfer['parts']??$transferParts) as $id=>$net): ?>
<dt><?= e($units[$id]['name']) ?></dt><dd><?= e($segmentText($net>0?'Gibt ab: ':'Erhält: ')) ?><?= $money(abs($net)) ?> €</dd>
<?php endforeach ?></dl>
<?php if($savedTransfer): ?>
<p><strong><?= e($segmentText('Erfasst:')) ?></strong> <?= e($s['journal']['accounts'][$savedTransfer['account']]['name']) ?> · <?= e($savedTransfer['reason']) ?></p>
<?php if($writer): ?><form method="post">
<?= $segmentFields('balance-segment-transfer-remove').hidden('segment_voucher',$voucher) ?>
<label><?= e($segmentText('Grund für die Aufhebung')) ?><input class="form-control" name="evidence" maxlength="900" required value="<?= e(input($post,'action')==='balance-segment-transfer-remove'?input($post,'evidence'):'') ?>"></label>
<button class="btn btn-outline theme-secondary secondary"><?= e($segmentText('Mittelübertragung aufheben')) ?></button></form><?php endif ?>
<?php elseif($writer): ?><form method="post">
<?= $segmentFields('balance-segment-transfer').hidden('segment_voucher',$voucher) ?>
<?php if(count($transferAccounts)===1):$accountId=array_key_first($transferAccounts); ?>
<?= hidden('transfer_account',$accountId) ?><p><?= e($segmentText('Kapitalkonto:')) ?> <?= e($transferAccounts[$accountId]['name']) ?></p>
<?php else: ?><label><?= e($segmentText('Kapitalkonto')) ?><select class="form-control" name="transfer_account" required><option value=""><?= e($segmentText('Auswählen …')) ?></option>
<?php foreach($transferAccounts as $id=>$account): ?><option value="<?= e($id) ?>" <?= input($post,'transfer_account')===$id?'selected':'' ?>><?= e($account['skr_code'].' · '.$account['name']) ?></option><?php endforeach ?></select></label><?php endif ?>
<label><?= e($segmentText('Grund / Beschluss / Beleg')) ?><input class="form-control" name="evidence" maxlength="1000" required value="<?= e(input($post,'action')==='balance-segment-transfer'?input($post,'evidence'):'') ?>"></label>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required <?= $segmentReviewed('balance-segment-transfer')?'checked':'' ?>><?= e($segmentText('Die Bereiche und Beträge sind geprüft. Die Mittel werden endgültig übertragen; die Zulässigkeit ist geklärt.')) ?></label>
<button class="btn btn-solid theme-primary"><?= e($segmentText('Mittelübertragung erfassen')) ?></button></form><?php endif ?>
</details>
