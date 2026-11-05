<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/native/bootstrap.php';

use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{CalendarFeed, Database, DocumentWorkflowPage, ManagerDocumentWorkflows, Registry};
use Kulturhus\Verein\DocumentWorkflow\{RiskRegister, WorkflowDeadlines, WorkflowSchema};

$db = TestSupport::db();
Database::migrate($db);
WorkflowSchema::install($db);
$db->exec("INSERT INTO clubs VALUES('hse-a','Own synthetic HSE'),('hse-b','Other synthetic club');
    INSERT INTO actors(id,club_id,role,is_superuser) VALUES
    ('author','hse-a','member',1),('reader','hse-a','member',0),('member','hse-a','member',0),('foreign','hse-b','member',1);
    INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,documents_access,version)
    VALUES('hse-a','document-reader','Synthetic HSE reader','','none','none',0,'read',1);
    INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('reader','hse-a','document-reader',1)");
$store = (new ManagerDocumentWorkflows($db, 'author'))->store;
$calendar = new CalendarFeed($db, 'author', new Registry($db, 'author'));
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
    echo "PASS $label\n";
}
function denied(callable $call, string $label): void {
    try { $call(); } catch (DomainException) { check(true, $label); return; }
    throw new RuntimeException($label);
}
$row = ['category' => 'Aufbau', 'hazard' => 'Private original hazard', 'impact' => 'Sturz',
    'initial' => ['likelihood' => 3, 'severity' => 3], 'controls' => 'Private original measures; Freitext 2026-10-25',
    'residual' => ['likelihood' => 1, 'severity' => 2], 'responsible' => 'Private original person', 'effectiveness' => 'Sichtprüfung'];
check(RiskRegister::validate([$row], true, true) === [$row], 'legacy eight-column rows retain their exact original shape');
foreach (RiskRegister::DATE_LABELS as $key => $label) {
    foreach (['2026-02-30', '2026-13-01', '0000-01-01', '2026-1-01', '2026-01-1', "2026-10-25\0", '25.10.2026', '2026-10-25T12:00', null, [], 1792882800, '<b>2026-10-25</b>'] as $invalid) {
        denied(fn() => RiskRegister::validate([array_replace($row, [$key => $invalid])]), 'invalid structured date rejected: ' . $key . ' ' . get_debug_type($invalid));
    }
}
check(RiskRegister::validate([array_replace($row, ['action_deadline' => '2028-02-29'])])[0]['action_deadline'] === '2028-02-29', 'real leap date accepted');
denied(fn() => RiskRegister::validate([array_replace($row, ['unexpected_date' => '2026-10-25'])]), 'unknown row fields still rejected');
denied(fn() => RiskRegister::validate([array_diff_key($row, ['controls' => true])]), 'required original columns remain required');
denied(fn() => RiskRegister::validate(array_fill(0, 31, $row)), 'existing thirty-row bound remains enforced');

$fields = [
    ['key' => 'review_date', 'label' => 'Nächster Prüftermin', 'type' => 'date', 'required' => false],
    ['key' => 'danger_rows', 'label' => 'Gefährdungen', 'type' => 'risk_register', 'required' => true],
    ['key' => 'secondary_rows', 'label' => 'Weitere Gefährdungen', 'type' => 'risk_register', 'required' => false],
];
$template = $store->saveTemplate('', 0, ['title' => 'Typed action dates', 'fields' => $fields]);
$store->publishTemplate($template, 1);
$legacy = $store->createDocument($template, '', 'Legacy original without structured dates');
$store->saveDocument($legacy, 1, ['danger_rows' => [$row]], 'Legacy original without structured dates', '');
$store->transition($legacy, 2, 'submit');
$store->transition($legacy, 3, 'approve');
$legacyOriginal = $store->document($legacy, 4);
$legacyHtml = $store->export($legacy, 4)['bytes'];

$id = $store->createDocument($template, '', 'Originale Maßnahmenbeurteilung');
$values = ['review_date' => '2026-10-25', 'danger_rows' => [
    array_replace($row, ['action_deadline' => '2026-10-25']),
    $row,
    array_replace($row, ['action_deadline' => '2026-10-25', 'action_completed_on' => '2026-10-23']),
    array_replace($row, ['action_deadline' => '2026-11-01']),
], 'secondary_rows' => [array_replace($row, ['action_deadline' => '2026-10-25'])]];
$store->saveDocument($id, 1, $values, 'Originale Maßnahmenbeurteilung', '');
$store->transition($id, 2, 'submit');
$store->transition($id, 3, 'approve');
$approved = $store->document($id, 4);
$snapshot = static function () use ($db): string {
    $rows = [];
    foreach (['dwf_documents', 'dwf_document_versions', 'dwf_activities', 'dwf_reminders', 'dwf_signature_files', 'appointments', 'audit_events'] as $table) {
        $keys = Db::run($db, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION", [$table])->fetchAll(PDO::FETCH_COLUMN);
        $rows[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY `' . implode('`,`', $keys) . '`')->fetchAll();
    }
    return hash('sha256', serialize($rows));
};
$before = $snapshot();
$rows = $calendar->between('2026-10-01', '2026-11-01', 'workflows');
check(count($rows) === 1, 'same-day review and two open measures share one canonical calendar entry');
$entry = $rows[0];
check(str_contains($entry['title'], 'Maßnahmenfrist · Gefährdungen · Zeile 1') && str_contains($entry['title'], 'Maßnahmenfrist · Weitere Gefährdungen · Zeile 1'), 'arbitrary typed register keys and multiple register fields are found');
check(!str_contains($entry['title'], 'Zeile 3') && !str_contains($entry['title'], 'Zeile 4'), 'completed and out-of-range measures excluded');
check(str_contains($entry['title'], 'Nächster Prüftermin') && $entry['location'] === 'Freigegeben', 'document approval preserves open measures and independent review date');
check($entry['route'] === ['view' => 'documents', 'tab' => 'workflows', 'wf_doc' => $id, 'wf_revision' => 4], 'calendar route pins the actual original document revision');
check($entry['all_day'] && $entry['starts_at'] === '2026-10-24T22:00:00Z', 'all-day action date uses Europe/Berlin at the DST boundary');
check(!str_contains(json_encode($rows), 'Private original'), 'calendar never projects hazard text, measures or responsible-person values');
check(count($calendar->between('2026-11-01', '2026-12-01', 'workflows')) === 1, 'exclusive month boundary keeps next-month measure in its actual month');
$reader = new CalendarFeed($db, 'reader', new Registry($db, 'reader'));
check($reader->between('2026-10-01', '2026-11-01', 'workflows') === $rows, 'current module reader sees the same narrow calendar metadata');
check($snapshot() === $before, 'calendar reads create no revisions, activities, signatures, jobs or appointments');
check($calendar->between('2026-10-25', '2026-10-26', 'workflows') === $rows, 'single-day original selection remains bounded and complete');

$html = $store->export($id, 4)['bytes'];
check(str_contains($html, "Maßnahmenfrist: 2026-10-25") && str_contains($html, 'Erledigt am: 2026-10-23'), 'print and PDF source includes actual structured dates beside the original measures');
$json = json_decode($store->export($id, 4, 'json')['bytes'], true, 64, JSON_THROW_ON_ERROR);
check($json['document']['values']['danger_rows'] === $approved['values']['danger_rows'] && $json['document']['values']['danger_rows'][0]['controls'] === $row['controls'], 'structured export retains exact stored typed row dates and original texts');
$asForm = static function (mixed $value) use (&$asForm): mixed {
    return is_array($value) ? array_map($asForm, $value) : (is_int($value) ? (string)$value : $value);
};
denied(fn() => DocumentWorkflowPage::handle($store, ['action' => 'document-workflow-document-save', 'wf_id' => $id, 'wf_version' => '4', 'wf_values_complete' => '1', 'wf_title' => 'Cannot rewrite approval', 'wf_values' => $asForm($values)]), 'form adapter cannot rewrite an approved original');

$store->transition($id, 4, 'reopen');
$changed = $values;
$changed['danger_rows'][0]['action_completed_on'] = '2026-10-26';
$changed['secondary_rows'][0]['action_deadline'] = '2026-11-05';
$post = ['action' => 'document-workflow-document-save', 'wf_id' => $id, 'wf_version' => '5', 'wf_values_complete' => '1',
    'wf_title' => 'Originale Maßnahmenbeurteilung', 'wf_values' => $asForm($changed)];
check(DocumentWorkflowPage::handle($store, $post) === ['wf_doc' => $id], 'real form adapter stores dates in a new original revision');
$current = $store->document($id);
check($current['version'] === 6 && $current['values']['danger_rows'][0]['action_completed_on'] === '2026-10-26', 'completion is versioned with the exact original row');
$october = $calendar->between('2026-10-01', '2026-11-01', 'workflows');
check(count($october) === 1 && !str_contains($october[0]['title'], 'Maßnahmenfrist') && str_contains($october[0]['title'], 'Nächster Prüftermin'), 'completing measure removes its due entry while document review remains');
check($october[0]['route']['wf_revision'] === 6, 'current date entry opens the new actual revision immediately');
check(count($calendar->between('2026-11-01', '2026-12-01', 'workflows')) === 2, 'editing source moves only its own action date without a stale copy');
check($store->document($id, 4) === $approved, 'earlier approved measure rows remain byte-for-byte unchanged');
check($store->document($legacy, 4) === $legacyOriginal && $store->export($legacy, 4)['bytes'] === $legacyHtml, 'legacy original and exported HTML retain their exact bytes');

$post['wf_version'] = '6';
$post['wf_values']['danger_rows'][0]['action_completed_on'] = '';
$post['wf_values']['danger_rows'][0]['action_deadline'] = '';
DocumentWorkflowPage::handle($store, $post);
check(!isset($store->document($id)['values']['danger_rows'][0]['action_deadline']), 'clearing optional dates through the form removes the dates without invented empty columns');
denied(fn() => DocumentWorkflowPage::handle($store, $post), 'stale tab cannot overwrite new action dates');
$post['wf_version'] = '7';
$post['wf_values']['danger_rows'][0]['action_deadline'] = '2026-02-30';
denied(fn() => DocumentWorkflowPage::handle($store, $post), 'impossible date from actual form adapter is rejected before persistence');
check($store->document($id)['version'] === 7, 'invalid form date creates no partial revision');
$store->saveTemplate($template, 2, ['title' => 'New template without registers', 'fields' => [
    ['key' => 'danger_rows', 'label' => 'Narrative measures', 'type' => 'textarea', 'required' => false],
]]);
$store->publishTemplate($template, 3);
check(count($calendar->between('2026-11-01', '2026-12-01', 'workflows')) === 2, 'later template changes retain pinned original register semantics');

$wfEsc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
require dirname(__DIR__) . '/views/Documents/document-workflow-risk-register.php';
$wfRegister('wf_values[danger_rows]', $values['danger_rows'], true);
$form = ob_get_clean();
check(str_contains($form, 'type="date"') && str_contains($form, 'Zeile 1: Maßnahmenfrist') && str_contains($form, 'Zeile 1: Erledigt am'), 'actual reusable row renderer labels both optional date controls');
check(str_contains($form, 'data-dwf-register-path="action_deadline"') && str_contains($form, 'value="2026-10-25"'), 'dynamic row template retains typed path and original date value');

denied(fn() => (new ManagerDocumentWorkflows($db, 'reader'))->store->saveDocument($id, 7, $values, 'Unauthorized', ''), 'read-only module grant cannot edit measures');
denied(fn() => (new CalendarFeed($db, 'member', new Registry($db, 'member')))->between('2026-10-01', '2026-11-01', 'workflows'), 'member cannot gain access through a calendar filter');
check((new CalendarFeed($db, 'foreign', new Registry($db, 'foreign')))->between('2026-10-01', '2026-11-01', 'workflows') === [], 'foreign club receives no source dates');
$db->exec("UPDATE settings_roles SET documents_access='none' WHERE id='document-reader'");
denied(fn() => $reader->between('2026-10-01', '2026-11-01', 'workflows'), 'current reader loses revoked calendar rights immediately');
check(WorkflowDeadlines::dates(['fields' => [['key' => 'notes', 'type' => 'textarea', 'label' => 'Notes']], 'values' => ['notes' => '2026-10-25']], '2026-10-01', '2026-11-01') === [], 'date-looking narrative never becomes an action date');
$standardBefore=(int)$db->query('SELECT COUNT(*) FROM dwf_documents')->fetchColumn();$standardIds=[];
foreach(\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::all()as$key=>$definition){$templateId=\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,$key);$standardIds[]=$templateId;$template=$store->template($templateId);check($template['status']==='published'&&$template['published_version']===2&&!str_contains($template['title'],'Demo'),'standard '.$key.' is a real published blank assessment template');check(\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,$key)===$templateId,'standard '.$key.' reopening never installs a duplicate');}
check(count(array_unique($standardIds))===6&&(int)$db->query('SELECT COUNT(*) FROM dwf_documents')->fetchColumn()===$standardBefore,'six useful standard templates create no assessment or approval');
denied(fn()=>\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,'unknown'),'only packaged standard templates can be installed');
// A later library definition must not replace an earlier edited or published template.
$colourTemplate=$store->template($standardIds[0]);$priorDefinition=array_intersect_key($colourTemplate,array_flip(['title','description','fields','provenance']));$priorDefinition['provenance']['adaptation']=str_replace('(Fassung 2)','(Fassung 1)',$priorDefinition['provenance']['adaptation']);
$store->saveTemplate($colourTemplate['id'],$colourTemplate['version'],$priorDefinition);$oldWorking=$store->template($colourTemplate['id']);
check(\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,'de-office')===$colourTemplate['id']&&$store->template($colourTemplate['id'])===$oldWorking,'new library keeps the exact earlier-version working template instead of duplicating or overwriting it');
$colourId=$store->createDocument($standardIds[1],'','Own colour matrix assessment');$colourRows=[];foreach([[1,1],[2,3],[4,4]]as[$likelihood,$severity])$colourRows[]=array_replace($row,['initial'=>['likelihood'=>$likelihood,'severity'=>$severity]]);
$store->saveDocument($colourId,1,['risk_register'=>$colourRows],'Own colour matrix assessment','');$colourOriginal=$store->document($colourId,2);$colourJson=json_decode($store->export($colourId,2,'json')['bytes'],true,64,JSON_THROW_ON_ERROR);
check(array_column($colourJson['risk_register_calculations']['risk_register'],'initial')===[['likelihood'=>1,'severity'=>1,'score'=>1,'profile'=>'rwe_reference_de_v1','band'=>'low','band_label'=>'Niedrig'],['likelihood'=>2,'severity'=>3,'score'=>6,'profile'=>'rwe_reference_de_v1','band'=>'medium','band_label'=>'Mittel'],['likelihood'=>4,'severity'=>4,'score'=>16,'profile'=>'rwe_reference_de_v1','band'=>'high','band_label'=>'Hoch']],'actual saved assessment classifies green yellow and red scores with the exact pinned original matrix');
$colourHtml=$store->export($colourId,2)['bytes'];check(substr_count($colourHtml,'class="dwf-rwe-score"')===25&&str_contains($colourHtml,'data-risk-band="low"')&&str_contains($colourHtml,'data-risk-band="medium"')&&str_contains($colourHtml,'data-risk-band="high"'),'actual assessment HTML contains all 25 matrix cells and all three labelled colour bands');
$colourPdf=$store->export($colourId,2,'pdf');check(str_starts_with($colourPdf['bytes'],'%PDF-')&&strlen($colourPdf['bytes'])>5000&&$store->document($colourId,2)===$colourOriginal,'actual coloured assessment PDF renders without changing its source values original revision or state');
foreach($standardIds as$standardId){$standard=$store->templateVersion($standardId,2);$risk=current(array_filter($standard['fields'],static fn(array $field):bool=>$field['type']==='risk_register'));check($risk['matrix_profile']==='rwe_reference_de_v1','each published standard template pins the retained German colour matrix');}

echo json_encode(['checks' => $checks, 'storage' => 'fresh synthetic MySQL', 'external_actions' => 0], JSON_THROW_ON_ERROR) . "\n";
