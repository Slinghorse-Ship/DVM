<?php

declare(strict_types=1);
$publicFinTsMock=class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active();


if (!$canFinance || !$finance->canWrite() || ($enableEnvironment ?? 'PRODUCTION') !== 'PRODUCTION') return;

$fintsMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$fintsText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\BankDialogMessages::text($source,$fintsMessages,$values);
$fintsStatus = $fintsStatus ?? [];
$fintsCredentialStatus = $fintsCredentialStatus ?? ['saved' => false, 'expires' => 0, 'bank_id' => ''];
$fintsDefaultBank = $fintsDefaultBank ?? [];
$fintsImportPosted=input($_POST,'action')==='fints-import';
$fintsTargetAccount = $fintsImportPosted?input($_POST,'account_id'):(string)($_SESSION['fints_target_account'] ?? '');
$fintsTargetAccountName = '';
if ($fintsTargetAccount !== '') {
    try { $fintsTargetAccountName = (string)$finance->account($fintsTargetAccount)['name']; } catch (DomainException) { if(!$fintsImportPosted)$fintsTargetAccount = ''; }
}
$phase = (string)($fintsStatus['phase'] ?? '');
$version = (int)($fintsStatus['version'] ?? 0);
$paymentKind = (string)input($_GET, 'payment', input($_POST, 'payment_kind'));
if (!in_array($paymentKind, ['transfer', 'direct-debit'], true)) $paymentKind = in_array(($fintsStatus['task'] ?? ''), ['transfer', 'direct-debit'], true) ? (string)$fintsStatus['task'] : '';
$paymentOrderId = (string)input($_GET, 'payment_order', input($_POST, 'payment_order'));
$paymentOrder = null;
$paymentError = '';
if ($paymentOrderId !== '' && $paymentKind !== '') {
    try {
        $paymentService = $paymentKind === 'transfer'
            ? new \Kulturhus\Verein\TransferOrders($db, (string)$_SESSION['actor'], \Kulturhus\Verein\MemberBankVault::fromEnvironment($db, (string)$_SESSION['actor'], \Kulturhus\Verein\ProductionRuntime::enabled()))
            : new \Kulturhus\Verein\SepaOrders($db, (string)$_SESSION['actor'], \Kulturhus\Verein\MemberBankVault::fromEnvironment($db, (string)$_SESSION['actor'], \Kulturhus\Verein\ProductionRuntime::enabled()));
        $paymentOrder = $paymentService->get($paymentOrderId);
    } catch (DomainException $ex) { $paymentError = $ex->getMessage(); }
}
$paymentTransport = null;
if ($paymentOrder) {
    try { $paymentTransport = $paymentService->decisionSnapshot($paymentOrderId)['transport']; }
    catch (DomainException $ex) { $paymentError = $ex->getMessage(); }
}
$paymentMode = $paymentOrder !== null;
$fintsStatusPaymentOrder = (string)($fintsStatus['payment_order'] ?? '');
// A dialog is bound to one payment order.  If the user opens another order
// after a completed demo/production dialog, do not carry the old result into
// the new order's card; require its own bank start instead.
$fintsPaymentMatches = !$paymentMode || $fintsStatusPaymentOrder === '' || hash_equals($fintsStatusPaymentOrder, (string)$paymentOrderId);
if ($paymentMode && !$fintsPaymentMatches) {
    $phase = '';
}
$fintsPaymentAuth = $paymentMode && $fintsPaymentMatches && $fintsStatusPaymentOrder !== '';
$paymentSnapshot = $paymentMode && is_array($paymentOrder['snapshot'] ?? null) ? $paymentOrder['snapshot'] : [];
$paymentRecipient = is_array($paymentSnapshot['recipient'] ?? null) ? $paymentSnapshot['recipient'] : [];
$fintsBanks = [];
$fintsBankDirectoryError = '';
if (($fintsAvailable ?? false) === true) {
    if (\Kulturhus\Verein\LocalRuntime::demo()) {
        // A demo must never expose or accidentally select a real institution.
        $fintsBanks = $fintsDefaultBank !== [] ? [$fintsDefaultBank] : [];
    } else {
        try { $fintsBanks = \Kulturhus\Verein\FintsInstitutions::all(); } catch (DomainException $ex) { $fintsBankDirectoryError = $ex->getMessage(); }
    }
}
$savedBankId = (string)($fintsCredentialStatus['bank_id'] ?? '');
$selectedBankId = (string)input($_POST, 'bank_id', $savedBankId !== '' ? $savedBankId : (string)($fintsDefaultBank['id'] ?? ''));
$savedBank = null;
if ($savedBankId !== '') {
    foreach ($fintsBanks as $bank) {
        if ((string)$bank['id'] === $savedBankId) {
            $savedBank = $bank;
            break;
        }
    }
}
$paymentFinished = $paymentMode && ($phase === 'payment_done' || $paymentOrder['state'] === 'submitted');
$paymentBlocked = $paymentError !== '' || ($paymentOrderId !== '' && !$paymentMode)
    || ($paymentMode && !$paymentFinished && ($paymentOrder['state'] !== 'approved' || !$paymentService->canSend()))
    || (($paymentTransport['pending'] ?? false) && !($fintsPaymentAuth && in_array($phase, ['tan','decoupled','poll','vop'], true)));

$fintsMethodForm=\Kulturhus\Verein\Web\BankDialogFields::state($_POST,'fints-select',['mode'=>(string)($fintsStatus['selected_mode']??''),'medium'=>'']);
$fintsStatementForm=\Kulturhus\Verein\Web\BankDialogFields::state($_POST,'fints-statement',['uid'=>'','from'=>'2000-01-01','to'=>\Kulturhus\Verein\Finance::today()]);
$fintsStep=match(true){$paymentFinished=>'Zahlung eingereicht',$phase==='interrupted'=>'Bankdialog unterbrochen',$phase==='ready'=>'Übernahme prüfen',in_array($phase,['select','tan','decoupled','poll','vop'],true)=>'Bestätigung',$phase==='accounts'=>$paymentMode?'Zahlung prüfen':'Kontoumsätze wählen',default=>'Anmeldung'};
?>
<?php if ($paymentTransport['pending'] ?? false): ?><section class="card fints-pending" role="status"><p><?= e($fintsText('Der FinTS-Ausgang dieses Zahlungsauftrags ist noch offen. Ein neuer Dialog oder ein manueller Export darf denselben Auftrag nicht nochmals einreichen. Den ursprünglichen Freigabedialog fortsetzen oder den tatsächlichen Bankstatus anhand eines Nachweises klären.')) ?></p><a class="btn btn-outline theme-secondary" href="<?=e(url($paymentKind==='transfer'?['view'=>'finance','tab'=>'receipts','entry'=>$paymentOrder['entry_id'],'transfer_order'=>$paymentOrderId]:['view'=>'finance','tab'=>'debit','sepa_order'=>$paymentOrderId]))?>"><?= e($fintsText('Offenen Zahlungsauftrag anhand Banknachweis klären')) ?></a></section><?php endif ?>
<section class="content">
<section class="card fints-workspace<?= $fintsImportPosted||(!$paymentMode&&$phase==='ready')?' fints-import-workspace':'' ?>" id="fints-manual" data-fints-workspace>
<h2><?= e($paymentMode ? ($paymentKind === 'transfer' ? $fintsText('Überweisung') : $fintsText('Lastschrift')) : $fintsText('Bankzugang (FinTS)')) ?></h2>
<p class="fints-stage" data-fints-stage role="status"><?= e($fintsText('Bankdialog: {step}',['step'=>$fintsText($fintsStep)])) ?></p>
<?php if(!$paymentMode&&$fintsTargetAccountName!==''): ?><p><a href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$fintsTargetAccount,'edit_account'=>1])) ?>"><?= e($fintsText('Konto bearbeiten')) ?></a></p><?php endif ?>
<?php if (\Kulturhus\Verein\LocalRuntime::demo()): ?><p class="notice"><strong><?= e($fintsText('Demo: kein echtes Geld.')) ?></strong> <?= e($fintsText('Test-TAN: 123456.')) ?></p><?php endif ?>
<?php if (!empty($bankWorkflowError)): ?><p class="error" role="alert" data-fints-error><?= e($fintsText($bankWorkflowError)) ?></p><?php endif ?>
<?php if ($paymentError !== ''): ?><p class="error" role="alert"><?= e($fintsText($paymentError)) ?></p><?php endif ?>
<?php if($fintsImportPosted&&$phase!=='ready'):$savedBankContext=array_replace(bankImportContext($_GET),['account'=>$fintsTargetAccountName!==''?$fintsTargetAccount:'','import_state'=>'','import_q'=>'']); ?>
<section class="card"><h3><?= e($fintsText('Bankübernahme prüfen')) ?></h3><p><?= e($fintsText('Der Bankdialog hat inzwischen einen anderen Stand. Deine ursprüngliche Kontowahl und Übernahme bleiben erhalten. Einen bereits gespeicherten Ausgang kannst du hier erneut prüfen.')) ?></p><p><?= e($fintsTargetAccountName!==''?$fintsTargetAccountName:$fintsText('Frühere Kontowahl erneut prüfen')) ?></p>
<form method="post" data-fints-import><?= csrf().hidden('action','fints-import').hidden('account_id',$fintsTargetAccount).hidden('dialog_version',input($_POST,'dialog_version')).hidden('request_key',input($_POST,'request_key')) ?><button class="btn btn-outline theme-secondary"><?= e($fintsText('Ursprüngliche Übernahme erneut prüfen')) ?></button></form>
<div class="actions"><a class="btn btn-outline theme-secondary" href="<?= e(url($savedBankContext)) ?>"><?= e($fintsText('Gespeicherten Bankstand prüfen')) ?></a><a class="btn btn-outline theme-secondary" href="<?= e(url(bankListContext($_GET)+['fints'=>'1'])) ?>"><?= e($fintsText('Aktuellen Bankabruf neu öffnen')) ?></a></div></section>
<?php endif ?>

<?php if ($paymentMode): ?>
    <section class="fints-payment-summary" id="fints-payment" aria-label="<?= e($fintsText('Ihre Zahlung')) ?>">
        <dl class="data-list">
            <dt><?= e($fintsText('Betrag')) ?></dt><dd><strong><?= e(euro((int)$paymentOrder['total_cents'])) ?></strong></dd>
            <?php if ($paymentKind === 'transfer'): ?>
                <dt><?= e($fintsText('Empfänger')) ?></dt><dd><?= e((string)($paymentRecipient['name'] ?? $fintsText('Bitte prüfen'))) ?></dd>
                <dt>IBAN</dt><dd><?= e(trim(chunk_split((string)($paymentRecipient['iban'] ?? ''),4,' '))) ?></dd>
                <dt><?= e($fintsText('Zahlungsart')) ?></dt><dd><?= e(($paymentSnapshot['transfer_mode'] ?? 'standard') === 'instant' ? $fintsText('Echtzeitüberweisung') : $fintsText('Normale Überweisung')) ?></dd>
                <dt><?= e($fintsText('Ausführung')) ?></dt><dd><?= e(dateDe((string)($paymentSnapshot['execution_date'] ?? ''))) ?></dd>
                <dt><?= e($fintsText('Verwendungszweck')) ?></dt><dd><?= e((string)($paymentSnapshot['purpose'] ?? '')) ?></dd>
            <?php else: ?>
                <dt><?= e($fintsText('Auftrag')) ?></dt><dd><?= e($fintsText('Freigegebene Lastschriften')) ?></dd>
            <?php endif ?>
        </dl>
    </section>
<?php endif ?>

<?php if ($paymentFinished): ?>
    <p class="success" role="status"><strong><?= e($fintsText('Bei der Bank eingereicht.')) ?></strong> <?= e($fintsText('Bitte nicht noch einmal überweisen.')) ?></p>
    <p><?= e($fintsText('Als bezahlt angezeigt, sobald der passende Kontoumsatz zugeordnet ist.')) ?></p>
    <?php if (($fintsStatus['payment_success'] ?? '') !== '' && $fintsPaymentMatches): ?><p role="status"><?= e($fintsStatus['payment_success']) ?></p><?php endif ?>
<?php elseif ($publicFinTsMock && $phase === 'payment_declined'): ?>
    <p class="notice" role="alert"><?= e($fintsText('Simulation: Die Demo-Bank hat den Auftrag abgelehnt. Keine Zahlung ausgeführt. Den Auftrag erneut prüfen.')) ?></p>
<?php elseif ($paymentBlocked): ?>
    <?php if ($paymentMode): ?><p class="notice"><?= e($paymentOrder['state'] === 'approved' ? $fintsText('Für diese Zahlung fehlt Ihnen die Berechtigung.') : $fintsText('Dieser Auftrag ist nicht zum Senden freigegeben. Bitte zur Rechnung zurückgehen.')) ?></p><?php endif ?>
<?php elseif (!($fintsAvailable ?? false)): ?>
    <p class="notice"><?= e($fintsText('Der Bankzugang ist noch nicht eingerichtet. Bitte die zuständige Person im Verein fragen.')) ?></p>
<?php elseif ($phase === '' || $phase === 'interrupted'): ?>
    <?php if ($phase === 'interrupted'): ?><p class="notice" role="alert"><strong><?= e($fintsText('Die Bankverbindung wurde unterbrochen.')) ?></strong><?php if ($paymentMode): ?> <?= e($fintsText('Prüfen Sie zuerst bei Ihrer Bank, ob die Zahlung bereits eingereicht wurde. Nicht einfach erneut senden.')) ?><?php else: ?> <?= e($fintsText('Bitte erneut verbinden.')) ?><?php endif ?></p><?php endif ?>
    <h3><?= e($fintsText('Mit Ihrer Bank verbinden')) ?></h3>
    <?php if (!empty($fintsCredentialStatus['saved'])): ?>
        <p><?= e((string)($savedBank['name'] ?? $fintsDefaultBank['name'] ?? $fintsText('Ihr gespeicherter Bankzugang'))) ?></p>
        <form method="post"><?= csrf().hidden('action','fints-start-saved').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?><button class="btn btn-solid theme-primary"><?= e($fintsText('Mit Bank verbinden')) ?></button></form>
        <details <?= input($_GET,'edit_fints')==='1'?'open':'' ?>><summary><?= e($fintsText('Bank, Benutzername oder PIN ändern')) ?></summary>
    <?php endif ?>
    <form method="post" class="fints-login-form">
        <?= csrf().hidden('action','fints-start').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?>
        <?php if ($fintsBanks): ?>
        <label><?= e($fintsText('Ihre Bank')) ?><select class="form-control" name="bank_id" required data-select-search><option value=""><?= e($fintsText('Bank auswählen')) ?></option>
            <?php foreach ($fintsBanks as $bank): ?><option value="<?= e((string)$bank['id']) ?>" <?= $selectedBankId === (string)$bank['id'] ? 'selected' : '' ?>><?= e($bank['name'].' · '.$bank['city'].' · BLZ '.$bank['bank_code']) ?></option><?php endforeach ?>
        </select></label>
        <?php else: ?><p><?= e((string)($fintsDefaultBank['name'] ?? $fintsText('Hinterlegte Bank'))) ?></p><?php endif ?>
        <label><?= e($fintsText('Benutzername bei Ihrer Bank')) ?><input class="form-control" name="user" required maxlength="200" <?= $publicFinTsMock?'value="demo" readonly autocomplete="off"':'autocomplete="username"' ?>></label>
        <label><?= e($fintsText('Onlinebanking-PIN')) ?><input class="form-control" type="password" name="pin" required maxlength="200" <?= $publicFinTsMock?'value="demo" readonly autocomplete="off"':'autocomplete="current-password"' ?>></label>
        <?php if($publicFinTsMock): ?><p><?= e($fintsText('FinTS-Mockup: Nur fiktive Zugangsdaten demo / demo. Keine echte Bankverbindung.')) ?></p><?php else: ?><label class="check"><input class="check" type="checkbox" name="remember" value="1"> <?= e($fintsText('Bankzugang verschlüsselt speichern')) ?></label><?php endif ?>
        <button class="btn btn-solid theme-primary"><?= e($fintsText('Mit Bank verbinden')) ?></button>
    </form>
    <?php if (!empty($fintsCredentialStatus['saved'])): ?>
        <form method="post"><?= csrf().hidden('action','fints-forget').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?><button class="btn btn-outline theme-secondary secondary"><?= e($fintsText('Gespeicherten Bankzugang löschen')) ?></button></form>
        </details>
    <?php endif ?>
<?php elseif ($phase === 'select'): ?>
    <h3><?= e($fintsText('Wie bestätigen Sie bei Ihrer Bank?')) ?></h3>
    <form method="post">
        <?= csrf().hidden('action','fints-select').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?>
        <?php $fintsChoiceForm=$fintsMethodForm;require __DIR__.'/fints-choice-original.php'; ?>
        <fieldset class="fints-choice-controls" <?= $fintsMethodForm['unsupported']?'disabled':'' ?>>
        <?php $chosenMode=$fintsMethodForm['values']['mode'];$offeredModes=array_map(static fn(array $mode):string=>(string)$mode['id'],$fintsStatus['modes']??[]); ?>
        <label><?= e($fintsText('Bestätigungsart')) ?><select class="form-control" name="mode" required><option value=""><?= e($fintsText('Bitte auswählen')) ?></option><?php if($chosenMode!==''&&!in_array($chosenMode,$offeredModes,true)): ?><option value="<?= e($chosenMode) ?>" selected><?= e($fintsText('Ursprüngliche Auswahl nicht verfügbar: {value}',['value'=>$chosenMode])) ?></option><?php endif ?><?php foreach ($fintsStatus['modes'] ?? [] as $mode): ?><option value="<?= e((string)$mode['id']) ?>" <?= $chosenMode===(string)$mode['id']?'selected':'' ?>><?= e($mode['name']) ?></option><?php endforeach ?></select></label>
        <?php if (!empty($fintsStatus['media'])||$fintsMethodForm['values']['medium']!==''):$chosenMedium=$fintsMethodForm['values']['medium']; ?>
        <p><?= e($fintsText('Die Bank benötigt zusätzlich ein Gerät. Das gewählte TAN-Verfahren bleibt erhalten.')) ?></p>
        <label><?= e($fintsText('Ihr Gerät')) ?><select class="form-control" name="medium" required><option value=""><?= e($fintsText('Bitte auswählen')) ?></option><?php if($chosenMedium!==''&&!in_array($chosenMedium,$fintsStatus['media']??[],true)): ?><option value="<?= e($chosenMedium) ?>" selected><?= e($fintsText('Ursprüngliche Auswahl nicht verfügbar: {value}',['value'=>$chosenMedium])) ?></option><?php endif ?><?php foreach ($fintsStatus['media']??[] as $medium): ?><option <?= $chosenMedium===$medium?'selected':'' ?>><?= e($medium) ?></option><?php endforeach ?></select></label><?php endif ?>
        <button class="btn btn-solid theme-primary"><?= e($fintsText('Weiter')) ?></button>
        </fieldset>
    </form>
<?php elseif ($phase === 'accounts' && $paymentMode): ?>
    <h3><?= e($fintsText('Stimmen die Angaben oben?')) ?></h3>
    <form method="post">
        <?= csrf().hidden('action',$paymentKind === 'transfer' ? 'fints-transfer-start' : 'fints-direct-debit-start').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrder['id']).hidden('payment_order_version',(string)$paymentOrder['version']).hidden('payment_kind',$paymentKind) ?>
        <?php if (\Kulturhus\Verein\LocalRuntime::demo()): ?><label><?= e($fintsText('Demo-Bestätigung')) ?><select class="form-control" name="demo_auth"><option value="app"><?= e($fintsText('Banking-App')) ?></option><option value="tan">TAN · 123456</option></select></label><?php endif ?>
        <?php if($publicFinTsMock): ?><label><?= e($fintsText('Mockup-Szenario')) ?><select class="form-control" name="demo_result"><option value="success"><?= e($fintsText('Auftrag angenommen')) ?></option><option value="declined"><?= e($fintsText('Auftrag abgelehnt')) ?></option><option value="interrupted"><?= e($fintsText('Bestätigung unterbrochen')) ?></option></select></label><?php endif ?>
        <button class="btn btn-solid theme-primary"><?= e(euro((int)$paymentOrder['total_cents'])) ?> <?= e($paymentKind === 'transfer' ? $fintsText('überweisen') : $fintsText('per Lastschrift einziehen')) ?></button>
    </form>
    <p class="muted"><?= e($fintsText('Ihre Bank zeigt danach die erforderliche Empfängerprüfung oder Bestätigung.')) ?></p>
<?php elseif ($phase === 'vop'): ?>
    <?php $payeeCheck = \Kulturhus\Banking\PaymentRules::payee($fintsStatus); ?>
    <h3><?= e($fintsText('Passt der Name zur IBAN?')) ?></h3>
    <div class="<?= $payeeCheck['warning'] ? 'notice' : 'success' ?>" role="alert">
        <strong><?= e($fintsText($payeeCheck['label'])) ?></strong>
        <?php if (($fintsStatus['vop_info'] ?? '') !== ''): ?><p><?= e($fintsStatus['vop_info']) ?></p><?php endif ?>
        <?php if (($fintsStatus['vop_reason'] ?? '') !== ''): ?><p><?= e($fintsStatus['vop_reason']) ?></p><?php endif ?>
    </div>
    <?php if ($payeeCheck['expired']): ?>
        <p class="error" role="alert"><?= e($fintsText('Die Prüfung ist abgelaufen. Bankverbindung beenden und erneut verbinden.')) ?></p>
    <?php else: ?>
        <form method="post">
            <?= csrf().hidden('action','fints-continue').hidden('dialog_version',(string)$version).hidden('confirm_vop','1').hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?>
            <?php if ($payeeCheck['warning']): ?>
                <p><?= e($fintsText('Bei falschen Bankdaten kann das Geld verloren gehen. Im Zweifel abbrechen und den Empfänger fragen.')) ?></p>
                <label class="check"><input class="check" type="checkbox" name="acknowledge_vop_warning" value="1" required> <?= e($fintsText('Ich habe Name und IBAN geprüft und möchte trotz der Warnung überweisen.')) ?></label>
            <?php endif ?>
            <button class="btn btn-solid theme-primary"><?= e($payeeCheck['warning'] ? $fintsText('Trotz Warnung weiter') : $fintsText('Weiter')) ?></button>
        </form>
    <?php endif ?>
<?php elseif (in_array($phase,['tan','decoupled','poll'],true)): ?>
    <h3><?= e($phase === 'tan' ? $fintsText('TAN eingeben') : ($phase === 'decoupled' ? $fintsText('In Ihrer Banking-App bestätigen') : $fintsText('Die Bank bearbeitet den Auftrag'))) ?></h3>
    <?php if (($fintsStatus['challenge'] ?? '') !== ''): ?><p role="status"><?= e($fintsStatus['challenge']) ?></p><?php endif ?>
    <?php if ($phase === 'decoupled'): ?><p><?= e($fintsText('Öffnen Sie Ihre Banking-App und bestätigen Sie den angezeigten Vorgang. Danach hier fortsetzen.')) ?></p><?php endif ?>
    <?php if (!empty($fintsStatus['image'])): ?><img alt="<?= e($fintsText('Freigabebild Ihrer Bank')) ?>" src="<?= e($fintsStatus['image']) ?>" class="fints-approval-image"><?php endif ?>
    <form method="post">
        <?= csrf().hidden('action','fints-continue').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?>
        <?php if ($phase === 'tan'): ?><label>TAN<input class="form-control" name="tan" required maxlength="100" autocomplete="one-time-code"></label><?php endif ?>
        <button class="btn btn-solid theme-primary"><?= e($phase === 'tan' ? $fintsText('TAN bestätigen') : ($phase === 'decoupled' ? $fintsText('Ich habe in der App bestätigt') : $fintsText('Ergebnis bei der Bank prüfen'))) ?></button>
    </form>
<?php elseif ($phase === 'accounts'): ?>
    <h3><?= e($fintsText('Welche Umsätze möchten Sie abrufen?')) ?></h3>
    <form method="post">
        <?= csrf().hidden('action','fints-statement').hidden('dialog_version',(string)$version) ?>
        <?php $fintsChoiceForm=$fintsStatementForm;require __DIR__.'/fints-choice-original.php'; ?>
        <fieldset class="fints-choice-controls" <?= $fintsStatementForm['unsupported']?'disabled':'' ?>>
        <?php $chosenUid=$fintsStatementForm['values']['uid']; ?>
        <label><?= e($fintsText('Bankkonto')) ?><select class="form-control" name="uid" required><option value=""><?= e($fintsText('Bitte auswählen')) ?></option><?php if($chosenUid!==''&&!in_array($chosenUid,array_column($fintsStatus['accounts'],'uid'),true)): ?><option value="<?= e($chosenUid) ?>" selected><?= e($fintsText('Ursprüngliche Auswahl nicht verfügbar: {value}',['value'=>$chosenUid])) ?></option><?php endif ?><?php foreach ($fintsStatus['accounts'] as $account): ?><option value="<?= e($account['uid']) ?>" <?= $chosenUid===$account['uid']?'selected':'' ?>><?= e($account['iban'] ?? $account['account_number']) ?></option><?php endforeach ?></select></label>
        <div class="form-grid"><?php foreach(['from'=>'Von','to'=>'Bis']as$field=>$label): ?><label><?= e($fintsText($label)) ?><input class="form-control" type="<?= e(\Kulturhus\Verein\Web\BankDialogFields::dateType($fintsStatementForm['values'][$field])) ?>" name="<?= e($field) ?>" required value="<?= e($fintsStatementForm['values'][$field]) ?>" max="<?= \Kulturhus\Verein\Finance::today() ?>"></label><?php endforeach ?></div>
        <?php if($publicFinTsMock): ?><label><?= e($fintsText('Mockup-Szenario')) ?><select class="form-control" name="demo_scenario"><option value="normal" <?= input($_POST,'demo_scenario','normal')==='normal'?'selected':'' ?>><?= e($fintsText('Fiktive Umsätze abrufen')) ?></option><option value="unavailable" <?= input($_POST,'demo_scenario')==='unavailable'?'selected':'' ?>><?= e($fintsText('Bank vorübergehend nicht erreichbar')) ?></option></select></label><?php endif ?>
        <button class="btn btn-solid theme-primary"><?= e($fintsText('Umsätze abrufen')) ?></button>
        </fieldset>
    </form>
<?php elseif ($phase === 'ready'): ?>
    <h3><?= e($fintsText('{count} Umsätze gefunden',['count'=>(string)$fintsStatus['row_count']])) ?></h3>
    <?php $fintsImportAccounts=array_values(array_filter($finance->paymentAccounts(),static fn(array $account):bool=>$account['kind']==='offline'));
    if($fintsImportPosted):$savedBankContext=bankImportContext($_GET);$savedBankContext=array_replace($savedBankContext,['account'=>$fintsTargetAccountName!==''?$fintsTargetAccount:'','import_state'=>'','import_q'=>'','import_from'=>(string)($fintsStatus['from']??''),'import_to'=>(string)($fintsStatus['to']??'')]); ?>
    <p class="notice"><?= e($fintsText('Deine ursprüngliche Kontowahl und Übernahme bleiben erhalten. Bei unbestätigtem Ausgang zuerst den gespeicherten Bankstand prüfen. Öffne einen neuen Abruf bewusst mit einem neuen Formular.')) ?></p>
    <div class="actions"><a class="btn btn-outline theme-secondary" href="<?= e(url($savedBankContext)) ?>"><?= e($fintsText('Gespeicherten Bankstand prüfen')) ?></a><a class="btn btn-outline theme-secondary" href="<?= e(url(bankListContext($_GET)+['fints'=>'1'])) ?>"><?= e($fintsText('Aktuellen Bankabruf neu öffnen')) ?></a></div>
    <?php endif ?>
    <form method="post" data-fints-import>
        <?= csrf().hidden('action','fints-import').hidden('dialog_version',$fintsImportPosted?input($_POST,'dialog_version'):(string)$version).hidden('request_key',$fintsImportPosted?input($_POST,'request_key'):bin2hex(random_bytes(16))) ?>
        <label><?= e($fintsText('Vereinskonto')) ?><select class="form-control" name="account_id" aria-label="<?= e($fintsText('Vereinskonto')) ?>" required><option value=""><?= e($fintsText('Bitte auswählen')) ?></option><?php if($fintsTargetAccount!==''&&!in_array($fintsTargetAccount,array_column($fintsImportAccounts,'id'),true)): ?><option value="<?= e($fintsTargetAccount) ?>" selected><?= e($fintsText('Frühere Kontowahl erneut prüfen')) ?></option><?php endif ?><?php foreach ($fintsImportAccounts as $account): ?><option value="<?= e($account['id']) ?>" <?= $fintsTargetAccount===$account['id']?'selected':'' ?>><?= e($account['name']) ?></option><?php endforeach ?></select></label>
        <button class="btn btn-solid theme-primary"><?= e($fintsText('Umsätze übernehmen')) ?></button>
    </form>
<?php endif ?>
<div class="actions">
<?php if ($paymentMode && $paymentKind === 'transfer'): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'finance','tab'=>'receipts','entry'=>$paymentOrder['entry_id'],'transfer_order'=>$paymentOrderId])) ?>#invoice-transfer"><?= e($fintsText('Zur Rechnung')) ?></a><?php endif ?>
<?php if ($phase !== ''): ?><form method="post"><?= csrf().hidden('action','fints-cancel').hidden('dialog_version',(string)$version).hidden('payment_order',$paymentOrderId).hidden('payment_kind',$paymentKind) ?><button class="btn btn-outline theme-secondary secondary"><?= e($paymentMode?$fintsText('Bankverbindung beenden'):$fintsText('Erneut anmelden / Bankzugang ändern')) ?></button></form><?php endif ?>
</div>
</section>
</section>
