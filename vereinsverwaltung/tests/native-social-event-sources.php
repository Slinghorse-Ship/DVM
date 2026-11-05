<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . '/src/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Cms\{Pages, Schema};
use Kulturhus\Native\Communication\{EventDrafts, Workspace};
use Kulturhus\Native\Events\Eventbrite;

$db = TestSupport::db();
\Kulturhus\Verein\Database::migrate($db);
TestSupport::seed($db);
Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
$a = new Access($db, "admin");
$pages = new Pages($db, $a);
$sources = new EventDrafts($db, $a);
$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
    echo "PASS $label\n";
}
function denied(callable $f, string $label): void
{
    try {
        $f();
    } catch (DomainException | InvalidArgumentException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
$own = $pages->save([
    "slug" => "veranstaltungen/vereinsfest",
    "title" => "Eigenes Vereinsfest",
    "template" => "event",
    "body" => "<p>Unser eigener <strong>Termin</strong>.</p>",
    "excerpt" => "Gemeinsam feiern",
    "event_start" => "2030-11-14T20:00",
    "event_end" => "2030-11-14T21:00:00Z",
    "event_timezone" => "Europe/Berlin",
    "event_location" => "Vereinssaal",
    "event_address" => "Beispielweg 2",
]);
$private = $pages->save([
    "slug" => "veranstaltungen/privat",
    "title" => "Unveröffentlichter Termin",
    "template" => "event",
    "event_start" => "2030-11-15T20:00",
]);
check(
    $sources->listing()["total"] === 0,
    "unpublished original cannot appear in public event picker",
);
$own = $pages->publish($own["id"], $own["version"]);
$original = $pages->get($own["id"]);
$list = $sources->listing();
check(
    $list["total"] === 1 && $list["rows"][0]["event_source"] === "local",
    "own event is available without an Eventbrite connection",
);
$draft = $sources->create($own["id"], $own["published_version"]);
check(
    $draft["status"] === "draft" &&
        $draft["approved_by"] === null &&
        $draft["title"] === "Eigenes Vereinsfest",
    "event selection creates an unapproved social draft",
);
check(
    str_contains($draft["body"], "14.11.2030 20:00") &&
        str_contains($draft["body"], "22:00") &&
        str_contains($draft["body"], "Vereinssaal Beispielweg 2"),
    "own original time, end and full place seed the social text: " .
        json_encode($draft["body"]),
);
check(
    str_contains($draft["body"], "Unser eigener Termin.") &&
        !str_contains($draft["body"], "<strong>"),
    "public original rich text becomes actual plain caption",
);
check(
    $draft["channels"] === ["facebook", "instagram"],
    "event draft uses the shared channel selection",
);
$origin = $sources->origin($draft["id"]);
check(
    $origin["page_id"] === $own["id"] &&
        $origin["revision"] === $own["published_version"] &&
        $origin["snapshot"]["title"] === "Eigenes Vereinsfest",
    "immutable origin points to exact published event revision",
);
check(
    $pages->get($own["id"]) === $original,
    "preparing social content never mutates the original event",
);
$edit = $pages->get($own["id"]);
$edit["title"] = "Private neuere Bearbeitung";
$pages->save($edit, $edit["version"]);
$another = $sources->create($own["id"], $own["published_version"]);
check(
    $another["title"] === "Eigenes Vereinsfest",
    "private newer edit is not leaked into the social draft",
);
$p = $pages->get($own["id"]);
$new = $pages->publish($p["id"], $p["version"]);
denied(
    fn() => $sources->create($own["id"], $own["published_version"]),
    "stale event choice cannot use a newer published revision silently",
);
check(
    $sources->origin($draft["id"])["snapshot"]["title"] ===
        "Eigenes Vereinsfest" &&
        (new Workspace($db, $a, "social"))->get($draft["id"])["title"] ===
            "Eigenes Vereinsfest",
    "later event publication cannot rewrite saved social content or origin",
);
$raw = [
    "id" => "1008",
    "name" => ["text" => "Importierter Termin"],
    "description" => ["text" => "Externe Ergänzung"],
    "start" => ["utc" => "2030-11-16T19:00:00Z", "timezone" => "Europe/Berlin"],
    "end" => ["utc" => "2030-11-16T21:00:00Z"],
    "venue" => ["name" => "Andere Bühne"],
    "status" => "live",
    "url" => "https://www.eventbrite.de/e/1008",
];
$import = (new Eventbrite($db, $a))->import([Eventbrite::normalize($raw)])[0];
$p = $pages->get($import["id"]);
$p = $pages->publish($p["id"], $p["version"]);
check(
    $sources->listing()["total"] === 2 &&
        $sources->create($p["id"], $p["published_version"])["title"] ===
            "Importierter Termin",
    "imported and own events share the same original draft workflow",
);
denied(
    fn() => $sources->create($private["id"], $private["version"]),
    "unpublished event cannot be forged through POST",
);
denied(
    fn() => (new EventDrafts($db, new Access($db, "outsider")))->create(
        $new["id"],
        $new["published_version"],
    ),
    "event source cannot cross club boundary",
);
denied(
    fn() => (new EventDrafts($db, new Access($db, "reader")))->create(
        $new["id"],
        $new["published_version"],
    ),
    "read-only user cannot prepare a draft",
);
denied(
    fn() => (new EventDrafts($db, new Access($db, "outsider")))->origin(
        $draft["id"],
    ),
    "source snapshot cannot cross club boundary",
);
$free = (new Workspace($db, $a, "social"))->save([
    "title" => "Freier Vereinsbeitrag",
    "body" => "Ohne Veranstaltung oder Eventbrite.",
]);
check(
    $sources->origin($free["id"]) === null && $free["status"] === "draft",
    "free social post remains independent of event sources",
);
$pages->unpublish($new["id"], $new["version"]);
denied(
    fn() => $sources->create($new["id"], $new["published_version"]),
    "withdrawn event cannot seed another draft",
);
check(
    $sources->origin($draft["id"])["snapshot"]["title"] ===
        "Eigenes Vereinsfest",
    "withdrawal preserves the already saved origin history",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='read'",
);
denied(
    fn() => (new EventDrafts($db, new Access($db, "editor")))->listing(),
    "website access is checked independently of social editing",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_delivery_jobs")
        ->fetchColumn() === 0,
    "source preparation creates no provider publication job",
);
// Existing manager calendar originals stay in their original tables and use calendar authority.
\Kulturhus\Native\Integration\Schema::install($db);
Db::run($db,"INSERT INTO clubs VALUES('social-calendar-a','Calendar A'),('social-calendar-b','Calendar B')");
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('calendar-social-admin','social-calendar-a','members',1),('calendar-social-foreign','social-calendar-b','members',1),('calendar-social-editor','social-calendar-a','editor',1)");
$managerAccess=(new \Kulturhus\Native\Integration\ManagerBridge($db,'calendar-social-admin','native',static fn()=>null))->access();
$managerSources=new EventDrafts($db,$managerAccess);
$registry=new \Kulturhus\Verein\Registry($db,'calendar-social-admin');
$person=$registry->saveContact(null,0,'person','Private attendee','','');
$data=['title'=>'Eigener Kalendertermin %_ Übung','start'=>'2050-10-10T18:00','end'=>'2050-10-10T19:30','location'=>'Eigener Saal','description'=>'Eigene Details & unveränderte Hinweise','activity_kind'=>'event'];
$calendarId=$registry->saveAppointment(null,0,$data,[$person]);
$calendarOriginal=$registry->appointment($calendarId);
check(isset($managerSources->sources()['manager']), 'connected manager exposes its permitted original calendar source');
$list=$managerSources->listing(['source'=>'manager']);
check($list['total']===1 && $list['rows'][0]['id']===$calendarId && $list['rows'][0]['event_source']==='manager', 'internal calendar original appears without website publication or Eventbrite');
check($managerSources->listing(['source'=>'manager','q'=>'%_ übung'])['total']===1 && $managerSources->listing(['source'=>'manager','q'=>'missing'])['total']===0,'calendar source search treats Unicode and wildcard characters literally');
denied(fn()=>$managerSources->create($calendarId,1,'manager'),'internal original requires explicit intentional social preparation');
$calendarDraft=$managerSources->create($calendarId,1,'manager',true);
check($calendarDraft['status']==='draft' && $calendarDraft['approved_by']===null && $calendarDraft['destination_url']==='', 'calendar source creates unapproved draft without a private manager URL');
check(str_contains($calendarDraft['body'],'10.10.2050 18:00') && str_contains($calendarDraft['body'],'19:30') && str_contains($calendarDraft['body'],$data['description']) && str_contains($calendarDraft['body'],'Eigener Saal'),'original calendar times, place and details become the actual caption');
$calendarOrigin=$managerSources->origin($calendarDraft['id']);
check($calendarOrigin['source_kind']==='manager' && $calendarOrigin['revision']===1 && $calendarOrigin['snapshot']['_manager_original']['id']===$calendarId,'origin preserves exact manager event and version separately from website sources');
check(!str_contains(json_encode($calendarOrigin),'Private attendee') && !isset($calendarOrigin['snapshot']['_manager_original']['attendees']),'participants and responses are not copied into social origin history');
check($registry->appointment($calendarId)===$calendarOriginal,'preparing a calendar draft leaves the complete original and responses unchanged');
$registry->saveAppointment($calendarId,1,array_replace($data,['title'=>'Spätere private Kalenderfassung']),[$person]);
denied(fn()=>$managerSources->create($calendarId,1,'manager',true),'changed calendar version cannot silently replace the selected original');
check($managerSources->origin($calendarDraft['id'])===$calendarOrigin,'later calendar editing cannot rewrite social source history');
// A composed caller may already hold a repeatable-read snapshot. The source row
// must still use its current locked version after a concurrent edit commits.
$db->beginTransaction();
try {
    Db::run($db,'SELECT version FROM appointments WHERE club_id=? AND id=?',['social-calendar-a',$calendarId])->fetchColumn();
    $concurrent=TestSupport::db();
    Db::run($concurrent,'UPDATE appointments SET title=?,version=version+1 WHERE club_id=? AND id=?',['Concurrent original calendar update','social-calendar-a',$calendarId]);
    denied(fn()=>$managerSources->create($calendarId,2,'manager',true),'repeatable-read snapshot cannot conceal a concurrently committed calendar revision');
} finally {
    $db->rollBack();
}
$cancelled=$registry->saveAppointment(null,0,array_replace($data,['title'=>'Abgesagter eigener Termin','activity_state'=>'cancelled']),[]);
check(str_starts_with($managerSources->create($cancelled,1,'manager',true)['body'],'Abgesagt: '),'cancelled internal calendar original keeps its actual status');
$foreignAccess=(new \Kulturhus\Native\Integration\ManagerBridge($db,'calendar-social-foreign','native',static fn()=>null))->access();
denied(fn()=>(new EventDrafts($db,$foreignAccess))->create($calendarId,3,'manager',true),'original calendar selection cannot cross manager club boundaries');
denied(fn()=>$managerSources->create($calendarId,2,'invented',true),'forged event source is rejected before a draft is created');
denied(fn()=>$sources->create($calendarId,2,'manager',true),'standalone native website identity cannot impersonate a manager calendar identity');
$editorAccess=(new \Kulturhus\Native\Integration\ManagerBridge($db,'calendar-social-editor','native',static fn()=>null))->access();
check(!isset((new EventDrafts($db,$editorAccess))->sources()['manager']),'social editor without calendar authority does not acquire calendar visibility');
denied(fn()=>(new EventDrafts($db,$editorAccess))->create($calendarId,2,'manager',true),'social editing is not permission to copy inaccessible calendar content');
for($index=0;$index<22;$index++) $registry->saveAppointment(null,0,array_replace($data,['title'=>'Weitere Kalenderquelle '.$index,'start'=>'2050-10-11T18:00','end'=>'2050-10-11T19:30']),[]);
$first=$managerSources->listing(['source'=>'manager']);$second=$managerSources->listing(['source'=>'manager','seite'=>'2']);
check($first['total']===24 && count($first['rows'])===20 && count($second['rows'])===4 && !array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')),'original calendar source is counted and paginated in MySQL without duplicated page rows');
denied(fn()=>$managerSources->listing(['source'=>'manager','q'=>['bad']]),'calendar source rejects array-shaped search filters');
denied(fn()=>$managerSources->listing(['source'=>'manager','seite'=>'-1']),'calendar source rejects invalid page offsets');
$past=$registry->saveAppointment(null,0,array_replace($data,['title'=>'Vergangener Kalendertermin','start'=>'2000-10-10T18:00','end'=>'2000-10-10T19:30','activity_state'=>'completed']),[]);
check($managerSources->listing(['source'=>'manager','archiv'=>'1'])['total']===1,'past calendar originals remain available through the archive filter');
check($managerSources->listing(['source'=>'manager','archiv'=>'1'])['rows'][0]['event_status']==='ended','completed calendar original is not mislabeled as a planned event');
$registry->deleteAppointment($calendarId,3);
denied(fn()=>$managerSources->create($calendarId,3,'manager',true),'deleted calendar original cannot seed a new draft');
check($managerSources->origin($calendarDraft['id'])===$calendarOrigin,'deleting the calendar original preserves already prepared social history');
Db::run($db,'UPDATE actors SET is_superuser=0 WHERE id=?',['calendar-social-admin']);
$socialOnly=array_fill_keys(array_keys(\Kulturhus\Verein\EditorialPolicy::AREAS),'none');$socialOnly['social']='write';
Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('social-calendar-a','social-only','Social/calendar','','read','none',0,?,1)",[json_encode($socialOnly,JSON_THROW_ON_ERROR)]);
Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('calendar-social-admin','social-calendar-a','social-only',1)");
check(array_keys($managerSources->sources())===['manager'],'calendar source is available with social and calendar authority without website read permission');
check($managerSources->create($cancelled,1,'manager',true)['status']==='draft','social/calendar account can prepare its own original without a website permission workaround');
Db::run($db,"UPDATE settings_roles SET members_access='none' WHERE club_id='social-calendar-a' AND id='social-only'");
denied(fn()=>$managerSources->create($cancelled,1,'manager',true),'current calendar permission revocation blocks another preparation despite retained social editing');
check(!$managerSources->sources(),'calendar permission revocation removes the source immediately');
check((int)$db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()===0,'manager event preparations never create or execute a provider publication job');
echo "$n social event-source checks passed; own originals, imported originals and free posts in fresh MySQL.\n";
