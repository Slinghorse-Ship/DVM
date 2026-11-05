<?php declare(strict_types=1); ?>
<fieldset class="card" data-inventory-loan-stock-picker><legend><?= e($loanText('Verfügbaren Bestand suchen')) ?></legend>
<p data-inventory-loan-stock-summary><?= e($loanText('{count} verfügbare Bestände · Seite {page} von {pages}',['count'=>(string)$stockPage['total'],'page'=>(string)$stockPage['page'],'pages'=>(string)$stockPage['pages']])) ?></p>
<div class="iv-filter"><label><?= e($loanText('Bestandssuche')) ?><input class="form-control" type="search" name="stock_q" maxlength="150" value="<?= e($loanPost?$_POST['stock_q']:'') ?>"></label><?= hidden('stock_page',(string)$stockPage['page']) ?>
<button class="btn btn-outline theme-secondary" type="submit" name="action" value="inventory-loan-stock-browse" formnovalidate formaction="<?= e(url($loanDraftRoute+['stock_move'=>'search'])) ?>"><?= e($loanText('Bestände suchen')) ?></button>
<?php foreach(['previous'=>'Vorherige Bestände','next'=>'Nächste Bestände']as$move=>$label)if(($move==='previous'&&$stockPage['page']>1)||($move==='next'&&$stockPage['page']<$stockPage['pages'])): ?><button class="btn btn-outline theme-secondary" type="submit" name="action" value="inventory-loan-stock-browse" formnovalidate formaction="<?= e(url($loanDraftRoute+['stock_move'=>$move])) ?>"><?= e($loanText($label)) ?></button><?php endif ?></div>
</fieldset>
