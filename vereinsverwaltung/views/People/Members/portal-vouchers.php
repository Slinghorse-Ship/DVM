<?php declare(strict_types=1);
try{$personalVouchers=(new \Kulturhus\Verein\PortalDocuments($db,(string)$_SESSION['actor']))->vouchers($websiteCalendar??null);}
catch(DomainException){$personalVouchers=[];}
?>
<section class="card" id="meine-gutscheine"><h2>Meine Gutscheine</h2>
<?php if(!$personalVouchers): ?><p>Noch keine Gutscheine vorhanden.</p><?php endif ?>
<div data-simple-list="Meine Gutscheine suchen" data-simple-list-size="10">
<?php foreach($personalVouchers as $voucherDocument): ?>
<article class="portal-document-item" data-simple-row>
<h3><?= e($voucherDocument['title']) ?></h3>
<p><?= e(dateDe(substr(localDateTime($voucherDocument['starts_at']),0,10))) ?></p>
<?php if($voucherDocument['benefits']): ?><ul><?php foreach($voucherDocument['benefits'] as $benefit): ?><li><?= e($benefit) ?></li><?php endforeach ?></ul>
<?php else: ?><p>Keine offenen Gutscheine.</p><?php endif ?>
<?php if($voucherDocument['notice']!==''): ?><p role="status"><?= e($voucherDocument['notice']) ?></p><?php endif ?>
<div class="actions">
<?php if($voucherDocument['can_download']): ?><a class="btn btn-solid theme-primary button" href="<?= e(url(['view'=>'portal','tab'=>'documents','voucher_event'=>$voucherDocument['id'],'voucher_pdf'=>$voucherDocument['contact_id']])) ?>">Gutschein-PDF herunterladen</a><?php endif ?>
<a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'portal','tab'=>'vouchers','voucher_event'=>$voucherDocument['id']])) ?>">Gutscheine ansehen</a>
</div></article>
<?php endforeach ?></div></section>
