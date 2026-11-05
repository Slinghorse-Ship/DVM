<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
// Caller supplies the exact, server-validated choices for this source account.
$caption=$account['name'].($account['skr_code']!==''?' · '.$account['skr_code']:'');
if(count($choices)===1&&$default['status']==='assigned'&&($selected===''||isset($choices[$selected]))):
?>
<div class="balance-account-assigned"><strong><?= e($caption) ?> · <?= $money($account['amount_cents']) ?></strong><p><?= e(isset($default['source'])?$bt('Originalnachweise geprüft: '):$bt('Automatisch: ')) ?><?= e($default['label']) ?></p><?php if(isset($default['source'])): ?><p><?= e($default['message']) ?></p><?php endif ?><input type="hidden" name="<?= e($fieldName) ?>" value="<?= e($selected) ?>"></div>
<?php else: ?>
<label><?= e($caption) ?> · <?= $money($account['amount_cents']) ?><?php if($selected!==''&&!isset($choices[$selected])): ?><span role="alert"><?=e($bt('Die bisherige Zuordnung passt nicht mehr. Bitte neu bestätigen.'))?></span><?php endif ?>
<?php if($default['status']==='review'&&!empty($default['message'])): ?><span><?= e($default['message']) ?></span><?php endif ?>
<select class="form-control" name="<?= e($fieldName) ?>"><option value=""><?= e($default['status']==='assigned'?$bt('Automatisch: ').$default['label']:$bt('Noch zu klären')) ?></option>
<?php foreach($choices as $target=>$label): ?><option value="<?= e($target) ?>" <?= $selected===$target?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?>
</select></label>
<?php endif ?>
