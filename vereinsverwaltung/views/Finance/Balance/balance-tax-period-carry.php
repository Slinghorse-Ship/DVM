<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
// No invented zero preview before the exact prior tax and asset values are selected.
try{$service->sourceReport($filing,true);}catch(DomainException $ex){echo '<p role="alert">'.e($ex->getMessage()).'</p>';return;}
$choices=$editable?$service->carryForwardChoices($year,$b['period'],$b['profile']['kind'],$filing['environment']):[];
?>
<p><a href="<?= e(url($base)) ?>"><?=e($bt('← Alle Erklärungen'))?></a></p>
<section class="card"><h2><?=e($bt('E-Bilanz'))?> <?= e(\Kulturhus\Verein\BalancePeriod::label($b['period'])) ?></h2>
<h3><?=e($bt('Vorherigen Abschluss übernehmen'))?></h3><p><?=e($bt('Die bestätigten Steuerwerte, Anlagenpläne und Vereinsangaben werden übernommen. Danach prüfen Sie die Angaben für diesen Zeitraum.'))?></p>
<?php if($editable&&$choices): ?><form method="post"><?= $hidden.hidden('action','tax-filing-balance-carry') ?><label><?=e($bt('Bestätigte E-Bilanz'))?><select class="form-control" name="prior_filing" required><option value=""><?=e($bt('Vorherigen Abschluss auswählen'))?></option><?php foreach($choices as $prior): ?><option value="<?= e($prior['id']) ?>"><?= e(\Kulturhus\Verein\BalancePeriod::label($prior['period']).' · '.$prior['updated_at']) ?></option><?php endforeach ?></select></label>
<label class="check"><input class="check" type="checkbox" name="carry_confirmed" value="1" required><?=e($bt('Dies ist der gültige Abschluss desselben Vereins unmittelbar vor diesem Zeitraum.'))?></label><button class="btn btn-solid theme-primary"><?=e($bt('Vorherigen Abschluss übernehmen'))?></button></form>
<?php elseif($editable): ?><p role="alert">Zuerst die E-Bilanz des unmittelbar vorherigen Zeitraums abschließen.</p><?php else: ?><p><?=e($bt('Die Buchhaltung muss den bestätigten vorherigen Abschluss zuordnen.'))?></p><?php endif ?></section>
