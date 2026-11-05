<?php declare(strict_types=1);
$assetSaleVatMessages=$managerLanguageMessages??[];
$assetSaleVatText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$assetSaleVatMessages); ?>
<fieldset><legend><?= e($assetSaleVatText('Lieferungsart des Anlagenverkaufs')) ?></legend>
<p><?= e($assetSaleVatText('Rechnung und die erforderlichen Ausfuhr- oder Liefernachweise einschließlich Umsatzsteuerangaben prüfen.')) ?></p>
<?php foreach(['domestic'=>'Inland oder andere belegte Steuerbefreiung','export_4_1a'=>'Ausfuhrlieferung (§ 4 Nr. 1a UStG)','intra_eu_4_1b'=>'Innergemeinschaftliche Lieferung (§ 4 Nr. 1b UStG)'] as $value=>$label): ?>
<label><input class="check" type="radio" name="sale_vat_kind" value="<?= e($value) ?>" <?= $saleVatKind===$value?'checked':'' ?>><?= e($assetSaleVatText($label)) ?></label>
<?php endforeach ?>
</fieldset>
