<?php declare(strict_types=1); ?>
<section class="cashbook-workspace" data-cashbook-interface>
<form method="get" class="book-filter" data-cashbook-filter><?= hidden('view','finance').hidden('tab','bank').hidden('account',$accountId) ?>
<label><?=e($bankLedgerText('Von'))?><input class="form-control" type="date" name="from" required min="<?=e($selectedAccount['opened_on'])?>" value="<?=e($bookFrom)?>"></label>
<label><?=e($bankLedgerText('Bis'))?><input class="form-control" type="date" name="to" required value="<?=e($bookTo)?>"></label>
<label><?=e($bankLedgerText('Seite'))?><input class="form-control" name="cash_page" inputmode="numeric" required value="<?=e($cashbook?(string)$cashbook['page']:input($_GET,'cash_page','1'))?>"></label>
<button class="btn btn-outline theme-secondary"><?=e($bankLedgerText('Zeitraum anzeigen'))?></button></form>
<?php if($cashbookError!==''):?><p class="error" role="alert" data-cashbook-error><?=e($bankLedgerText($cashbookError))?></p><?php else:?>
<h3><?=e($bankLedgerText('Gesamter Kassenzeitraum'))?></h3>
<p class="cashbook-explanation"><?=e($bankLedgerText('Die Summen und der CSV-Export umfassen den ganzen Zeitraum. Bargeldzugänge und -abgänge enthalten auch eigene Geldtransfers; sie sind keine steuerliche Einnahmen-Ausgaben-Auswertung.'))?></p>
<div class="cash-stats" data-cashbook-period-totals>
<div><small><?=e($bankLedgerText('Übertrag'))?></small><strong><?=e(euro($cashbook['opening']))?></strong></div>
<div><small><?=e($bankLedgerText('Bareinnahmen'))?></small><strong class="positive"><?=e(euro($cashbook['income']))?></strong></div>
<div><small><?=e($bankLedgerText('Barausgaben'))?></small><strong class="negative"><?=e(euro($cashbook['expense']))?></strong></div>
<div><small><?=e($bankLedgerText('Endbestand'))?></small><strong><?=e(euro($cashbook['closing']))?></strong></div></div>
<?php $catalogue=$cashbook;$catalogueContext=$bankListContext;$cataloguePageKey='cash_page';$catalogueLabel=$bankLedgerText('Kassenbuchseiten');$catalogueText=$bankLedgerText;require __DIR__.'/catalogue-pager.php';?>
<dl class="cashbook-page-carry"><dt><?=e($bankLedgerText('Übertrag dieser Seite'))?></dt><dd data-cashbook-page-opening><?=e(euro($cashbook['page_opening']))?></dd></dl>
<?php if(!$cashbook['rows']):?><p role="status"><?=e($bankLedgerText('Keine Bargeldbewegungen in diesem Zeitraum.'))?></p><?php else:?>
<ol class="cashbook-entries" start="<?=($cashbook['page']-1)*$cashbook['size']+1?>">
<?php foreach($cashbook['rows'] as $r):?>
<li><article class="cashbook-entry" data-cashbook-transaction="<?=e($r['transaction_id'])?>">
<div class="cashbook-entry-heading"><h4><?=e(dateDe($r['day']))?> · <?=e($r['counterparty'])?></h4><strong class="<?=$r['cents']<0?'negative':'positive'?>"><?=e(euro((int)$r['cents']))?></strong></div>
<p class="cashbook-purpose"><?=e($r['description'])?></p>
<?php if($r['receipts']):?><div class="cashbook-receipts"><span><?=e($bankLedgerText('Beleg'))?></span><?php foreach($r['receipts'] as $receipt):?><a href="<?=e(url(receiptPaymentContext($receipt['id'],$bankListContext)))?>"><?=e($receipt['number'])?></a><?php endforeach?></div><?php endif?>
<?php if((int)$r['internal_cents']>0):?><p class="cashbook-association"><?=e($bankLedgerText('Eigener Geldtransfer'))?></p><?php endif?>
<?php if((int)$r['excess_return_cents']>0):?><p class="cashbook-association"><?=e($bankLedgerText('Rückzahlung eines Überschusses'))?></p><?php endif?>
<?php if($r['reversal']||$r['reversed_on']):?><p><?=e($bankLedgerText('Gegenbuchung'))?></p><?php endif?>
<dl class="cashbook-entry-amounts"><dt><?=e($bankLedgerText($r['cents']<0?'Bargeldabgang':'Bargeldzugang'))?></dt><dd><?=e(euro(abs((int)$r['cents'])))?></dd><dt><?=e($bankLedgerText('Bestand nach diesem Umsatz'))?></dt><dd><?=e(euro((int)$r['balance']))?></dd>
<?php if(!$r['reversal']&&!$r['reversed_on']&&$r['remaining_cents']>0):?><dt><?=e($bankLedgerText('Noch zuzuordnen'))?></dt><dd><?=e(euro((int)$r['remaining_cents']))?></dd><?php endif?></dl>
<a class="btn btn-outline theme-secondary" data-cashbook-source href="<?=e(url(bankTransactionContext($r['transaction_id'],$bankListContext)))?>"><?=e($bankLedgerText('Zuordnung öffnen →'))?></a>
</article></li><?php endforeach?></ol><?php endif?>
<dl class="cashbook-page-carry"><dt><?=e($bankLedgerText('Bestand nach dieser Seite'))?></dt><dd data-cashbook-page-closing><?=e(euro($cashbook['page_closing']))?></dd><dt><?=e($bankLedgerText('Endbestand am {date}',['date'=>dateDe($bookTo)]))?></dt><dd><?=e(euro($cashbook['closing']))?></dd></dl>
<div class="book-actions"><form id="cash-export" method="post"><?=csrf().hidden('action','cash-export').hidden('account',$accountId).hidden('from',$bookFrom).hidden('to',$bookTo)?><button class="btn btn-outline theme-secondary"><?=icon('download')?> <?=e($bankLedgerText('Kassenbuch als CSV'))?></button></form></div>
<details class="cash-close" id="cash-close" <?= input($_POST,'action')==='close-cashbook'?'open':'' ?>><summary><?= e($bankLedgerText('Kassenabschluss')) ?></summary><p class="muted"><?= e($bankLedgerText('Zuerst alle Kassenumsätze zuordnen, dann den gezählten Bestand bestätigen. Nach dem Abschluss sind Buchungen und Zuordnungsänderungen bis einschließlich des Abschlussdatums gesperrt. Korrekturen erfolgen als spätere Gegenbuchung.')) ?></p><?php if($finance->canWrite()): ?><form method="post" class="filter-form" data-confirm="<?= e($bankLedgerText('Diesen Kassenzeitraum abschließen und gegen rückdatierte Änderungen sperren?')) ?>"><?= csrf().hidden('action','close-cashbook').hidden('account',$accountId) ?><label><?= e($bankLedgerText('Abschließen bis')) ?><input class="form-control" type="date" name="through" required max="<?= \Kulturhus\Verein\Finance::today() ?>" value="<?= e(input($_POST,'action')==='close-cashbook'?input($_POST,'through'):min($bookTo,\Kulturhus\Verein\Finance::today())) ?>"></label><label><?= e($bankLedgerText('Gezählter Bestand (EUR)')) ?><input class="form-control" name="counted" inputmode="decimal" required value="<?= e(input($_POST,'action')==='close-cashbook'?input($_POST,'counted'):'') ?>" placeholder="<?= e($bankLedgerText('Tatsächlich gezählten Betrag eingeben')) ?>"></label><button class="btn btn-solid theme-primary"><?= e($bankLedgerText('Kasse abschließen')) ?></button></form><?php endif ?><?php foreach($cashbook['closures'] as $closed): ?><p class="muted"><?= e($bankLedgerText('Abgeschlossen bis {date} · {amount}',['date'=>dateDe($closed['closed_through']),'amount'=>euro((int)$closed['balance_cents'])])) ?></p><?php endforeach ?></details>
<?php endif?>
</section>
