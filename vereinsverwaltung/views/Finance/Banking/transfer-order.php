<?php declare(strict_types=1);
if ($r['kind'] !== 'expense') return;
if(($nativeManagerEnabled??false)&&($transferRoute??false)){require __DIR__.'/transfer-native.php';return;}

$hasReimbursement = !empty($reimbursement);
$transferService = null;
$transferError = '';
$transferOrder = null;
$transferOrders = [];
$approvalStatus = (string)($approval['status'] ?? 'pending');
$approvalBlockedCoding = (bool)($approvalMissingCoding ?? false);
$approvalReady = $approvalStatus === 'approved' && !$approvalBlockedCoding;
$paymentReportState = 'none';
try {
    $paymentReportState = (string)((new \Kulturhus\Verein\InvoicePaymentReports($db, (string)$_SESSION['actor']))->summary($r['id'])['state'] ?? 'none');
} catch (DomainException) {
}
$paymentReported = $paymentReportState === 'reported';
$paymentOpen = in_array($r['payment_state'], ['open', 'partial'], true) && $r['remaining_cents'] > 0;
$paymentAccounts = array_values(array_filter(
    $finance->paymentAccounts(),
    static fn (array $account): bool => ($account['kind'] ?? '') === 'offline'
));
$singlePaymentAccount = count($paymentAccounts) === 1 ? $paymentAccounts[0] : null;

try {
    $transferService = new \Kulturhus\Verein\TransferOrders(
        $db,
        (string)$_SESSION['actor'],
        \Kulturhus\Verein\MemberBankVault::fromEnvironment($db, (string)$_SESSION['actor'], \Kulturhus\Verein\ProductionRuntime::enabled())
    );
    $transferOrders = $transferService->ordersForEntry($r['id']);
    if (input($_GET, 'transfer_order') === '' && !isset($_GET['transfer_new'])) {
        foreach ($transferOrders as $existing) {
            if (in_array($existing['state'], ['prepared','approved','exported','submitted'], true) && !($existing['state']==='submitted'&&$finance->paymentReturnResolvesTransfer($existing['id']))) {
                $transferOrder = $transferService->get($existing['id']);
                break;
            }
        }
    }
    if (input($_GET, 'transfer_order') !== '') {
        $transferOrder = $transferService->get(input($_GET, 'transfer_order'));
        if ($transferOrder['entry_id'] !== $r['id']) {
            throw new \DomainException('Überweisungsauftrag gehört zu einer anderen Rechnung.');
        }
    }
} catch (\DomainException $ex) {
    $transferError = $ex->getMessage();
}

$reimbursementBankKnown = false;
$reimbursementBankReady = false;
if ($hasReimbursement) {
    try {
        $reimbursementBankVault = \Kulturhus\Verein\MemberBankVault::fromEnvironment($db, (string)$_SESSION['actor'], \Kulturhus\Verein\ProductionRuntime::enabled());
        $reimbursementBankSummary = $reimbursementBankVault->summary((string)$reimbursement['contact_id']);
        $reimbursementBankKnown = true;
        $reimbursementBankReady = ($reimbursementBankSummary['iban_masked'] ?? '') !== '';
    } catch (\DomainException) {
        $bankCheck = $db->prepare('SELECT status FROM member_bank_records WHERE club_id=? AND contact_id=?');
        $bankCheck->execute([$r['club_id'], $reimbursement['contact_id']]);
        $bankStatus = (string)($bankCheck->fetchColumn() ?: '');
        $reimbursementBankKnown = true;
        $reimbursementBankReady = $bankStatus !== '' && $bankStatus !== 'removed';
    }
}

$preview = $_SESSION['transfer_preview'] ?? null;
if (!is_array($preview) || ($preview['actor'] ?? '') !== $_SESSION['actor'] || ($preview['expires'] ?? 0) < time() || ($preview['entry'] ?? '') !== $r['id']) {
    $preview = null;
    if (isset($_SESSION['transfer_preview'])) unset($_SESSION['transfer_preview']);
}
$transferStates = [
    'prepared' => 'Vorbereitet',
    'approved' => 'Zahlungsauftrag freigegeben',
    'exported' => 'Bankdatei heruntergeladen',
    'submitted' => 'Bei der Bank eingereicht',
    'cancelled' => 'Verworfen',
];
$showTransferCard = $transferOrder || $transferError !== '' || isset($_GET['transfer_new']) || $transferOrders || !$paymentOpen || ($approvalReady && $paymentOpen);
?>
<?php if ($showTransferCard): ?>
<section class="card" id="invoice-transfer">
    <h2><?= $paymentOpen ? 'Bezahlen' : 'Zahlungsstatus' ?></h2>
    <?php if ($paymentOpen && !$approvalReady): ?><p class="notice"><strong>Rechnung zuerst prüfen und freigeben.</strong> <a href="#invoice-approval">Zum Prüfen</a></p><?php endif ?>
    <?php if ($paymentReported): ?><p class="notice" role="status"><strong>Bereits als bezahlt gemeldet.</strong> Keine zweite Zahlung.</p><?php endif ?>
    <?php if ($transferError !== ''): ?><p class="error" role="alert"><?= e($transferError) ?></p><?php endif ?>

    <?php if ($transferOrder): ?>
        <p><strong><?= e($transferStates[$transferOrder['state']] ?? 'Status prüfen') ?></strong> · <?= e(euro((int)$transferOrder['total_cents'])) ?> · <?= e(dateDe($transferOrder['execution_date'])) ?></p>
        <dl class="data-list">
            <dt>Empfänger</dt><dd><?= e($transferOrder['snapshot']['recipient']['name']) ?> · IBAN <?= e(trim(chunk_split($transferOrder['snapshot']['recipient']['iban'],4,' '))) ?></dd>
            <dt>Zahlungsart</dt><dd><?= ($transferOrder['snapshot']['transfer_mode'] ?? 'standard') === 'instant' ? 'Echtzeitüberweisung · ohne Umwandlung' : 'Normale Überweisung' ?></dd>
            <dt>Verwendungszweck</dt><dd><?= e($transferOrder['snapshot']['purpose']) ?></dd>
            <dt>Bankkonto</dt><dd><?= e((string)$finance->account($transferOrder['account_id'])['name']) ?></dd>
        </dl>

        <?php if ($transferOrder['state'] === 'prepared' && $approvalReady && !$paymentReported && $transferService && $transferService->canManage()): ?>
            <form method="post"><?= csrf().hidden('continue_to_bank', '1').hidden('action', 'transfer-order-approve').hidden('transfer_order', $transferOrder['id']).hidden('version', (string)$transferOrder['version']) ?><button class="btn btn-solid theme-primary">Freigeben &amp; weiter zur Bank</button></form>
        <?php elseif ($transferOrder['state'] === 'prepared' && (!$approvalReady || $paymentReported)): ?>
            <p class="notice">Der Auftrag kann nach der Rechnungsfreigabe weiterbearbeitet werden.</p>
        <?php elseif ($transferOrder['state'] === 'prepared'): ?>
            <p>Die Freigabe ist nur für eine berechtigte Person möglich.</p>
        <?php endif ?>

        <?php if ($transferOrder['state'] === 'approved' && !$paymentReported && $approvalReady): ?>
            <?php if ($transferService && $transferService->canSend()): ?>
                <div class="actions">
                    <a class="btn btn-solid theme-primary button" href="<?= e(url(['view' => 'finance', 'tab' => 'bank', 'account' => $transferOrder['account_id'], 'fints' => 1, 'payment' => 'transfer', 'payment_order' => $transferOrder['id']])) ?>#fints-payment">Weiter zur Bank</a>
                    <details class="invoice-payment-alternative"><summary>Andere Zahlungsart</summary><form method="post"><?= csrf().hidden('action', 'transfer-order-export').hidden('transfer_order', $transferOrder['id']).hidden('version', (string)$transferOrder['version']) ?><button class="btn btn-outline theme-secondary secondary">SEPA-Datei herunterladen</button></form></details>
                </div>
            <?php else: ?>
                <form method="post" class="actions"><?= csrf().hidden('action', 'transfer-order-export').hidden('transfer_order', $transferOrder['id']).hidden('version', (string)$transferOrder['version']) ?><button class="btn btn-solid theme-primary">SEPA-Datei herunterladen</button></form>
            <?php endif ?>
        <?php elseif ($transferOrder['state'] === 'approved' && !$paymentReported && $approvalBlockedCoding): ?>
            <p class="notice"><strong>Kontierung ergänzen:</strong> Sphäre und Steuerkonto prüfen.</p>
        <?php endif ?>

        <?php if ($transferOrder['state'] === 'exported'): ?>
            <p>Bankeinreichung danach hier dokumentieren.</p>
            <form method="post"><label>Bankbestätigung / Auftragsnummer<input class="form-control" name="bank_reference" required maxlength="200"></label><?= csrf().hidden('action', 'transfer-order-submitted').hidden('transfer_order', $transferOrder['id']).hidden('version', (string)$transferOrder['version']) ?><button class="btn btn-solid theme-primary">Einreichung dokumentieren</button></form>
        <?php endif ?>
        <?php if (in_array($transferOrder['state'], ['prepared', 'approved'], true)): ?>
            <details class="invoice-payment-alternative"><summary>Auftrag verwerfen</summary><form method="post" data-confirm="Diesen vorbereiteten Überweisungsauftrag verwerfen?"><?= csrf().hidden('action', 'transfer-order-cancel').hidden('transfer_order', $transferOrder['id']).hidden('version', (string)$transferOrder['version']) ?><button class="btn btn-outline theme-secondary secondary">Verwerfen</button></form></details>
        <?php endif ?>
        <?php if ($transferOrder['state'] === 'submitted'): ?><p>Die Rechnung wird erst nach dem echten Kontoauszug als bezahlt angezeigt.</p><?php endif ?>

    <?php elseif ($paymentOpen): ?>
        <?php if ($paymentReported): ?><p class="notice">Eine vorläufige Zahlungsmeldung ist aktiv. Eine neue Zahlung bleibt gesperrt.</p><?php elseif (!$finance->canWrite()): ?><p class="muted">Finanz-Schreibrecht erforderlich.</p><?php endif ?>
        <?php if ($approvalReady && !$paymentReported): ?>
            <?php if ($hasReimbursement && $reimbursementBankKnown && !$reimbursementBankReady): ?>
                <p class="notice invoice-payment-block" role="alert"><strong>Bankverbindung von <?= e($reimbursement['contact_name']) ?> fehlt.</strong><br><a href="<?= e(url(['view' => 'members', 'id' => $reimbursement['contact_id']])) ?>">Bankdaten hinterlegen</a></p>
            <?php elseif ($preview): ?>
                <h3>Zahlung prüfen</h3>
                <p><strong><?= ($preview['transfer_mode'] ?? 'standard') === 'instant' ? 'Echtzeitüberweisung · heute · keine Umwandlung in eine normale Überweisung' : 'Normale Überweisung' ?></strong></p>
                <p><strong><?= e($preview['preview']['recipient_name']) ?></strong> · IBAN <?= e($preview['preview']['iban_masked']) ?><br><?= e(euro((int)$preview['preview']['total_cents'])) ?> · <?= e(dateDe($preview['preview']['execution_date'])) ?><br><?= e($preview['preview']['purpose']) ?></p>
                <form method="post"><?= csrf().hidden('continue_to_bank', '1').hidden('action', 'transfer-order-confirm').hidden('entry', $r['id']).hidden('account', $preview['account']).hidden('transfer_mode', $preview['transfer_mode'] ?? 'standard').hidden('execution_date', $preview['execution_date']).hidden('purpose', $preview['purpose']).hidden('preview_hash', $preview['preview']['hash']).hidden('request_key', bin2hex(random_bytes(16))) ?><button class="btn btn-solid theme-primary"><?= $transferService && $transferService->canManage() ? 'Freigeben & weiter zur Bank' : 'Zur Freigabe speichern' ?></button> <a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view' => 'finance', 'tab' => 'receipts', 'entry' => $r['id']])) ?>#invoice-transfer">Ändern</a></form>
            <?php else: ?>
                <form method="post" class="form-grid"><?= csrf().hidden('action', 'transfer-order-preview').hidden('entry', $r['id']).hidden('version', (string)$r['version']) ?>
                    <?php if ($singlePaymentAccount): ?><input type="hidden" name="account" value="<?= e($singlePaymentAccount['id']) ?>"><p class="invoice-single-account"><strong><?= e($singlePaymentAccount['name']) ?></strong></p><?php else: ?><label>Vereinskonto<select class="form-control" name="account" required><option value="">Bitte auswählen</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= e($account['id']) ?>" <?= input($_POST, 'account') === $account['id'] ? 'selected' : '' ?>><?= e($account['name']) ?></option><?php endforeach ?></select></label><?php endif ?>
                    <label class="check"><input class="check" type="checkbox" name="transfer_mode" value="instant" data-transfer-mode <?= input($_POST, 'transfer_mode') === 'instant' ? 'checked' : '' ?>> Echtzeitüberweisung (wenn von Ihrer Bank unterstützt)</label>
                    <label data-transfer-date>Ausführungstag (nur normale Überweisung)<input class="form-control" type="date" name="execution_date" min="<?= e(\Kulturhus\Verein\Finance::today()) ?>" value="<?= e(input($_POST, 'execution_date', \Kulturhus\Verein\Finance::today())) ?>"></label>
                    <p data-transfer-instant-note hidden>Echtzeit · heute. Falls die Bank das nicht unterstützt, wird keine normale Überweisung gesendet.</p>
                    <label class="wide">Verwendungszweck<input class="form-control" name="purpose" maxlength="140" required value="<?= e(input($_POST, 'purpose', ($r['display_number'].' '.($r['counterparty'] ?: 'Rechnung')))) ?>"></label>
                    <div class="actions"><button class="btn btn-solid theme-primary">Zahlung prüfen</button><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view' => 'finance', 'tab' => 'receipts', 'entry' => $r['id']])) ?>">Abbrechen</a></div>
                </form>
            <?php endif ?>
        <?php endif ?>
    <?php else: ?>
        <p>Keine Zahlung offen.</p>
    <?php endif ?>

    <?php if ($transferOrders && !$transferOrder): ?><details><summary>Bisherige Überweisungsaufträge</summary><ul><?php foreach ($transferOrders as $o): ?><li><a href="<?= e(url(['view' => 'finance', 'tab' => 'receipts', 'entry' => $r['id'], 'transfer_order' => $o['id']])) ?>"><?= e(($transferStates[$o['state']] ?? 'Status prüfen').' · '.euro((int)$o['total_cents']).' · '.dateDe($o['execution_date'])) ?></a></li><?php endforeach ?></ul></details><?php endif ?>
</section>
<?php endif; ?>
