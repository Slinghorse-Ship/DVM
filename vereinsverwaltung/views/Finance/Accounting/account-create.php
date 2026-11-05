<?php declare(strict_types=1);
$accountCreateState=\Kulturhus\Verein\Web\AccountCreateForm::read($_POST,$_GET);$accountCreateValues=$accountCreateState['values'];$accountKind=$accountCreateValues['kind'];$accountCreateUnsupported=$accountCreateState['unsupported'];$today=date('Y-m-d');
?>
<script defer src="account-create.js?v=3"></script><script defer src="bank-opening.js?v=1"></script>
<div class="content account-create-content">
 <div class="account-create-grid">
  <form method="post" class="card account-create-card" data-account-create data-account-save-label="<?= e($bankLedgerText('Konto speichern')) ?>" data-account-online-label="<?= e($bankLedgerText('Konto speichern und Onlinekonto verbinden')) ?>">
   <?= csrf().hidden('action','create-account') ?>
   <?php if($accountCreateState['posted']||$accountCreateUnsupported): ?><details data-account-create-original <?= $accountCreateUnsupported?'open':'' ?>><summary><?= e($bankLedgerText('Kontoanlage')) ?></summary><textarea class="form-control" readonly data-account-create-original-values aria-label="<?= e($bankLedgerText('Kontoanlage')) ?>"><?= e(\Kulturhus\Verein\Web\AccountSettingsForm::original($accountCreateState['original'])) ?></textarea></details><?php endif ?>
   <?php if($accountCreateUnsupported): ?><p class="error" role="alert" data-account-create-unsupported><?= e($bankLedgerText('Diese Kontoanlage wird nicht übernommen. Sichere ursprüngliche Angaben und öffne die Anlage bewusst neu.')) ?></p><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'finance','tab'=>'bank','new_account'=>1])) ?>"><?= e($bankLedgerText('Kontoanlage neu öffnen')) ?></a><?php endif ?>
   <span class="account-create-kicker"><?= e($bankLedgerText('Neues Finanzkonto')) ?></span>
   <h2><?= e($bankLedgerText('Konto oder Handkasse anlegen')) ?></h2>
    <p class="form-intro"><?= e($bankLedgerText('Wählen Sie genau eine Möglichkeit. Danach erscheint sie zusammen mit allen anderen Konten in derselben Übersicht.')) ?></p>
   <fieldset class="account-create-section" <?= $accountCreateUnsupported?'disabled':'' ?>>
    <legend><?= e($bankLedgerText('1. Kontoart wählen')) ?></legend>
    <div class="account-kind-options" role="radiogroup" aria-label="<?= e($bankLedgerText('Kontoart')) ?>">
      <label class="account-kind-option" data-account-kind-option="offline-online"><input class="check" type="radio" name="kind" value="offline-online" <?= $accountKind==='offline-online'?'checked':'' ?>><span><strong><?= e($bankLedgerText('Onlinekonto')) ?></strong><small><?= e($bankLedgerText('Bankkonto anlegen und im nächsten Schritt per FinTS verbinden.')) ?></small></span></label>
      <label class="account-kind-option" data-account-kind-option="offline-manual"><input class="check" type="radio" name="kind" value="offline-manual" <?= $accountKind==='offline-manual'?'checked':'' ?>><span><strong><?= e($bankLedgerText('Offlinekonto')) ?></strong><small><?= e($bankLedgerText('Bankkonto ohne direkte Verbindung; Umsätze per Datei oder von Hand erfassen.')) ?></small></span></label>
      <?php if($finance->cashEnabled()||$accountKind==='cash'): ?><label class="account-kind-option" data-account-kind-option="cash"><input class="check" type="radio" name="kind" value="cash" <?= $accountKind==='cash'?'checked':'' ?>><span><strong><?= e($bankLedgerText('Handkasse')) ?></strong><small><?= e($bankLedgerText('Bargeld im Kassenbuch führen.')) ?></small></span></label><?php endif ?>
      <?php if(!$accountCreateUnsupported&&!in_array($accountKind,\Kulturhus\Verein\Web\AccountCreateForm::MODES,true)): ?><label class="account-kind-option" data-account-kind-option="<?= e($accountKind) ?>"><input class="check" type="radio" name="kind" value="<?= e($accountKind) ?>" checked><span><strong><?= e($bankLedgerText('Ursprüngliche Kontoart nicht verfügbar: {value}',['value'=>$accountKind])) ?></strong><small><?= e($bankLedgerText('Wähle eine der angebotenen Kontoarten ausdrücklich.')) ?></small></span></label><?php endif ?>
    </div>
   </fieldset>
   <fieldset class="account-create-section" <?= $accountCreateUnsupported?'disabled':'' ?>>
    <legend><?= e($bankLedgerText('2. Grundangaben')) ?></legend>
    <div class="form-grid">
     <div><label for="account-create-name"><?= e($bankLedgerText('Bezeichnung')) ?> <span class="required">*</span></label><input id="account-create-name" class="form-control" name="name" required maxlength="300" aria-describedby="account-create-name-help" value="<?= e($accountCreateValues['name']) ?>" placeholder="<?= e($bankLedgerText('z. B. Vereinskonto Volksbank')) ?>"><small id="account-create-name-help" class="field-help"><?= e($bankLedgerText('Höchstens 150 Zeichen. Sonderzeichen bleiben erhalten.')) ?></small></div>
     <div><label for="account-create-opening"><?= e($bankLedgerText('Anfangsbestand in Euro')) ?> <span class="required">*</span></label><div class="bank-opening-controls"><input id="account-create-opening" class="form-control" name="opening" inputmode="text" required value="<?= e($accountCreateValues['opening']) ?>" aria-describedby="account-opening-help"><button type="button" hidden class="btn btn-outline theme-secondary" data-opening-sign-toggle aria-pressed="false"><?= e($bankLedgerText('Vorzeichen wechseln')) ?></button></div><small id="account-opening-help" class="field-help"><?= e($bankLedgerText('Meist 0,00. Bereits vorhandenes Guthaben hier eintragen.')) ?> <?= e($bankLedgerText('Für ein Bankkonto im Minus ein Minusvorzeichen verwenden. Eine Handkasse kann nicht im Minus beginnen.')) ?></small></div>
     <label><?= e($bankLedgerText('Bestand ab')) ?> <span class="required">*</span><input class="form-control" name="opened_on" placeholder="YYYY-MM-DD" type="<?= \Kulturhus\Verein\Web\AccountSettingsForm::dateInputType($accountCreateValues['opened_on']) ?>" max="<?= e($today) ?>" required value="<?= e($accountCreateValues['opened_on']) ?>"><small class="field-help"><?= e($bankLedgerText('Ab diesem Datum gilt der Anfangsbestand.')) ?></small></label>
    </div>
   </fieldset>
   <div class="account-create-actions"><button class="btn btn-solid theme-primary" id="account-create-submit" type="submit" <?= $accountCreateUnsupported?'disabled':'' ?>><?= e($bankLedgerText('Konto speichern')) ?></button><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$accountId])) ?>"><?= e($bankLedgerText('Abbrechen')) ?></a></div>
  </form>
   <section class="card account-create-help" aria-live="polite">
    <span class="account-create-kicker"><?= e($bankLedgerText('Ihre Auswahl')) ?></span>
    <div data-account-help="offline-online"><h2><?= e($bankLedgerText('Onlinekonto')) ?></h2><p><?= e($bankLedgerText('Nach dem Speichern öffnen Sie direkt die sichere FinTS-Verbindung. Dort wählen Sie Ihre Bank und melden sich mit den von der Bank verlangten Angaben an.')) ?></p><p class="muted"><?= e($bankLedgerText('PIN und Zugangsdaten werden nur verschlüsselt und nur bei ausdrücklicher Auswahl dauerhaft gespeichert. TANs und Freigabecodes werden nie gespeichert.')) ?></p></div>
    <div data-account-help="offline-manual"><h2><?= e($bankLedgerText('Offlinekonto')) ?></h2><p><?= e($bankLedgerText('Das Konto wird nur im Vereinsmanager geführt. Umsätze können später als Datei importiert oder von Hand eingetragen werden.')) ?></p></div>
    <div data-account-help="cash"><h2><?= e($bankLedgerText('Handkasse')) ?></h2><p><?= e($bankLedgerText('Die Kasse wird als Kassenbuch geführt. Einnahmen, Ausgaben und der Kassenabschluss bleiben getrennt von Bankkonten.')) ?></p></div>
   </section>
 </div>
</div>
