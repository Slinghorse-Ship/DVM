<?php declare(strict_types=1); ?>
<div class="page-heading"><a class="icon-button" href="<?=e(url($calendarBackRoute))?>" aria-label="<?= e($calendarText('Zurück zum Kalender')) ?>"><?= icon('arrow') ?></a><h1><?= e($calendarText('Veranstaltung')) ?></h1></div>
<div class="content"><section class="card">
  <h2><?= e($event['title']) ?></h2>
  <?php if(\Kulturhus\Verein\BoardAccess::allowed($db,(string)$_SESSION['actor'])): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'portal','tab'=>'vouchers','source_id'=>$event['id'],'year'=>substr($event['starts_at'],0,4)])) ?>"><?= e($calendarText('Gutscheine für diese Veranstaltung')) ?></a><?php endif ?>
  <p><?= e(dateDe(substr(localDateTime($event['starts_at']),0,10))) ?> · <?= e(timeDe($event['starts_at'])) ?><?php if($event['end_known']): ?> <?= e($calendarText('bis')) ?> <?= e(dateDe(substr(localDateTime($event['ends_at']),0,10)).' · '.timeDe($event['ends_at'])) ?><?php else: ?> <?= e($calendarText('· Ende noch nicht angegeben')) ?><?php endif ?></p>
  <p><?= e($event['location']) ?></p><p><?= nl2br(e($event['description'])) ?></p>
  <p class="muted"><?= e($calendarText('Automatisch aus den Website-Veranstaltungen übernommen. Archivtermine bleiben vor externen Abgleichen geschützt; kommende Eventbrite-Termine werden weiterhin abgeglichen.')) ?></p>
  <div class="actions"><a class="btn btn-outline theme-secondary button secondary" href="<?= e($event['source_url']) ?>" target="_blank" rel="noopener"><?= e($calendarText('Veranstaltung ansehen ↗')) ?></a><?php if($canWebsite): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'website','tab'=>'events','item'=>$event['source_id']])) ?>"><?= e($calendarText('Redaktion öffnen')) ?></a><?php endif ?></div>
</section></div>

<?php require __DIR__.'/calendar-invitation.php'; ?>

<?php require __DIR__.'/calendar-print.php';echo '<script src="calendar-recipient-choices.js?v=2" defer></script>';require __DIR__.'/event-gema.php'; ?>
