<?php
declare(strict_types=1);

/** Real MySQL regression suite. Creates a new registered isolated fixture per run; never deletes a database. */
require_once dirname(__DIR__) . '/vereinsverwaltung/tests/support/document-workflow-fixture.php';
require_once dirname(__DIR__) . '/vereinsverwaltung/src/DocumentWorkflow/WorkflowStore.php';

use Kulturhus\Verein\DocumentWorkflow\WorkflowAccess;
use Kulturhus\Verein\DocumentWorkflow\WorkflowException;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSchema;
use Kulturhus\Verein\DocumentWorkflow\WorkflowStore;
use Kulturhus\Verein\DocumentWorkflow\RiskMatrix;
use Kulturhus\Verein\DocumentWorkflow\RiskRegister;
use Kulturhus\Verein\DocumentWorkflow\RiskProfiles;

final class WorkflowCoreTestAccess implements WorkflowAccess
{
    public string $id = 'ada';
    public string $club = 'synthetic-club-a';
    public string $level = 'write';
    public array $levels = ['ada' => 'write', 'bob' => 'write', 'cara' => 'write', 'reader' => 'read', 'revoked' => 'none'];

    public function actor(): array
    {
        return ['id' => $this->id, 'club_id' => $this->club, 'access' => $this->level];
    }

    public function participants(): array
    {
        $result = [];
        foreach ($this->levels as $id => $access) {
            $result[] = ['id' => $id, 'name' => 'Demo ' . $id, 'access' => $access];
        }
        return $result;
    }
}

// Two real PHP connections compete for one optimistic version while the main process holds its row lock.
if (($argv[1] ?? '') === '--race') {
    $database = $argv[2] ?? '';
    if (preg_match('/\Akh_wf_[a-f0-9]{24}\z/D', $database) !== 1) {
        exit(4);
    }
    $worker = new WorkflowStore(DocumentWorkflowFixture::connection($database), new WorkflowCoreTestAccess(), false);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    try {
        $worker->saveDocument($argv[3], (int) $argv[4], ['activity' => 'Concurrent synthetic edit'], 'Concurrent synthetic edit', '');
        fwrite(STDOUT, "SAVED\n");
        exit(0);
    } catch (WorkflowException) {
        fwrite(STDOUT, "CONFLICT\n");
        exit(3);
    }
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks++;
};
$reject = static function (callable $action, string $label) use ($assert): void {
    try {
        $action();
    } catch (WorkflowException) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};

$database = DocumentWorkflowFixture::create();
$pdo = DocumentWorkflowFixture::connection($database);
$access = new WorkflowCoreTestAccess();
$store = new WorkflowStore($pdo, $access, false); // Retains explicit strict four-eyes coverage alongside shared module tests.
$tableCount = static fn(): int => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'dwf\\_%'")->fetchColumn();
$assert($tableCount() === 0, 'constructor never installs schema');
$pdo->beginTransaction();
$reject(static fn() => WorkflowSchema::install($pdo), 'schema installation forbidden inside transaction');
$assert($pdo->inTransaction(), 'rejected DDL preserves caller transaction');
$pdo->rollBack();
WorkflowSchema::install($pdo);
WorkflowSchema::install($pdo);
$assert($tableCount() === 10, 'explicit repeatable MySQL schema installation including private PDF attachments, signature evidence, personal PDFs and reminder queue');
$assert(count($store->participants()) === 4, 'revoked participants are excluded');

$folder = $store->saveFolder('', 0, 'Synthetische Prüfungen');
$child = $store->saveFolder('', 0, 'Unterordner', $folder);
$reject(static fn() => $store->saveFolder($folder, 1, 'Zyklus', $child), 'folder cycle rejected');
$reject(static fn() => $store->saveFolder($folder, 1, 'Selbstbezug', $folder), 'folder self-parent rejected');
$store->saveFolder($folder, 1, 'Demo-Prüfungen');
$reject(static fn() => $store->saveFolder($folder, 1, 'Veraltet'), 'stale folder version rejected');
$assert($store->folders()[0]['version'] === 2, 'folder optimistic version advanced');

$fields = [
    ['key' => 'activity', 'label' => 'Tätigkeit', 'type' => 'text', 'required' => true],
    ['key' => 'notes', 'label' => 'Notizen', 'type' => 'textarea', 'required' => false, 'help' => 'Synthetische Angaben'],
    ['key' => 'date', 'label' => 'Datum', 'type' => 'date', 'required' => true],
    ['key' => 'count', 'label' => 'Anzahl', 'type' => 'number', 'required' => true],
    ['key' => 'choice', 'label' => 'Auswahl', 'type' => 'select', 'required' => true, 'options' => ['A', 'B']],
    ['key' => 'confirmed', 'label' => 'Bestätigung', 'type' => 'checkbox', 'required' => true],
];
$provenance = ['title' => 'Synthetic source', 'url' => 'https://example.test/reference', 'licence' => 'Own synthetic text', 'licence_url' => '', 'attribution' => 'Synthetic test only', 'adaptation' => 'Original demo', 'verified_at' => '2026-10-07'];
$data = ['title' => 'Synthetic template', 'description' => 'Isolated test only', 'fields' => $fields, 'provenance' => $provenance];
$template = $store->saveTemplate('', 0, $data);
$reject(static fn() => $store->createDocument($template, '', 'Not yet published'), 'unpublished template cannot create document');
$reject(static fn() => $store->publishTemplate($template, 99), 'stale publish rejected');
$store->publishTemplate($template, 1);
$assert($store->template($template)['published_version'] === 2, 'published template receives immutable version');
$reject(static fn() => $store->publishTemplate($template, 2), 'published template cannot be republished without edit');
$empty = $store->saveTemplate('', 0, ['title' => 'Empty', 'fields' => [], 'provenance' => []]);
$reject(static fn() => $store->publishTemplate($empty, 1), 'empty template remains draft');

$invalidSchemas = [
    [['key' => 'bad key', 'label' => 'Bad', 'type' => 'text', 'required' => false]],
    [$fields[0], $fields[0]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'script', 'required' => false]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'text', 'required' => 'true']],
    [['key' => 'bad', 'label' => '<img src=x onerror=alert(1)>', 'type' => 'text', 'required' => false]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'select', 'required' => false, 'options' => []]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'select', 'required' => false, 'options' => ['A', 'A']]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'text', 'required' => false, 'options' => ['A']]],
    [['key' => 'bad', 'label' => 'Bad', 'type' => 'text', 'required' => false, 'onchange' => 'evil']],
    array_fill(0, 101, $fields[0]),
];
foreach ($invalidSchemas as $index => $schema) {
    $reject(static fn() => $store->saveTemplate('', 0, array_replace($data, ['fields' => $schema])), 'invalid schema ' . $index);
}
foreach (['javascript:alert(1)', 'data:text/html,x', 'https://user:password@example.test/', '//example.test/path', "https://example.test/\npath"] as $url) {
    $reject(static fn() => $store->saveTemplate('', 0, array_replace($data, ['provenance' => ['url' => $url]])), 'unsafe provenance URL');
}
$reject(static fn() => $store->saveTemplate('', 0, array_replace($data, ['provenance' => ['verified_at' => '2026-02-30']])), 'invalid provenance date');
$reject(static fn() => $store->saveTemplate('', 0, array_replace($data, ['provenance' => ['script' => 'evil']])), 'unknown provenance keys');
$reject(static fn() => $store->saveTemplate('', 0, $data + ['status' => 'published']), 'client cannot assign template status');

$document = $store->createDocument($template, $folder, 'Synthetic document < 3 & > 1');
$first = $store->document($document);
$assert($first['version'] === 1 && $first['revision'] === 1 && $first['status'] === 'draft', 'new document draft contract');
$assert($first['fields'] == $fields && $first['provenance'] == $provenance && $first['template_version'] === 2, 'schema and provenance pinned');
$assert(count($store->versions($document)) === 1 && count($store->activities($document)) === 1, 'create records history and activity');
$store->saveDocument($document, 1, [], 'Incomplete draft', $folder);
$assert($store->document($document)['values'] === [], 'draft accepts missing required values');
$reject(static fn() => $store->transition($document, 2, 'submit'), 'submit needs assigned reviewer');
$reject(static fn() => $store->assignDocument($document, 2, '', 'ada'), 'creator cannot review own document');
$reject(static fn() => $store->assignDocument($document, 2, 'bob', 'bob'), 'assignee cannot review own document');
$reject(static fn() => $store->assignDocument($document, 2, 'reader', 'cara'), 'read-only assignee rejected');
$reject(static fn() => $store->assignDocument($document, 2, 'missing', 'cara'), 'unknown assignee rejected');
$reject(static fn() => $store->assignDocument($document, 2, '', 'revoked'), 'revoked reviewer rejected');
$store->assignDocument($document, 2, '', 'cara');
$reject(static fn() => $store->transition($document, 3, 'submit'), 'incomplete draft cannot submit');
$assert($store->document($document)['version'] === 3, 'failed submission does not consume revision');

$validValues = ['activity' => 'Demo < 3 & > 1', 'notes' => "Line one\nLine two & details", 'date' => '2026-10-07', 'count' => 0, 'choice' => 'A', 'confirmed' => true];
foreach ([['date' => '2026-02-30'], ['date' => '07.10.2026'], ['choice' => 'C'], ['confirmed' => 'true'], ['count' => INF], ['count' => '1e9999'], ['count' => 'NaN'], ['activity' => ['nested']], ['activity' => '<script>alert(1)</script>'], ['activity' => str_repeat('x', 2001)], ['unknown' => 'injected']] as $replacement) {
    $reject(static fn() => $store->saveDocument($document, 3, array_replace($validValues, $replacement), 'Draft', $folder), 'invalid typed document value');
}
$store->saveDocument($document, 3, array_replace($validValues, ['confirmed' => false]), 'Checkbox draft', $folder);
$reject(static fn() => $store->transition($document, 4, 'submit'), 'required checkbox must be confirmed');
$store->saveDocument($document, 4, $validValues, 'Complete draft < 3 & > 1', $folder);
$reject(static fn() => $store->saveDocument($document, 4, [], 'Stale overwrite', ''), 'stale save rejected');
$assert($store->document($document)['version'] === 5 && $store->document($document)['values'] == $validValues, 'stale save leaves document unchanged');

$store->assignDocument($document, 5, 'bob', 'cara');
$reject(static fn() => $store->saveDocument($document, 6, $validValues, 'Creator overwrite', ''), 'creator cannot edit a draft assigned to someone else');
$reject(static fn() => $store->transition($document, 6, 'submit'), 'creator cannot submit assigned draft');
$access->id = 'bob';
$reject(static fn() => $store->assignDocument($document, 6, 'bob', 'cara'), 'assignee cannot assign roles');
$store->saveDocument($document, 6, $validValues, 'Ready for review', $folder);
$access->levels['cara'] = 'read';
$reject(static fn() => $store->transition($document, 7, 'submit'), 'reviewer permission revocation checked live at submission');
$access->levels['cara'] = 'write';
$store->transition($document, 7, 'submit', 'Please review synthetic test');
$assert($store->document($document)['status'] === 'submitted', 'submission enters frozen review state');
$reject(static fn() => $store->saveDocument($document, 8, $validValues, 'Frozen edit', ''), 'submitted values cannot be edited');
$reject(static fn() => $store->transition($document, 8, 'approve'), 'assignee cannot approve');
$access->id = 'ada';
$reject(static fn() => $store->assignDocument($document, 8, '', 'cara'), 'submitted assignments frozen');
$reject(static fn() => $store->transition($document, 8, 'approve'), 'creator cannot approve');
$reject(static fn() => $store->transition($document, 8, 'reopen'), 'creator cannot bypass pending review');
$access->id = 'cara';
$reject(static fn() => $store->transition($document, 8, 'return'), 'return requires reason');
$store->transition($document, 8, 'return', 'Add the missing synthetic explanation');
$assert($store->document($document)['status'] === 'returned', 'reviewer can return with comment');
$access->id = 'bob';
$store->transition($document, 9, 'submit');
$access->id = 'cara';
$access->levels['cara'] = 'read';
$reject(static fn() => $store->transition($document, 10, 'approve'), 'reviewer membership permission checked live at approval');
$access->levels['cara'] = 'write';
$store->transition($document, 10, 'approve', 'Administrative synthetic review only');
$assert($store->document($document)['status'] === 'approved', 'independent reviewer can administratively approve');
$reject(static fn() => $store->transition($document, 11, 'reopen'), 'reviewer cannot reopen');
$access->id = 'bob';
$reject(static fn() => $store->saveDocument($document, 11, $validValues, 'Frozen approval', ''), 'approved document cannot be edited');
$access->id = 'ada';
$store->transition($document, 11, 'reopen', 'New synthetic revision');
$assert($store->document($document)['status'] === 'draft' && $store->document($document, 11)['status'] === 'approved', 'reopen preserves approved revision');

$changed = $data;
$changed['title'] = 'Revised template';
$changed['fields'] = [['key' => 'replacement', 'label' => 'Replacement', 'type' => 'text', 'required' => true]];
$changed['provenance']['adaptation'] = 'Changed source note';
$store->saveTemplate($template, 2, $changed);
$assert($store->template($template)['published_version'] === 2 && $store->template($template)['status'] === 'draft', 'editing published template retains last published version');
$oldPublishedDocument = $store->createDocument($template, '', 'Uses last published');
$assert($store->document($oldPublishedDocument)['fields'] == $fields, 'new document uses published revision while next template edit is draft');
$store->publishTemplate($template, 3);
$assert(count($store->templateVersions($template)) === 2, 'both template publications remain available');
$assert($store->document($document)['fields'] == $fields && $store->document($document)['provenance'] == $provenance, 'later publication cannot change document schema or provenance');
$newPublishedDocument = $store->createDocument($template, '', 'Uses new published');
$assert($store->document($newPublishedDocument)['template_version'] === 4 && $store->document($newPublishedDocument)['fields'] == $changed['fields'], 'later document receives latest published revision');

foreach ($store->versions($document) as $version) {
    $snapshot = $store->document($document, $version['revision']);
    $json = json_decode($store->export($document, $version['revision'], 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
    $html = $store->export($document, $version['revision'], 'html');
    $assert($json['document'] == $snapshot && $snapshot['version'] === $version['revision'], 'exact historical JSON export revision ' . $version['revision']);
    $assert(!str_contains($html['bytes'], WorkflowStore::APPROVAL_NOTE) && str_contains($html['bytes'], '<!doctype html>') && !str_contains($html['name'], '/'), 'clean print export without product notes revision ' . $version['revision']);
    $assert(count($json['activities']) === $version['revision'] && max(array_column($json['activities'], 'revision')) === $version['revision'], 'historical export activities bounded at revision ' . $version['revision']);
    $assert(str_contains($html['bytes'], 'Administrative synthetic review only') === ($version['revision'] >= 11), 'future approval comment never enters historic export revision ' . $version['revision']);
}
$assert(str_contains($store->export($document, 11)['bytes'], 'Ersteller (Kennung): ada') && str_contains($store->export($document, 11)['bytes'], 'Bearbeiter (Kennung): bob') && str_contains($store->export($document, 11)['bytes'], 'Prüfer (Kennung): cara'), 'approved export identifies creator assignee and reviewer');
$assert(str_contains($store->export($document, 5)['bytes'], 'Demo &lt; 3 &amp; &gt; 1'), 'export escapes plain text');
$generalHtml = $store->export($document, 11)['bytes'];
$generalJson = $store->export($document, 11, 'json')['bytes'];
$assert(!str_contains($generalHtml, 'Gefährdungsbeurteilung') && !str_contains($generalJson, 'Gefährdungsbeurteilung'), 'general document exports contain no blanket safety-assessment warning');
$assert(!str_contains($generalHtml, RiskMatrix::NOTE) && !str_contains($generalJson, RiskMatrix::NOTE), 'matrix-specific safety note is absent from ordinary documents');
$assert(!array_key_exists('risk_matrix_note', json_decode($generalJson, true, 64, JSON_THROW_ON_ERROR)) && !str_contains($generalHtml, WorkflowStore::APPROVAL_NOTE), 'ordinary print exports contain no product instructions');
$assert($store->document($document, 1) == $first, 'first immutable document snapshot unchanged');
$assert(count($store->activities($document)) === 12 && count($store->versions($document)) === 12, 'each successful mutation has exactly one snapshot and activity');
$reject(static fn() => $store->document($document, 999), 'missing revision rejected');
$reject(static fn() => $store->export($document, null, 'zip'), 'unsupported export type rejected');

$access->level = 'read';
$assert($store->document($document)['id'] === $document && count($store->activities($document)) === 12, 'read-only actor can read documents and history');
$assert($store->export($document, 1)['mime'] === 'text/html; charset=utf-8', 'read-only actor can export');
foreach ([static fn() => $store->saveFolder('', 0, 'Blocked'), static fn() => $store->saveTemplate('', 0, $data), static fn() => $store->publishTemplate($empty, 1), static fn() => $store->createDocument($template, '', 'Blocked'), static fn() => $store->saveDocument($document, 12, [], 'Blocked', ''), static fn() => $store->assignDocument($document, 12, '', 'cara'), static fn() => $store->transition($document, 12, 'submit')] as $mutation) {
    $reject($mutation, 'read-only mutation rejected');
}
$access->level = 'none';
foreach ([static fn() => $store->folders(), static fn() => $store->templates(), static fn() => $store->template($template), static fn() => $store->templateVersions($template), static fn() => $store->documents(), static fn() => $store->document($document), static fn() => $store->document($document, 1), static fn() => $store->versions($document), static fn() => $store->activities($document), static fn() => $store->export($document), static fn() => $store->participants()] as $read) {
    $reject($read, 'revoked access immediately blocks existing service instance');
}
$access->level = 'write';
$access->club = 'synthetic-club-b';
$assert($store->folders() === [] && $store->templates() === [] && $store->documents() === [], 'tenant listing isolation');
foreach ([static fn() => $store->template($template), static fn() => $store->templateVersions($template), static fn() => $store->document($document), static fn() => $store->document($document, 1), static fn() => $store->versions($document), static fn() => $store->activities($document), static fn() => $store->export($document), static fn() => $store->export($document, 1), static fn() => $store->saveFolder($folder, 2, 'Cross tenant'), static fn() => $store->saveFolder('', 0, 'Cross parent', $folder), static fn() => $store->saveTemplate($template, 4, $data), static fn() => $store->publishTemplate($template, 4), static fn() => $store->createDocument($template, '', 'Cross template'), static fn() => $store->saveDocument($document, 12, [], 'Cross save', ''), static fn() => $store->assignDocument($document, 12, '', 'cara'), static fn() => $store->transition($document, 12, 'submit')] as $crossTenant) {
    $reject($crossTenant, 'cross-tenant object rejected');
}
$tenantBTemplate = $store->saveTemplate('', 0, $data);
$store->publishTemplate($tenantBTemplate, 1);
$reject(static fn() => $store->createDocument($tenantBTemplate, $folder, 'Cross folder'), 'cross-tenant folder rejected on creation');
$tenantBDocument = $store->createDocument($tenantBTemplate, '', 'Tenant B document');
$reject(static fn() => $store->saveDocument($tenantBDocument, 1, [], 'Cross folder', $folder), 'cross-tenant folder rejected on save');
$assert(count($store->documents()) === 1 && $store->document($tenantBDocument)['club_id'] === 'synthetic-club-b', 'own tenant remains usable');
$access->club = 'synthetic-club-a';
$assert(count($store->documents(['folder_id' => $folder])) === 1, 'folder filter');
$assert(count($store->documents(['search' => 'Uses new published'])) === 1, 'title filter');
$assert(count($store->documents(['search' => '%'])) === 0, 'LIKE wildcard escaped as literal');
$reject(static fn() => $store->documents(['status' => 'evil']), 'invalid status filter');
$reject(static fn() => $store->documents(['sql' => 'evil']), 'unknown filter rejected');

// Trigger a genuine SQL failure after updating the document and inserting its snapshot.
$before = $store->document($oldPublishedDocument);
$pdo->exec("CREATE TRIGGER dwf_test_activity_failure BEFORE INSERT ON dwf_activities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic rollback check'");
try {
    $store->saveDocument($oldPublishedDocument, 1, [], 'Must roll back', '');
    $assert(false, 'SQL failure propagated');
} catch (PDOException) {
    $assert(true, 'SQL failure propagated');
} finally {
    $pdo->exec('DROP TRIGGER dwf_test_activity_failure');
}
$assert($store->document($oldPublishedDocument) === $before && count($store->versions($oldPublishedDocument)) === 1 && count($store->activities($oldPublishedDocument)) === 1, 'document, snapshot and activity rolled back atomically');
$pdo->beginTransaction();
$reject(static fn() => $store->saveFolder('', 0, 'Nested'), 'nested transaction rejected');
$assert($pdo->inTransaction(), 'caller transaction preserved after rejected nested mutation');
$pdo->rollBack();

$raceTemplate = $store->saveTemplate('', 0, ['title' => 'Concurrent template', 'fields' => [$fields[0]], 'provenance' => []]);
$store->publishTemplate($raceTemplate, 1);

$contributorDocument = $store->createDocument($raceTemplate, '', 'Historical contributor separation');
$store->assignDocument($contributorDocument, 1, 'bob', 'cara');
$access->id = 'bob';
$store->saveDocument($contributorDocument, 2, ['activity' => 'Saved by Bob'], 'Historical contributor separation', '');
$access->id = 'ada';
$reject(static fn() => $store->assignDocument($contributorDocument, 3, 'cara', 'bob'), 'reassignment cannot turn a previous content author into reviewer');
$assert($store->document($contributorDocument)['version'] === 3 && count($store->versions($contributorDocument)) === 3, 'rejected contributor assignment remains atomic');
$store->assignDocument($contributorDocument, 3, 'cara', '');
// Emulate an inconsistent imported record to verify transition checks independently of assignment validation.
$corruptAssignment = $pdo->prepare('UPDATE dwf_documents SET reviewer_id = ? WHERE club_id = ? AND id = ?');
$corruptAssignment->execute(['bob', $access->club, $contributorDocument]);
$access->id = 'cara';
$reject(static fn() => $store->transition($contributorDocument, 4, 'submit'), 'submission independently blocks historical contributor reviewer');
$corruptState = $pdo->prepare('UPDATE dwf_documents SET status = ? WHERE club_id = ? AND id = ?');
$corruptState->execute(['submitted', $access->club, $contributorDocument]);
$access->id = 'bob';
$reject(static fn() => $store->transition($contributorDocument, 4, 'approve'), 'approval independently blocks historical contributor reviewer');
$corruptAssignment->execute(['', $access->club, $contributorDocument]);
$corruptState->execute(['draft', $access->club, $contributorDocument]);
$access->id = 'ada';

$raceDocument = $store->createDocument($raceTemplate, '', 'Concurrent document');
$workers = [];
$pdo->beginTransaction();
$lock = $pdo->prepare('SELECT id FROM dwf_documents WHERE club_id = ? AND id = ? FOR UPDATE');
$lock->execute([$access->club, $raceDocument]);
try {
    for ($index = 0; $index < 2; $index++) {
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . dirname(PHP_BINARY) . '/ext', '-d', 'extension=pdo_mysql', __FILE__, '--race', $database, $raceDocument, '1'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent PHP connection.');
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        $workers[] = [$process, $pipes];
        $assert(trim((string) fgets($pipes[1])) === 'READY', 'concurrent worker ready');
    }
    $pdo->commit();
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $outcomes[] = trim((string) stream_get_contents($pipes[1]));
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $assert($errors === '' && in_array($exit, [0, 3], true), 'concurrent worker exits cleanly');
    }
    $workers = [];
    sort($outcomes);
    $assert($outcomes === ['CONFLICT', 'SAVED'], 'exactly one concurrent write succeeds');
    $assert($store->document($raceDocument)['version'] === 2 && count($store->versions($raceDocument)) === 2 && count($store->activities($raceDocument)) === 2, 'concurrent loser leaves no duplicate revision or activity');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($workers as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        proc_close($process);
    }
}

// A neutral matrix stores only the two assessed integers. Products are derived per exported revision.
$matrixFields = [
    ['key' => 'risk_before', 'label' => 'Bewertung vor Maßnahmen', 'type' => 'risk_matrix', 'required' => true],
    ['key' => 'risk_after', 'label' => 'Bewertung nach Maßnahmen', 'type' => 'risk_matrix', 'required' => false],
];
$matrixTemplate = $store->saveTemplate('', 0, ['title' => 'Neutral synthetic matrix', 'fields' => $matrixFields, 'provenance' => []]);
$store->publishTemplate($matrixTemplate, 1);
$matrixDocument = $store->createDocument($matrixTemplate, '', 'Neutral matrix draft');
$store->saveDocument($matrixDocument, 1, [], 'Empty matrix draft', '');
$store->assignDocument($matrixDocument, 2, '', 'cara');
$reject(static fn() => $store->transition($matrixDocument, 3, 'submit'), 'required missing matrix blocks submission');
$assert($store->document($matrixDocument)['version'] === 3, 'missing matrix failure leaves revision unchanged');
$matrixVersion = 3;
foreach ([null, ''] as $blank) {
    $store->saveDocument($matrixDocument, $matrixVersion, ['risk_before' => $blank], 'Blank matrix draft', '');
    $matrixVersion++;
    $reject(static fn() => $store->transition($matrixDocument, $matrixVersion, 'submit'), 'blank matrix accepted in draft but rejected on submission');
}
$blankExport = json_decode($store->export($matrixDocument, $matrixVersion, 'json')['bytes'], false, 64, JSON_THROW_ON_ERROR);
$assert($blankExport->risk_matrix_calculations instanceof stdClass && count(get_object_vars($blankExport->risk_matrix_calculations)) === 0, 'blank matrix has no invented calculation');
$blankHtml = $store->export($matrixDocument, $matrixVersion)['bytes'];
$assert(str_contains($blankHtml, 'Bewertung noch offen.') && substr_count($blankHtml, 'class="dwf-risk-selected"') === 0, 'blank coloured matrix has no invented selected assessment');

$invalidMatrices = [
    [], ['likelihood' => 2], ['severity' => 3], ['likelihood' => 2, 'severity' => null],
    ['likelihood' => '2', 'severity' => 3], ['likelihood' => 2, 'severity' => '3'],
    ['likelihood' => true, 'severity' => 3], ['likelihood' => 2, 'severity' => false],
    ['likelihood' => 2.0, 'severity' => 3], ['likelihood' => 2, 'severity' => 3.0],
    ['likelihood' => 0, 'severity' => 3], ['likelihood' => 6, 'severity' => 3],
    ['likelihood' => 2, 'severity' => 0], ['likelihood' => 2, 'severity' => 6],
    ['likelihood' => -1, 'severity' => 3], ['likelihood' => 2, 'severity' => INF],
    ['likelihood' => 2, 'severity' => 3, 'score' => 1], ['likelihood' => 2, 'severity' => 3, 'accepted' => true],
    ['score' => 6], [2, 3], '2 × 3', (object) ['likelihood' => 2, 'severity' => 3],
];
foreach ($invalidMatrices as $index => $matrix) {
    $reject(static fn() => $store->saveDocument($matrixDocument, $matrixVersion, ['risk_before' => $matrix], 'Invalid matrix', ''), 'invalid matrix payload ' . $index);
}
$assert($store->document($matrixDocument)['version'] === $matrixVersion && count($store->versions($matrixDocument)) === $matrixVersion, 'invalid matrices never create saved revisions');
$reject(static fn() => $store->saveTemplate('', 0, ['title' => 'Injected thresholds', 'fields' => [array_replace($matrixFields[0], ['thresholds' => ['accept' => 10]])], 'provenance' => []]), 'client matrix acceptance thresholds are rejected');

$matrixValues = ['risk_before' => ['likelihood' => 3, 'severity' => 4], 'risk_after' => ['likelihood' => 2, 'severity' => 5]];
$store->saveDocument($matrixDocument, $matrixVersion, $matrixValues, 'Two neutral products', '');
$matrixVersion++;
$firstMatrixVersion = $matrixVersion;
$calculated = json_decode($store->export($matrixDocument, $matrixVersion, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($calculated['document']['values'] == $matrixValues && !array_key_exists('score', $calculated['document']['values']['risk_before']), 'stored and exported raw matrix values contain only assessed integers');
$assert($calculated['risk_matrix_calculations'] == ['risk_before' => ['likelihood' => 3, 'severity' => 4, 'score' => 12], 'risk_after' => ['likelihood' => 2, 'severity' => 5, 'score' => 10]], 'matrix products computed by server independently per field');
$matrixHtml = $store->export($matrixDocument, $matrixVersion)['bytes'];
$assert(substr_count($matrixHtml, '<td') === 50 && substr_count($matrixHtml, 'class="dwf-risk-selected"') === 2 && str_contains($matrixHtml, 'Schadensschwere D × Wahrscheinlichkeit 3 = Risiko 12.'), 'HTML renders two 5 by 5 reference grids with each selected cell');
$assert(str_contains($matrixHtml, RiskMatrix::NOTE) && $calculated['risk_matrix_note'] === RiskMatrix::NOTE, 'matrix exports explain the displayed reference colours and separate approval');
$store->saveDocument($matrixDocument, $matrixVersion, ['risk_before' => ['likelihood' => 5, 'severity' => 5]], 'Later matrix revision', '');
$matrixVersion++;
$latestCalculation = json_decode($store->export($matrixDocument, null, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$historicCalculation = json_decode($store->export($matrixDocument, $firstMatrixVersion, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($latestCalculation['risk_matrix_calculations'] == ['risk_before' => ['likelihood' => 5, 'severity' => 5, 'score' => 25]], 'maximum product valid and omitted optional matrix uncalculated');
$assert($historicCalculation == $calculated, 'later matrix saves do not affect complete historical JSON export');
$assert($store->document($matrixDocument)['status'] === 'draft', 'numeric score never automatically submits or approves');
$store->transition($matrixDocument, $matrixVersion, 'submit');
$matrixVersion++;
$assert($store->document($matrixDocument)['status'] === 'submitted', 'complete required matrix can be submitted');
$reject(static fn() => $store->saveDocument($matrixDocument, $matrixVersion, ['risk_before' => ['likelihood' => 1, 'severity' => 1]], 'Frozen matrix', ''), 'submitted matrix remains frozen');
$access->id = 'cara';
$store->transition($matrixDocument, $matrixVersion, 'approve', 'Administrative matrix review only');
$matrixVersion++;
$assert($store->document($matrixDocument)['status'] === 'approved', 'matrix still requires independent administrative review');
$access->id = 'ada';
$store->saveTemplate($matrixTemplate, 2, ['title' => 'Changed form after publication', 'fields' => [$fields[0]], 'provenance' => []]);
$store->publishTemplate($matrixTemplate, 3);
$assert($store->document($matrixDocument)['fields'] == $matrixFields && $store->document($matrixDocument)['template_version'] === 2, 'matrix schema remains pinned after template type changes');
$legacyExport = json_decode($store->export($document, 1, 'json')['bytes'], false, 64, JSON_THROW_ON_ERROR);
$assert(count(get_object_vars($legacyExport->risk_matrix_calculations)) === 0, 'legacy six-type documents remain exportable without synthetic matrix values');
$assert(RiskMatrix::calculate(['likelihood' => 1, 'severity' => 1])['score'] === 1, 'minimum neutral product valid');

// Repeatable registers retain six text columns and two independently validated assessments per row.
$registerFields = [
    ['key' => 'hazards', 'label' => 'Gefährdungsregister', 'type' => 'risk_register', 'required' => true],
    ['key' => 'additional_hazards', 'label' => 'Weitere Gefährdungen', 'type' => 'risk_register', 'required' => false],
];
$registerTemplate = $store->saveTemplate('', 0, ['title' => 'Synthetic hazard register', 'fields' => $registerFields, 'provenance' => []]);
$store->publishTemplate($registerTemplate, 1);
$registerDocument = $store->createDocument($registerTemplate, '', 'Synthetic hazard register draft');
$store->assignDocument($registerDocument, 1, '', 'cara');
$reject(static fn() => $store->transition($registerDocument, 2, 'submit'), 'missing required register blocks submission');
$store->saveDocument($registerDocument, 2, ['hazards' => []], 'Empty hazard register draft', '');
$reject(static fn() => $store->transition($registerDocument, 3, 'submit'), 'empty required register blocks submission');
$emptyRegisterExport = json_decode($store->export($registerDocument, null, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($emptyRegisterExport['risk_register_calculations'] == ['hazards' => [], 'additional_hazards' => []], 'empty and omitted registers retain empty calculation lists');

$partialRow = ['category' => 'Synthetic category', 'hazard' => '', 'impact' => '', 'initial' => null, 'controls' => '', 'residual' => null, 'responsible' => '', 'effectiveness' => ''];
$store->saveDocument($registerDocument, 3, ['hazards' => [$partialRow]], 'Partial register draft', '');
$partialRegisterExport = json_decode($store->export($registerDocument, null, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($partialRegisterExport['document']['values']['hazards'] == [$partialRow] && $partialRegisterExport['risk_register_calculations']['hazards'] == [['initial' => null, 'residual' => null]], 'partial text and null risk pairs are preserved in draft');
$reject(static fn() => $store->transition($registerDocument, 4, 'submit'), 'partial register row blocks submission');
$completeRow = ['category' => 'Demo < 3 & > 1', 'hazard' => 'Synthetic hazard', 'impact' => 'Synthetic potential consequence', 'initial' => ['likelihood' => 3, 'severity' => 4], 'controls' => "Synthetic measure\nPending review", 'residual' => ['likelihood' => 2, 'severity' => 3], 'responsible' => 'Demo role', 'effectiveness' => 'Synthetic verification planned'];
$missingColumn = $completeRow;
unset($missingColumn['effectiveness']);
$invalidRegisters = [
    null, '', 'register', [1 => $completeRow], [null], [$missingColumn],
    [array_replace($completeRow, ['score' => 12])],
    [array_replace($completeRow, ['category' => ['nested']])],
    [array_replace($completeRow, ['hazard' => '<img src=x onerror=alert(1)>'])],
    [array_replace($completeRow, ['controls' => str_repeat('x', 6001)])],
    [array_replace($completeRow, ['responsible' => str_repeat('x', 201)])],
    [array_replace($completeRow, ['initial' => ['likelihood' => 3]])],
    [array_replace($completeRow, ['initial' => ['likelihood' => '3', 'severity' => 4]])],
    [array_replace($completeRow, ['residual' => ['likelihood' => true, 'severity' => 3]])],
    [array_replace($completeRow, ['residual' => ['likelihood' => 2, 'severity' => 3, 'score' => 0]])],
    [array_replace($completeRow, ['initial' => []])],
    [array_replace($completeRow, ['residual' => ''])],
    array_fill(0, 31, $completeRow),
];
foreach ($invalidRegisters as $index => $register) {
    $reject(static fn() => $store->saveDocument($registerDocument, 4, ['hazards' => $register], 'Invalid register', ''), 'invalid register payload ' . $index);
}
$assert($store->document($registerDocument)['version'] === 4 && count($store->versions($registerDocument)) === 4, 'invalid register payloads create no revisions');
foreach (array_keys(RiskRegister::TEXT_LIMITS) as $key) {
    $reject(static fn() => RiskRegister::validate([array_replace($completeRow, [$key => '   '])], true, true), 'each register text column required on submission: ' . $key);
}
foreach (['initial', 'residual'] as $key) {
    $reject(static fn() => RiskRegister::validate([array_replace($completeRow, [$key => null])], true, true), 'both register risk assessments required on submission: ' . $key);
}
$store->saveDocument($registerDocument, 4, ['hazards' => array_fill(0, 30, $partialRow)], 'Thirty draft rows', '');
$assert(count($store->document($registerDocument)['values']['hazards']) === 30, 'maximum thirty register rows accepted in draft');
$store->saveDocument($registerDocument, 5, ['hazards' => [$completeRow], 'additional_hazards' => [$partialRow]], 'Optional register with incomplete row', '');
$reject(static fn() => $store->transition($registerDocument, 6, 'submit'), 'present rows of optional registers must also be complete on submission');
$secondRow = array_replace($completeRow, ['category' => 'Second synthetic category', 'initial' => ['likelihood' => 5, 'severity' => 5], 'residual' => ['likelihood' => 1, 'severity' => 1]]);
$registerValues = ['hazards' => [$completeRow, $secondRow], 'additional_hazards' => []];
$store->saveDocument($registerDocument, 6, $registerValues, 'Two complete register rows', '');
$registerRevision = 7;
$registerExport = json_decode($store->export($registerDocument, $registerRevision, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($registerExport['document']['values'] == $registerValues, 'register raw values retain row order and contain no computed scores');
$assert($registerExport['risk_register_calculations']['hazards'] == [
    ['initial' => ['likelihood' => 3, 'severity' => 4, 'score' => 12], 'residual' => ['likelihood' => 2, 'severity' => 3, 'score' => 6]],
    ['initial' => ['likelihood' => 5, 'severity' => 5, 'score' => 25], 'residual' => ['likelihood' => 1, 'severity' => 1, 'score' => 1]],
], 'initial and residual products calculated for each row at matching indices');
$registerHtml = $store->export($registerDocument, $registerRevision)['bytes'];
$assert(str_contains($registerHtml, '@page{size:A4 landscape;') && str_contains($registerHtml, 'class="dwf-register-document"') && str_contains($registerHtml, 'scope="colgroup">Ausgangsbewertung'), 'register documents print in landscape with semantic grouped columns');
$assert(str_contains($registerHtml, '<td class="dwf-register-score">D</td>') && str_contains($registerHtml, '<td class="dwf-register-score">E</td>') && str_contains($registerHtml, '<td class="dwf-register-score">A</td>'), 'register severity integers displayed as neutral A to E letters');
$assert(str_contains($registerHtml, 'Demo &lt; 3 &amp; &gt; 1') && str_contains($registerHtml, RiskMatrix::NOTE), 'register texts escaped and context-specific scale note retained');
$assert(!str_contains($store->export($document, 1)['bytes'], '@page{size:A4 landscape;') && !str_contains($store->export($matrixDocument, 1)['bytes'], '@page{size:A4 landscape;'), 'ordinary and legacy matrix documents do not acquire register landscape layout');
$store->saveDocument($registerDocument, 7, ['hazards' => [array_replace($completeRow, ['initial' => ['likelihood' => 1, 'severity' => 1]])]], 'Later register revision', '');
$historicRegisterExport = json_decode($store->export($registerDocument, $registerRevision, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($historicRegisterExport == $registerExport, 'later register edits never change historical values calculations or activities');
$assert($store->document($registerDocument)['status'] === 'draft', 'register arithmetic never causes automatic approval');
$store->transition($registerDocument, 8, 'submit');
$assert($store->document($registerDocument)['status'] === 'submitted', 'complete register can enter review');
$reject(static fn() => $store->saveDocument($registerDocument, 9, ['hazards' => []], 'Frozen register', ''), 'submitted register remains frozen');
$access->id = 'cara';
$store->transition($registerDocument, 9, 'approve', 'Internal document review');
$assert($store->document($registerDocument)['status'] === 'approved', 'register requires independent document approval');
$access->id = 'ada';
$store->saveTemplate($registerTemplate, 2, ['title' => 'Later ordinary template', 'fields' => [$fields[0]], 'provenance' => []]);
$store->publishTemplate($registerTemplate, 3);
$assert($store->document($registerDocument)['fields'] == $registerFields && str_contains($store->export($registerDocument)['bytes'], '@page{size:A4 landscape;'), 'register field schema and print layout remain pinned after template changes');

// A closed, versioned reference profile adds evidenced bands only to explicitly profiled registers.
$profileId = RiskProfiles::REFERENCE;
$profileConfig = RiskProfiles::get($profileId);
$assert(array_column($profileConfig['severity'], 'value') === [5, 4, 3, 2, 1] && array_column($profileConfig['severity'], 'letter') === ['E', 'D', 'C', 'B', 'A'], 'profile severity rows match source order E to A');
$assert(array_column($profileConfig['likelihood'], 'value') === [1, 2, 3, 4, 5], 'profile likelihood columns ascend one to five');
$assert(array_column($profileConfig['bands'], 'background') === ['#5ab88f', '#ffd744', '#e7343f'], 'reference band colors match verified source pixels');
$assert($profileConfig['source_sha256'] === 'd8634845499b7ee78ef838a277af8070c5ba3bfc8ceda721e0253f94ce03f16d', 'profile identifies the provided reference PDF hash');
$assert(count($profileConfig['cells']) === 5 && count($profileConfig['cells'][0]) === 5 && $profileConfig['cells'][0][4]['score'] === 25, 'shared JSON profile contains an ordered five-by-five lookup');
$assert(RiskProfiles::calculate(['likelihood' => 4, 'severity' => 2], $profileId)['band'] === 'medium', 'reference R8 is medium');
$assert(RiskProfiles::calculate(['likelihood' => 1, 'severity' => 4], $profileId)['band'] === 'low', 'reference R4 is low');
$assert(RiskProfiles::calculate(['likelihood' => 2, 'severity' => 5], $profileId)['band'] === 'high', 'reference R10 is high');
$assert(RiskProfiles::bandFor(5, $profileId)['key'] === 'medium' && RiskProfiles::bandFor(9, $profileId)['key'] === 'medium' && RiskProfiles::bandFor(25, $profileId)['key'] === 'high', 'closed band boundaries match source');
$assert(RiskProfiles::calculate(['likelihood' => 4, 'severity' => 2]) === ['likelihood' => 4, 'severity' => 2, 'score' => 8] && RiskProfiles::get()['bands'] === [], 'neutral calculations retain exactly legacy integers without classifications');
$reject(static fn() => RiskProfiles::get('untrusted_profile'), 'unknown profile lookup rejected');
$reject(static fn() => RiskRegister::calculate([], 'untrusted_profile'), 'empty register cannot bypass profile validation');
$profiledRegisterField = ['key' => 'hazards', 'label' => 'Profiled register', 'type' => 'risk_register', 'required' => true, 'matrix_profile' => $profileId];
foreach (['untrusted_profile', '', null, true, ['id' => $profileId], '<script>alert(1)</script>'] as $invalidProfile) {
    $reject(static fn() => $store->saveTemplate('', 0, ['title' => 'Invalid profile', 'fields' => [array_replace($profiledRegisterField, ['matrix_profile' => $invalidProfile])], 'provenance' => []]), 'invalid field profile rejected');
}
foreach (['text', 'risk_matrix'] as $wrongType) {
    $reject(static fn() => $store->saveTemplate('', 0, ['title' => 'Wrong profile field', 'fields' => [array_replace($profiledRegisterField, ['type' => $wrongType])], 'provenance' => []]), 'profiles cannot affect legacy matrix or ordinary field types');
}
$profileFields = [
    ['key' => 'site', 'label' => 'Synthetischer Bereich', 'type' => 'text', 'required' => false, 'help' => 'Synthetic context only'],
    ['key' => 'assessed_on', 'label' => 'Demo-Datum', 'type' => 'date', 'required' => false],
    ['key' => 'owner', 'label' => 'Demo-Verantwortung', 'type' => 'text', 'required' => false],
    $profiledRegisterField,
];
$profileProvenance = ['title' => 'Private reference provenance', 'licence' => 'User-authorized private reference', 'adaptation' => 'Assessment scale transcribed from FS-RA-030 Business Travel.pdf.'];
$profileData = ['title' => 'Profiled synthetic register', 'description' => 'Synthetic profile-specific description', 'fields' => $profileFields, 'provenance' => $profileProvenance];
$profileTemplate = $store->saveTemplate('', 0, $profileData);
$store->publishTemplate($profileTemplate, 1);
$profileDocument = $store->createDocument($profileTemplate, '', 'Profiled register document');
$profileRows = [
    array_replace($completeRow, ['initial' => ['likelihood' => 4, 'severity' => 2], 'residual' => ['likelihood' => 1, 'severity' => 4]]),
    array_replace($completeRow, ['initial' => ['likelihood' => 2, 'severity' => 5], 'residual' => ['likelihood' => 1, 'severity' => 1]]),
];
$profileValues = ['site' => 'Synthetic room < 3 & > 1', 'assessed_on' => '2026-10-07', 'owner' => 'Demo role', 'hazards' => $profileRows];
$store->saveDocument($profileDocument, 1, $profileValues, 'Profiled register document', '');
$profileExport = json_decode($store->export($profileDocument, 2, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($profileExport['document']['values'] == $profileValues && $profileExport['document']['fields'][3]['matrix_profile'] === $profileId, 'profile identifier pinned to schema while raw risk values remain two integers');
$assert($profileExport['risk_register_calculations']['hazards'][0]['initial'] == ['likelihood' => 4, 'severity' => 2, 'score' => 8, 'profile' => $profileId, 'band' => 'medium', 'band_label' => 'Mittel'], 'JSON exports identify applied profile and derived band');
$profileHtml = $store->export($profileDocument, 2)['bytes'];
$assert(str_contains($profileHtml, 'data-risk-band="medium" style="background:#ffd744;color:#000000"><strong>8</strong>') && str_contains($profileHtml, 'data-risk-band="low" style="background:#5ab88f;color:#000000"><strong>4</strong>') && str_contains($profileHtml, 'data-risk-band="high" style="background:#e7343f;color:#000000"><strong>10</strong>'), 'profiled R cells use evidenced colors and visible band labels');
$assert(str_contains($profileHtml, 'Katastrophal') && str_contains($profileHtml, 'Erste Hilfe; sofortige Erholung') && str_contains($profileHtml, 'Einmal bis mehrmals jährlich am Standort'), 'export includes the provided severity and likelihood legend without invented frequency ratios');
foreach ($profileConfig['severity'] as $severityDefinition) {
    $assert(str_contains($profileHtml, $severityDefinition['label']) && substr_count($profileHtml, $severityDefinition['description']) === 1, 'full severity definition printed exactly once: ' . $severityDefinition['letter']);
}
foreach ($profileConfig['likelihood'] as $likelihoodDefinition) {
    $assert(str_contains($profileHtml, $likelihoodDefinition['label']) && substr_count($profileHtml, $likelihoodDefinition['description']) === 1, 'full likelihood definition printed exactly once: ' . $likelihoodDefinition['value']);
}
foreach ($profileConfig['bands'] as $bandDefinition) {
    $assert(str_contains($profileHtml, $bandDefinition['label']) && substr_count($profileHtml, $bandDefinition['description']) === 1, 'full risk band meaning printed exactly once: ' . $bandDefinition['key']);
}
$assert(str_contains($profileHtml, 'R = S × L (Produkt der beiden Zahlenwerte).'), 'register prints the risk product formula explicitly');
$assert(!str_contains($profileHtml, '<details') && !str_contains($profileHtml, '<summary'), 'printable matrix definitions do not depend on interactive disclosure elements');
$tablePosition = strpos($profileHtml, '<table class="dwf-register-table"');
$assert(strpos($profileHtml, '<table class="dwf-register-meta"') < $tablePosition && strpos($profileHtml, 'class="dwf-risk-compact"') < $tablePosition && strpos($profileHtml, $profileConfig['bands'][2]['description']) < $tablePosition && substr_count($profileHtml, 'class="dwf-risk-composite-table"') === 1 && !str_contains($profileHtml, '<h2>Synthetischer Bereich</h2>'), 'register metadata and one complete composite legend precede the hazard table');
$registerSheet = substr($profileHtml, 0, strpos($profileHtml, '<h2>Dokumentnachweis</h2>'));
$assert(strpos($profileHtml, '<h2>Dokumentnachweis</h2>') > $tablePosition && !str_contains($profileHtml, WorkflowStore::APPROVAL_NOTE) && !str_contains($profileHtml, 'Synthetic profile-specific description') && !str_contains($profileHtml, '<p class="notice">') && !str_contains($registerSheet, RiskMatrix::NOTE) && !str_contains($profileHtml, 'FS-RA-030') && !str_contains($profileHtml, 'Private reference provenance') && $profileExport['document']['provenance'] == $profileProvenance && $profileExport['risk_profiles'][$profileId]['source'] === $profileConfig['source'] && $profileExport['risk_matrix_note'] === RiskMatrix::NOTE, 'private profile provenance stays in JSON and product hints are absent from printed sheet');
$assert(str_contains($profileHtml, 'Synthetic room &lt; 3 &amp; &gt; 1') && str_contains($profileHtml, '<td colspan="2"></td>'), 'compact metadata escapes values and handles an odd field count');
$assert($store->document($profileDocument)['status'] === 'draft', 'profile classifications do not automatically approve a document');

$neutralProfileData = $profileData;
unset($neutralProfileData['fields'][3]['matrix_profile']);
$store->saveTemplate($profileTemplate, 2, $neutralProfileData);
$store->publishTemplate($profileTemplate, 3);
$neutralAfterProfileDocument = $store->createDocument($profileTemplate, '', 'Neutral after profile edit');
$store->saveDocument($neutralAfterProfileDocument, 1, $profileValues, 'Neutral after profile edit', '');
$neutralAfterExport = json_decode($store->export($neutralAfterProfileDocument, 2, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
$assert($neutralAfterExport['risk_register_calculations']['hazards'][0]['initial'] === ['likelihood' => 4, 'severity' => 2, 'score' => 8] && !str_contains($store->export($neutralAfterProfileDocument)['bytes'], 'data-risk-band='), 'new neutral template revision does not invent reference classifications');
$assert(json_decode($store->export($profileDocument, 2, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR) == $profileExport, 'removing template profile cannot change historical profiled exports');
$store->saveTemplate($profileTemplate, 4, $profileData);
$store->publishTemplate($profileTemplate, 5);
$assert(json_decode($store->export($neutralAfterProfileDocument, 2, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR) == $neutralAfterExport, 'adding a later template profile cannot change existing neutral exports');
$assert(str_contains($store->export($registerDocument, 7)['bytes'], 'data-risk-band=') && !array_key_exists('profile', json_decode($store->export($registerDocument, 7, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR)['risk_register_calculations']['hazards'][0]['initial']), 'older unprofiled register values remain unchanged while presentation shows reference colours');
$store->assignDocument($profileDocument, 2, '', 'cara');
$store->transition($profileDocument, 3, 'submit');
$assert($store->document($profileDocument)['status'] === 'submitted', 'profiled registers still use the explicit administrative review workflow');

$oglData = $data;
$oglData['title'] = 'Synthetic OGL source retention test';
$oglData['provenance'] = ['title' => 'HSE reference', 'licence' => 'Open Government Licence v3.0', 'attribution' => 'Contains public sector information published by the Health and Safety Executive and licensed under the Open Government Licence.'];
$oglTemplate = $store->saveTemplate('', 0, $oglData);
$store->publishTemplate($oglTemplate, 1);
$oglDocument = $store->createDocument($oglTemplate, '', 'Synthetic OGL source retention document');
$oglHtml = $store->export($oglDocument)['bytes'];
$assert(str_contains($oglHtml, 'Herkunft und Nutzung') && str_contains($oglHtml, $oglData['provenance']['licence']) && str_contains($oglHtml, $oglData['provenance']['attribution']), 'unprofiled OGL-HSE source and attribution remain printed');

$result = [
    'suite' => 'document-workflow-core',
    'status' => 'passed',
    'checks' => $checks,
    'database' => $database,
    'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'php' => PHP_VERSION,
    'finished_at_utc' => gmdate('c'),
    'scope' => ['tenant isolation', 'live permissions', 'strict typed schemas and provenance', 'draft and review transitions', 'four-eyes approval', 'immutable revisions and exports', 'template pinning', 'atomic rollback after SQL failure', 'real concurrent optimistic updates', 'neutral risk matrix validation, server products and historical exports', 'repeatable hazard registers, initial/residual calculations and landscape exports', 'source-evidenced immutable matrix profile, neutral historical compatibility and compact register print layout'],
    'limits' => ['No production data or external requests', 'Application immutability; database administrators can modify tables', 'No safety or legal certification', 'Global manager route integration tested separately'],
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
