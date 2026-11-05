<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Local, evidenced decisions. An accepted message and its first receipt never change. */
final class ReminderDecisions
{
    public const SECTION = 'invoice-reminder-delivery';
    public function __construct(private PDO $db, private string $actorId, #[\SensitiveParameter] private string $master) {}

    private function read(callable $work): mixed
    {
        $own = !$this->db->inTransaction();
        try {
            if ($own) $this->db->exec('START TRANSACTION');
            $result = $work();
            if ($own) $this->db->commit();
            return $result;
        } catch (\Throwable $failure) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            $this->busy($failure);
            throw $failure;
        }
    }
    private function busy(\Throwable $failure): void
    {
        if (($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205,3572], true)) || ($failure instanceof DomainException && $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')) {
            throw new DomainException('Rechnung oder Erinnerung wird gerade bearbeitet. Erneut prüfen.', 503, $failure);
        }
    }
    private function actor(bool $write = false): array
    {
        return (new ReminderRequests($this->db, $this->actorId, $this->master))->assertAccess($write);
    }
    public function assertAccess(bool $write = false): array { return $this->actor($write); }
    public function context(string $id): array
    {
        return (new ReminderDelivery($this->db, $this->actorId, $this->master))->get($id);
    }
    private static function hash(array $review): string
    {
        unset($review['hash']);
        return hash('sha256', ReminderRequests::json($review));
    }
    public function prepare(array $raw): array
    {
        $input = ReminderDecisionInput::capture($raw);
        $normalized = ReminderDecisionInput::normalize($input);
        return $this->read(function () use ($input, $normalized): array {
            $this->actor(true);
            $source = $this->context($input['job']);
            $expected = ['cancel'=>'pending', 'hold'=>'sending', 'confirm_sent'=>'uncertain', 'confirm_not_sent'=>'uncertain'];
            if ($source['state'] !== $expected[$input['operation']]) throw new DomainException('Diese Aktion passt nicht mehr zum Erinnerungsstatus. Ursprünglichen Ausgang erneut prüfen.', 409);
            if ($input['operation'] !== 'cancel') {
                $attempt = $source['attempt'];
                $state = $input['operation'] === 'hold' ? 'claimed' : 'uncertain';
                if (!$attempt || $attempt['state'] !== $state || $attempt['message_sha256'] !== $source['message_sha256']) throw new DomainException('Ursprünglichen Erinnerungsversuch bitte prüfen lassen.', 503);
                if ($input['operation'] === 'hold') {
                    $started = new DateTimeImmutable($attempt['started_at'], new DateTimeZone('UTC'));
                    if ($started > new DateTimeImmutable('-15 minutes', new DateTimeZone('UTC'))) throw new DomainException('Der Versand läuft noch. Unterbrochenen Serverlauf zuerst prüfen; keine Wiederfreigabe.', 409);
                }
                if ($input['operation'] === 'confirm_sent' && new DateTimeImmutable($normalized['sent_at']) < new DateTimeImmutable(substr($attempt['started_at'], 0, 16).':00', new DateTimeZone('UTC'))) throw new DomainException('Bestätigter Versandzeitpunkt liegt vor dem ursprünglichen Versuch. Nachweis prüfen.', 422);
            } elseif ($source['attempt_id'] !== null || $source['attempt'] !== null) {
                throw new DomainException('Begonnenen Erinnerungsversuch zuerst klären.', 409);
            }
            $review = ['input'=>$input, 'normalized'=>$normalized, 'source'=>$source, 'no_transport'=>true, 'no_requeue'=>true, 'no_new_money'=>true];
            return $review + ['hash'=>self::hash($review)];
        });
    }
    private function known(array $actor, array $input, string $key, string $hash): ?array
    {
        $row = Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT', [$actor['club_id'], $key])->fetch();
        if (!$row) return null;
        if ($row['actor_id'] !== $actor['id'] || $row['section'] !== self::SECTION || $row['input_json'] !== ReminderRequests::json($input)) throw new DomainException('Diese Erinnerungsentscheidung gehört zu anderen ursprünglichen Angaben.', 409);
        $first = $this->decode($row);
        if (!hash_equals($first['review']['hash'], $hash)) throw new DomainException('Diese Erinnerungsentscheidung gehört zu einer anderen ursprünglichen Prüfung.', 409);
        return $first;
    }
    public function accept(array $raw, string $key, string $hash, bool $approved): array
    {
        $input = ReminderDecisionInput::capture($raw);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $key) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) throw new DomainException('Eigene ursprüngliche Erinnerungsentscheidung auswählen.', 422);
        if (!$approved) throw new DomainException('Genau diese geprüfte Erinnerungsentscheidung ausdrücklich bestätigen.', 422);
        $actor = $this->actor();
        if ($first = $this->read(fn()=> $this->known($actor, $input, $key, $hash))) return $first;
        try {
            return Transactions::run($this->db, function () use ($input, $key, $hash, $actor): array {
                if (!Db::run($this->db, 'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT', [$actor['club_id']])->fetchColumn()) throw new DomainException('Eigenen aktiven Verein auswählen.', 403);
                $currentActor = $this->actor();
                if ($currentActor['club_id'] !== $actor['club_id']) throw new DomainException('Vereinszuordnung inzwischen geändert.', 403);
                if ($first = $this->known($currentActor, $input, $key, $hash)) return $first;
                $review = $this->prepare($input);
                if (!hash_equals($review['hash'], $hash)) throw new DomainException('Erinnerungsstatus inzwischen geändert. Ganze Entscheidung erneut prüfen.', 409);
                $before = $review['source'];
                // Reading the row above checks every current output scope; this lock excludes a simultaneous worker claim.
                $locked = Db::run($this->db, 'SELECT state,version FROM native_invoice_reminder_deliveries WHERE club_id=? AND id=? FOR UPDATE NOWAIT', [$actor['club_id'], $input['job']])->fetch();
                if (!$locked || $locked['state'] !== $before['state'] || (int)$locked['version'] !== (int)$before['version']) throw new DomainException('Erinnerungsstatus inzwischen geändert. Ganze Entscheidung erneut prüfen.', 409);
                $state = ['cancel'=>'cancelled', 'hold'=>'uncertain', 'confirm_sent'=>'sent', 'confirm_not_sent'=>'blocked'][$input['operation']];
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $sqlTime = $now->format('Y-m-d H:i:s.u');
                Db::run($this->db, 'UPDATE native_invoice_reminder_deliveries SET state=?,reason_code=?,version=version+1,updated_at=? WHERE club_id=? AND id=? AND version=?', [$state, 'manual_'.$input['operation'], $sqlTime, $actor['club_id'], $input['job'], $before['version']]);
                if ($before['attempt_id']) {
                    $attemptState = ['hold'=>'uncertain', 'confirm_sent'=>'sent', 'confirm_not_sent'=>'not_sent'][$input['operation']];
                    Db::run($this->db, 'UPDATE native_invoice_reminder_attempts SET state=?,reason_code=?,resolution_key=?,completed_at=? WHERE club_id=? AND id=? AND delivery_id=?', [$attemptState, 'manual_'.$input['operation'], $key, $sqlTime, $actor['club_id'], $before['attempt_id'], $input['job']]);
                }
                $result = $this->context($input['job']);
                $first = ['format'=>'dvm-invoice-reminder-delivery-original/1', 'request_key'=>$key, 'section'=>self::SECTION, 'club_id'=>$actor['club_id'], 'actor_id'=>$actor['id'], 'input'=>$input, 'review'=>$review, 'approved'=>true, 'result'=>$result, 'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')];
                $json = ReminderRequests::json($input);
                $original = ReminderRequests::json($first);
                Db::run($this->db, 'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,1,?,?,?,?,?)', [$actor['club_id'], $key, $actor['id'], self::SECTION, hash('sha256', self::SECTION."\0".$json), $json, hash('sha256', $original), $original, $sqlTime]);
                Db::run($this->db, 'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)', [$actor['club_id'], $actor['id'], 'invoice.reminder.'.$input['operation'], $input['job'], (int)$result['version'], $now->format('Y-m-d\TH:i:s.u\Z')]);
                return $this->decode(Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT', [$actor['club_id'], $key])->fetch());
            });
        } catch (\Throwable $failure) {
            $this->busy($failure);
            throw $failure;
        }
    }
    private function decode(array $row): array
    {
        try {
            $input = json_decode($row['input_json'], true, 64, JSON_THROW_ON_ERROR);
            $first = json_decode($row['original_json'], true, 64, JSON_THROW_ON_ERROR);
            $normalized = ReminderDecisionInput::normalize($input);
            $expected = ['cancel'=>'cancelled', 'hold'=>'uncertain', 'confirm_sent'=>'sent', 'confirm_not_sent'=>'blocked'][$input['operation']];
            $valid = $row['section'] === self::SECTION && (int)$row['version'] === 1
                && hash_equals($row['input_sha256'], hash('sha256', self::SECTION."\0".$row['input_json']))
                && hash_equals($row['original_sha256'], hash('sha256', $row['original_json']))
                && ($first['format'] ?? null) === 'dvm-invoice-reminder-delivery-original/1'
                && ($first['section'] ?? null) === self::SECTION && ($first['request_key'] ?? null) === $row['request_key']
                && ($first['club_id'] ?? null) === $row['club_id'] && ($first['actor_id'] ?? null) === $row['actor_id']
                && ($first['input'] ?? null) === $input && ($first['review']['input'] ?? null) === $input
                && ($first['review']['normalized'] ?? null) === $normalized && ($first['approved'] ?? null) === true
                && ($first['review']['no_transport'] ?? null) === true && ($first['review']['no_requeue'] ?? null) === true
                && ($first['review']['no_new_money'] ?? null) === true && hash_equals($first['review']['hash'] ?? '', self::hash($first['review'] ?? []))
                && ($first['result']['id'] ?? null) === $input['job'] && ($first['result']['club_id'] ?? null) === $row['club_id']
                && ($first['result']['state'] ?? null) === $expected
                && (int)($first['result']['version'] ?? 0) === (int)($first['review']['source']['version'] ?? 0) + 1
                && ($first['created_at'] ?? null) === (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            if (!$valid) throw new DomainException('Ungültige ursprüngliche Entscheidung.');
            $this->context($input['job']); // Current permissions and original output; later state never substitutes the first.
            return ['key'=>$row['request_key'], 'input'=>$input, 'review'=>$first['review'], 'result'=>$first['result'], 'created_at'=>$first['created_at'], 'original_json'=>$row['original_json'], 'sha256'=>$row['original_sha256']];
        } catch (DomainException $failure) {
            if (in_array($failure->getCode(), [403,404,503], true)) throw $failure;
            throw new DomainException('Ursprüngliche Erinnerungsentscheidung bitte prüfen lassen.', 503, $failure);
        } catch (\Throwable $failure) {
            throw new DomainException('Ursprüngliche Erinnerungsentscheidung bitte prüfen lassen.', 503, $failure);
        }
    }
    public function receipt(string $key): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $key)) throw new DomainException('Eigene ursprüngliche Erinnerungsentscheidung nicht verfügbar.', 404);
        return $this->read(function () use ($key): array {
            $actor = $this->actor();
            $row = Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT', [$actor['club_id'], $actor['id'], self::SECTION, $key])->fetch();
            if (!$row) throw new DomainException('Eigene ursprüngliche Erinnerungsentscheidung nicht verfügbar.', 404);
            return $this->decode($row);
        });
    }
    public function history(string $job, array $query = []): array
    {
        return ReminderHistory::read($this->db, $this->actorId, self::SECTION, $job, $query);
    }
}
