<?php declare(strict_types=1);
// Optional bookkeeping details; ordinary invoice processing is unchanged.
require_once __DIR__.'/../../../src/Finance/Balance/BalanceTaxMapping.php';
try{$chartChoices=\Kulturhus\Verein\BalanceTaxMapping::chartChoices($year);}catch(DomainException){$chartChoices=[];}
?>
<details class="card"><summary><?= e($annualSourceText('Buchhaltungsdetails: zusätzliches Konto einrichten')) ?></summary>
<form method="post"><?= $hidden.hidden('action','balance-account') ?>
<label><?= e($annualSourceText('SKR42-Konto')) ?><select class="form-control" name="skr_code" data-searchable-select><option value=""><?= e($annualSourceText('Eigenes Konto ohne SKR42-Nummer')) ?></option><?php foreach($chartChoices as $code=>$choice): ?><option value="<?= e($code) ?>"><?= e($code.' · '.$choice['name']) ?></option><?php endforeach ?></select></label>
<label><?= e($annualSourceText('Interne Kennung')) ?><input class="form-control" name="account_id" pattern="[a-z][a-z0-9_-]{0,39}" maxlength="40" required></label>
<label><?= e($annualSourceText('Eigene Bezeichnung · optional bei SKR42')) ?><input class="form-control" name="account_name" maxlength="160"></label>
<label><?= e($annualSourceText('Bilanzbereich · falls nicht automatisch zugeordnet')) ?><select class="form-control" name="account_group"><option value=""><?= e($annualSourceText('Bitte auswählen')) ?></option><?php foreach(\Kulturhus\Verein\BalanceCalculation::GROUPS as $key=>[$label]): ?><option value="<?= e($key) ?>"><?= e($annualSourceText($label)) ?></option><?php endforeach ?></select></label>
<?= hidden(\Kulturhus\Verein\BalanceSetupForm::COMPLETE,'1') ?><button class="btn btn-solid theme-primary"><?= e($annualSourceText('Konto hinzufügen')) ?></button></form></details>
