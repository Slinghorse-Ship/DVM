<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/native/bootstrap.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\ManagerDocumentWorkflows;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSchema;

$config = TestSupport::config();
if (($config['owner'] ?? '') !== 'cloud-onboarding-synthetic' || ($config['label'] ?? '') !== 'integrated-manager') {
    throw new RuntimeException('Owned synthetic manager fixture required.');
}
$db = TestSupport::db();
$path = dirname(__DIR__, 3) . '/.local/browser-tests/hse-measures.json';
$fixture = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : null;
if ($fixture && $fixture['database'] !== $config['database']) throw new RuntimeException('Owned fixture database differs.');
$store = (new ManagerDocumentWorkflows($db, 'manager-admin'))->store;
$snapshot = static function (array $fixture) use ($db): string {
    $records = [];
    foreach (['dwf_documents' => 'id', 'dwf_document_versions' => 'document_id', 'dwf_activities' => 'document_id',
        'dwf_attachments' => 'document_id', 'dwf_templates' => 'id', 'dwf_template_versions' => 'template_id'] as $table => $column) {
        $id = str_contains($table, 'template') ? $fixture['template'] : $fixture['document'];
        $keys = Db::run($db, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION", [$table])->fetchAll(PDO::FETCH_COLUMN);
        $records[$table] = Db::run($db, 'SELECT * FROM ' . $table . ' WHERE ' . $column . '<>? ORDER BY `' . implode('`,`', $keys) . '`', [$id])->fetchAll();
    }
    foreach (['dwf_signatures', 'dwf_signature_files', 'dwf_reminders', 'document_files', 'documents', 'contacts'] as $table) {
        $keys = Db::run($db, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION", [$table])->fetchAll(PDO::FETCH_COLUMN);
        $records[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY `' . implode('`,`', $keys) . '`')->fetchAll();
    }
    return hash('sha256', serialize($records));
};
$mode = $argv[1] ?? 'prepare';
if ($mode === 'check') {
    if (!$fixture || $snapshot($fixture) !== $fixture['originals'] ||
        hash('sha256', serialize($store->document($fixture['document'], 4))) !== $fixture['approval_hash'] ||
        hash('sha256', $store->export($fixture['document'], 4)['bytes']) !== $fixture['approval_html_hash']) {
        throw new RuntimeException('Original HSE/attachments/signatures/member documents changed.');
    }
    echo "Original assessments, attachments, signatures, personal documents and approved bytes retained.\n";
    exit;
}
if ($mode !== 'prepare') throw new RuntimeException('Unknown owned fixture action.');
WorkflowSchema::install($db);
$row = ['category' => 'Aufbau', 'hazard' => 'Eigene Kabelprüfung', 'impact' => 'Sturz', 'initial' => ['likelihood' => 3, 'severity' => 3],
    'controls' => 'Eigene Kabelbrücken prüfen', 'residual' => ['likelihood' => 1, 'severity' => 2], 'responsible' => 'Eigene Browser-Verantwortung', 'effectiveness' => 'Sichtprüfung'];
$values = ['review_date' => '2026-10-25', 'hazards' => [array_replace($row, ['action_deadline' => '2026-10-25']),
    array_replace($row, ['hazard' => 'Eigene zweite Gefährdung', 'controls' => 'Freitext 2026-10-25 erzeugt keine Frist'])]];
if (!$fixture) {
    $template = $store->saveTemplate('', 0, ['title' => 'Eigene Maßnahmenfrist-Browservorlage', 'fields' => [
        ['key' => 'review_date', 'label' => 'Nächster Prüftermin', 'type' => 'date', 'required' => false],
        ['key' => 'hazards', 'label' => 'Gefährdungen', 'type' => 'risk_register', 'required' => true],
    ]]);
    $store->publishTemplate($template, 1);
    $document = $store->createDocument($template, '', 'Eigene Maßnahmenfrist-Browserbeurteilung');
    $store->saveDocument($document, 1, $values, 'Eigene Maßnahmenfrist-Browserbeurteilung', '');
    $store->transition($document, 2, 'submit');
    $store->transition($document, 3, 'approve');
    $fixture = ['database' => $config['database'], 'document' => $document, 'template' => $template,
        'date' => '2026-10-25', 'next_date' => '2026-11-05', 'approval_hash' => hash('sha256', serialize($store->document($document, 4))),
        'approval_html_hash' => hash('sha256', $store->export($document, 4)['bytes'])];
}
$current = $store->document($fixture['document']);
if ($current['title'] !== 'Eigene Maßnahmenfrist-Browserbeurteilung') throw new RuntimeException('Owned document title differs.');
if ($current['status'] === 'approved') {
    $store->transition($fixture['document'], $current['version'], 'reopen');
    $current = $store->document($fixture['document']);
}
if ($current['status'] !== 'draft') throw new RuntimeException('Owned document not a draft.');
$store->saveDocument($fixture['document'], $current['version'], $values, $current['title'], '');
$fixture['revision'] = $store->document($fixture['document'])['version'];
$fixture['originals'] = $snapshot($fixture);
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($path, 0600);
echo "Owned action-deadline fixture prepared; approved original retained.\n";
