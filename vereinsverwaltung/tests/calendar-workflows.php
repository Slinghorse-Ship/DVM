<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{CalendarFeed, Database, ManagerDocumentWorkflows, Registry};
use Kulturhus\Verein\DocumentWorkflow\WorkflowSchema;
use Kulturhus\Verein\Tests\MySqlFixture;

$db = MySqlFixture::connect(':memory:');
Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','HSE calendar test'),('b','Other club');
INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('foreign','b','members',1),('member','a','member',0),('reader','a','member',0),('editor','a','editor',1);
INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,documents_access,version) VALUES('a','documents','Document reader','','none','none',0,'read',1);
INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('reader','a','documents',1)");
$n = 0;
function check(bool $ok, string $label): void { global $n; if (!$ok) throw new RuntimeException($label); ++$n; echo "PASS $label\n"; }
function denied(callable $fn, string $label): void { try { $fn(); } catch (DomainException) { check(true,$label); return; } throw new RuntimeException($label); }
$feed = static fn(string $actor): CalendarFeed => new CalendarFeed($db,$actor,new Registry($db,$actor));
$calendar = $feed('admin');
check(!isset($calendar->filters()['workflows']), 'optional source hidden before explicit schema installation');
WorkflowSchema::install($db);
$store = (new ManagerDocumentWorkflows($db,'admin'))->store;
$fields = [
    ['key'=>'review_date','label'=>'Nächster Prüftermin','type'=>'date','required'=>false],
    ['key'=>'deadline','label'=>'Termin für die Klärung','type'=>'date','required'=>false],
    ['key'=>'assessment_date','label'=>'Datum der Beurteilung','type'=>'date','required'=>false],
    ['key'=>'notes','label'=>'Interne Notiz','type'=>'textarea','required'=>false],
];
$template = $store->saveTemplate('',0,['title'=>'HSE date fixture','fields'=>$fields]);
$store->publishTemplate($template,1);
$create = static function(string $title, array $values) use ($store,$template): string {
    $id = $store->createDocument($template,'',$title); $store->saveDocument($id,1,$values,$title,''); return $id;
};
$doc = $create('Originale Bühnenbeurteilung',['review_date'=>'2026-10-25','deadline'=>'2026-10-25','assessment_date'=>'2026-10-01','notes'=>'Private medical note excluded']);
$boundary = $create('Separate planning facts',['review_date'=>'2026-10-01','deadline'=>'2026-11-01']);
$store->transition($doc,2,'submit'); $store->transition($doc,3,'approve');
$original = $store->document($doc,4);
$reader = $feed('reader');
check(isset($reader->filters()['workflows']) && !isset($reader->filters()['deadlines']), 'document reader receives only the independent workflow filter');
$before = [];
foreach (['dwf_documents','dwf_document_versions','dwf_activities','dwf_reminders','dwf_signature_files','appointments','audit_events'] as $table) $before[$table] = MySqlFixture::rows($db,$table);
$rows = $calendar->between('2026-10-01','2026-11-01','workflows');
check(count($rows)===2, 'same-day facts grouped and next-month boundary excluded');
check($reader->between('2026-10-01','2026-11-01','workflows')===$rows, 'current module reader sees the same original dates');
check($calendar->between('2026-10-01','2026-11-01','all')===$rows, 'all calendar includes workflow source');
$row = array_values(array_filter($rows,fn($r)=>str_contains($r['id'],$doc)))[0];
check(str_contains($row['title'],'Nächster Prüftermin / Termin für die Klärung'), 'both same-day semantic labels retained');
check($row['route']===['view'=>'documents','tab'=>'workflows','wf_doc'=>$doc,'wf_revision'=>4], 'calendar link pins the actual original revision');
check($row['all_day'] && $row['starts_at']==='2026-10-24T22:00:00Z', 'all-day Berlin date survives the DST boundary');
check($row['location']==='Freigegeben', 'internal approval remains document state without claiming completed work');
check(!str_contains(json_encode($rows),'Private medical') && !str_contains(json_encode($rows),'assessment_date'), 'projection contains neither private values nor ordinary document dates');
foreach ($before as $table=>$records) check(MySqlFixture::rows($db,$table)===$records, 'calendar never writes '.$table);
foreach (['member','editor'] as $actor) {
    check(!isset($feed($actor)->filters()['workflows']), $actor.' receives no general workflow filter');
    denied(fn()=>$feed($actor)->between('2026-10-01','2026-11-01','workflows'), $actor.' cannot grant access through query parameter');
    if ($actor==='editor') denied(fn()=>$feed($actor)->between('2026-10-01','2026-11-01','all'), 'editor has no manager calendar permission');
    else check($feed($actor)->between('2026-10-01','2026-11-01','all')===[], $actor.' cannot bypass source rights through all filter');
}
check($feed('foreign')->between('2026-10-01','2026-11-01','workflows')===[], 'other-club administrator cannot see original source');
$store->transition($doc,4,'reopen');
$store->saveDocument($doc,5,['review_date'=>'2026-11-05','deadline'=>'','assessment_date'=>'2026-10-25'],'Originale Bühnenbeurteilung','');
check(count($calendar->between('2026-10-01','2026-11-01','workflows'))===1, 'editing original moves deadline without leaving stale appointment');
$new = $calendar->between('2026-11-01','2026-12-01','workflows');
check(count($new)===2 && array_values(array_filter($new,fn($r)=>str_contains($r['id'],$doc)))[0]['route']['wf_revision']===6, 'new range uses current revision immediately');
check($store->document($doc,4)===$original, 'earlier approved original is unchanged');
$store->saveTemplate($template,2,['title'=>'Revised template','fields'=>[['key'=>'review_date','label'=>'Narrative review','type'=>'textarea','required'=>false]]]);
$store->publishTemplate($template,3);
check(count($calendar->between('2026-11-01','2026-12-01','workflows'))===2, 'later template changes never alter pinned source date semantics');
denied(fn()=>$store->deadlines('2026-02-30','2026-03-10'), 'source rejects impossible calendar dates');
denied(fn()=>$store->deadlines('2026-01-01','2028-01-01'), 'source rejects unbounded date ranges');
// A key named deadline with a narrative type must never be interpreted as a date.
$textTemplate = $store->saveTemplate('',0,['title'=>'Narrative deadline','fields'=>[['key'=>'deadline','label'=>'Measures','type'=>'textarea','required'=>false]]]);
$store->publishTemplate($textTemplate,1); $textDoc=$store->createDocument($textTemplate,'','Narrative');
$store->saveDocument($textDoc,1,['deadline'=>'2026-10-25'],'Narrative','');
check(count($calendar->between('2026-10-01','2026-11-01','workflows'))===1, 'date-looking narrative deadline stays in its original document');
// Use real source mutations to exceed the general catalogue's 500-row page size.
// Restore a published date template for newly created documents; old ones remain pinned.
$store->saveTemplate($template,4,['title'=>'Date template','fields'=>$fields]); $store->publishTemplate($template,5);
for ($i=0;$i<501;$i++) $create('Bulk original '.$i,['review_date'=>'2026-10-25']);
check(count($calendar->between('2026-10-01','2026-11-01','workflows'))===502, 'calendar does not silently truncate source deadlines at 500 documents');
$db->exec("UPDATE settings_roles SET documents_access='none' WHERE club_id='a' AND id='documents'");
check(!isset($reader->filters()['workflows']), 'existing feed immediately recognizes permission revocation');
denied(fn()=>$reader->between('2026-10-01','2026-11-01','workflows'), 'existing reader cannot retain revoked source rights');
$db->exec("UPDATE actors SET active=0 WHERE id='admin'");
denied(fn()=>$calendar->between('2026-10-01','2026-11-01','workflows'), 'deactivated account loses access on same feed');
check(MySqlFixture::foreignKeyErrors($db)===[], 'source revisions and foreign keys remain consistent');
echo "$n workflow calendar checks passed; original MySQL documents, no generated PDFs, jobs or appointments.\n";
