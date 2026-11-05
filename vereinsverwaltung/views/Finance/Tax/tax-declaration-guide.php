<?php declare(strict_types=1);
$taxText??=static fn(string $source,array $values=[]):string=>$source;
$taxGuideRows=is_array($taxGuideInput??null)?$taxGuideInput:[];
?>
<section aria-labelledby="tax-guide-heading">
<h3 id="tax-guide-heading"><?= e($taxText('Fragen für den gewählten Prüfungszeitraum')) ?></h3>
<p><?= e($taxText('Die Antworten gelten für den gesamten gewählten Zeitraum. Ungeklärte Angaben können gespeichert werden.')) ?></p>
<?php foreach(\Kulturhus\Verein\TaxDeclarationGuide::questions() as $taxGuideId=>$taxGuideQuestion):
 $taxGuideRow=is_array($taxGuideRows[$taxGuideId]??null)?$taxGuideRows[$taxGuideId]:[];
 $taxGuideAnswer=is_string($taxGuideRow['answer']??null)?$taxGuideRow['answer']:'unknown';
 if(!in_array($taxGuideAnswer,['unknown','no','yes'],true))$taxGuideAnswer='unknown';
 $taxGuideNote=is_string($taxGuideRow['note']??null)?$taxGuideRow['note']:'';
?>
<fieldset id="tax-guide-<?= e($taxGuideId) ?>" data-tax-guide-when="<?= e($taxGuideQuestion['notes_when']) ?>"><legend><?= e($taxText($taxGuideQuestion['question'])) ?></legend>
<details><summary><?= e($taxText('Was ist damit gemeint?')) ?></summary><p id="tax-guide-help-<?= e($taxGuideId) ?>"><?= e($taxText($taxGuideQuestion['help'])) ?></p></details>
<label><?= e($taxText('Ihre Antwort')) ?><select class="form-control" name="tax_guide[<?= e($taxGuideId) ?>][answer]" aria-describedby="tax-guide-help-<?= e($taxGuideId) ?>">
<?php foreach(['unknown'=>'Weiß ich nicht – prüfen lassen','no'=>'Nein','yes'=>'Ja'] as $taxGuideValue=>$taxGuideLabel): ?><option value="<?= e($taxGuideValue) ?>"<?= $taxGuideAnswer===$taxGuideValue?' selected':'' ?>><?= e($taxText($taxGuideLabel)) ?></option><?php endforeach ?>
</select></label>
<label data-tax-guide-note><?=e($taxText('Erläuterung und Nachweise'))?> <?=e($taxText($taxGuideQuestion['notes_when']==='always'?'(bei Ja oder Nein erforderlich)':'(bei Ja erforderlich)'))?><textarea class="form-control" name="tax_guide[<?= e($taxGuideId) ?>][note]" rows="3" maxlength="2000"><?= e($taxGuideNote) ?></textarea></label>
</fieldset>
<?php endforeach ?>
<details><summary><?= e($taxText('Amtliche Grundlage')) ?></summary><p><?= e($taxText('Fragegrundlage:')) ?> <a href="<?= e(\Kulturhus\Verein\TaxDeclarationGuide::SOURCE_URL) ?>" target="_blank" rel="noopener noreferrer"><?= e($taxText('amtliche Anleitung 2025')) ?></a><?= e($taxText('. Für andere Jahre die jeweils geltende Anleitung prüfen.')) ?></p></details>
</section>
