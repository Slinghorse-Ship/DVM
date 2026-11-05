<?php declare(strict_types=1);$vmPrint=$vmState['print']; ?>
<section class="dwf-card" data-voucher-person-print><h2><?= e($vmPrint['person']['name']) ?></h2><p><?= e($vmF('{count} verfügbare Karten',['count'=>$vmPrint['count']])) ?></p>
<?php if($vmPrint['available']):?><p><a download class="btn btn-solid theme-primary dwf-button" href="<?= e(url(['voucher_manager_pdf'=>$vmEventId,'voucher_person'=>$vmPrint['person']['id']])) ?>"><?= e($vmI('Verfügbare Karten dieser Person als PDF')) ?></a></p><?php else:?><p class="dwf-alert" role="status"><?= e($vmI($vmPrint['notice'])) ?></p><?php endif ?>
<p><?= e($vmI('Die PDF enthält alle derzeit verfügbaren Karten dieser Person. Drucken verändert kein Guthaben.')) ?></p></section>
