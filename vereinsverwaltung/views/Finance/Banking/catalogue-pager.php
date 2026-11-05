<?php declare(strict_types=1);
// $catalogue, $catalogueContext, $cataloguePageKey and $catalogueLabel belong to the caller.
if(!$catalogue)return;
$pagerText=$catalogueText??static fn(string $value):string=>$value;
?>
<div class="bank-catalogue-pager" aria-label="<?= e($catalogueLabel) ?>">
 <p data-bank-catalogue-summary><?=e($pagerText('Seite'))?> <?= (int)$catalogue['page'] ?> <?=e($pagerText('von'))?> <?= (int)$catalogue['pages'] ?> · <?= (int)$catalogue['total'] ?> <?=e($pagerText('Treffer'))?></p>
 <?php if($catalogue['pages']>1): ?>
 <nav class="actions" aria-label="<?= e($catalogueLabel) ?>">
 <?php if($catalogue['page']>1): ?><a class="btn btn-outline theme-secondary" rel="prev" href="<?= e(url(array_replace($catalogueContext,[$cataloguePageKey=>$catalogue['page']-1]))) ?>"><?=e($pagerText('Vorherige Seite'))?></a><?php endif ?>
 <?php if($catalogue['page']<$catalogue['pages']): ?><a class="btn btn-outline theme-secondary" rel="next" href="<?= e(url(array_replace($catalogueContext,[$cataloguePageKey=>$catalogue['page']+1]))) ?>"><?=e($pagerText('Nächste Seite'))?></a><?php endif ?>
 </nav>
 <form method="get" class="filter-form"><?php foreach($catalogueContext as $key=>$value)if($key!==$cataloguePageKey)echo hidden($key,$value); ?>
 <label><?=e($pagerText('Seite'))?><input class="form-control" type="number" name="<?= e($cataloguePageKey) ?>" min="1" max="<?= (int)$catalogue['pages'] ?>" required value="<?= (int)$catalogue['page'] ?>"></label>
 <button class="btn btn-outline theme-secondary"><?=e($pagerText('Seite anzeigen'))?></button>
 </form>
 <?php endif ?>
</div>
