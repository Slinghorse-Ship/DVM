<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents, Registry};
$config = TestSupport::config();
if (($config['owner'] ?? '') !== 'cloud-onboarding-synthetic' || ($config['label'] ?? '') !== 'integrated-manager') throw new RuntimeException('Owned synthetic manager required.');
$db = TestSupport::db();
$path = dirname(__DIR__, 3) . '/.local/browser-tests/document-controls.json';
$fixture = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : null;
$documents = new Documents($db, 'manager-admin');
if ($fixture && ($fixture['database'] !== $config['database'] || $documents->document($fixture['document'])['title'] !== 'Eigene Dokumentformular-Prüfung')) throw new RuntimeException('Owned document fixture differs.');
$snapshot = static function () use ($db): string {
    $state = [];
    foreach (['documents', 'document_files', 'document_links', 'document_history', 'contacts', 'appointments', 'dwf_documents', 'dwf_document_versions', 'dwf_attachments', 'dwf_signatures', 'dwf_signature_files'] as $table) {
        $keys = Db::run($db, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION", [$table])->fetchAll(PDO::FETCH_COLUMN);
        $state[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY `' . implode('`,`', $keys) . '`')->fetchAll();
    }
    return hash('sha256', serialize($state));
};
$mode = $argv[1] ?? 'prepare';
if ($mode === 'check') {
    if (!$fixture || $snapshot() !== $fixture['originals'] || hash('sha256', $documents->file($fixture['document'])['bytes']) !== $fixture['sha256']) throw new RuntimeException('Original documents, links, files or assessments changed.');
    echo "Original documents, source links, files, contacts, calendar and assessments retained.\n";
    exit;
}
if ($mode !== 'prepare') throw new RuntimeException('Unknown owned fixture action.');
if (!$fixture) {
    $registry = new Registry($db, 'manager-admin');
    $event = $registry->saveAppointment(null, 0, ['title' => 'Eigener Dokumentformular-Termin', 'start' => '2026-10-25T10:00', 'end' => '2026-10-25T11:00', 'category' => 'verein'], []);
    $bytes = "Eigenes Dokumentformular-Original\nUnveränderte Datei für Browserprüfung.\n";
    $id = $documents->upload('Eigenes-Formularoriginal.txt', $bytes, ['title' => 'Eigene Dokumentformular-Prüfung', 'kind' => 'other', 'document_date' => '2026-10-25', 'links' => ['appointment:' . $event]], bin2hex(random_bytes(16)));
    $fixture = ['database' => $config['database'], 'document' => $id, 'event' => $event, 'sha256' => hash('sha256', $bytes)];
}
$fixture['originals'] = $snapshot();
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($path, 0600);
echo "Owned original document and source-link fixture prepared.\n";
