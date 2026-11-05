<?php declare(strict_types=1);
require_once __DIR__.'/../../../src/Finance/Tax/TaxUsability.php';
$scopeText??=static fn(string$text,array$values=[]):string=>strtr($text,array_combine(array_map(static fn($key)=>'{'.$key.'}',array_keys($values)),array_values($values))?:[]);
// Caller supplies scopeAnswers, scopeProfile, scopeYear and scopeCanWrite.
$scopeQuestions=\Kulturhus\Verein\TaxFilingScope::questions();$scopeOpened=false;
foreach(\Kulturhus\Verein\TaxUsability::scopeGroups() as $scopeGroupId=>[$scopeTitle,$scopeKeys]):
 $scopeOpenCount=count(array_filter($scopeKeys,static fn($key)=>input($scopeAnswers,$key,'unknown')==='unknown'));
 $scopeOpen=$scopeOpenCount>0&&!$scopeOpened;if($scopeOpen)$scopeOpened=true;
?>
<details class="tax-question-group" <?= $scopeOpen?'open':'' ?>>
<summary><?= e($scopeText($scopeTitle)) ?> · <?= e($scopeOpenCount?$scopeText('{count} offen',['count'=>$scopeOpenCount]):$scopeText('Angaben vorhanden')) ?></summary>
<?php foreach($scopeKeys as $key):$q=$scopeQuestions[$key];$v=input($scopeAnswers,$key,'unknown');$suggestion=$scopeProfile['settings_defaults'][$key]??null;$scopeHelp=\Kulturhus\Verein\TaxUsability::scopeHelp($key); ?>
<div class="tax-question" id="tax-scope-<?= e($key) ?>">
<?php if($scopeRadios??false):?><fieldset class="tax-scope-options"><legend><?=e($scopeText(\Kulturhus\Verein\TaxUsability::scopeLabel($key)))?></legend><?php foreach($q['options']as$value=>$label):?><label class="tax-local-check"><input type="radio" name="answers[<?=e($key)?>]" value="<?=e($value)?>" <?=$v===$value?'checked':''?> <?=$scopeCanWrite?'':'disabled'?>><span><?=e($scopeText($value==='unknown'?'Weiß ich nicht – prüfen lassen':$label))?></span></label><?php endforeach?></fieldset><?php else:?><label><?= e($scopeText(\Kulturhus\Verein\TaxUsability::scopeLabel($key))) ?><select class="form-control" name="answers[<?= e($key) ?>]" <?= !$scopeCanWrite?'disabled':'' ?>><?php foreach($q['options'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $v===$value?'selected':'' ?>><?= e($scopeText($value==='unknown'?'Weiß ich nicht – prüfen lassen':$label)) ?></option><?php endforeach ?></select></label><?php endif?>
<?php if($suggestion!==null&&isset($q['options'][$suggestion])): ?>
<p class="tax-source"><?=e($scopeText('Vereinseinstellung:'))?> <?= e($scopeText($q['options'][$suggestion])) ?><?php if((int)$scopeProfile['version']>0&&$suggestion!==$v): ?>. <?=e($scopeText('Für {year}: {state}',['year'=>$scopeYear,'state'=>$scopeText($v==='unknown'?'noch nicht bestätigt':'abweichend gespeichert')]))?>.
<?php if($scopeCanWrite): ?><button type="button" class="btn btn-outline theme-secondary secondary" data-tax-use-setting="<?= e($suggestion) ?>" hidden><?=e($scopeText('Für {year} übernehmen',['year'=>$scopeYear]))?></button><?php endif ?>
<?php else: ?> · <?=e($scopeText('Bitte für {year} prüfen.',['year'=>$scopeYear]))?><?php endif ?></p>
<?php endif ?>
<?php if($scopeHelp!==''): ?><details class="tax-help"><summary><?=e($scopeText('Wo finde ich diese Angabe?'))?></summary><p><?= e($scopeText($scopeHelp)) ?></p></details><?php endif ?>
</div><?php endforeach ?></details><?php endforeach ?>

<?php unset($scopeText);?>
