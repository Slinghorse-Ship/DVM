<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$membershipDraft = ($profile && input($_POST,'action')==='join' && input($_POST,'id')===$profile['id'])
    || (!$profile && input($_POST,'action')==='save' && input($_POST,'id')==='');
$membershipInput = $membershipDraft ? $_POST : [];
$joinFamily=$profile?$registry->family($profile['id']):null;
?>
<div class="member-membership-fields">
 <noscript><p><?= e($memberProfileText('Je nach Datumsgenauigkeit ist das Eintrittsdatum oder das Eintrittsjahr erforderlich.')) ?></p></noscript>
 <div class="form-grid">
  <label><?= e($memberProfileText('Status')) ?><select class="form-control" name="status"><option value="aktiv"><?= e($memberProfileText('Aktiv')) ?></option><option value="passiv" <?= input($membershipInput,'status')==='passiv'?'selected':'' ?>><?= e($memberProfileText('Passiv')) ?></option></select></label>
  <label><?= e($memberProfileText('Datumsgenauigkeit')) ?><select class="form-control" name="start_precision"><option value="day"><?= e($memberProfileText('Genaues Datum bekannt')) ?></option><option value="year" <?= input($membershipInput,'start_precision')==='year'?'selected':'' ?>><?= e($memberProfileText('Nur Eintrittsjahr bekannt')) ?></option></select></label>
  <label><?= e($memberProfileText('Eintrittsjahr')) ?> <span class="required">*</span><input class="form-control" name="start_year" type="number" min="1800" max="<?= date('Y') ?>" value="<?= e(input($membershipInput,'start_year')) ?>" data-membership-date-required><small><?= e($memberProfileText('Nur bei „Nur Eintrittsjahr bekannt“ ausfüllen.')) ?></small></label>
  <label><?= e($memberProfileText('Eintrittsdatum')) ?> <span class="required">*</span><input class="form-control" type="date" name="start" value="<?= e(input($membershipInput,'start')) ?>" data-membership-date-required><small><?= e($memberProfileText('Bitte das tatsächliche Eintrittsdatum eintragen.')) ?></small></label>
 </div>
 <?= hidden('expected_family',$membershipDraft?input($membershipInput,'expected_family'):($joinFamily['id']??'')) ?>
 <div class="form-grid">
  <label><?= e($memberProfileText('Einzelmitglied oder Familie')) ?><select class="form-control" name="family"><option value=""><?= e($memberProfileText('Einzelmitglied / keine Familie')) ?></option><option value="new" <?= input($membershipInput,'family')==='new'?'selected':'' ?>><?= e($memberProfileText('Neue Familie anlegen')) ?></option><?php foreach($registry->families() as $option): ?><option value="<?= e($option['id']) ?>" <?= input($membershipInput,'family',$joinFamily['id']??'')===$option['id']?'selected':'' ?>><?= e($option['name'].' · '.$option['payer_name']) ?></option><?php endforeach ?></select></label>
  <label><?= e($memberProfileText('Name bei neuer Familie')) ?><input class="form-control" name="family_name" maxlength="200" value="<?= e(input($membershipInput,'family_name')) ?>"></label>
 </div>
 <details class="member-profile-inline" <?= input($membershipInput,'number')!==''?'open':'' ?>><summary><?= e($memberProfileText('Mitgliedsnummer festlegen (optional)')) ?></summary><label><?= e($memberProfileText('Mitgliedsnummer')) ?><input class="form-control" name="number" placeholder="<?= e($memberProfileText('Leer = automatisch')) ?>" maxlength="80" value="<?= e(input($membershipInput,'number')) ?>"></label><p class="muted"><?= e($memberProfileText('Ohne Eingabe vergibt das Programm automatisch die nächste Mitgliedsnummer.')) ?></p></details>
 <details class="member-profile-inline" <?= input($membershipInput,'access_mail')==='1'?'open':'' ?>><summary><?= e($memberProfileText('Website-Zugang und Zugangsmail')) ?></summary><?php $accessMailInput=$membershipInput;require __DIR__.'/../../Help/manual-account-option.php';unset($accessMailInput); ?></details>
</div>
