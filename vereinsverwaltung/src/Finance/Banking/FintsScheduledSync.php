<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/**
 * Five-minute read-only FinTS worker.
 *
 * The worker deliberately stops when the bank asks for a TAN or app approval.
 * It records the required next step for the manager instead of trying to
 * bypass strong authentication. Credentials are read from the encrypted
 * vault for the duration of one run and are never written to the database.
 */
final class FintsAuthorizationRequired extends DomainException
{
    public function __construct(public readonly string $phase, string $message, public readonly string $medium = '')
    {
        parent::__construct($message);
    }
}

final class FintsScheduledSync
{
    public const INTERVAL_MINUTES = 5;
    private const INTERVAL_SECONDS = self::INTERVAL_MINUTES * 60;
    private const DEFAULT_FIRST = '08:00';
    private const DEFAULT_SECOND = '18:00';

    public function __construct(private PDO $db, private string $actor, private string $root, private ?object $engine = null)
    {
        $this->table('fints_sync_settings');
        $this->table('fints_sync_runs');
    }

    private function table(string $name): void
    {
        if (!Database::tableExists($this->db,$name)) {
            throw new DomainException('Die FinTS-Zeitplanung ist noch nicht eingerichtet.');
        }
    }

    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }

    private function access(bool $write = false): array
    {
        $a = Permissions::actor($this->db, $this->actor);
        $allowed = $write ? ['write'] : ['read', 'write'];
        if (!in_array($a['finance_access'], $allowed, true)) throw new DomainException('Finanz-Schreibrecht erforderlich.');
        return $a;
    }

    private static function time(string $value): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) throw new DomainException('Uhrzeit im Format HH:MM eingeben.');
        return $value;
    }

    private function mapping(string $id, bool $lock = false): array
    {
        $a = $this->access();
        $row = $this->q('SELECT m.*,a.name account_name FROM bank_account_mappings m JOIN finance_accounts a ON a.club_id=m.club_id AND a.id=m.account_id WHERE m.club_id=? AND m.id=?'.($lock?' FOR UPDATE':''), [$a['club_id'], $id])->fetch();
        if (!$row || $row['provider'] !== 'fints') throw new DomainException('FinTS-Kontoverbindung nicht verfügbar.');
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function overview(): array
    {
        $a = $this->access();
        $rows = $this->q("SELECT m.id,m.provider,m.label,m.account_id,m.club_id,a.name account_name,
            COALESCE(s.enabled,1) enabled,COALESCE(s.interval_minutes,?) interval_minutes,
            COALESCE(s.first_time,?) first_time,COALESCE(s.second_time,?) second_time,
            COALESCE(s.version,0) version,
            (SELECT r.state FROM fints_sync_runs r WHERE r.club_id=m.club_id AND r.mapping_id=m.id ORDER BY r.started_at DESC,r.id DESC LIMIT 1) last_state,
            (SELECT r.phase FROM fints_sync_runs r WHERE r.club_id=m.club_id AND r.mapping_id=m.id ORDER BY r.started_at DESC,r.id DESC LIMIT 1) last_phase,
            (SELECT r.message FROM fints_sync_runs r WHERE r.club_id=m.club_id AND r.mapping_id=m.id ORDER BY r.started_at DESC,r.id DESC LIMIT 1) last_message,
            (SELECT r.rows_count FROM fints_sync_runs r WHERE r.club_id=m.club_id AND r.mapping_id=m.id ORDER BY r.started_at DESC,r.id DESC LIMIT 1) last_rows,
            (SELECT r.started_at FROM fints_sync_runs r WHERE r.club_id=m.club_id AND r.mapping_id=m.id ORDER BY r.started_at DESC,r.id DESC LIMIT 1) last_started_at
            FROM bank_account_mappings m JOIN finance_accounts a ON a.club_id=m.club_id AND a.id=m.account_id
            LEFT JOIN fints_sync_settings s ON s.club_id=m.club_id AND s.mapping_id=m.id
            WHERE m.club_id=? AND m.provider='fints' ORDER BY a.name,m.label", [self::INTERVAL_MINUTES, self::DEFAULT_FIRST, self::DEFAULT_SECOND, $a['club_id']])->fetchAll();
        return array_map(static function (array $row): array {
            $row['enabled'] = (int)$row['enabled'];
            $row['interval_minutes'] = self::INTERVAL_MINUTES;
            $row['version'] = (int)$row['version'];
            $row['last_rows'] = (int)($row['last_rows'] ?? 0);
            return $row;
        }, $rows);
    }

    /**
     * Enable or disable the fixed five-minute schedule.
     *
     * The optional clock arguments remain accepted for old form handlers and
     * integrations. They are deliberately ignored: the cadence is a single
     * predictable five-minute interval and cannot drift per account.
     */
    public function saveSchedule(string $mappingId, bool $enabled, string $firstTime = self::DEFAULT_FIRST, string $secondTime = self::DEFAULT_SECOND, ?int $expectedVersion = null, ?string $expectedAccount = null): void
    {
        \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($mappingId,$enabled,$expectedVersion,$expectedAccount):void{
            $a=$this->access(true);$mapping=$this->mapping($mappingId,true);
            if($expectedAccount!==null&&$mapping['account_id']!==$expectedAccount)throw new DomainException('Ursprüngliches Bankkonto für diesen Abrufplan prüfen.',409);
            $current=$this->q('SELECT version FROM fints_sync_settings WHERE club_id=? AND mapping_id=?',[$a['club_id'],$mappingId])->fetchColumn();$version=$current===false?0:(int)$current;
            if($expectedVersion!==null&&$expectedVersion!==$version)throw new DomainException('Der Abrufplan wurde inzwischen geändert. Aktuellen Stand zuerst vergleichen.',409);
            $this->q('INSERT INTO fints_sync_settings(club_id,mapping_id,enabled,interval_minutes,first_time,second_time,version,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),interval_minutes=VALUES(interval_minutes),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)', [$a['club_id'],$mappingId,$enabled?1:0,self::INTERVAL_MINUTES,self::DEFAULT_FIRST,self::DEFAULT_SECOND,$version+1,$a['id'],gmdate('c')]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'bank.fints.schedule.saved',$mappingId,$version+1,gmdate('c')]);
        });
    }

    private static function now(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?: new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
    }

    private function requireCurrentBankSchema():void
    {
        if($this->db->inTransaction())throw new DomainException('Geplanter Bankabruf benötigt einen eigenen abgeschlossenen Vorgang.',409);
        $head=(int)$this->q('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1')->fetchColumn();if($head!==Database::SCHEMA_VERSION)throw new DomainException('Vor dem geplanten Bankabruf den aktuellen Paketstand ausdrücklich warten.',503);
    }

    private function alreadyRun(string $mappingId, string $window): bool
    {
        return (bool)$this->q('SELECT 1 FROM fints_sync_runs WHERE club_id=? AND mapping_id=? AND window_key=?', [$this->access()['club_id'], $mappingId, $window])->fetchColumn();
    }

    /**
     * Run the current five-minute slot once for every enabled mapping.
     * A failed or authentication-required slot is not retried in a loop; the
     * next hosting tick creates a fresh slot and can try again.
     */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        $this->requireCurrentBankSchema();
        $now = self::now($now);
        $result = ['checked_at' => $now->format('c'), 'started' => 0, 'complete' => 0, 'needs_auth' => 0, 'needs_setup' => 0, 'failed' => 0, 'skipped' => 0, 'runs' => []];
        foreach ($this->overview() as $row) {
            if (!(int)$row['enabled']) continue;
            $slot = intdiv($now->getTimestamp(), self::INTERVAL_SECONDS);
            $window = 'slot-'.$slot;
            if ($this->alreadyRun((string)$row['id'], $window)) continue;
            ++$result['started'];
            $run = $this->runOne((string)$row['id'], $window, $now);
            $result['runs'][] = $run;
            $state = (string)($run['state'] ?? 'failed');
            if (isset($result[$state])) ++$result[$state];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function runOne(string $mappingId, string $window, ?DateTimeImmutable $now = null): array
    {
        $this->requireCurrentBankSchema();
        $a = $this->access(true);
        if (!preg_match('/^(?:slot-\d+|\d{4}-\d{2}-\d{2}\|(?:first|second))$/D', $window)) throw new DomainException('FinTS-Zeitfenster ungültig.');
        $mapping = $this->mapping($mappingId);
        $now = self::now($now);
        $id = bin2hex(random_bytes(16));
        try {
            $this->q('INSERT INTO fints_sync_runs(club_id,id,mapping_id,window_key,state,started_at) VALUES(?,?,?,?,?,?)', [$a['club_id'], $id, $mappingId, $window, 'failed', $now->format('c')]);
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) return ['state' => 'skipped', 'mapping_id' => $mappingId, 'window' => $window];
            throw $e;
        }
        try {
            // The account-level FinTS read switch is authoritative for both
            // manual and scheduled imports.  Do this check before loading
            // credentials or touching the bank so disabling reads immediately
            // stops the five-minute worker for this account.
            $accountSettings = (new Finance($this->db, $this->actor))->accountSettings((string)$mapping['account_id']);
            if (!(int)($accountSettings['fints_read'] ?? 1)) {
                return $this->finish($a, $id, 'skipped', '', 'FinTS-Lesen ist für dieses Konto gesperrt. In den Kontoeinstellungen wieder erlauben, wenn Umsätze automatisch abgerufen werden sollen.');
            }
            $credentials = (new FinTsCredentialVault($this->root, $this->stateKey(), $a['club_id']))->load();
        } catch (DomainException $e) {
            return $this->finish($a, $id, 'needs_setup', '', 'Ein dauerhaft gespeicherter FinTS-Zugang ist erforderlich. Einmalig verbinden und „Zugang dauerhaft merken“ auswählen.');
        }
        try {
            $config = $this->config();
            $bank = $this->bank($config, (string)($credentials['bank_id'] ?? ''));
            $engine = $this->engine ?: new \Kulturhus\Banking\FinTsReadEngine($config);
            $hasCompletedRun = (bool)$this->q('SELECT 1 FROM fints_sync_runs WHERE club_id=? AND mapping_id=? AND state=? LIMIT 1', [$a['club_id'], $mappingId, 'complete'])->fetchColumn();
            $from = $hasCompletedRun ? $now->modify('-89 days')->format('Y-m-d') : '2000-01-01';
            $to = $now->format('Y-m-d');
            $read = $this->read($engine, $credentials, $bank, $mapping, $from, $to);
            $request = substr(hash('sha256', 'fints-scheduled|'.$a['club_id'].'|'.$mappingId.'|'.$window), 0, 32);
            $import = (new BankImport($this->db, $this->actor))->fetch($mappingId, 'PRODUCTION', $read['account'], $from, $to, $request, static fn () => ['transactions' => $read['rows']]);
            $period = $hasCompletedRun ? 'die letzten 89 Tage' : 'den verfügbaren historischen Zeitraum';
            return $this->finish($a, $id, 'complete', '', 'Abruf abgeschlossen; '.$period.' wurde abgeglichen.', (int)($import['rows_count'] ?? count($read['rows'])));
        } catch (FintsAuthorizationRequired $e) {
            return $this->finish($a, $id, 'needs_auth', $e->phase, $e->getMessage(), 0);
        } catch (DomainException $e) {
            return $this->finish($a, $id, 'failed', '', $e->getMessage(), 0);
        } catch (\Throwable) {
            return $this->finish($a, $id, 'failed', '', 'Der automatische Bankabruf konnte nicht abgeschlossen werden. Bitte den manuellen Abruf öffnen.', 0);
        }
    }

    private function finish(array $a, string $id, string $state, string $phase, string $message, int $rows = 0): array
    {
        $this->q('UPDATE fints_sync_runs SET state=?,phase=?,message=?,rows_count=?,finished_at=? WHERE club_id=? AND id=?', [$state, $phase, $message, $rows, gmdate('c'), $a['club_id'], $id]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)', [$a['club_id'], $a['id'], 'bank.fints.scheduled.'.$state, $id, 1, gmdate('c')]);
        return ['id' => $id, 'state' => $state, 'phase' => $phase, 'message' => $message, 'rows_count' => $rows];
    }

    private function stateKey(): string
    {
        $key = is_file($this->root.'/fints-state.key') ? (string)file_get_contents($this->root.'/fints-state.key') : '';
        if (strlen($key) !== 32) throw new DomainException('Privater FinTS-Sitzungsschlüssel fehlt.');
        return $key;
    }

    private function config(): array
    {
        $path = $this->root.'/fints.json';
        if (!is_file($path)) throw new DomainException('FinTS-Registrierung und Bankendpunkt zuerst privat einrichten.');
        $config = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new DomainException('Private FinTS-Konfiguration ungültig.');
        foreach (['url', 'bank_code', 'product_id', 'product_version', 'club_id'] as $key) if (empty($config[$key]) || !is_string($config[$key])) throw new DomainException('Private FinTS-Konfiguration unvollständig.');
        return $config;
    }

    private function bank(array $config, string $bankId): array
    {
        if ($bankId !== '') {
            try { return FintsInstitutions::find($bankId); } catch (DomainException) { /* private directory entry may have been removed */ }
        }
        return ['id' => $bankId !== '' ? $bankId : (string)$config['bank_code'], 'bank_code' => (string)$config['bank_code'], 'bic' => '', 'name' => 'Hinterlegte Bank', 'city' => '', 'url' => (string)$config['url'], 'version' => (string)$config['product_version']];
    }

    /** @return array{account:array<string,mixed>,rows:list<array<string,mixed>>} */
    private function read(object $engine, array $credentials, array $bank, array $mapping, string $from, string $to): array
    {
        $state = ['user' => $credentials['user'], 'pin' => $credentials['pin'], 'bank' => $bank, 'expires' => time() + 900];
        $state = $engine->advance($state, 'discover', []);
        $modes = is_array($state['modes'] ?? null) ? $state['modes'] : [];
        $mode = null;
        foreach ($modes as $candidate) if (is_array($candidate) && empty($candidate['medium'])) { $mode = $candidate; break; }
        if (!$mode) throw new FintsAuthorizationRequired('select', 'Die Bank verlangt eine Freigabe. Bitte den manuellen Abruf öffnen und PIN/Banking-App bestätigen.');
        $state = $engine->advance($state, 'select', ['mode' => (string)$mode['id']]);
        if (in_array($state['phase'] ?? '', ['tan', 'decoupled', 'poll'], true)) throw new FintsAuthorizationRequired((string)$state['phase'], 'Für den Umsatzabruf ist eine Freigabe in der Banking-App oder eine TAN erforderlich.', (string)($state['medium'] ?? ''));
        if (($state['phase'] ?? '') !== 'accounts' || !is_array($state['accounts'] ?? null)) throw new DomainException('Die Bank lieferte keine Kontenliste.');
        $selected = null;
        foreach ($state['accounts'] as $account) {
            if (!is_array($account)) continue;
            try { if (hash_equals((string)$mapping['identity_hash'], BankImport::identity($account))) { $selected = $account; break; } } catch (DomainException) { continue; }
        }
        if (!$selected) throw new DomainException('Das freigegebene Bankkonto wurde nicht gefunden. Bitte die Kontozuordnung prüfen.');
        $state = $engine->advance($state, 'statement', ['uid' => (string)$selected['uid'], 'from' => $from, 'to' => $to]);
        if (in_array($state['phase'] ?? '', ['tan', 'decoupled', 'poll'], true)) throw new FintsAuthorizationRequired((string)$state['phase'], 'Für die Umsätze ist jetzt eine Freigabe in der Banking-App oder eine TAN erforderlich.', (string)($state['medium'] ?? ''));
        if (($state['phase'] ?? '') !== 'ready' || !is_array($state['rows'] ?? null)) throw new DomainException('Die Bank lieferte keine lesbaren Umsätze.');
        return ['account' => $selected, 'rows' => array_values($state['rows'])];
    }
}
