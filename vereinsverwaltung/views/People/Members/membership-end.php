<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$editingEnd=input($_POST,'action')==='leave'&&input($_POST,'id')===$profile['id']&&input($_POST,'membership')===$m['id'];
?>
<details <?= $editingEnd?'open':'' ?>><summary><?= e($memberProfileText('Austritt erfassen')) ?></summary><form method="post" class="filter-form">
<?= csrf().hidden('action','leave').hidden('id',$profile['id']).hidden('membership',$m['id']).hidden('version',$editingEnd?input($_POST,'version'):$m['version']) ?>
<label><?= e($memberProfileText('Austrittsdatum')) ?><input class="form-control" name="end" type="date" required min="<?= e($m['starts_on']) ?>" value="<?= e($editingEnd?input($_POST,'end'):'') ?>"></label>
<button class="btn btn-outline theme-secondary secondary"><?= e($memberProfileText('Mitgliedschaft beenden')) ?></button></form></details>
