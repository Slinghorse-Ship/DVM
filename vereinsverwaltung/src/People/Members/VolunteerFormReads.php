<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Current personal reads and original acknowledgements for the existing certificate service. */
trait VolunteerFormReads
{
    private function currentLock(): string
    {
        return $this->db->inTransaction() ? ' FOR SHARE NOWAIT' : '';
    }

    private function read(callable $operation): mixed
    {
        $owned = !$this->db->inTransaction();
        if ($owned) $this->db->beginTransaction();
        try {
            $result = $operation();
            if ($owned) $this->db->commit();
            return $result;
        } catch (\Throwable $failure) {
            if ($owned && $this->db->inTransaction()) $this->db->rollBack();
            if ($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true)) {
                throw new DomainException('Die Bestätigung wird gerade bearbeitet. Bitte erneut öffnen.', 503, $failure);
            }
            throw $failure;
        }
    }

    private function currentSignature(): ?array
    {
        if (!$this->canSign()) throw new DomainException('Die hinterlegte Unterschrift darf hier nur der erste Vorstand oder Superuser verwenden.');
        $actor = Permissions::actor($this->db, $this->actor);
        $paper = $this->query("SELECT payload FROM club_settings WHERE club_id=? AND section='paper'".$this->currentLock(), [$actor['club_id']])->fetchColumn();
        $settings = $paper === false ? [] : json_decode($paper, true, 512, JSON_THROW_ON_ERROR);
        $id = $settings['signature'] ?? '';
        if ($id === '') return null;
        $asset = $this->query('SELECT id,mime,bytes,sha256 FROM settings_assets WHERE club_id=? AND id=?'.$this->currentLock(), [$actor['club_id'], $id])->fetch();
        if (!$asset || !is_string($asset['bytes']) || !hash_equals($asset['sha256'], hash('sha256', $asset['bytes']))) {
            throw new DomainException('Die hinterlegte Unterschrift ist nicht verfügbar. Bitte neu hochladen.');
        }
        return $asset;
    }

    /** Bounded current metadata for a deliberate native person selection; no profile bodies. */
    public function peoplePage(array $query = []): array
    {
        return $this->read(function () use ($query): array {
            $actor = BoardAccess::require($this->db, $this->actor);
            $search = array_key_exists('query', $query) ? $query['query'] : '';
            $page = array_key_exists('page', $query) ? $query['page'] : 1;
            if (!is_string($search) || preg_match('//u', $search) !== 1 || mb_strlen($search) > 240 || preg_match('/[\x00-\x1f]/', $search)) {
                throw new DomainException('Personensuche mit höchstens 240 Zeichen eingeben.', 422);
            }
            if (!is_int($page) && !is_string($page) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$page)) {
                throw new DomainException('Gültige Personenseite auswählen.', 422);
            }
            $page = (int)$page;
            $args = [$actor['club_id']];
            $where = "club_id=? AND kind='person' AND archived_at IS NULL";
            if ($this->kind === 'general') {
                $where .= " AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=contacts.club_id AND m.contact_id=contacts.id AND m.status IN('aktiv','passiv') AND m.ends_on IS NULL AND m.starts_on<=?".$this->currentLock().")";
                $args[] = date('Y-m-d');
            }
            if ($search !== '') {
                $where .= " AND (name LIKE ? ESCAPE '=' OR id LIKE ? ESCAPE '=')";
                $needle = '%'.str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search).'%';
                $args[] = $needle; $args[] = $needle;
            }
            // Count the same current locked lightweight index, then load at most 25 names.
            $ids = $this->query('SELECT id FROM contacts WHERE '.$where.' ORDER BY name,id'.$this->currentLock(), $args)->fetchAll(PDO::FETCH_COLUMN);
            $total = count($ids); $pages = max(1, (int)ceil($total / 25));
            if ($page > $pages) throw new DomainException('Diese Personenseite ist nicht verfügbar.', 422);
            $ids = array_slice($ids, ($page - 1) * 25, 25);
            $rows = $ids ? $this->query('SELECT id,name FROM contacts WHERE club_id=? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY name,id'.$this->currentLock(), [$actor['club_id'], ...$ids])->fetchAll() : [];
            return ['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'per_page'=>25, 'query'=>$search];
        });
    }

    private function intent(string $contact, array $input, bool $signed, string $key, string $previewHash): array
    {
        $values = [];
        foreach ($this->fields() as $field) $values[$field] = $input[$field] ?? '';
        return ['actor'=>$this->actor, 'contact'=>$contact, 'kind'=>$this->kind, 'input'=>$values,
            'signed'=>$signed, 'request_key'=>$key, 'preview_hash'=>$previewHash];
    }

    /** Only new exact original intentions are acknowledged; no historic receipts are invented. */
    public function confirmation(string $key): array
    {
        return $this->read(function () use ($key): array {
            if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new DomainException('Anlegebestätigung nicht verfügbar.', 404);
            $actor = Permissions::actor($this->db, $this->actor);
            $request = $this->query('SELECT request_hash,document_id FROM document_requests WHERE club_id=? AND request_key=?'.$this->currentLock(), [$actor['club_id'], $key])->fetch();
            $file = $request ? $this->query('SELECT name,mime,sha256,snapshot FROM document_files WHERE club_id=? AND document_id=? AND revision=1'.$this->currentLock(), [$actor['club_id'], $request['document_id']])->fetch() : false;
            $snapshot = $file ? json_decode($file['snapshot'], true, 512, JSON_THROW_ON_ERROR) : null;
            $intent = is_array($snapshot) ? ($snapshot['volunteer_intent'] ?? null) : null;
            if (!is_array($intent) || ($intent['actor'] ?? '') !== $actor['id'] || ($intent['request_key'] ?? '') !== $key || ($intent['kind'] ?? '') !== $this->kind) {
                throw new DomainException('Anlegebestätigung nicht verfügbar.', 404);
            }
            $original = $snapshot; unset($original['purpose'], $original['volunteer_intent']);
            $digest = self::digest($original);
            if (!is_string($intent['preview_hash'] ?? null) || !hash_equals($intent['preview_hash'], $digest) ||
                !hash_equals($request['request_hash'], hash('sha256', 'volunteer-form|'.$actor['id'].'|'.$digest))) {
                throw new DomainException('Anlegebestätigung nicht verfügbar.', 404);
            }
            $download = $this->file($request['document_id']);
            if (!hash_equals($file['sha256'], hash('sha256', $download['bytes']))) throw new DomainException('Anlegebestätigung nicht verfügbar.', 404);
            return ['document_id'=>$request['document_id'], 'intent'=>$intent, 'snapshot'=>$original,
                'name'=>$file['name'], 'mime'=>$file['mime'], 'sha256'=>$file['sha256']];
        });
    }

    private function replayed(string $contact, array $input, bool $signed, string $key, string $previewHash): ?string
    {
        return $this->read(function () use ($contact, $input, $signed, $key, $previewHash): ?string {
            $actor = Permissions::actor($this->db, $this->actor);
            $existing = $this->query('SELECT document_id FROM document_requests WHERE club_id=? AND request_key=?'.$this->currentLock(), [$actor['club_id'], $key])->fetchColumn();
            if ($existing === false) return null;
            try { $receipt = $this->confirmation($key); }
            catch (DomainException $failure) {
                if ($failure->getCode() === 404) return null; // Legacy requests retain their established validation below.
                throw $failure;
            }
            $expected = $this->intent($contact, $input, $signed, $key, $previewHash !== '' ? $previewHash : $receipt['intent']['preview_hash']);
            if ($expected !== $receipt['intent']) throw new DomainException('Vorgangskennung mit anderen Eingaben bereits verwendet.');
            return $receipt['document_id'];
        });
    }
}
