<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$editingStart=input($_POST,'action')==='membership-start'&&input($_POST,'id')===$profile['id']&&input($_POST,'membership')===$m['id'];
$startPrecision=$editingStart?input($_POST,'start_precision'):($m['starts_precision']??'day');
?>
<details <?= $editingStart?'open':'' ?>><summary><?= e($memberProfileText('Eintritt bearbeiten')) ?></summary><form method="post" class="filter-form">
<?= csrf().hidden('action','membership-start').hidden('id',$profile['id']).hidden('membership',$m['id']).hidden('version',$editingStart?input($_POST,'version'):$m['version']) ?>
<label><?= e($memberProfileText('Datumsgenauigkeit')) ?><select class="form-control" name="start_precision"><option value="day" <?= $startPrecision==='day'?'selected':'' ?>><?= e($memberProfileText('Genaues Datum bekannt')) ?></option><option value="year" <?= $startPrecision==='year'?'selected':'' ?>><?= e($memberProfileText('Nur Eintrittsjahr bekannt')) ?></option></select></label>
<label><?= e($memberProfileText('Eintrittsdatum')) ?> <span class="required">*</span><input class="form-control" type="date" name="start" value="<?= e($editingStart?input($_POST,'start'):($startPrecision==='day'?$m['starts_on']:'')) ?>" <?= $m['ends_on']?'max="'.e($m['ends_on']).'"':'' ?> data-membership-date-required><small><?= e($memberProfileText('Bitte das tatsächliche Eintrittsdatum eintragen.')) ?></small></label>
<label><?= e($memberProfileText('Eintrittsjahr')) ?> <span class="required">*</span><input class="form-control" type="number" name="start_year" min="1800" max="<?= date('Y') ?>" value="<?= e($editingStart?input($_POST,'start_year'):substr($m['starts_on'],0,4)) ?>" data-membership-date-required><small><?= e($memberProfileText('Nur bei „Nur Eintrittsjahr bekannt“ ausfüllen.')) ?></small></label>
<p class="muted"><?= e($memberProfileText('Bereits ausgestellte Beitragsrechnungen werden durch die Datumskorrektur nicht neu berechnet.')) ?></p><button class="btn btn-solid theme-primary"><?= e($memberProfileText('Eintritt speichern')) ?></button>
</form></details>
