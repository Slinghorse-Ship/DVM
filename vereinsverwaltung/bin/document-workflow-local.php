<?php declare(strict_types=1);
// Explicit development utility. No runtime, remote or ProcessWire database is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
use Kulturhus\Verein\DocumentWorkflow\{WorkflowStore, WorkflowSchema};
$lock = null;
$exitCode = 0;
try {
    require_once __DIR__.'/../tests/support/document-workflow-fixture.php';
    require_once __DIR__.'/../tests/support/document-workflow-demo-access.php';
    require_once __DIR__.'/../src/DocumentWorkflow/WorkflowStore.php';
    require_once __DIR__.'/../src/DocumentWorkflow/WorkflowSchema.php';
    if (($argv[1] ?? '') !== 'init') {
        echo "Usage: php vereinsverwaltung/bin/document-workflow-local.php init\n";
        $exitCode = 1;
    } else {
        $lock = DocumentWorkflowFixture::initializationLock();
        $registration = DocumentWorkflowFixture::registryDirectory().'/demo.json';
        if (file_exists($registration) || is_link($registration)) {
            $existing = DocumentWorkflowFixture::demoRegistration();
            WorkflowSchema::install(DocumentWorkflowFixture::connection($existing['database']));
            echo "Eigene lokale Workflow-Demo ist registriert. Schema ergänzt; Inhalte bleiben erhalten.\n";
        } else {
            $name = DocumentWorkflowFixture::create();
            $db = DocumentWorkflowFixture::connection($name);
            WorkflowSchema::install($db);
            $access = new DocumentWorkflowDemoAccess();
            $store = new WorkflowStore($db, $access);
            $folder = $store->saveFolder('', 0, 'Demo · Sicherheit');
            $templateBytes = @file_get_contents(__DIR__.'/../resources/document-workflows/hse-templates.json');
            if ($templateBytes === false) throw new RuntimeException('Lokale Workflow-Vorlagen fehlen.');
            $templates = json_decode($templateBytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($templates)) throw new RuntimeException('Lokale Workflow-Vorlagen sind ungültig.');
            $created = [];
            foreach ($templates as $index => $template) {
                $id = $store->saveTemplate('', 0, array_intersect_key($template, array_flip(['title', 'description', 'fields', 'provenance'])));
                $store->publishTemplate($id, $store->template($id)['version']);
                $doc = $store->createDocument($id, $folder, $index === 0 ? 'Demo · Vorbereitung des Vereinsraums' : 'Demo · Material für Workshop');
                if ($index === 1) {
                    $row = $store->document($doc);
                    $values = [];
                    foreach ($row['fields'] as $field) {
                        $values[$field['key']] = match ($field['type']) {
                            'date' => '2026-10-07', 'number' => '1', 'checkbox' => true, 'select' => $field['options'][0],
                            default => 'Synthetische Demo: '.$field['label'].'. Betriebliche Angaben und tatsächliche Prüfung fehlen.'
                        };
                    }
                    $store->saveDocument($doc, $row['version'], $values, $row['title'], $folder);
                    $store->assignDocument($doc, $store->document($doc)['version'], 'wf-assignee', 'wf-reviewer');
                    $access->id = 'wf-assignee';
                    $store->transition($doc, $store->document($doc)['version'], 'submit', 'Synthetischer Prüfauftrag für die lokale Demo.');
                    $access->id = 'wf-author';
                }
                $created[] = ['key' => $template['key'], 'template' => $id, 'document' => $doc];
            }
            DocumentWorkflowFixture::writeRegistration('demo.json', ['database' => $name, 'owner' => DocumentWorkflowFixture::OWNER, 'created_at' => gmdate('c'), 'templates' => $created]);
            echo "Eigene lokale MySQL-Workflow-Demo eingerichtet: ".count($created)." Vorlagen und synthetische Dokumente.\n";
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Lokale Workflow-Einrichtung fehlgeschlagen. Bestehende Registrierungen und Datenbanken bleiben erhalten.\n");
    error_log('Workflow initialization error: '.get_class($error));
    $exitCode = 1;
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($exitCode);
