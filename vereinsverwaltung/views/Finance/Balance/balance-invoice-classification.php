<?php declare(strict_types=1);
$purchaseChoiceTextMessages=$managerLanguageMessages??[];
$purchaseChoiceText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$purchaseChoiceTextMessages);
// Shared by calendar and dated-period invoice review. Only factual use is requested.
if($purchaseView['questions']): ?>
<?= hidden('purchase_form','1') ?>
<?php if($purchaseBound): ?><label class="check"><input class="check" type="checkbox" name="classify_purchases" value="1" <?= $purchaseView['request']!==null?'checked':'' ?>><?= e($purchaseChoiceText('Zuordnung aus der Rechnung übernehmen')) ?></label>
<?php else: ?><?= hidden('classify_purchases','1') ?><?php endif ?>
<?php foreach($purchaseView['questions'] as $ordinal=>$question): if(!$question['choices'])continue; ?>
<fieldset class="balance-purchase-question"><legend><?= e($purchaseChoiceText('Position').' '.$ordinal.': '.$question['description']) ?></legend>
<p><?= e($purchaseChoiceText('Wofür wurde es gekauft?')) ?></p>
<?php foreach($question['choices']+[''=>'Noch unklar'] as $use=>$label): ?><label><input class="check" type="radio" name="purchase_uses[<?= (int)$ordinal ?>]" value="<?= e($use) ?>" <?= ($purchaseView['request']['uses'][$ordinal]??'')===$use?'checked':'' ?>><span><?= e($purchaseChoiceText($label)) ?></span></label><?php endforeach ?>
</fieldset>
<?php endforeach;
elseif($purchaseView['request']!==null): ?>
<?= hidden('purchase_form','1').hidden('classify_purchases','1') ?>
<?php foreach($purchaseView['request']['uses'] as $ordinal=>$use): ?><?= hidden('purchase_uses['.(int)$ordinal.']',$use) ?><?php endforeach;endif ?>
