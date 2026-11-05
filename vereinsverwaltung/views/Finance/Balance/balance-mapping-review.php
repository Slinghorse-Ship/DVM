<?php declare(strict_types=1);
$mappingFormText=$annualSourceText??static fn(string$s):string=>$s;$mappingFormDisplay=new \Kulturhus\Verein\BalanceDisplayText($managerLanguageMessages??[]);$preparation=$xmlOverview['preparation'];$targets=\Kulturhus\Verein\BalanceTaxFiling::targets();
?>
<details class="card"><summary><?= e($mappingFormText('XML-Zuordnungen für diesen Buchstand speichern')) ?></summary>
<p><?= e($mappingFormText('Kontenbedeutung und Steuerart prüfen. Änderungen am Buchstand machen diese Prüfung ungültig. Jahresabschluss und amtliche XML-Prüfung bleiben getrennte Schritte.')) ?></p>
<form method="post">
<?= csrf() ?>
<?= hidden('action','balance-mapping-review').hidden('balance_year',(string)$balanceReport['year']).hidden('version',(string)$balanceReport['version']).hidden('review_version',(string)$preparation['version']).hidden('source_sha256',$preparation['digest']) ?>
<?php $mappingFormActive=0;foreach($balanceReport['calculation']['accounts'] as $id=>$account): if(\Kulturhus\Verein\BalanceXmlOverview::unused($account))continue;$mappingFormActive++;
 $choices=\Kulturhus\Verein\BalanceTaxMapping::choices($balanceReport['year'],$account,$targets);$selected=$mappingReview['mapping'][$id]??'';
?>
<label><?= e($mappingFormDisplay->account($account)) ?><select class="form-control" name="mapping[<?= e($id) ?>]" required><option value=""><?= e($mappingFormText('Zuordnung wählen')) ?></option><?php foreach($choices as $value=>$label): ?><option value="<?= e($value) ?>" <?= $selected===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<?php endforeach ?>
<?php if($mappingFormActive===0): ?><?= hidden('mapping_empty','1') ?><p><?= e($mappingFormText('Für diesen Buchstand sind keine aktiven Konten zuzuordnen.')) ?></p><?php endif ?>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required> <?= e($mappingFormText('Konten, Belege und gewählte Steuerarten für diesen Buchstand geprüft')) ?></label>
<?= hidden(\Kulturhus\Verein\BalanceMappingForm::COMPLETE,'1') ?><button class="btn btn-solid theme-primary" type="submit"><?= e($mappingFormText('Geprüfte Zuordnungen speichern')) ?></button>
</form></details>
