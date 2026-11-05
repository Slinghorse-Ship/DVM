<?php declare(strict_types=1);
$attachmentStatusText=$ft??static fn(string$s):string=>$s;
$feedback=(new \Kulturhus\Verein\TaxAttachmentFeedback($db,(string)$_SESSION['actor']))->status($fid);
?>
<section class="card"><h2><?=e($attachmentStatusText('Rückmeldungen zu den PDF-Belegen'))?></h2>
<?php if($feedback['last_error']): ?><p role="alert"><?= e($feedback['last_error']) ?></p><?php endif ?>
<?php if($feedback['items']): foreach($feedback['items'] as $notice): ?>
<div role="alert"><h3><?=e($attachmentStatusText('Bitte Anlagen prüfen'))?></h3>
<?php foreach($notice['details']['attachments']??[] as $problem): ?><p><strong><?= e($problem['name']) ?>:</strong> <?= e($problem['message']) ?></p><?php endforeach ?>
<p><?= nl2br(e($notice['details']['message']??'')) ?></p><p><?= nl2br(e($notice['details']['instruction']??'')) ?></p>
<?php if(!$notice['acknowledged_at']): ?><p><?=e($attachmentStatusText('Rückmeldung gespeichert. Empfangsbestätigung noch offen – bitte erneut abrufen.'))?></p><?php endif ?>
</div>
<?php endforeach;elseif($feedback['checked_at']): ?><p><?=e($attachmentStatusText('Keine Beanstandung gemeldet · letzter vollständiger Abruf:'))?> <?= e($feedback['checked_at']) ?></p>
<?php else: ?><p><?=e($attachmentStatusText('PDF-Belege übermittelt. Rückmeldungen noch nicht abgerufen.'))?></p><?php endif ?>
<p><?=e($attachmentStatusText('ELSTER meldet nur beanstandete Anhänge. Ein leerer Abruf ist keine zusätzliche Eingangsbestätigung für jedes PDF.'))?></p>
<?php if($feedback['cursor_id']!==''): ?><p role="status"><?=e($attachmentStatusText('Weitere Rückmeldungen vorhanden. Bitte den Abruf fortsetzen.'))?></p><?php endif ?>
<?php if($canFile&&($filing['environment']==='test'||\Kulturhus\Verein\BoardAccess::allowed($db,(string)$_SESSION['actor']))): ?>
<details><summary><?=e($attachmentStatusText('Rückmeldungen abrufen'))?></summary><form method="post" enctype="multipart/form-data" autocomplete="off">
<?= csrf().hidden('action','tax-filing-feedback').hidden('filing',$fid) ?>
<?php require __DIR__.'/tax-certificate-input.php'; ?>
<button class="btn btn-solid theme-primary"><?=e($attachmentStatusText('Rückmeldungen jetzt abrufen'))?></button>
</form></details><?php endif ?>
</section>
