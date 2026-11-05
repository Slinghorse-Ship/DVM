<?php declare(strict_types=1);
$sepaCatalogue=$sepaService->ordersPage($_GET['sepa_page']??1,$_GET['sepa_search']??'',$_GET['sepa_state']??'');
$sepaStates=['prepared'=>'Vorbereitet','approved'=>'Freigegeben','exported'=>'Heruntergeladen','submitted'=>'Eingereicht','cancelled'=>'Verworfen'];
$sepaRoute=['view'=>'finance','tab'=>'debit','sepa_search'=>$sepaCatalogue['search'],'sepa_state'=>$sepaCatalogue['state']];
?>
<details id="sepa-order-catalogue" <?= $selectedSepa===''?'open':'' ?>>
<summary>Gespeicherte Aufträge · <?= (int)$sepaCatalogue['total'] ?></summary>
<form method="get" action="#sepa-order-catalogue" class="form-grid">
<?= hidden('view','finance').hidden('tab','debit') ?>
<label>Auftrag, Einzugstag oder Bankreferenz<input class="form-control" type="search" name="sepa_search" maxlength="200" value="<?= e($sepaCatalogue['search']) ?>"></label>
<label>Status<select class="form-control" name="sepa_state"><option value="">Alle</option><?php foreach($sepaStates as $value=>$label): ?><option value="<?= e($value) ?>" <?= $sepaCatalogue['state']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<button class="btn btn-solid theme-primary">Aufträge suchen</button></form>
<p data-sepa-catalogue-summary><?= (int)$sepaCatalogue['total'] ?> Aufträge · Seite <?= (int)$sepaCatalogue['page'] ?> von <?= (int)$sepaCatalogue['pages'] ?></p>
<div class="sepa-order-cards">
<?php foreach($sepaCatalogue['rows']as$original): ?><article class="sepa-order-card" data-sepa-order="<?= e($original['id']) ?>">
<a class="btn btn-outline theme-secondary" href="<?= e(url($sepaRoute+['sepa_page'=>$sepaCatalogue['page'],'sepa_order'=>$original['id']])) ?>"><?= e(dateDe($original['collection_date']).' · '.euro($original['total_cents']).' · '.$sepaStates[$original['state']]) ?></a>
<?php if($original['bank_reference']!==''): ?><p><?= e($original['bank_reference']) ?></p><?php endif ?></article><?php endforeach ?>
<?php if(!$sepaCatalogue['rows']): ?><p>Keine Aufträge gefunden.</p><?php endif ?></div>
<nav aria-label="Auftragsseiten" class="actions">
<?php if($sepaCatalogue['page']>1): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($sepaRoute+['sepa_page'=>$sepaCatalogue['page']-1])) ?>#sepa-order-catalogue">Vorherige</a><?php endif ?>
<?php if($sepaCatalogue['page']<$sepaCatalogue['pages']): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($sepaRoute+['sepa_page'=>$sepaCatalogue['page']+1])) ?>#sepa-order-catalogue">Nächste</a><?php endif ?></nav>
<form method="get" action="#sepa-order-catalogue" class="actions"><?= hidden('view','finance').hidden('tab','debit').hidden('sepa_search',$sepaCatalogue['search']).hidden('sepa_state',$sepaCatalogue['state']) ?>
<label>Seite<input class="form-control" type="number" name="sepa_page" min="1" max="<?= (int)$sepaCatalogue['pages'] ?>" value="<?= (int)$sepaCatalogue['page'] ?>" required></label><button class="btn btn-outline theme-secondary">Seite anzeigen</button></form>
</details>
<?php if($selectedSepa!==''): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($sepaRoute+['sepa_page'=>$sepaCatalogue['page']])) ?>#sepa-order-catalogue">Zur Auftragsliste</a><?php endif ?>
<a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'finance','tab'=>'debit'])) ?>">Neuen Auftrag vorbereiten</a>
