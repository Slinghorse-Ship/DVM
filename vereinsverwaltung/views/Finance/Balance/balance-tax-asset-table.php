<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
// The filing view supplies the retained, flattened asset schedule and money formatter.
$periodAssets = isset($b['period']);
$hasDisposals = isset($displaySchedule['totals']['book_disposals_cents']);
?>
<?php if (empty($displaySchedule['items'])): ?>
<p><?=e($bt('Keine Gegenstände im festgeschriebenen Anlagenverzeichnis.'))?></p>
<?php else: ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Anlagenwerte">
<table class="table"><thead><tr>
<th scope="col">Gegenstand</th>
<th scope="col"><?=e($bt('Anschaffungs-/Herstellungskosten'))?></th>
<?php if ($periodAssets): ?><th scope="col">Wert am Anfang</th><?php endif ?>
<th scope="col"><?= e($periodAssets ? $bt('Abschreibung im Zeitraum') : 'Abschreibung') ?></th>
<?php if ($hasDisposals): ?><th scope="col">Restwert beim Abgang</th><?php endif ?>
<th scope="col">Restbuchwert</th>
</tr></thead><tbody>
<?php foreach ($displaySchedule['items'] as $item): ?>
<tr><td><?= e($item['name'] ?? $item['item_id']) ?><?php if (isset($item['disposal'])): ?><br><?= ($item['disposal']['kind'] ?? '') === 'sale' ? 'Verkauft' : 'Ausgemustert' ?>: <?= e($item['disposal']['date']) ?><?php endif ?>
</td><td><?= $money($item['basis_cents'] ?? $item['cost_closing_cents']) ?></td>
<?php if ($periodAssets): ?><td><?= $money($item['book_opening_cents']) ?></td><?php endif ?>
<td><?= $money($item['depreciation_cents']) ?></td>
<?php if ($hasDisposals): ?><td><?= $money($item['book_disposals_cents'] ?? 0) ?></td><?php endif ?>
<td><?= $money($item['book_closing_cents']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
