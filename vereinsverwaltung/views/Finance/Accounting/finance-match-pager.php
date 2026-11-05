<?php declare(strict_types=1);
// $financeMatchPagerKind identifies the existing receipt or own-movement list.
if(!$catalogue)return;
if(!$financeMatchBody||!$finance->canWrite()){require __DIR__.'/../Banking/catalogue-pager.php';return;}
$financeMatchPagePrefix=$financeMatchPagerKind==='own'?'own':'match';
?>
<div class="bank-catalogue-pager finance-match-pager" aria-label="<?=e($catalogueLabel)?>">
<p data-bank-catalogue-summary><?=e($financeMatchText('Seite'))?> <?= (int)$catalogue['page']?> <?=e($financeMatchText('von'))?> <?= (int)$catalogue['pages']?> · <?= (int)$catalogue['total']?> <?=e($financeMatchText('Treffer'))?></p>
<?php if($catalogue['pages']>1):?><nav class="actions" aria-label="<?=e($catalogueLabel)?>">
<?php if($catalogue['page']>1):?><button class="btn btn-outline theme-secondary" form="finance-match-original-form" name="match_move" value="<?=e($financeMatchPagerKind.':page:'.($catalogue['page']-1))?>" formnovalidate><?=e($financeMatchText('Vorherige Seite'))?></button><?php endif?>
<?php if($catalogue['page']<$catalogue['pages']):?><button class="btn btn-outline theme-secondary" form="finance-match-original-form" name="match_move" value="<?=e($financeMatchPagerKind.':page:'.($catalogue['page']+1))?>" formnovalidate><?=e($financeMatchText('Nächste Seite'))?></button><?php endif?></nav>
<div class="filter-form"><label><?=e($financeMatchText('Seite'))?><input class="form-control" type="number" name="<?=e($financeMatchPagePrefix.'_page')?>" form="finance-match-original-form" min="1" max="<?= (int)$catalogue['pages']?>" value="<?= (int)$catalogue['page']?>"></label><button class="btn btn-outline theme-secondary" form="finance-match-original-form" name="match_move" value="<?=e($financeMatchPagerKind.':jump')?>" formnovalidate><?=e($financeMatchText('Seite anzeigen'))?></button></div><?php endif?>
</div>
