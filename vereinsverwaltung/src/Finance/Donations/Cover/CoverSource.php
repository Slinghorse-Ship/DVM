<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Donations\Cover;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents, Finance, Permissions};

/** A cover accompanies the issued certificate; it never rebuilds that certificate. */
final class CoverSource
{
    public static function read(PDO $db, string $actorId, string $receiptId): array
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $receiptId)) {
            throw new DomainException('Eigene ausgestellte Zuwendungsbescheinigung auswählen.', 404);
        }
        $own = !$db->inTransaction();
        try {
            if ($own) $db->exec('START TRANSACTION');
            try {
                $actor = Permissions::finance($db, $actorId);
            } catch (DomainException $failure) {
                if ($failure->getCode() === 503 || $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.') throw $failure;
                throw new DomainException('Aktuelle Finanzleseberechtigung erforderlich.', 403, $failure);
            }
            $receipt = Db::run($db, 'SELECT * FROM donation_receipts WHERE club_id=? AND id=? FOR SHARE NOWAIT', [$actor['club_id'], $receiptId])->fetch();
            if (!$receipt) throw new DomainException('Eigene ausgestellte Zuwendungsbescheinigung auswählen.', 404);
            if ($receipt['cancelled_at'] !== null && $receipt['cancelled_at'] !== '') {
                throw new DomainException('Zu einer stornierten Bescheinigung kein neues Begleitschreiben erstellen.', 409);
            }

            $original = (new Documents($db, $actorId))->currentFinancialOriginal($receipt['document_id'], 1);
            $document = $original['document'];
            if ($original['trashed'] || $document['state'] !== 'active') {
                throw new DomainException('Ursprüngliche Zuwendungsbescheinigung zuerst wiederherstellen.', 409);
            }
            $file = Db::run($db, 'SELECT * FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR SHARE NOWAIT', [$actor['club_id'], $receipt['document_id']])->fetch();
            if (!$file || $document['kind'] !== 'certificate' || $document['origin'] !== 'generated'
                || $file['mime'] !== 'application/pdf' || !str_starts_with($file['bytes'], '%PDF-')
                || $file['sha256'] !== $original['file']['sha256']
                || !hash_equals($file['sha256'], hash('sha256', $file['bytes']))) {
                throw new DomainException('Ursprüngliches ausgestelltes Bescheinigungs-PDF bitte prüfen lassen.', 503);
            }
            $snapshot = json_decode($file['snapshot'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['receipt_number'] ?? null) !== $receiptId) {
                throw new DomainException('Ursprünglicher Bescheinigungsstand stimmt nicht überein.', 503);
            }

            if (($snapshot['kind'] ?? null) === 'donation_collection') {
                $collection = $snapshot['collection'] ?? null;
                if (!is_array($collection) || !is_int($collection['amount_cents'] ?? null)
                    || $collection['amount_cents'] <= 0 || !is_array($collection['rows'] ?? null) || !$collection['rows']) {
                    throw new DomainException('Ursprüngliche Sammelbescheinigung bitte prüfen lassen.', 503);
                }
                $template = 'collective';
                $donor = $collection['donor'] ?? null;
                $amount = $collection['amount_cents'];
                $sources = Db::run($db, 'SELECT * FROM donation_receipt_sources WHERE club_id=? AND receipt_id=? ORDER BY source_type,source_id,donated_on FOR SHARE NOWAIT', [$actor['club_id'], $receiptId])->fetchAll();
                if (!$sources) throw new DomainException('Ursprüngliche Quellen der Sammelbescheinigung fehlen.', 503);
            } elseif (($snapshot['kind'] ?? null) === 'donation_receipt') {
                $form = $snapshot['donation_form'] ?? null;
                if (!is_array($form) || ($form['kind'] ?? null) !== $receipt['kind']
                    || ($snapshot['source_id'] ?? null) !== $receipt['source_id'] || !is_string($form['amount'] ?? null)) {
                    throw new DomainException('Ursprüngliche Einzelbescheinigung bitte prüfen lassen.', 503);
                }
                $template = 'donation';
                $donor = $form['donor'] ?? null;
                $amount = Finance::cents($form['amount']);
                if ($amount <= 0) throw new DomainException('Ursprünglichen Zuwendungsbetrag bitte prüfen lassen.', 503);
                $sources = [];
            } else {
                throw new DomainException('Tatsächlich ausgestellte Einzel- oder Sammelbescheinigung auswählen.', 503);
            }
            if (!is_string($donor) || trim($donor) === '') {
                throw new DomainException('Ursprüngliche Zuwenderanschrift fehlt.', 503);
            }
            $links = Db::run($db, 'SELECT source_type,source_id FROM document_links WHERE club_id=? AND document_id=? ORDER BY source_type,source_id FOR SHARE NOWAIT', [$actor['club_id'], $document['id']])->fetchAll();
            $source = [
                'receipt' => $receipt,
                'template' => $template,
                'donor' => $donor,
                'amount_cents' => $amount,
                'document' => $document,
                'folders' => $original['folders'],
                'file' => array_intersect_key($file, array_flip(['document_id', 'revision', 'name', 'mime', 'sha256']))
                    + ['bytes' => strlen($file['bytes']), 'snapshot_sha256' => hash('sha256', $file['snapshot'])],
                'snapshot' => $snapshot,
                'sources' => $sources,
                'links' => $links,
            ];
            if ($own) $db->commit();
            return $source;
        } catch (\Throwable $failure) {
            if ($own && $db->inTransaction()) $db->rollBack();
            if (($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true))
                || ($failure instanceof DomainException && $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')) {
                throw new DomainException('Bescheinigung oder Dokumentrechte werden gerade bearbeitet. Erneut prüfen.', 503, $failure);
            }
            throw $failure;
        }
    }
}
