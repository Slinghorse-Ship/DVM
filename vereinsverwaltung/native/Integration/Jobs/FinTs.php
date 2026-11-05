<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions, FintsScheduledSync};

/** The hosting job uses the existing read-only bank schedule and imports no free payment orders. */
final class FinTs
{
    public static function run(PDO $db, string $club, mixed $actor, string $private): array
    {
        $disabled = ['worker' => 'native-fints', 'transport' => 'disabled', 'bank_sync' => ['status' => 'disabled', 'rows' => 0, 'counts' => []]];
        if ($actor === null || $actor === '') return $disabled;
        if (!is_string($actor) || strlen($actor) > 128) throw new DomainException('Eigenen Verantwortlichen für den Bankabruf prüfen.');
        $current = Permissions::actor($db, $actor);
        if ($current['club_id'] !== $club || $current['finance_access'] !== 'write') throw new DomainException('Aktuelle Finanz-Schreibrechte des eigenen Vereins für den Bankabruf erforderlich.');
        $root = $private . '/banking';
        foreach (['VEREIN_FINTS_ROOT', 'VEREIN_BANKING_ROOT'] as $name) {
            $configured = getenv($name);
            if ($configured !== false && $configured !== '' && $configured !== $root) throw new DomainException('Bankabruf verwendet die ursprüngliche private Bankkonfiguration dieser Installation.');
        }
        if (!is_file($root . '/fints.json')) return $disabled;
        if (is_link($root) || realpath($root) !== $root || (fileperms($root) & 0077)) throw new DomainException('Eigenes privates Bankverzeichnis erforderlich.');
        foreach (['fints.json', 'fints-state.key'] as $name) if (file_exists($root . '/' . $name) || is_link($root . '/' . $name)) {
            if (is_link($root . '/' . $name) || !is_file($root . '/' . $name) || (fileperms($root . '/' . $name) & 0077)) throw new DomainException('Ursprüngliche geschützte Bankdateien erforderlich.');
        }
        if (getenv('KH_NATIVE_CI') === '1' || getenv('VEREIN_MODE') !== 'production' || getenv('VEREIN_FINTS_DISABLE_TRANSPORT') === '1') return $disabled;
        $lock = 'dvm-fints-job-' . substr(hash('sha256', $club), 0, 40);
        if ((int) Db::run($db, 'SELECT GET_LOCK(?,0)', [$lock])->fetchColumn() !== 1) return ['worker' => 'native-fints', 'transport' => 'read-only', 'bank_sync' => ['status' => 'busy', 'rows' => 0, 'counts' => []]];
        try {
            $counts = []; $rows = 0;
            $scan = (new FintsScheduledSync($db, $actor, $root))->runDue();
            foreach ($scan['runs'] as $result) {
                $state = $result['state'] ?? 'failed';
                if (!in_array($state, ['complete', 'needs_auth', 'needs_setup', 'failed', 'skipped'], true)) $state = 'failed';
                $counts[$state] = ($counts[$state] ?? 0) + 1;
                $rows += max(0, (int) ($result['rows_count'] ?? 0));
            }
            $review = ($counts['needs_auth'] ?? 0) + ($counts['needs_setup'] ?? 0) + ($counts['failed'] ?? 0) > 0;
            return ['worker' => 'native-fints', 'transport' => 'read-only', 'bank_sync' => ['status' => $review ? 'needs_review' : 'checked', 'rows' => $rows, 'counts' => $counts]];
        } finally { Db::run($db, 'SELECT RELEASE_LOCK(?)', [$lock]); }
    }
}
