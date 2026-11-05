<?php declare(strict_types=1);
$capitalText=$bt??static fn(string $source):string=>$source;
if(!isset($values['capital_comparison']))return;
$capital=$values['capital_comparison'];
$capitalInput=$capital['decisions'];
if(input($_POST,'action')==='tax-filing-balance-capital'&&input($_POST,'filing')===$fid&&is_array($_POST['capital_movements']??null))$capitalInput=$_POST['capital_movements'];
?>
<details class="card" <?= !$capital['complete']?'open':'' ?>><summary><?= e($capitalText('Kapital zu Jahresanfang und Jahresende')) ?></summary>
<div data-balance-specialist-readonly data-balance-readonly-title="<?= e($capitalText('Kapital zu Jahresanfang und Jahresende')) ?>"><dl><dt><?= e($capitalText('Kapital am Jahresanfang · Steuerwert')) ?></dt><dd><?= $money($capital['tax_opening_cents']) ?></dd><dt><?= e($capitalText('Kapital am Jahresende · Steuerwert')) ?></dt><dd><?= $money($capital['tax_closing_cents']) ?></dd><dt><?= e($capitalText('Jahresergebnis')) ?></dt><dd><?= $money($capital['profit_cents']) ?></dd></dl></div>
<?php if(!$capital['sources']): ?><p><?= e($capitalText('Keine Kapitalzuführungen, Entnahmen oder Kapitalumbuchungen.')) ?></p>
<?php else: ?><form method="post"><?= $hidden.hidden('action','tax-filing-balance-capital') ?><fieldset <?= !$editable?'disabled':'' ?>>
<?php foreach($capital['sources'] as $key=>$row): $decision=is_array($capitalInput[$key]??null)?$capitalInput[$key]:[]; ?>
<fieldset><legend><?= e($row['date'].' · '.$row['description']) ?></legend><p><?= e($values['accounts'][$row['account']]['name']) ?> · <?= $money($row['credit_cents']-$row['debit_cents']) ?></p>
<label><?= e($capitalText('Was wurde gebucht?')) ?><select class="form-control" name="capital_movements[<?= e($key) ?>][kind]" required><option value=""><?= e($capitalText('Bitte auswählen')) ?></option><?php foreach(['contribution'=>'Kapital zugeführt','withdrawal'=>'Kapital entnommen / ausgeschüttet','reclassification'=>'Nur zwischen Kapitalkonten umgebucht'] as $kind=>$label): ?><option value="<?= e($kind) ?>" <?= ($decision['kind']??'')===$kind?'selected':'' ?>><?= e($capitalText($label)) ?></option><?php endforeach ?></select></label>
<label><?= e($capitalText('Grund / Beleg')) ?><input class="form-control" name="capital_movements[<?= e($key) ?>][reason]" value="<?= e(is_string($decision['reason']??null)?$decision['reason']:'') ?>" maxlength="1000" required></label></fieldset>
<?php endforeach ?>
<?php if(!$capital['complete']): ?><p role="alert"><?= e($capitalText('Alle Kapitalbewegungen zuordnen. Reine Umbuchungen müssen innerhalb derselben Buchung ausgeglichen sein.')) ?></p><?php endif ?>
<?php if($editable): ?><button class="btn btn-solid theme-primary"><?= e($capitalText('Kapitalbewegungen speichern')) ?></button><?php endif ?></fieldset></form><?php endif ?></details>
