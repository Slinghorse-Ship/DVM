<?php declare(strict_types=1);
$open=$v['state']==='active'&&(int)$v['remaining']>0;
?>
<article class="voucher <?= !$open?'voucher-used':'' ?>">
<p class="voucher-event"><strong><?= e($ve['title']) ?></strong><br><?= e(dateDe(substr(localDateTime($ve['starts_at']),0,10)).' · '.$ve['location']) ?></p>
<p><strong><?= e($v['name']) ?></strong></p>
<h3><?= e($v['label']) ?></h3>
<p class="voucher-balance"><?= e($v['state']==='revoked'?'Gesperrt':(!$open?'Eingelöst':\Kulturhus\Verein\EventVouchers::display((int)$v['remaining'],$v['unit']))) ?></p>
<?php if($v['unit']==='piece'): ?><p>Gutschein <?= (int)$v['ticket_number'] ?> von <?= (int)$v['ticket_total'] ?></p><?php endif ?>
<?php if($open): ?><img class="voucher-qr" src="<?= e(\Kulturhus\Verein\EventVoucherQr::image($eventId,$v['code'])) ?>" width="222" height="222" alt="QR-Code für diesen Gutschein"><?php endif ?>
<p>Code <strong class="voucher-code"><?= e(implode('-',str_split($v['code'],4))) ?></strong></p>
<p class="voucher-validity">Nur für diese Person und Veranstaltung gültig.</p>
<?php if(!$printView&&$redeem&&$available&&$open): ?><form method="post" class="voucher-screen"><?= $form('redeem',['voucher_id'=>$v['id'],'ticket_id'=>$v['ticket_id'],'version'=>$v['version'],'q'=>$search]) ?>
<?php if($v['unit']==='eur'): ?><label for="amount-<?= e($v['id']) ?>">Betrag in Euro</label><input class="form-control" id="amount-<?= e($v['id']) ?>" name="amount" inputmode="decimal" required><?php else: ?><?= hidden('amount','1') ?><?php endif ?><button class="btn btn-solid theme-primary">Einlösen</button></form><?php endif ?>
<?php if(!$printView&&$manage&&$open): ?><details class="voucher-screen"><summary>Gutschein sperren</summary><form method="post" data-confirm="Diesen Gutschein sperren?"><?= $form('revoke',['voucher_id'=>$v['id'],'ticket_id'=>$v['ticket_id'],'version'=>$v['version']]) ?><label>Grund <input class="form-control" name="reason" maxlength="255" required></label><button class="btn btn-outline theme-secondary secondary">Diesen Gutschein sperren</button></form></details><?php endif ?>
<?php if(!$printView&&$manage&&$open): ?><form method="post" class="voucher-screen" data-confirm="Diesen Gutschein löschen und ungültig machen? Der bisherige Verlauf bleibt erhalten."><?= $form('delete',['voucher_id'=>$v['id'],'ticket_id'=>$v['ticket_id'],'version'=>$v['version']]) ?><button class="btn btn-outline theme-secondary secondary">Gutschein löschen</button></form><?php endif ?>
</article>
