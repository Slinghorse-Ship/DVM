<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/**
 * Tracks a member's private advance separately from a donation.
 * The expense remains the invoice entry; the later reimbursement is the
 * actual outgoing transaction which is assigned to that entry.
 */
final class ExpenseReimbursements
{
    public const RECIPIENTS = [
        'club' => 'Verein',
        'member' => 'Mitglied',
        'other' => 'Andere Person / anderer Name',
        'unknown' => 'Noch unklar',
    ];

    public function __construct(private PDO $db, private string $actorId) {}

    private function q(string $sql, array $args = []): \PDOStatement
    {
        return DocumentCatalog::query($this->db, $sql, $args);
    }

    private function actor(bool $write = false): array
    {
        $a = Permissions::actor($this->db, $this->actorId);
        if (!in_array($a['finance_access'], $write ? ['write'] : ['read', 'write'], true)) {
            throw new DomainException('Finanzberechtigung erforderlich.');
        }
        return $a;
    }

    private static function key(string $key): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new DomainException('Formular neu öffnen.');
    }

    /** Members who can be named as the claimant. Former members remain selectable for audit history. */
    public function members(): array
    {
        $a = $this->actor();
        return $this->q("SELECT c.id,c.name,c.version FROM contacts c
            WHERE c.club_id=? AND c.archived_at IS NULL AND c.kind='person'
              AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id)
            ORDER BY c.name,c.id LIMIT 1000", [$a['club_id']])->fetchAll();
    }

    public function forEntry(string $entry): ?array
    {
        $a = $this->actor();
        $row = $this->q('SELECT r.*,c.name AS contact_name,c.email AS contact_email,e.amount_cents AS entry_amount_cents,e.description AS entry_description,e.counterparty,e.version AS entry_version
            FROM finance_reimbursement_claims r
            JOIN contacts c ON c.club_id=r.club_id AND c.id=r.contact_id
            JOIN finance_entries e ON e.club_id=r.club_id AND e.id=r.entry_id
            WHERE r.club_id=? AND r.entry_id=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''), [$a['club_id'], $entry])->fetch();
        if (!$row) return null;
        $row['invoice_recipient_label'] = self::RECIPIENTS[$row['invoice_recipient']] ?? $row['invoice_recipient'];
        $row['state_label'] = [
            'open' => 'Erstattung offen',
            'approved' => 'Erstattung freigegeben',
            'paid' => 'Erstattung bezahlt',
            'waived' => 'Anspruch aufgegeben – gesondert als Aufwandsverzicht prüfen',
            'cancelled' => 'Erstattung verworfen',
        ][$row['state']] ?? $row['state'];
        return $row;
    }

    /** Credit refunds use their original buyer instead of a private advance. */
    public function isCredit(string $entry):bool
    {
        $a=$this->actor();return (bool)$this->q("SELECT id FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND kind='credit'".($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$a['club_id'],$entry])->fetchColumn();
    }

    /** Standalone and composed invoice creation retain the claim and its audit together. */
    public function createForEntry(string $entry, string $contactId, string $invoiceRecipient, string $key, string $notes = ''): string
    {
        return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,fn():string=>$this->createForEntryLocked($entry,$contactId,$invoiceRecipient,$key,$notes));
    }

    private function createForEntryLocked(string $entry, string $contactId, string $invoiceRecipient, string $key, string $notes): string
    {
        $a = $this->actor(true); self::key($key);
        if($this->isCredit($entry))throw new DomainException('Eine Gutschrift ist keine private Auslage. Ursprünglichen Beleg prüfen.',409);
        if (!isset(self::RECIPIENTS[$invoiceRecipient])) throw new DomainException('Rechnungsempfänger prüfen.');
        $e = $this->q("SELECT * FROM finance_entries WHERE club_id=? AND id=? AND kind='expense' FOR UPDATE", [$a['club_id'], $entry])->fetch();
        if (!$e) throw new DomainException('Ausgabe für die Erstattung nicht verfügbar.');
        $contact = $this->q("SELECT c.id FROM contacts c WHERE c.club_id=? AND c.id=? AND c.kind='person' AND c.archived_at IS NULL
            AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id)", [$a['club_id'], $contactId])->fetchColumn();
        if (!$contact) throw new DomainException('Ein Mitglied für die private Vorleistung auswählen.');
        $notes = trim($notes); if (strlen($notes) > 2000) throw new DomainException('Erstattungsnotiz ist zu lang.');
        $existing = $this->q('SELECT * FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=?', [$a['club_id'], $entry])->fetch();
        if ($existing) {
            if ($existing['contact_id'] !== $contactId || $existing['invoice_recipient'] !== $invoiceRecipient || (int)$existing['amount_cents'] !== (int)$e['amount_cents']) {
                throw new DomainException('Für diese Rechnung besteht bereits ein anderer Erstattungsfall.');
            }
            return (string)$existing['id'];
        }
        InvoiceRecipientTax::assertEntry($this->db,$this->actorId,$entry,$invoiceRecipient);
        self::key($key);
        $id = bin2hex(random_bytes(16)); $now = gmdate('c');
        $this->q('INSERT INTO finance_reimbursement_claims(club_id,id,entry_id,contact_id,amount_cents,invoice_recipient,state,requested_on,notes,request_key,created_by,created_at) VALUES(?,?,?,?,?,?,\'open\',?,?,?,?,?)',
            [$a['club_id'],$id,$entry,$contact,(int)$e['amount_cents'],$invoiceRecipient,Finance::today(),$notes,$key,$a['id'],$now]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)', [$a['club_id'],$a['id'],'finance.reimbursement.created',$id,1,$now]);
        return $id;
    }

    public function approve(string $id, int $version, string $key): void
    {
        $a = $this->actor(true); self::key($key);
        $this->db->beginTransaction();
        try {
            $office = FinancePaymentAuthority::require($this->db, $this->actorId);
            $claim = $this->q('SELECT * FROM finance_reimbursement_claims WHERE club_id=? AND id=?', [$a['club_id'],$id])->fetch();
            if (!$claim || $claim['state'] !== 'open' || (int)$claim['version'] !== $version) throw new DomainException('Offenen, unveränderten Erstattungsfall auswählen.');
            (new InvoiceApprovals($this->db, $this->actorId))->assertApproved((string)$claim['entry_id']);
            $now = gmdate('c');
            $this->q("UPDATE finance_reimbursement_claims SET state='approved',approved_by=?,approved_at=?,version=version+1 WHERE club_id=? AND id=? AND version=?", [$a['id'],$now,$a['club_id'],$id,$version]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)', [$a['club_id'],$a['id'],'finance.reimbursement.approved',$id,$version+1,$now]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Link a real outgoing transaction that was already assigned to the invoice. */
    public function linkPaid(string $id, int $version, string $transactionId, int $transactionVersion, string $key): void
    {
        $a = $this->actor(true); self::key($key);
        $this->db->beginTransaction();
        try {
            $claim = $this->q('SELECT * FROM finance_reimbursement_claims WHERE club_id=? AND id=?', [$a['club_id'],$id])->fetch();
            if (!$claim || $claim['state'] !== 'approved' || (int)$claim['version'] !== $version) throw new DomainException('Nur eine aktuell freigegebene Erstattung kann als bezahlt markiert werden.');
            $finance=new Finance($this->db,$this->actorId);$tx=$finance->transactionDetail($transactionId,true);
            if (!$tx || (int)$tx['version'] !== $transactionVersion || $tx['cents'] >= 0 || $tx['reversal_of'] || $tx['reversed_on']) throw new DomainException('Ausgehenden Kontoumsatz prüfen.');
            $linked = $this->q('SELECT l.cents FROM finance_allocations l WHERE l.club_id=? AND l.transaction_id=? AND l.entry_id=? AND l.voided_at IS NULL', [$a['club_id'],$transactionId,$claim['entry_id']])->fetchColumn();
            if ((int)$linked !== (int)$claim['amount_cents']) throw new DomainException('Zuerst den vollständigen Erstattungsbetrag dieser Rechnung dem Kontoumsatz zuordnen.');
            $entry=$finance->currentEntry($claim['entry_id']);if((int)$entry['allocated_cents']!==(int)$claim['amount_cents']||$entry['status']==='cancelled'||$entry['reversed_on']!==null)throw new DomainException('Die ursprüngliche Erstattung ist nicht mehr vollständig bezahlt. Den tatsächlichen Zahlungs- und Rückzahlungsstand zuerst klären.',409);
            $now = gmdate('c');
            $this->q("UPDATE finance_reimbursement_claims SET state='paid',paid_transaction_id=?,version=version+1 WHERE club_id=? AND id=? AND version=?", [$transactionId,$a['club_id'],$id,$version]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)', [$a['club_id'],$a['id'],'finance.reimbursement.paid',$id,$version+1,$now]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function paymentCandidates(string $id): array
    {
        $a = $this->actor();
        $claim = $this->q('SELECT * FROM finance_reimbursement_claims WHERE club_id=? AND id=?', [$a['club_id'],$id])->fetch();
        if (!$claim) return [];
        return $this->q("SELECT t.*,a.name AS account_name,t.version AS transaction_version,
            ".PaymentFunding::consumedSql($this->db)." AS allocated_cents
            FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id
            WHERE t.club_id=? AND t.cents<0 AND t.reversal_of IS NULL
              AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id)
              AND abs(t.cents)-".PaymentFunding::consumedSql($this->db).">=?
            ORDER BY t.booked_on DESC,t.id LIMIT 100", [$a['club_id'],(int)$claim['amount_cents']])->fetchAll();
    }
}
