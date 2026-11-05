<?php declare(strict_types=1);
// Read-only detail shared by tax review and the final filing review.
if(!isset($sphereTaxValues['sphere_reconciliation']))return;
$sphereLabel=$sphereText??static fn(string$value):string=>$value;
?>
<details><summary><?= e($sphereLabel('Anlagen: Wechsel zwischen Vereinsbereichen')) ?></summary>
<?php foreach($sphereTaxValues['sphere_reconciliation']['items'] as $id=>$sphereItem): $sphereOriginal=$sphereTaxValues['valuation']['asset_schedule']['items'][$id]; ?>
<h3><?= e($sphereOriginal['name']) ?></h3>
<dl class="tax-balance-figures">
<?php foreach(['opening_cents'=>'Steuerwert am Anfang','additions_cents'=>'In diesem Zeitraum angeschafft','contributions_cents'=>'In den steuerpflichtigen Bereich übernommen','withdrawals_cents'=>'Aus dem steuerpflichtigen Bereich herausgenommen','valuation_result_cents'=>'Bewertungsergebnis beim Wechsel','depreciation_cents'=>'Steuerliche Abschreibung','closing_cents'=>'Steuerwert am Ende'] as $field=>$label): ?>
<div><dt><?= e($sphereLabel($label)) ?></dt><dd><?= $money($sphereItem['tax'][$field]) ?></dd></div>
<?php endforeach ?>
<div><dt><?= e($sphereLabel('Änderung des steuerpflichtigen Ergebnisses')) ?></dt><dd><?= $money($sphereItem['valuation_difference_cents']) ?></dd></div>
</dl>
<?php foreach($sphereItem['movements'] as $movement): ?><p><?= e(implode('.',array_reverse(explode('-',$movement['on']))).' · '.$sphereLabel(\Kulturhus\Verein\TaxCatalog::SPHERES[$movement['from_sphere']]).' → '.$sphereLabel(\Kulturhus\Verein\TaxCatalog::SPHERES[$movement['to_sphere']])) ?><br><?= e($movement['reason']) ?></p><?php endforeach ?>
<?php endforeach ?>
</details>
