<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{DocumentPreview, Documents, Permissions, MemberBankVault, ProductionRuntime, FinancePaymentAuthority};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Conscious local notices create no collection order, money movement or delivery proof. */
final class DebitRequests
{
    public const SECTION = 'debit-notice';
    public function __construct(private PDO $db, private string $actorId, private ?MemberBankVault $vault = null) {}
    private function vault(): MemberBankVault { return $this->vault ??= MemberBankVault::fromEnvironment($this->db, $this->actorId, ProductionRuntime::enabled()); }
    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
    private function actor(bool $write = false): array
    {
        try {
            $actor = Permissions::finance($this->db, $this->actorId, $write);
            if ($write && !FinancePaymentAuthority::find($this->db, $this->actorId)) throw new DomainException('Aktuelle Bankberechtigung für das gebundene Mandat erforderlich.', 403);
            return $actor;
        }
        catch (DomainException $failure) {
            if ($failure->getCode() === 503 || $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.') throw $failure;
            if ($failure->getMessage() === 'Aktuelle Bankberechtigung für das gebundene Mandat erforderlich.') throw $failure;
            throw new DomainException($write ? 'Aktuelle Finanzschreibberechtigung erforderlich.' : 'Aktuelle Finanzleseberechtigung erforderlich.', 403, $failure);
        }
    }
    private function busy(\Throwable $failure): void
    {
        if (($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true))
            || ($failure instanceof DomainException && $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')) {
            throw new DomainException('Lastschriftvorankündigung oder Quelle wird gerade bearbeitet. Erneut prüfen.', 503, $failure);
        }
    }
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
    public function assertAccess(bool $write = false): array { return $this->read(fn(): array => $this->actor($write)); }
    public function source(string $entry): array
    {
        return $this->read(function () use ($entry): array {
            $this->actor(true);
            return DebitSource::read($this->db, $this->actorId, $entry, $this->vault());
        });
    }
    public function parentDocument(string $entry): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $entry)) throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
        return $this->read(function () use ($entry): string {
            $actor = $this->actor();
            $rows = Db::run($this->db, "SELECT document_id FROM contribution_invoices WHERE club_id=? AND entry_id=? UNION ALL SELECT document_id FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND kind='invoice'", [$actor['club_id'], $entry, $actor['club_id'], $entry])->fetchAll(PDO::FETCH_COLUMN);
            $document = count($rows) === 1 ? $rows[0] : null;
            if (!is_string($document) || $document === '') throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
            return $document;
        });
    }
    private function available(): void
    {
        if (!Db::run($this->db, "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn()) {
            throw new DomainException('Lastschriftvorankündigungen benötigen das aktuelle installierte Paket.', 503);
        }
    }
    private static function hash(array $review): string
    {
        unset($review['hash']);
        return hash('sha256', self::json($review));
    }
    public function initial(string $entry, string $day, string $collection): array
    {
        return $this->read(function () use ($entry, $day, $collection): array {
            $this->actor(true);
            $this->available();
            return DebitInput::initial(DebitContext::read($this->db, $this->actorId, $entry, $day, $collection, $this->vault()));
        });
    }
    private function plan(array $raw): array
    {
        $input = DebitInput::normalize($raw);
        return $this->read(function () use ($input): array {
            $actor = $this->actor(true);
            $this->available();
            $context = DebitContext::read($this->db, $this->actorId, $input['entry'], $input['day'], $input['collection'], $this->vault());
            $document = DebitText::document($context, $input);
            $source = $context;
            unset($source['assets'], $source['claim']['snapshot']);
            $review = ['input'=>$input, 'source'=>$source, 'document_sha256'=>hash('sha256', self::json($document)),
                'actor_id'=>$actor['id'], 'club_id'=>$actor['club_id'], 'no_transport'=>true, 'no_new_money'=>true, 'no_new_claim'=>true];
            return ['review'=>$review + ['hash'=>self::hash($review)], 'document'=>$document];
        });
    }
    public function preview(array $raw): array
    {
        $plan = $this->plan($raw);
        $plan['pdf'] = DocumentPreview::pdf($plan['document']);
        return $plan;
    }
    public function prepare(array $raw): array { return $this->preview($raw)['review']; }
    public function reviewedPreview(array $raw, string $reviewHash): array
    {
        $plan = $this->plan($raw);
        if (!hash_equals($plan['review']['hash'], $reviewHash)) {
            throw new DomainException('Originalrechnung, Brieftexte oder Vorlagenquellen inzwischen geändert. Ganze Lastschriftvorankündigung erneut prüfen.', 409);
        }
        $plan['pdf'] = DocumentPreview::pdf($plan['document']);
        return $plan;
    }
    private function known(array $input, string $key, string $reviewHash): ?array
    {
        return $this->read(function () use ($input, $key, $reviewHash): ?array {
            $actor = $this->actor();
            $this->available();
            $row = Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT', [$actor['club_id'], $key])->fetch();
            if (!$row) return null;
            $json = self::json($input);
            if ($row['actor_id'] !== $actor['id'] || $row['section'] !== self::SECTION || $row['input_json'] !== $json
                || !hash_equals($row['input_sha256'], hash('sha256', self::SECTION."\0".$json))) {
                throw new DomainException('Diese Vorankündigungskennung gehört zu anderen ursprünglichen Angaben.', 409);
            }
            $first = $this->decode($row);
            if (!hash_equals($first['review']['hash'], $reviewHash)) {
                throw new DomainException('Diese Vorankündigungskennung gehört zu einer anderen ursprünglichen Prüfung.', 409);
            }
            return $first;
        });
    }
    public function accept(array $raw, string $key, string $reviewHash, bool $approved): array
    {
        $input = DebitInput::capture($raw);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $key) || !preg_match('/\A[a-f0-9]{64}\z/D', $reviewHash)) {
            throw new DomainException('Eigene ursprüngliche Vorankündigungskennung und Prüfung auswählen.', 422);
        }
        if (!$approved) throw new DomainException('Genau diese vollständig geprüfte Lastschriftvorankündigung ausdrücklich bestätigen.', 422);
        if ($first = $this->known($input, $key, $reviewHash)) return $first;
        // Rendering is outside our own financial transaction. The exact sources are read again before storage.
        $preview = $this->reviewedPreview($input, $reviewHash);
        $club = Db::run($this->db, 'SELECT club_id FROM actors WHERE id=?', [$this->actorId])->fetchColumn();
        if (!is_string($club) || $club === '') throw new DomainException('Eigenen aktiven Verein auswählen.', 403);
        try {
            return Transactions::run($this->db, function () use ($input, $key, $reviewHash, $preview, $club): array {
                if (!Db::run($this->db, 'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT', [$club])->fetchColumn()) {
                    throw new DomainException('Eigenen aktiven Verein auswählen.', 403);
                }
                $actor = $this->actor();
                if ($actor['club_id'] !== $club) throw new DomainException('Vereinszuordnung inzwischen geändert.', 403);
                if ($first = $this->known($input, $key, $reviewHash)) return $first;
                $current = $this->plan($input);
                if (!hash_equals($current['review']['hash'], $reviewHash)
                    || $current['review']['document_sha256'] !== $preview['review']['document_sha256']) {
                    throw new DomainException('Originalrechnung, Brieftexte oder Vorlagenquellen inzwischen geändert. Ganze Lastschriftvorankündigung erneut prüfen.', 409);
                }
                $source = $current['review']['source']['claim'];
                $parent = $source['document']['id'];
                $snapshot = ['kind'=>'debit-notice', 'format'=>'dvm-debit-notice-document/1', 'source_document'=>$parent,
                    'entry_id'=>$input['entry'], 'source_file_sha256'=>$source['file']['sha256'],
                    'template'=>$source['template'], 'template_source'=>$current['review']['source']['settings']['template'],
                    'rendered'=>$current['document']];
                $filename = 'dvm-lastschriftvorankuendigung-'.$input['entry'].'.pdf';
                $documents = new Documents($this->db, $this->actorId);
                $document = $documents->generatedForFinancialOriginal($parent, $filename, $preview['pdf'],
                    ['title'=>$current['document']['title'], 'kind'=>'record', 'scope'=>'finance', 'document_date'=>$input['day'],
                        'links'=>[], 'notes'=>'Lastschriftvorankündigung zur Originalrechnung '.$input['entry'].'. Keine Einreichung, Zahlung oder Versandbestätigung.'],
                    $key, $snapshot);
                $result = ['document_id'=>$document, 'filename'=>$filename, 'mime'=>'application/pdf',
                    'sha256'=>hash('sha256', $preview['pdf']), 'bytes'=>strlen($preview['pdf']),
                    'source_document'=>$parent, 'entry_id'=>$input['entry']];
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $original = self::json(['format'=>'dvm-debit-notice-original/1', 'request_key'=>$key,
                    'club_id'=>$club, 'actor_id'=>$actor['id'], 'section'=>self::SECTION, 'input'=>$input,
                    'review'=>$current['review'], 'approved'=>true, 'result'=>$result, 'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
                Db::run($this->db, 'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,1,?)',
                    [$club, $actor['id'], 'debit.notice.accepted', $document, $now->format('Y-m-d\TH:i:s.u\Z')]);
                $json = self::json($input);
                Db::run($this->db, 'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,1,?,?,?,?,?)',
                    [$club, $key, $actor['id'], self::SECTION, hash('sha256', self::SECTION."\0".$json), $json,
                        hash('sha256', $original), $original, $now->format('Y-m-d H:i:s.u')]);
                return $this->decode(Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT', [$club, $key])->fetch());
            });
        } catch (\Throwable $failure) {
            $this->busy($failure);
            throw $failure;
        }
    }
    private function decode(array $row): array
    {
        $input = json_decode($row['input_json'], true, 64, JSON_THROW_ON_ERROR);
        $first = json_decode($row['original_json'], true, 64, JSON_THROW_ON_ERROR);
        $valid = is_array($input) && is_array($first) && $row['section'] === self::SECTION && (int)$row['version'] === 1
            && hash_equals($row['input_sha256'], hash('sha256', self::SECTION."\0".$row['input_json']))
            && hash_equals($row['original_sha256'], hash('sha256', $row['original_json']))
            && ($first['format'] ?? null) === 'dvm-debit-notice-original/1'
            && ($first['request_key'] ?? null) === $row['request_key'] && ($first['club_id'] ?? null) === $row['club_id']
            && ($first['actor_id'] ?? null) === $row['actor_id'] && ($first['section'] ?? null) === self::SECTION
            && ($first['input'] ?? null) === $input && ($first['review']['input'] ?? null) === $input
            && ($first['approved'] ?? null) === true && ($first['review']['no_transport'] ?? null) === true
            && ($first['review']['no_new_money'] ?? null) === true && ($first['review']['no_new_claim'] ?? null) === true;
        if (!$valid || !hash_equals((string)($first['review']['hash'] ?? ''), self::hash($first['review'] ?? []))
            || ($first['created_at'] ?? null) !== (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')) {
            throw new DomainException('Ursprüngliche persönliche Vorankündigungsbestätigung bitte prüfen lassen.', 503);
        }
        DebitInput::normalize($input);
        $result = $first['result'] ?? [];
        $source = $first['review']['source']['claim'] ?? [];
        if (($first['review']['actor_id'] ?? null) !== $row['actor_id'] || ($first['review']['club_id'] ?? null) !== $row['club_id']
            || ($result['entry_id'] ?? null) !== $input['entry'] || ($source['entry']['id'] ?? null) !== $input['entry']
            || ($result['source_document'] ?? null) !== ($source['document']['id'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/D', (string)($result['document_id'] ?? ''))
            || !preg_match('/\A[a-f0-9]{64}\z/D', (string)($result['sha256'] ?? ''))
            || ($result['mime'] ?? null) !== 'application/pdf' || !is_int($result['bytes'] ?? null) || $result['bytes'] < 5
            || ($result['filename'] ?? null) !== 'dvm-lastschriftvorankuendigung-'.$input['entry'].'.pdf') {
            throw new DomainException('Ursprüngliches Lastschriftvorankündigung-PDF bitte prüfen lassen.', 503);
        }
        // Historical originals retain all live output ACLs; later template or donor changes are irrelevant.
        $output = (new Documents($this->db, $this->actorId))->currentFinancialOriginal($result['document_id'], 1);
        if ($output['document']['origin'] !== 'generated' || $output['document']['kind'] !== 'record'
            || $output['file']['sha256'] !== $result['sha256'] || (int)$output['file']['size'] !== $result['bytes']) {
            throw new DomainException('Ursprüngliches Lastschriftvorankündigung-PDF bitte prüfen lassen.', 503);
        }
        return ['key'=>$row['request_key'], 'input'=>$input, 'review'=>$first['review'], 'result'=>$result,
            'created_at'=>$first['created_at'], 'original_json'=>$row['original_json'], 'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $key)) throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.', 404);
        return $this->read(function () use ($key): array {
            $actor = $this->actor();
            $this->available();
            $row = Db::run($this->db, 'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT', [$actor['club_id'], $actor['id'], self::SECTION, $key])->fetch();
            if (!$row) throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.', 404);
            return $this->decode($row);
        });
    }
    public function pdf(string $key): string
    {
        return $this->read(function () use ($key): string {
            $first = $this->receipt($key);
            $actor = $this->actor();
            $file = Db::run($this->db, 'SELECT bytes,sha256,mime FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR SHARE NOWAIT', [$actor['club_id'], $first['result']['document_id']])->fetch();
            if (!$file || $file['mime'] !== 'application/pdf' || $file['sha256'] !== $first['result']['sha256']
                || !hash_equals($file['sha256'], hash('sha256', $file['bytes'])) || strlen($file['bytes']) !== $first['result']['bytes']) {
                throw new DomainException('Ursprüngliches Lastschriftvorankündigung-PDF bitte prüfen lassen.', 503);
            }
            return $file['bytes'];
        });
    }
    public function history(string $entry, array $query = []): array
    {
        $search = $query['query'] ?? '';
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) || is_int($rawPage) ? (string)$rawPage : '';
        $error = '';
        if (!is_string($search) || preg_match('//u', $search) !== 1 || mb_strlen($search) > 150 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            $error = 'Ganze Vorankündigungssuche ohne Steuerzeichen mit höchstens 150 Zeichen eingeben.';
        }
        if (!preg_match('/\A[1-9][0-9]{0,8}\z/D', $page)) $error = 'Verfügbare genaue Vorankündigungsseite auswählen.';
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $entry)) throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
        return $this->read(function () use ($entry, $search, $page, $error): array {
            $actor = $this->actor();
            $this->available();
            $base = ['rows'=>[], 'total'=>0, 'pages'=>1, 'page'=>null, 'page_input'=>$page,
                'query'=>is_string($search) ? $search : '', 'per_page'=>25, 'error'=>$error];
            if ($error !== '') return $base;
            $where = " WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.entry'))=?";
            $args = [$actor['club_id'], $actor['id'], self::SECTION, $entry];
            if ($search !== '') {
                $where .= " AND LOCATE(LOWER(?),LOWER(CONCAT(request_key,CHAR(10),JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.reason')),CHAR(10),JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.text.subject')))))>0";
                $args[] = $search;
            }
            $total = (int)Db::run($this->db, 'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT', $args)->fetchColumn();
            $pages = max(1, (int)ceil($total / 25));
            if ((int)$page > $pages) return array_replace($base, ['total'=>$total, 'pages'=>$pages, 'error'=>'Verfügbare genaue Vorankündigungsseite auswählen.']);
            $rows = Db::run($this->db, 'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT', $args)->fetchAll();
            return array_replace($base, ['rows'=>$rows, 'total'=>$total, 'pages'=>$pages, 'page'=>(int)$page]);
        });
    }
}
