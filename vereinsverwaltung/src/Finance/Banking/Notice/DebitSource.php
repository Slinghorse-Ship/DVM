<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{CollectionFunding, ContributionInvoices, Finance, MemberBankVault, Permissions};
use Kulturhus\Verein\Finance\Invoices\OutgoingCorrectionSource;

/** Bind the issued creditor, payer, current whole claim and current mandate. */
final class DebitSource
{
    public static function read(PDO $db, string $actorId, string $entryId, MemberBankVault $vault): array
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $entryId)) throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
        $actor = Permissions::finance($db, $actorId, true);
        $finance = new Finance($db, $actorId);
        $entry = $finance->currentEntry($entryId);
        if (!in_array($entry['payment_state'], ['open','partial'], true) || $entry['remaining_cents'] <= 0) {
            throw new DomainException('Nur eine offene ursprüngliche Rechnungsforderung mit tatsächlichem Restbetrag vorankündigen.', 409);
        }
        if ($entry['kind'] === 'contribution') {
            $original = (new ContributionInvoices($db, $actorId))->currentOriginal($entryId);
            if ($original['invoice']['state'] !== 'issued' || ($original['snapshot']['draft'] ?? true)) throw new DomainException('Beitragsrechnung zuerst endgültig ausgeben.', 409);
            $contact = $original['invoice']['recipient_contact_id'];
            $snapshot = $original['snapshot'];
            $read = $original['original'];
            $file = $original['file'];
            $accountId = $snapshot['payment_account_id'] ?? '';
            $bankIban = $snapshot['values']['VEREINS_IBAN'] ?? '';
            $template = 'texts_contributions';
            $invoice = $original['invoice'];
        } else {
            $invoiceId = Db::run($db, "SELECT id FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND kind='invoice' FOR SHARE NOWAIT", [$actor['club_id'], $entryId])->fetchColumn();
            if (!is_string($invoiceId)) throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
            $original = OutgoingCorrectionSource::read($db, $actorId, $invoiceId);
            if ($original['credits']) throw new DomainException('Zugehörige Gutschrift zuerst klären. Keine geänderte Rechnungsforderung vorankündigen.', 409);
            $invoice = $original['invoice'];
            $contact = $invoice['data']['contact_id'] ?? '';
            $snapshot = $original['snapshot'];
            $read = $original['source'];
            $file = $original['source']['file'];
            $accountId = $invoice['data']['issuer']['bank']['account_id'] ?? '';
            $bankIban = $invoice['data']['issuer']['bank']['iban'] ?? '';
            $template = 'texts_invoices';
        }
        if (($read['document']['state'] ?? '') !== 'active') throw new DomainException('Ursprüngliches Rechnungsdokument zuerst wiederherstellen.', 409);
        $values = $snapshot['values'] ?? [];
        $amount = number_format($entry['amount_cents'] / 100, 2, ',', '.').' €';
        $date = static fn(string $value): string => (new \DateTimeImmutable($value))->format('d.m.Y');
        if (($snapshot['payment_reference'] ?? null) !== $entry['display_number']
            || ($values['ZAHLBETRAG'] ?? null) !== $amount
            || ($values['RECHNUNGSDATUM'] ?? null) !== $date($entry['document_date'])
            || ($values['FAELLIGKEIT'] ?? null) !== $date($entry['due_date'])) {
            throw new DomainException('Aktuelle Forderung stimmt nicht mit der ausgegebenen Originalrechnung überein. Original zuerst klären.', 409);
        }
        if (!is_string($contact) || $contact === '') throw new DomainException('Ursprünglicher Rechnungsempfänger hat keine eindeutige Mandatszuordnung.', 422);
        if (!is_string($accountId) || $accountId === '' || !is_string($bankIban) || $bankIban === '') throw new DomainException('Ursprüngliches Rechnungskonto bitte prüfen lassen.', 503);
        $account = Db::run($db, "SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR SHARE NOWAIT", [$actor['club_id'], $accountId])->fetch();
        if (!$account || $account['kind'] !== 'offline') throw new DomainException('Ursprüngliches Rechnungskonto ist kein aktives Bankkonto.', 409);
        $finance->requireActiveAccount($accountId);
        $mandate = $vault->mandateForCollection($contact);
        // Full member bank details never leave the vault in a notice or personal JSON.
        $masked = ['contact_id'=>$contact, 'version'=>$mandate['version'], 'reference'=>$mandate['reference'],
            'signed_on'=>$mandate['signed_on'], 'creditor_id'=>$mandate['terms']['creditor_id'],
            'creditor_name'=>$mandate['terms']['creditor_name'],
            'iban_masked'=>substr($mandate['bank']['iban'], 0, 2).' •••• '.substr($mandate['bank']['iban'], -4),
            'source_sha256'=>hash('sha256', DebitRequests::json($mandate))];
        $fileMeta = array_intersect_key($file, array_flip(['document_id', 'revision', 'name', 'mime', 'sha256']));
        if (isset($file['snapshot'])) $fileMeta['snapshot_sha256'] = hash('sha256', $file['snapshot']);
        $recipient = $snapshot['recipient'] ?? '';
        if (!is_string($recipient) || trim($recipient) === '') throw new DomainException('Ursprüngliche Rechnungsanschrift fehlt.', 503);
        return ['entry'=>$entry, 'invoice'=>$invoice, 'document'=>$read['document'], 'folders'=>$read['folders'],
            'file'=>$fileMeta, 'snapshot'=>$snapshot, 'recipient'=>$recipient, 'amount_cents'=>$entry['remaining_cents'],
            'template'=>$template, 'account'=>$account, 'original_iban'=>$bankIban, 'mandate'=>$masked]
            + ($entry['allocated_cents'] > 0 ? ['funding'=>CollectionFunding::summary(CollectionFunding::read($db, $actorId, $entryId))] : []);
    }
}
