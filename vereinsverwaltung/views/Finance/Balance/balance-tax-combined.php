<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
use Kulturhus\Verein\{BalanceTaxMapping,BalanceTaxFiling};
// Included inside the existing versioned/CSRF-protected form, not a second form.
$wholeValues=$allValues['whole'];$reconciled=$allValues['reconciliation']['result'];
$wholeSchedule=isset($wholeValues['period'])?\Kulturhus\Verein\BalancePeriodWholeFiling::mappingSchedule($wholeValues):$wholeValues['asset_schedule'];
$wholeAuto=BalanceTaxMapping::preview($year,$wholeValues['book']['accounts'],$wholeSchedule,$wholeValues);
$wholeDisplay=$wholeSchedule['items'];if(isset($wholeValues['period']))foreach($wholeDisplay as &$item)$item=$item['schedule']+['name'=>$item['name']??$item['item_id'],'basis_cents'=>$item['plan']['basis_cents'],'acquired_on'=>$item['plan']['acquired_on'],'skr_code'=>$item['plan']['account']];unset($item);
?>
<section aria-labelledby="whole-club-result"><h3 id="whole-club-result"><?=e($bt('Gesamter Verein'))?></h3>
<p><?= e(isset($wholeValues['period'])?$bt('Ergebnis des Zeitraums'):$bt('Jahresergebnis')) ?>: <strong><?= $money($reconciled['whole']) ?></strong></p>
<details><summary><?=e($bt('Wie setzt sich das Ergebnis zusammen?'))?></summary><dl>
<?php foreach(['other_areas'=>$bt('Übrige Vereinsbereiche'),'taxable_book'=>$bt('Steuerpflichtige Betriebe · Buchführung'),'tax_adjustment'=>$bt('Steuerliche Wertkorrekturen'),'taxable_tax'=>$bt('Steuerpflichtige Betriebe · nach Bewertung')] as $key=>$label): ?><dt><?= e($label) ?></dt><dd><?= $money($reconciled[$key]) ?></dd><?php endforeach ?></dl>
<p><?=e($bt('Die Steuerkorrekturen ändern nicht die Buchführung des Gesamtvereins.'))?></p></details>
<details><summary><?=e($bt('Gesamtverein: Konten und Zuordnung prüfen'))?></summary>
<?php $wholeTargets=BalanceTaxFiling::targets();foreach($wholeValues['book']['accounts'] as $key=>$account):if(!$account['opening_cents']&&!$account['debit_cents']&&!$account['credit_cents']&&!isset($wholeMapping[$key]))continue;$default=$wholeAuto['rows'][$key]; ?>
<?php $choices=in_array($default['source']??null,['original_own_hgb','original_own_tax'],true)?[$default['target']=>$default['label']]:BalanceTaxMapping::choices($wholeValues['year'],$account,$wholeTargets);$selected=$wholeMapping[$key]??'';$fieldName='whole_mapping['.$key.']';require __DIR__.'/balance-tax-account.php';endforeach ?></details>
<details><summary><?=e($bt('Gesamtverein: Bilanz, Ergebnis und Anlagen ansehen'))?></summary>
<?php foreach(['assets'=>$bt('Vermögen'),'capital'=>$bt('Kapital'),'liabilities'=>$bt('Verbindlichkeiten'),'profit_and_loss'=>$bt('Erträge und Aufwendungen')] as $group=>$heading): ?><h4><?= e($heading) ?></h4><?php foreach($wholeValues['book'][$group] as $account):if(!$account['amount_cents']&&!$account['opening_cents']&&!$account['debit_cents']&&!$account['credit_cents'])continue; ?><p><?= e($account['name']) ?>: <strong><?= $money($account['amount_cents']) ?></strong></p><?php endforeach;endforeach ?>
<p><?=e($bt('Vermögen gesamt:'))?> <?= $money($wholeValues['book']['asset_total_cents']) ?><br><?=e($bt('Kapital einschließlich Jahresergebnis und Verbindlichkeiten:'))?> <?= $money($wholeValues['book']['passive_total_cents']) ?></p>
<h4><?=e($bt('Alle Gegenstände des Vereins'))?></h4><?php if(!$wholeValues['asset_schedule']['items']): ?><p><?=e($bt('Keine Gegenstände im festgeschriebenen Anlagenverzeichnis.'))?></p><?php endif ?>
<?php foreach($wholeDisplay as $item): $ownOriginal=($item['method']??null)==='own_intangible_hgb'; ?><article><strong><?= e($item['name']??$item['receipt_number']??'Gegenstand') ?></strong><p><?= e($ownOriginal?$bt('Beginn des ursprünglichen Kostenzeitraums'):$bt('Angeschafft am')) ?> <?= e($item['acquired_on']) ?> · Konto <?= e($item['skr_code']) ?><br><?= e($ownOriginal?$bt('Ursprüngliche Herstellungskosten'):'Anschaffungskosten') ?>: <?= $money($item['basis_cents']??$item['cost_closing_cents']) ?><br><?= e(isset($wholeValues['period'])?$bt('Abschreibung im Zeitraum'):$bt('Abschreibung im Jahr')) ?>: <?= $money($item['depreciation_cents']) ?><br>Restbuchwert: <?= $money($item['book_closing_cents']) ?></p></article><?php endforeach ?>
</details></section>
