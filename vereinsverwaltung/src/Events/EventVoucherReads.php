<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Current event access for private benefits; a read never owns its caller's transaction. */
trait EventVoucherReads
{
    private function currentLock(): string
    {
        return $this->db->inTransaction() ? ' FOR SHARE NOWAIT' : '';
    }

    private function readContext(callable $operation): mixed
    {
        $owned = !$this->db->inTransaction();
        try {
            // A read must not acquire MySqlDatabaseConnection's global writer gate.
            // Current shared row locks below still protect its complete authorized snapshot.
            if ($owned) $this->db->exec('START TRANSACTION');
            $result = $operation();
            if ($owned) $this->db->commit();
            return $result;
        } catch (\Throwable $failure) {
            if ($owned && $this->db->inTransaction()) $this->db->rollBack();
            if ($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true)) {
                throw new DomainException('Gutscheine werden gerade bearbeitet. Bitte erneut öffnen.', 503, $failure);
            }
            throw $failure;
        }
    }

    /** Canonical metadata held current until the surrounding read/write transaction ends. */
    private function currentSource(array $event): array
    {
        if (str_starts_with($event['source_id'], 'website:')) {
            if (!$this->website) throw new DomainException('Veranstaltung derzeit nicht prüfbar. Bitte später erneut versuchen.');
            return $this->website->appointmentMetadata($event['club_id'], $event['source_id'], true);
        }
        $source = $this->q('SELECT id,club_id,title,starts_at,ends_at,activity_state,location FROM appointments WHERE club_id=? AND id=?'.$this->currentLock(), [$event['club_id'], $event['source_id']])->fetch();
        if (!$source) throw new DomainException('Die Veranstaltung ist nicht mehr vorhanden.');
        return $source;
    }

    private function availableProfile(array $actor, bool $required = true): bool
    {
        $id = $actor['contact_id'] ?? null;
        $available = is_string($id) && $id !== '' && (bool)$this->q(
            "SELECT id FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL".$this->currentLock(),
            [$actor['club_id'], $id]
        )->fetchColumn();
        if (!$available && $required) throw new DomainException('Eigenes Mitgliederprofil für Gutscheine nicht verfügbar.');
        return $available;
    }

    private function currentHelpers(array $actor): array
    {
        return $this->q('SELECT event_id FROM event_voucher_helpers WHERE club_id=? AND actor_id=?'.$this->currentLock(),
            [$actor['club_id'], $actor['id']])->fetchAll(\PDO::FETCH_COLUMN);
    }
}
