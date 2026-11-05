<?php declare(strict_types=1);
$costText=$bt??static fn(string $source):string=>$source;
$costItems=array_filter($values['asset_schedule']['items']??[],static fn($i)=>($i['method']??$i['plan']['method']??null)==='cost');
if(!$costItems)return;
$costFields=['cost_opening_cents'=>'Anschaffungskosten am Anfang','additions_cents'=>'Zugänge','disposals_cents'=>'Abgegangene Anschaffungskosten',
    'impairment_cents'=>'Außerplanmäßige Wertminderung','writeup_cents'=>'Zuschreibung','book_disposals_cents'=>'Restwert beim Abgang','book_closing_cents'=>'Wert am Ende'];
?>
<details class="card" data-balance-specialist-readonly><summary><?= e($costText('Grundstücks- und Finanzanlagen · Buch- und Steuerbewertung')) ?></summary>
<p><?= e($costText('Die Werte stammen aus den bestätigten ursprünglichen Bewertungsnachweisen. Änderungen werden beim jeweiligen Gegenstand mit Beleg und Begründung erfasst.')) ?></p>
<?php foreach($costItems as $costId=>$costItem):$costValue=$costItem['valuation']; ?>
<h3><?= e($costItem['name']??$costId) ?></h3>
<div class="table-scroll" tabindex="0" role="region" aria-label="<?= e($costText('Buch- und Steuerbewertung')) ?>"><table class="table"><thead><tr><th><?= e($costText('Bewertung')) ?></th><th><?= e($costText('Buchwert')) ?></th><th><?= e($costText('Steuerwert')) ?></th></tr></thead><tbody>
<?php foreach($costFields as $field=>$label): ?><tr><th scope="row"><?= e($costText($label)) ?></th><td><?= $money($costValue['book'][$field]) ?></td><td><?= $money($costValue['tax'][$field]) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php foreach($costValue['source']['events'] as $event): ?><p><?= e(date('d.m.Y',strtotime($event['on']))) ?> · <?= e($event['reason']) ?><br><?= e($event['legal_basis']) ?></p><?php endforeach ?>
<?php endforeach ?></details>
