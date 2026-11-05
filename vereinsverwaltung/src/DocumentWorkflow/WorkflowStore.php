<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

require_once __DIR__ . '/WorkflowAccess.php';
require_once __DIR__ . '/WorkflowSignatureAccess.php';
require_once __DIR__ . '/WorkflowException.php';
require_once __DIR__ . '/WorkflowSchema.php';
require_once __DIR__ . '/RiskMatrix.php';
require_once __DIR__ . '/RiskRegister.php';
require_once __DIR__ . '/WorkflowPdf.php';
require_once __DIR__ . '/WorkflowDeadlines.php';
require_once __DIR__ . '/WorkflowPages.php';
require_once __DIR__ . '/WorkflowCreations.php';

/** CMS-independent service. No migrations, external calls or side effects in the constructor. */
final class WorkflowStore
{
    use WorkflowPages;
    use WorkflowCreations;
    public const APPROVAL_NOTE = 'Die Freigabe dokumentiert die interne Prüfung dieser Dokumentfassung.';

    public function __construct(private \PDO $pdo, private WorkflowAccess $access, private bool $sharedEditing = true)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new WorkflowException('Das Dokumentenmodul benötigt MySQL.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Shared module workflow: current write permission is sufficient, assignment remains informational. */
    public function collaborative(): bool { return $this->sharedEditing; }

    public function actor(): array
    {
        $actor = $this->access->actor();
        $id = $this->externalId($actor['id'] ?? '', false);
        $club = $this->externalId($actor['club_id'] ?? '', false);
        $level = $actor['access'] ?? 'none';
        if (!is_string($level) || !in_array($level, ['none', 'read', 'write'], true)) {
            throw new WorkflowException('Die Modulberechtigung ist ungültig.');
        }
        return ['id' => $id, 'club_id' => $club, 'access' => $level];
    }

    public function participants(): array
    {
        $this->context();
        return $this->currentParticipants();
    }

    public function folders(): array
    {
        $actor = $this->context();
        $rows = $this->all('SELECT * FROM dwf_folders WHERE club_id = ? ORDER BY name, id', [$actor['club_id']]);
        return array_map(fn(array $row): array => $this->normalize($row), $rows);
    }

    public function folder(string $id):array
    {
        $actor=$this->context();$this->internalId($id);
        $row=$this->one('SELECT * FROM dwf_folders WHERE club_id=? AND id=?',[$actor['club_id'],$id]);
        if($row===null)throw new WorkflowException('Der Ordner wurde nicht gefunden.');
        return $this->normalize($row);
    }

    public function saveFolder(string $id, int $version, string $name, string $parent = '', ?string $creationKey=null): string
    {
        if($creationKey!==null){if($id!==''||$version!==0)throw new WorkflowException('Anlegekennung nur für neue Ordner verwenden.');return $this->createOriginalOnce('folder',['name'=>$name,'parent'=>$parent],$creationKey,fn():string=>$this->saveFolder($id,$version,$name,$parent));}
        $this->internalId($id, true);
        $this->internalId($parent, true);
        $name = $this->text($name, 240, 'Ordnername', true);
        return $this->mutate(function (array $actor) use ($id, $version, $name, $parent): string {
            // Lock the complete tenant tree in a deterministic order so two concurrent moves cannot form a cycle.
            $rows = $this->all('SELECT * FROM dwf_folders WHERE club_id = ? ORDER BY id FOR UPDATE', [$actor['club_id']]);
            $tree = array_column($rows, null, 'id');
            if ($id !== '') {
                if (!isset($tree[$id])) {
                    throw new WorkflowException('Der Ordner wurde nicht gefunden.');
                }
                $this->expected($tree[$id], $version);
            } elseif ($version !== 0) {
                throw new WorkflowException('Neue Ordner benötigen Version 0.');
            }
            if ($parent !== '' && !isset($tree[$parent])) {
                throw new WorkflowException('Der übergeordnete Ordner wurde nicht gefunden.');
            }
            $seen = [];
            for ($cursor = $parent; $cursor !== ''; $cursor = (string) ($tree[$cursor]['parent_id'] ?? '')) {
                if ($cursor === $id || isset($seen[$cursor])) {
                    throw new WorkflowException('Ordner dürfen keine zyklische Hierarchie bilden.');
                }
                $seen[$cursor] = true;
            }
            $now = $this->now();
            if ($id === '') {
                $newId = $this->newId();
                $this->run('INSERT INTO dwf_folders (id, club_id, name, parent_id, version, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [$newId, $actor['club_id'], $name, $parent, $now, $now]);
                return $newId;
            }
            $this->run('UPDATE dwf_folders SET name = ?, parent_id = ?, version = version + 1, updated_at = ? WHERE club_id = ? AND id = ?', [$name, $parent, $now, $actor['club_id'], $id]);
            return $id;
        });
    }

    public function templates(): array
    {
        $actor = $this->context();
        return $this->completeOrderedOriginals('dwf_templates','club_id=?',[$actor['club_id']],'title,id');
    }

    public function template(string $id): array
    {
        $actor = $this->context();
        return $this->normalize($this->templateRow($id, $actor));
    }

    /** Published revisions only. Editing a published template never updates these records. */
    public function templateVersions(string $id): array
    {
        $actor = $this->context();
        $this->templateRow($id, $actor);
        $rows = $this->all('SELECT * FROM dwf_template_versions WHERE club_id = ? AND template_id = ? ORDER BY version DESC', [$actor['club_id'], $id]);
        return array_map(function (array $row): array {
            return $this->decode($row['snapshot_json']) + ['published_by' => $row['published_by'], 'published_at' => $row['published_at']];
        }, $rows);
    }

    public function saveTemplate(string $id, int $version, array $data, ?string $creationKey=null): string
    {
        if($creationKey!==null){if($id!==''||$version!==0)throw new WorkflowException('Anlegekennung nur für neue Vorlagen verwenden.');return $this->createOriginalOnce('template',['data'=>$data],$creationKey,fn():string=>$this->saveTemplate($id,$version,$data));}
        $this->internalId($id, true);
        $this->keys($data, ['title', 'description', 'fields', 'provenance'], 'Vorlage');
        $title = $this->text($data['title'] ?? null, 240, 'Vorlagentitel', true);
        $description = $this->text($data['description'] ?? '', 6000, 'Beschreibung');
        $fields = $this->validateFields($data['fields'] ?? null);
        $provenance = $this->validateProvenance($data['provenance'] ?? []);
        return $this->mutate(function (array $actor) use ($id, $version, $title, $description, $fields, $provenance): string {
            $now = $this->now();
            if ($id === '') {
                if ($version !== 0) {
                    throw new WorkflowException('Neue Vorlagen benötigen Version 0.');
                }
                $newId = $this->newId();
                $this->run('INSERT INTO dwf_templates (id, club_id, title, description, fields_json, provenance_json, version, published_version, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?)', [$newId, $actor['club_id'], $title, $description, $this->json($fields), $this->json($provenance), 'draft', $actor['id'], $now, $now]);
                return $newId;
            }
            $row = $this->templateRow($id, $actor, true);
            $this->expected($row, $version);
            $this->run('UPDATE dwf_templates SET title = ?, description = ?, fields_json = ?, provenance_json = ?, version = version + 1, status = ?, updated_at = ? WHERE club_id = ? AND id = ?', [$title, $description, $this->json($fields), $this->json($provenance), 'draft', $now, $actor['club_id'], $id]);
            return $id;
        });
    }

    public function publishTemplate(string $id, int $version): void
    {
        $this->mutate(function (array $actor) use ($id, $version): void {
            $row = $this->normalize($this->templateRow($id, $actor, true));
            $this->expected($row, $version);
            if ($row['status'] !== 'draft') {
                throw new WorkflowException('Nur ein Vorlagenentwurf kann veröffentlicht werden.');
            }
            if ($row['fields'] === []) {
                throw new WorkflowException('Eine veröffentlichte Vorlage benötigt mindestens ein Formularfeld.');
            }
            $row['version']++;
            $row['published_version'] = $row['version'];
            $row['status'] = 'published';
            $row['updated_at'] = $this->now();
            $this->run('UPDATE dwf_templates SET version = ?, published_version = ?, status = ?, updated_at = ? WHERE club_id = ? AND id = ?', [$row['version'], $row['version'], 'published', $row['updated_at'], $actor['club_id'], $id]);
            $this->run('INSERT INTO dwf_template_versions (club_id, template_id, version, snapshot_json, published_by, published_at) VALUES (?, ?, ?, ?, ?, ?)', [$actor['club_id'], $id, $row['version'], $this->json($row), $actor['id'], $row['updated_at']]);
        });
    }

    public function createDocument(string $templateId, string $folderId, string $title, ?string $creationKey=null): string
    {
        if($creationKey!==null)return $this->createOriginalOnce('document',['template_id'=>$templateId,'folder_id'=>$folderId,'title'=>$title],$creationKey,fn():string=>$this->createDocument($templateId,$folderId,$title));
        $title = $this->text($title, 240, 'Dokumenttitel', true);
        return $this->mutate(function (array $actor) use ($templateId, $folderId, $title): string {
            $template = $this->templateRow($templateId, $actor, true);
            if ((int) $template['published_version'] < 1) {
                throw new WorkflowException('Die Vorlage wurde noch nicht veröffentlicht.');
            }
            $published = $this->one('SELECT snapshot_json FROM dwf_template_versions WHERE club_id = ? AND template_id = ? AND version = ?', [$actor['club_id'], $templateId, $template['published_version']]);
            if ($published === null) {
                throw new WorkflowException('Die veröffentlichte Vorlagenversion wurde nicht gefunden.');
            }
            $pinned = $this->decode($published['snapshot_json']);
            $this->folderExists($folderId, $actor);
            $id = $this->newId();
            $now = $this->now();
            $this->run('INSERT INTO dwf_documents (id, club_id, folder_id, template_id, template_version, template_title, template_description, title, fields_json, provenance_json, values_json, version, status, assignee_id, reviewer_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)', [$id, $actor['club_id'], $folderId, $templateId, $pinned['version'], $pinned['title'], $pinned['description'], $title, $this->json($pinned['fields']), $this->json($pinned['provenance']), '{}', 'draft', '', '', $actor['id'], $now, $now]);
            $this->record($this->normalize($this->documentRow($id, $actor)), $actor, 'create', '');
            return $id;
        });
    }

    public function documents(array $filters = []): array
    {
        $actor=$this->context();
        $this->keys($filters,['folder_id','status','assignee_id','template_id','search','limit'],'Filter');
        [$where,$parameters]=$this->documentCriteria($filters,$actor);
        $limit=$filters['limit']??500;
        if(!is_int($limit)||$limit<1||$limit>500)throw new WorkflowException('Die Listenbegrenzung muss zwischen 1 und 500 liegen.');
        return $this->completeOrderedOriginals('dwf_documents',$where,$parameters,'updated_at DESC,id DESC',$limit);
    }

    /** Current original deadlines, independent of the paginated document catalogue. */
    public function deadlines(string $from, string $to): array
    {
        $actor = $this->context();
        if (!WorkflowDeadlines::validDate($from) || !WorkflowDeadlines::validDate($to) || $from >= $to ||
            (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 366) {
            throw new WorkflowException('Ungültiger Zeitraum für Dokumentenfristen.');
        }
        $candidates = $this->all("SELECT id FROM dwf_documents WHERE club_id = ? AND (
            (JSON_UNQUOTE(JSON_EXTRACT(values_json, '$.review_date')) >= ? AND JSON_UNQUOTE(JSON_EXTRACT(values_json, '$.review_date')) < ?) OR
            (JSON_UNQUOTE(JSON_EXTRACT(values_json, '$.deadline')) >= ? AND JSON_UNQUOTE(JSON_EXTRACT(values_json, '$.deadline')) < ?) OR
            EXISTS (SELECT 1 FROM JSON_TABLE(values_json, '$.*[*]' COLUMNS (
                action_deadline VARCHAR(10) PATH '$.action_deadline' NULL ON EMPTY NULL ON ERROR
            )) AS measures WHERE measures.action_deadline >= ? AND measures.action_deadline < ?)) ORDER BY id",
            [$actor['club_id'], $from, $to, $from, $to, $from, $to]);
        $rows = [];
        foreach ($candidates as $candidate) {
            // Recheck the current source and access; pinned template fields determine semantics.
            $document = $this->document($candidate['id']);
            $dates = WorkflowDeadlines::dates($document, $from, $to);
            if ($dates === []) continue;
            $rows[] = ['id' => $document['id'], 'title' => $document['title'],
                'version' => $document['version'], 'status' => $document['status'], 'dates' => $dates];
        }
        return $rows;
    }

    public function document(string $id, ?int $revision = null): array
    {
        $actor = $this->context();
        $current = $this->documentRow($id, $actor);
        if ($revision === null) {
            return $this->normalize($current);
        }
        if ($revision < 1) {
            throw new WorkflowException('Die Dokumentversion ist ungültig.');
        }
        $row = $this->one('SELECT snapshot_json FROM dwf_document_versions WHERE club_id = ? AND document_id = ? AND revision = ?', [$actor['club_id'], $id, $revision]);
        if ($row === null) {
            throw new WorkflowException('Die Dokumentversion wurde nicht gefunden.');
        }
        return $this->decode($row['snapshot_json']);
    }

    /** Original catalogue fields only; risk registers and original PDF bytes stay at their source. */
    public function librarySummary(string $id): array
    {
        $actor=$this->context();$this->internalId($id);
        $row=$this->one('SELECT id,club_id,title,status,version,updated_at FROM dwf_documents WHERE club_id=? AND id=?',[$actor['club_id'],$id]);
        if($row===null)throw new WorkflowException('Das Dokument wurde nicht gefunden.');
        $row['version']=(int)$row['version'];$row['attachments']=$this->attachmentMetadata($row);
        return $row;
    }

    /** Current permission and status for a transient editor; this performs no mutation. */
    public function editingDocument(string $id):array
    {
        $actor=$this->context(true);$row=$this->normalize($this->documentRow($id,$actor));$this->editable($row,$actor);return $row;
    }

    public function saveDocument(string $id, int $version, array $values, string $title, string $folderId): void
    {
        $title = $this->text($title, 240, 'Dokumenttitel', true);
        $this->mutate(function (array $actor) use ($id, $version, $values, $title, $folderId): void {
            $row = $this->normalize($this->documentRow($id, $actor, true));
            $this->expected($row, $version);
            $this->editable($row, $actor);
            $this->folderExists($folderId, $actor);
            $row['values'] = $this->validateValues($row['fields'], $values, false);
            $row['title'] = $title;
            $row['folder_id'] = $folderId;
            $this->persistDocument($row, $actor, 'save');
        });
    }

    public function assignDocument(string $id, int $version, string $assigneeId, string $reviewerId): void
    {
        $this->externalId($assigneeId, true);
        $this->externalId($reviewerId, true);
        $this->mutate(function (array $actor) use ($id, $version, $assigneeId, $reviewerId): void {
            $row = $this->normalize($this->documentRow($id, $actor, true));
            $this->expected($row, $version);
            if ((!$this->sharedEditing && $row['created_by'] !== $actor['id']) || !in_array($row['status'], ['draft', 'returned'], true)) {
                throw new WorkflowException('Nur der Ersteller kann einen bearbeitbaren Entwurf zuweisen.');
            }
            $people = array_column($this->currentParticipants(), null, 'id');
            foreach ([$assigneeId, $reviewerId] as $personId) {
                if ($personId !== '' && (!isset($people[$personId]) || $people[$personId]['access'] !== 'write')) {
                    throw new WorkflowException('Zuweisungen benötigen aktuelle Schreibrechte im Verein.');
                }
            }
            if (!$this->sharedEditing && $reviewerId !== '' && (in_array($reviewerId, [$row['created_by'], $assigneeId], true) || $this->hasContributed($row, $reviewerId))) {
                throw new WorkflowException('Ersteller, aktuelle und frühere Inhaltsbearbeiter dürfen das Dokument nicht selbst freigeben.');
            }
            $row['assignee_id'] = $assigneeId;
            $row['reviewer_id'] = $reviewerId;
            $this->persistDocument($row, $actor, 'assign');
        });
    }

    public function transition(string $id, int $version, string $action, string $comment = ''): void
    {
        if (!in_array($action, ['submit', 'approve', 'return', 'reopen'], true)) {
            throw new WorkflowException('Der Statuswechsel ist ungültig.');
        }
        $comment = $this->text($comment, 4000, 'Kommentar', $action === 'return');
        $this->mutate(function (array $actor) use ($id, $version, $action, $comment): void {
            $row = $this->normalize($this->documentRow($id, $actor, true));
            $this->expected($row, $version);
            if ($action === 'submit') {
                $this->editable($row, $actor);
                if (!$this->sharedEditing) $this->independentReviewer($row);
                $row['values'] = $this->validateValues($row['fields'], $row['values'], true);
                $row['status'] = 'submitted';
            } elseif ($action === 'approve' || $action === 'return') {
                if ($row['status'] !== 'submitted' || (!$this->sharedEditing && $row['reviewer_id'] !== $actor['id'])) {
                    throw new WorkflowException('Nur die zugewiesene prüfende Person kann ein eingereichtes Dokument prüfen.');
                }
                if (!$this->sharedEditing) $this->independentReviewer($row);
                else $row['reviewer_id'] = $actor['id'];
                $row['status'] = $action === 'approve' ? 'approved' : 'returned';
            } else {
                if (!in_array($row['status'], $this->sharedEditing ? ['approved', 'submitted'] : ['approved'], true) || (!$this->sharedEditing && $row['created_by'] !== $actor['id'])) {
                    throw new WorkflowException('Nur der Ersteller kann ein freigegebenes Dokument erneut öffnen.');
                }
                $row['status'] = 'draft';
            }
            $this->persistDocument($row, $actor, $action, $comment);
        });
    }

    public function addAttachment(string $id, int $version, string $name, string $bytes): string
    {
        $this->context(true);
        $name = $this->text($name, 200, 'PDF-Dateiname', true);
        if (!preg_match('/\.pdf$/iD', $name) || strpbrk($name, "/\\\r\n") !== false || strlen($bytes) > 2097152 || !str_starts_with($bytes, '%PDF-')) {
            throw new WorkflowException('Eine PDF-Datei bis 2 MB auswählen.');
        }
        WorkflowPdf::validate($bytes);
        return $this->mutate(function (array $actor) use ($id, $version, $name, $bytes): string {
            $row = $this->normalize($this->documentRow($id, $actor, true));
            $this->expected($row, $version);
            $this->editable($row, $actor);
            $attachments = $row['attachments'];
            if (count($attachments) >= 5 || array_sum(array_column($attachments, 'size_bytes')) + strlen($bytes) > 3145728) {
                throw new WorkflowException('Höchstens fünf PDF-Anlagen mit zusammen 3 MB anhängen.');
            }
            $hash = hash('sha256', $bytes);
            foreach ($attachments as $attachment) if ($attachment['sha256'] === $hash) throw new WorkflowException('Diese PDF ist bereits angehängt.');
            $attachmentId = $this->newId();
            $this->run('INSERT INTO dwf_attachments (id, club_id, document_id, name, sha256, size_bytes, file_bytes, added_revision, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$attachmentId, $actor['club_id'], $id, $name, $hash, strlen($bytes), $bytes, $version + 1, $actor['id'], $this->now()]);
            $this->persistDocument($row, $actor, 'attach', $name);
            return $attachmentId;
        });
    }

    public function removeAttachment(string $id, int $version, string $attachmentId): void
    {
        $this->internalId($attachmentId);
        $this->mutate(function (array $actor) use ($id, $version, $attachmentId): void {
            $row = $this->normalize($this->documentRow($id, $actor, true));
            $this->expected($row, $version);
            $this->editable($row, $actor);
            $attachment = $this->one('SELECT name FROM dwf_attachments WHERE club_id=? AND document_id=? AND id=? AND removed_revision IS NULL FOR UPDATE', [$actor['club_id'], $id, $attachmentId]);
            if ($attachment === null) throw new WorkflowException('Die aktuelle PDF-Anlage wurde nicht gefunden.');
            $this->run('UPDATE dwf_attachments SET removed_revision=? WHERE club_id=? AND document_id=? AND id=?', [$version + 1, $actor['club_id'], $id, $attachmentId]);
            $this->persistDocument($row, $actor, 'detach', $attachment['name']);
        });
    }

    public function attachment(string $id, string $attachmentId, ?int $revision = null): array
    {
        $row = $this->document($id, $revision); // Resolves current tenant and permission before any blob is read.
        $this->internalId($attachmentId);
        $metadata = array_column($row['attachments'] ?? [], null, 'id');
        if (!isset($metadata[$attachmentId])) throw new WorkflowException('Die PDF-Anlage gehört nicht zu dieser Dokumentfassung.');
        $file = $this->one('SELECT file_bytes FROM dwf_attachments WHERE club_id=? AND document_id=? AND id=?', [$row['club_id'], $id, $attachmentId]);
        if ($file === null || !hash_equals($metadata[$attachmentId]['sha256'], hash('sha256', $file['file_bytes']))) throw new WorkflowException('Die PDF-Anlage ist nicht unverändert verfügbar.');
        return ['name' => $metadata[$attachmentId]['name'], 'mime' => 'application/pdf', 'bytes' => $file['file_bytes']];
    }

    private function attachmentMetadata(array $row): array
    {
        $rows = $this->all('SELECT id, name, sha256, size_bytes, created_by, created_at FROM dwf_attachments WHERE club_id=? AND document_id=? AND added_revision<=? AND (removed_revision IS NULL OR removed_revision>?) ORDER BY added_revision, id', [$row['club_id'], $row['id'], $row['version'], $row['version']]);
        foreach ($rows as &$attachment) $attachment['size_bytes'] = (int)$attachment['size_bytes'];
        unset($attachment);
        return $rows;
    }

    public function activities(string $id): array
    {
        $actor = $this->context();
        $this->documentRow($id, $actor);
        $rows = $this->all('SELECT id, actor_id, action, comment, revision, created_at FROM dwf_activities WHERE club_id = ? AND document_id = ? ORDER BY revision DESC', [$actor['club_id'], $id]);
        return array_map(function (array $row): array {
            $row['revision'] = (int) $row['revision'];
            $row['event'] = $row['action'];
            return $row;
        }, $rows);
    }

    public function versions(string $id): array
    {
        $actor = $this->context();
        $this->documentRow($id, $actor);
        $rows = $this->all('SELECT revision, snapshot_json, actor_id, action, created_at FROM dwf_document_versions WHERE club_id = ? AND document_id = ? ORDER BY revision DESC', [$actor['club_id'], $id]);
        return array_map(function (array $row) use ($id): array {
            $snapshot = $this->decode($row['snapshot_json']);
            return ['id' => $id, 'version' => (int) $row['revision'], 'revision' => (int) $row['revision'], 'title' => $snapshot['title'], 'status' => $snapshot['status'], 'actor_id' => $row['actor_id'], 'action' => $row['action'], 'created_at' => $row['created_at']];
        }, $rows);
    }

    public function export(string $id, ?int $revision = null, string $format = 'html'): array
    {
        // document() resolves access anew and scopes both the current record and any historic revision.
        $row = $this->document($id, $revision);
        if (!in_array($format, ['html', 'json', 'pdf'], true)) {
            throw new WorkflowException('Unterstützte Exportformate sind PDF, HTML und JSON.');
        }
        $activities = array_values(array_filter($this->activities($id), static fn(array $activity): bool => $activity['revision'] <= $row['revision']));
        $riskMatrixCalculations = [];
        $riskRegisterCalculations = [];
        $riskProfiles = [];
        $hasRiskMatrix = false;
        $hasRiskRegister = false;
        foreach ($row['fields'] as $field) {
            $value = $row['values'][$field['key']] ?? null;
            if ($field['type'] === 'risk_matrix') {
                $hasRiskMatrix = true;
                if($format!=='json')$riskProfiles[RiskProfiles::RWE_DE]=RiskProfiles::get(RiskProfiles::RWE_DE);
                if ($value !== null && $value !== '') {
                    $riskMatrixCalculations[$field['key']] = RiskMatrix::calculate($value);
                }
            }
            if ($field['type'] === 'risk_register') {
                $hasRiskRegister = true;
                $profile = $field['matrix_profile'] ?? '';
                $riskRegisterCalculations[$field['key']] = RiskRegister::calculate($value ?? [], $profile);
                if ($profile !== '' || $format!=='json') {
                    $displayProfile=$profile!==''?$profile:RiskProfiles::RWE_DE;$riskProfiles[$displayProfile]=RiskProfiles::get($displayProfile);
                }
            }
        }
        $stem = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $row['title']), '-');
        $name = substr($stem !== '' ? $stem : 'Dokument', 0, 80) . '-' . substr($id, 0, 8) . '-v' . $row['version'] . '.' . $format;
        if ($format === 'pdf') {
            $signatures=[];
            if($this->access instanceof WorkflowSignatureAccess){
                require_once __DIR__.'/WorkflowSignatures.php';
                $signatures=(new WorkflowSignatures($this->pdo,$this->access))->evidence($id,(int)$row['revision']);
            }
            $people = array_column($this->currentParticipants(), 'name', 'id');
            $files = [];
            foreach ($row['attachments'] ?? [] as $attachment) {
                $file = $this->attachment($id, $attachment['id'], $row['version']);
                $files[] = $attachment + ['base64' => base64_encode($file['bytes'])];
            }
            return ['name' => $name, 'mime' => 'application/pdf', 'bytes' => WorkflowPdf::render([
                'document' => $row, 'activities' => $activities, 'people' => $people,
                'profiles' => $riskProfiles, 'attachments' => $files, 'signatures'=>$signatures,
            ])];
        }
        if ($format === 'json') {
            $payload = ['document' => $row, 'activities' => $activities, 'risk_matrix_calculations' => (object) $riskMatrixCalculations, 'risk_register_calculations' => (object) $riskRegisterCalculations, 'approval_note' => self::APPROVAL_NOTE];
            if ($hasRiskMatrix || $hasRiskRegister) {
                $payload['risk_matrix_note'] = RiskMatrix::NOTE;
            }
            if ($riskProfiles !== []) {
                $payload['risk_profiles'] = (object) $riskProfiles;
            }
            return ['name' => $name, 'mime' => 'application/json; charset=utf-8', 'bytes' => $this->json($payload, true)];
        }
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = ['draft' => 'Entwurf', 'submitted' => 'In Prüfung', 'returned' => 'Zur Überarbeitung', 'approved' => 'Freigegeben'][$row['status']] ?? $row['status'];
        $pageCss = $hasRiskRegister ? RiskRegister::CSS . RiskProfiles::CSS . RiskProfiles::MINI_CSS . RiskRegister::LANDSCAPE_CSS : '';
        $bodyClass = $hasRiskRegister ? ' class="dwf-register-document"' : '';
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($row['title']) . '</title><style>body{font:15px/1.55 system-ui,sans-serif;max-width:850px;margin:40px auto;padding:0 24px;color:#202820}h1{font-size:28px}h2{font-size:18px;margin:0 0 8px}.meta{color:#465346}.notice{padding:16px;border:2px solid #ad7317;background:#fff9ea}section{break-inside:avoid;border-bottom:1px solid #ccd4cc;padding:18px 0}.value{white-space:pre-wrap;overflow-wrap:anywhere}.help{font-size:13px;color:#465346}a{overflow-wrap:anywhere}' . RiskMatrix::CSS . RiskMatrix::referenceCss() . $pageCss . '@media print{body{margin:0;max-width:none}.notice{background:white}a{color:inherit}}</style></head><body' . $bodyClass . '>';
        $roleMetadata = '<p class="meta">Ersteller (Kennung): ' . $e($row['created_by']) . '<br>Bearbeiter (Kennung): ' . $e($row['assignee_id'] !== '' ? $row['assignee_id'] : $row['created_by']) . '<br>Prüfer (Kennung): ' . $e($row['reviewer_id'] !== '' ? $row['reviewer_id'] : 'Nicht zugewiesen') . '</p>';
        $description = $row['template_description'] !== '' ? '<p class="value">' . $e($row['template_description']) . '</p>' : '';
        $fieldsToRender = $row['fields'];
        if ($hasRiskRegister) {
            $html .= '<header class="dwf-register-header"><h1>' . $e($row['title']) . '</h1><p class="meta">Version ' . $row['version'] . ' · ' . $e($status) . ' · Stand: ' . $e($row['updated_at']) . ' UTC<br>Vorlage: ' . $e($row['template_title']) . ' · Vorlagenversion ' . $row['template_version'] . '</p></header>';
            $ordinaryFields = array_values(array_filter($row['fields'], static fn(array $field): bool => !in_array($field['type'], ['risk_matrix', 'risk_register'], true)));
            $fieldsToRender = array_values(array_filter($row['fields'], static fn(array $field): bool => in_array($field['type'], ['risk_matrix', 'risk_register'], true)));
            $profiles = array_keys($riskProfiles);
            $html .= '<div class="dwf-register-intro' . ($profiles === [] ? ' dwf-register-no-profile' : '') . '"><div>';
            if ($ordinaryFields !== []) {
                $html .= '<table class="dwf-register-meta"><tbody>';
                foreach (array_chunk($ordinaryFields, 2) as $pair) {
                    $html .= '<tr>';
                    foreach ($pair as $field) {
                        $value = $row['values'][$field['key']] ?? null;
                        if (is_bool($value)) {
                            $value = $value ? 'Ja' : 'Nein';
                        }
                        $html .= '<th scope="row">' . $e($field['label']);
                        $html .= '</th><td>' . $e($value === null || $value === '' ? '—' : $value) . '</td>';
                    }
                    if (count($pair) === 1) {
                        $html .= '<td colspan="2"></td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '</div>';
            foreach ($profiles as $profile) {
                $html .= RiskProfiles::renderCompactLegend($profile);
            }
            $html .= '</div>';
        } else {
            $html .= '<h1>' . $e($row['title']) . '</h1><p class="meta">Version ' . $row['version'] . ' · ' . $e($status) . '<br>Vorlage: ' . $e($row['template_title']) . ' · Vorlagenversion ' . $row['template_version'] . '<br>Stand: ' . $e($row['updated_at']) . ' UTC</p>' . $roleMetadata;
        }
        foreach ($fieldsToRender as $field) {
            $value = $row['values'][$field['key']] ?? null;
            if (is_bool($value)) {
                $value = $value ? 'Ja' : 'Nein';
            }
            $html .= '<section' . ($field['type'] === 'risk_register' ? ' class="dwf-register-section"' : '') . '><h2>' . $e($field['label']) . '</h2>';
            $html .= match ($field['type']) {
                'risk_matrix' => RiskMatrix::renderHtml($value === null || $value === '' ? null : $value),
                'risk_register' => RiskRegister::renderHtml($value ?? [], $field['matrix_profile'] ?? ''),
                default => '<div class="value">' . $e($value === null || $value === '' ? '—' : $value) . '</div>',
            };
            $html .= '</section>';
        }
        if ($hasRiskRegister) {
            $html .= '<section class="dwf-register-evidence"><h2>Dokumentnachweis</h2>' . $roleMetadata;
            foreach ($profiles as $profile) {
                if ($profile === RiskProfiles::REFERENCE) {
                    continue;
                }
                $configuration = RiskProfiles::get($profile);
                $html .= '<p class="value">Bewertungsprofil: ' . $e($configuration['id']) . '<br>' . $e($configuration['source']) . '</p>';
            }
            $html .= '</section>';
        }
        if ($row['provenance'] !== [] && !array_key_exists(RiskProfiles::REFERENCE, $riskProfiles)) {
            $html .= '<section><h2>Herkunft und Nutzung</h2><dl>';
            foreach ($row['provenance'] as $key => $value) {
                $html .= '<dt>' . $e($key) . '</dt><dd class="value">' . $e($value) . '</dd>';
            }
            $html .= '</dl></section>';
        }
        $html .= '<section><h2>Aktivitäten bis Version ' . $row['revision'] . '</h2><ol>';
        $actions = ['create' => 'Erstellt', 'save' => 'Gespeichert', 'assign' => 'Zuweisung geändert', 'submit' => 'Zur Prüfung eingereicht', 'approve' => 'Administrativ freigegeben', 'return' => 'Zurückgegeben', 'reopen' => 'Erneut geöffnet'];
        foreach (array_reverse($activities) as $activity) {
            $html .= '<li>Version ' . $activity['revision'] . ': ' . $e($actions[$activity['action']] ?? $activity['action']) . ' · ' . $e($activity['actor_id']) . ' · ' . $e($activity['created_at']) . ' UTC';
            if ($activity['comment'] !== '') {
                $html .= '<p class="value">' . $e($activity['comment']) . '</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></section>';
        if (!empty($row['attachments'])) {
            $html .= '<section><h2>Anlagen</h2><ol>';
            foreach ($row['attachments'] as $attachment) $html .= '<li>' . $e($attachment['name']) . '</li>';
            $html .= '</ol></section>';
        }
        $html .= '<p class="meta">Dokument-ID: ' . $e($id) . '<br>Erstellt: ' . $e($row['created_at']) . ' UTC</p></body></html>';
        return ['name' => $name, 'mime' => 'text/html; charset=utf-8', 'bytes' => $html];
    }

    private function context(bool $write = false): array
    {
        $actor = $this->actor();
        if ($actor['access'] === 'none' || ($write && $actor['access'] !== 'write')) {
            throw new WorkflowException($write ? 'Für diese Aktion fehlen Schreibrechte im Dokumentenmodul.' : 'Für das Dokumentenmodul fehlt die Leseberechtigung.');
        }
        return $actor;
    }

    private function currentParticipants(): array
    {
        $rows = $this->access->participants();
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['access'] ?? null, ['none', 'read', 'write'], true)) {
                throw new WorkflowException('Die Liste der Vereinsakteure ist ungültig.');
            }
            if ($row['access'] === 'none') {
                continue;
            }
            $id = $this->externalId($row['id'] ?? null, false);
            if (isset($result[$id])) {
                throw new WorkflowException('Die Liste der Vereinsakteure enthält doppelte Kennungen.');
            }
            $result[$id] = ['id' => $id, 'name' => $this->text($row['name'] ?? null, 200, 'Anzeigename', true), 'access' => $row['access']];
        }
        return array_values($result);
    }

    private function mutate(callable $callback): mixed
    {
        $actor = $this->context(true);
        if ($this->pdo->inTransaction()) {
            if($this->ownCreationTransaction)return $callback($actor);
            throw new WorkflowException('Workflow-Änderungen benötigen eine eigene Transaktion.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback($actor);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function templateRow(string $id, array $actor, bool $lock = false): array
    {
        $this->internalId($id);
        $row = $this->one('SELECT * FROM dwf_templates WHERE club_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$actor['club_id'], $id]);
        if ($row === null) {
            throw new WorkflowException('Die Vorlage wurde nicht gefunden.');
        }
        return $row;
    }

    private function documentRow(string $id, array $actor, bool $lock = false): array
    {
        $this->internalId($id);
        $row = $this->one('SELECT * FROM dwf_documents WHERE club_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$actor['club_id'], $id]);
        if ($row === null) {
            throw new WorkflowException('Das Dokument wurde nicht gefunden.');
        }
        return $row;
    }

    private function folderExists(string $id, array $actor): void
    {
        $this->internalId($id, true);
        if ($id !== '' && $this->one('SELECT id FROM dwf_folders WHERE club_id = ? AND id = ?', [$actor['club_id'], $id]) === null) {
            throw new WorkflowException('Der Ordner wurde nicht gefunden.');
        }
    }

    private function expected(array $row, int $version): void
    {
        if ($version < 1 || (int) $row['version'] !== $version) {
            throw WorkflowException::conflict('Der Datensatz wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.');
        }
    }

    private function editable(array $row, array $actor): void
    {
        if (!in_array($row['status'], ['draft', 'returned'], true)) {
            throw new WorkflowException('Eingereichte und freigegebene Dokumente sind für Bearbeitungen gesperrt.');
        }
        $editor = $row['assignee_id'] !== '' ? $row['assignee_id'] : $row['created_by'];
        if (!$this->sharedEditing && $editor !== $actor['id']) {
            throw new WorkflowException('Nur die zugewiesene bearbeitende Person darf den Entwurf ändern oder einreichen.');
        }
    }

    private function independentReviewer(array $row): void
    {
        $people = array_column($this->currentParticipants(), null, 'id');
        $reviewer = $row['reviewer_id'];
        if ($reviewer === '' || !isset($people[$reviewer]) || $people[$reviewer]['access'] !== 'write' || in_array($reviewer, [$row['created_by'], $row['assignee_id']], true) || $this->hasContributed($row, $reviewer)) {
            throw new WorkflowException('Eine unabhängige prüfende Person mit aktuellen Schreibrechten muss zugewiesen sein. Frühere Inhaltsbearbeiter sind ebenfalls ausgeschlossen.');
        }
    }

    private function hasContributed(array $row, string $actorId): bool
    {
        return $this->one("SELECT revision FROM dwf_document_versions WHERE club_id = ? AND document_id = ? AND actor_id = ? AND action IN ('create', 'save') LIMIT 1", [$row['club_id'], $row['id'], $actorId]) !== null;
    }

    private function persistDocument(array $row, array $actor, string $action, string $comment = ''): void
    {
        $row['version']++;
        $row['revision'] = $row['version'];
        $row['updated_at'] = $this->now();
        $row['attachments'] = $this->attachmentMetadata($row);
        $this->run('UPDATE dwf_documents SET folder_id = ?, title = ?, values_json = ?, version = ?, status = ?, assignee_id = ?, reviewer_id = ?, updated_at = ? WHERE club_id = ? AND id = ?', [$row['folder_id'], $row['title'], $this->json($row['values']), $row['version'], $row['status'], $row['assignee_id'], $row['reviewer_id'], $row['updated_at'], $actor['club_id'], $row['id']]);
        $this->record($row, $actor, $action, $comment);
    }

    private function record(array $row, array $actor, string $action, string $comment): void
    {
        $this->run('INSERT INTO dwf_document_versions (club_id, document_id, revision, snapshot_json, actor_id, action, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$actor['club_id'], $row['id'], $row['version'], $this->json($row), $actor['id'], $action, $row['updated_at']]);
        $this->run('INSERT INTO dwf_activities (id, club_id, document_id, revision, actor_id, action, comment, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$this->newId(), $actor['club_id'], $row['id'], $row['version'], $actor['id'], $action, $comment, $row['updated_at']]);
    }

    private function validateFields(mixed $fields): array
    {
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > 100) {
            throw new WorkflowException('Die Vorlage darf höchstens 100 geordnete Formularfelder enthalten.');
        }
        $seen = [];
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new WorkflowException('Das Formularfeld ist ungültig.');
            }
            $this->keys($field, ['key', 'label', 'type', 'required', 'help', 'options', 'matrix_profile'], 'Formularfeld');
            $key = $field['key'] ?? null;
            if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1 || isset($seen[$key])) {
                throw new WorkflowException('Feldkennungen müssen eindeutig sein und mit einem Kleinbuchstaben beginnen. Erlaubt sind Kleinbuchstaben, Zahlen und Unterstriche bis 64 Zeichen.');
            }
            $seen[$key] = true;
            $type = $field['type'] ?? null;
            if (!in_array($type, ['text', 'textarea', 'date', 'number', 'select', 'checkbox', 'risk_matrix', 'risk_register'], true) || !is_bool($field['required'] ?? null)) {
                throw new WorkflowException('Feldtyp oder Pflichtfeldangabe ist ungültig.');
            }
            $item = ['key' => $key, 'label' => $this->text($field['label'] ?? null, 200, 'Feldbezeichnung', true), 'type' => $type, 'required' => $field['required']];
            if (array_key_exists('matrix_profile', $field)) {
                if ($type !== 'risk_register' || !is_string($field['matrix_profile']) || !in_array($field['matrix_profile'], [RiskProfiles::REFERENCE, RiskProfiles::RWE_DE], true)) {
                    throw new WorkflowException('Nur Gefährdungsregister dürfen ein bekanntes, nicht leeres Bewertungsprofil verwenden.');
                }
                $item['matrix_profile'] = $field['matrix_profile'];
            }
            if (array_key_exists('help', $field)) {
                $item['help'] = $this->text($field['help'], 2000, 'Feldhilfe');
            }
            if ($type === 'select') {
                if (!isset($field['options']) || !is_array($field['options']) || !array_is_list($field['options']) || count($field['options']) < 1 || count($field['options']) > 100) {
                    throw new WorkflowException('Auswahlfelder benötigen 1 bis 100 eindeutige Auswahlmöglichkeiten.');
                }
                $options = array_map(fn(mixed $option): string => $this->text($option, 200, 'Auswahlmöglichkeit', true), $field['options']);
                if (count(array_unique($options, SORT_STRING)) !== count($options)) {
                    throw new WorkflowException('Auswahlmöglichkeiten dürfen nicht doppelt vorkommen.');
                }
                $item['options'] = $options;
            } elseif (array_key_exists('options', $field)) {
                throw new WorkflowException('Nur Auswahlfelder dürfen Auswahlmöglichkeiten enthalten.');
            }
            $result[] = $item;
        }
        return $result;
    }

    private function validateProvenance(mixed $provenance): array
    {
        if (!is_array($provenance)) {
            throw new WorkflowException('Die Herkunftsangaben sind ungültig.');
        }
        $this->keys($provenance, ['title', 'url', 'licence', 'licence_url', 'attribution', 'adaptation', 'verified_at'], 'Herkunftsangabe');
        $result = [];
        foreach ($provenance as $key => $value) {
            $value = $this->text($value, in_array($key, ['attribution', 'adaptation'], true) ? 6000 : 2000, 'Herkunftsangabe');
            if (($key === 'url' || $key === 'licence_url') && $value !== '') {
                $parts = parse_url($value);
                if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $value)) {
                    throw new WorkflowException('Quellenlinks müssen absolute HTTP- oder HTTPS-Adressen ohne Zugangsdaten sein.');
                }
            }
            if ($key === 'verified_at' && $value !== '' && !$this->isDate($value)) {
                throw new WorkflowException('Das Quellenprüfdatum benötigt das Format JJJJ-MM-TT.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function validateValues(array $fields, array $values, bool $required): array
    {
        $schema = array_column($fields, null, 'key');
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($schema[$key])) {
                throw new WorkflowException('Die Werte enthalten ein unbekanntes Formularfeld.');
            }
            $field = $schema[$key];
            if ($field['type'] === 'risk_register') {
                $result[$key] = RiskRegister::validate($value, $required, $field['required']);
                continue;
            }
            if ($value === null || $value === '') {
                $result[$key] = $value;
                continue;
            }
            switch ($field['type']) {
                case 'text':
                case 'textarea':
                    $result[$key] = $this->text($value, $field['type'] === 'text' ? 2000 : 20000, $field['label']);
                    break;
                case 'date':
                    if (!is_string($value) || !$this->isDate($value)) {
                        throw new WorkflowException('Das Feld „' . $field['label'] . '“ benötigt ein gültiges Datum im Format JJJJ-MM-TT.');
                    }
                    $result[$key] = $value;
                    break;
                case 'number':
                    if ((!is_int($value) && !is_float($value) && !is_string($value)) || (is_string($value) && (strlen($value) > 100 || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $value) !== 1)) || !is_numeric($value) || !is_finite((float) $value)) {
                        throw new WorkflowException('Das Feld „' . $field['label'] . '“ benötigt eine endliche Zahl mit Dezimalpunkt.');
                    }
                    $result[$key] = $value;
                    break;
                case 'select':
                    if (!is_string($value) || !in_array($value, $field['options'], true)) {
                        throw new WorkflowException('Das Feld „' . $field['label'] . '“ enthält keine gültige Auswahl.');
                    }
                    $result[$key] = $value;
                    break;
                case 'checkbox':
                    if (!is_bool($value)) {
                        throw new WorkflowException('Das Feld „' . $field['label'] . '“ benötigt Ja oder Nein.');
                    }
                    $result[$key] = $value;
                    break;
                case 'risk_matrix':
                    $result[$key] = RiskMatrix::validate($value);
                    break;
            }
        }
        if ($required) {
            foreach ($fields as $field) {
                $value = $result[$field['key']] ?? null;
                if ($field['required'] && ($value === null || $value === '' || (is_string($value) && trim($value) === '') || ($field['type'] === 'checkbox' && $value !== true))) {
                    throw new WorkflowException('Das Pflichtfeld „' . $field['label'] . '“ muss vor dem Einreichen ausgefüllt beziehungsweise bestätigt werden.');
                }
            }
        }
        return $result;
    }

    private function text(mixed $value, int $max, string $label, bool $required = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || preg_match('/\A.{0,' . $max . '}\z/usD', $value) !== 1 || preg_match('/<\s*(?:\/?[a-zA-Z][^>]*>|!|\?)/u', $value)) {
            throw new WorkflowException($label . ' muss reiner Text mit höchstens ' . $max . ' Zeichen sein.');
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new WorkflowException($label . ' darf nicht leer sein.');
        }
        return $value;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $matches) !== 1) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function keys(array $input, array $allowed, string $label): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new WorkflowException($label . ' enthält unbekannte Eigenschaften.');
            }
        }
    }

    private function internalId(string $id, bool $empty = false): void
    {
        if (($empty && $id === '') || preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1) {
            return;
        }
        throw new WorkflowException('Die Datensatzkennung ist ungültig.');
    }

    private function externalId(mixed $id, bool $empty): string
    {
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (!is_string($id) || strlen($id) > 128 || preg_match('//u', $id) !== 1 || preg_match('/[\s\x00-\x1f\x7f<>]/u', $id) || (!$empty && $id === '')) {
            throw new WorkflowException('Die Vereins- oder Benutzerkennung ist ungültig.');
        }
        return $id;
    }

    private function normalize(array $row): array
    {
        foreach (['version', 'published_version', 'template_version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['fields', 'provenance', 'values'] as $key) {
            if (array_key_exists($key . '_json', $row)) {
                $row[$key] = $this->decode($row[$key . '_json']);
                unset($row[$key . '_json']);
            }
        }
        if (array_key_exists('template_id', $row)) {
            $row['revision'] = $row['version'];
            $row['attachments'] = $this->attachmentMetadata($row);
        }
        return $row;
    }

    private function json(array $value, bool $pretty = false): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    private function decode(string $value): array
    {
        $result = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new WorkflowException('Die gespeicherten Workflow-Daten sind ungültig.');
        }
        return $result;
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function all(string $sql, array $params): array
    {
        return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $params): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
