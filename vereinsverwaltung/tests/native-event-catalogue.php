<?php
declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/Events/WebsiteCalendar.php";
require __DIR__.'/support/original-database-hash.php';
require __DIR__.'/support/event-cancellation-http.php';

use Kulturhus\Native\Cms\{
    Conflict,
    EventDetails,
    Media,
    Pages,
    PathHistory,
    PublishedEvents,
    Schema,
    Templates,
};
use Kulturhus\Native\Events\Eventbrite;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\{Cache, Feeds, Manager, Site};

$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$pages = new Pages($db, $access);
$catalogue = $pages->events();
$other = new Pages($db, new Access($db, "outsider"));
$root = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/runtime-" . TestSupport::config()["database"];
mkdir($root, 0700);
$media = new Media($db, $access, $root . "/media");
$feeds = new Feeds($pages, "https://example.invalid", "Synthetischer Verein");
$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL " . $label);
    }
    $checks++;
    echo "PASS $label\n";
}
function rejects(callable $f, string $label): void
{
    try {
        $f();
    } catch (InvalidArgumentException | DomainException | Conflict) {
        check(true, $label);
        return;
    }
    throw new RuntimeException("FAIL " . $label);
}
function publish(Pages $pages, array $data): array
{
    $row = $pages->save($data);
    return $pages->publish($row["id"], $row["version"]);
}
function unfolded(string $value): string
{
    return preg_replace('/\r\n[ \t]/', "", $value);
}
$now = new DateTimeImmutable("2030-01-10T15:00:00+01:00");
check(
    $catalogue->listing([], $now)["total"] === 0,
    "empty programme has no invented event",
);
$container = publish($pages, [
    "slug" => "veranstaltungen",
    "title" => "Veranstaltungen",
    "excerpt" => "Vereinsprogramm",
]);
$ids = [];
for ($i = 0; $i < 25; $i++) {
    $day = 12 + $i;
    $date = (new DateTimeImmutable("2030-01-01"))
        ->modify("+" . ($day - 1) . " days")
        ->format("Y-m-d");
    $ids[] = publish($pages, [
        "slug" => "veranstaltungen/test-" . $i,
        "title" => "Öffentlicher Termin " . $i,
        "template" => "event",
        "event_start" => $date . "T19:30",
        "event_location" => $i === 7 ? "Bühne %_ literal.*" : "Vereinssaal",
        "body" => $i === 8 ? "ÖFFENTLICHE Beschreibung" : "Programm",
        "event_address" => "Testweg 1",
    ])["id"];
}
$past = publish($pages, [
    "slug" => "veranstaltungen/vergangen",
    "title" => "Vergangener Termin",
    "template" => "event",
    "event_start" => "2029-12-31T23:30",
]);
$undated = publish($pages, [
    "slug" => "veranstaltungen/offen",
    "title" => "Termin folgt",
    "template" => "event",
]);
$draft = $pages->save([
    "slug" => "veranstaltungen/privat",
    "title" => "PRIVATE-DRAFT",
    "template" => "event",
    "event_start" => "2030-01-14T19:30",
    "body" => "PRIVATE-TEXT",
]);
publish($other, [
    "slug" => "veranstaltungen/fremd",
    "title" => "FOREIGN-CLUB",
    "template" => "event",
    "event_start" => "2030-01-14T19:30",
]);
$list = $catalogue->listing([], $now);
check(
    $list["total"] === 25 &&
        count($list["rows"]) === 20 &&
        $list["pages"] === 2 &&
        $list["rows"][0]["id"] === $ids[0],
    "programme counts originals and returns exactly the first twenty",
);
$second = $catalogue->listing(["seite" => "2"], $now);
check(
    count($second["rows"]) === 5 && $second["rows"][0]["id"] === $ids[20],
    "second programme page continues original order",
);
check(
    $catalogue->listing(["seite" => "999999999"], $now)["page"] === 2,
    "out-of-range page is clamped to the final actual page",
);
check(
    $catalogue->listing(["sort" => "desc"], $now)["rows"][0]["id"] === $ids[24],
    "explicit reverse sort retains stable original identity",
);
$archive = $catalogue->listing(["archiv" => "1"], $now);
check(
    $archive["total"] === 1 && $archive["rows"][0]["id"] === $past["id"],
    "archive uses Berlin midnight and excludes undated/draft/foreign originals",
);
check(
    $catalogue->listing(["jahr" => "2029"], $now)["total"] === 0 &&
        $catalogue->listing(["archiv" => "1", "jahr" => "2029"], $now)[
            "total"
        ] === 1,
    "year and archive filters combine",
);
check(
    $list["years"] === [2030, 2029],
    "year choices come from all dated published originals",
);
check(
    $catalogue->listing(["q" => "%_ literal.*"], $now)["rows"][0]["id"] ===
        $ids[7],
    "search treats SQL and regex metacharacters literally",
);
check(
    $catalogue->listing(["q" => "öffentliche beschreibung"], $now)["rows"][0][
        "id"
    ] === $ids[8],
    "Unicode search matches the published description",
);
check(
    $catalogue->listing(["q" => "Testweg"], $now)["total"] === 25,
    "published venue address is searchable",
);
check(
    $catalogue->listing(["q" => "PRIVATE"], $now)["total"] === 0 &&
        $catalogue->listing(["q" => "FOREIGN"], $now)["total"] === 0,
    "filter counts never include draft or foreign content",
);
foreach (
    [
        ["q" => ["bad"]],
        ["sort" => ["bad"]],
        ["seite" => "-1"],
        ["q" => str_repeat("ä", 151)],
        ["q" => "bad\nquery"],
        ["q" => "\xff"],
    ]
    as $query
) {
    rejects(
        fn() => $catalogue->listing($query, $now),
        "invalid public programme query rejected",
    );
}
$edited = $pages->get($ids[0]);
$edited = $pages->save(
    array_replace($edited, [
        "slug" => "private-rename",
        "title" => "PRIVATE-RENAME",
        "event_start" => "2040-01-01T10:00",
        "event_ticket_url" => "https://example.invalid/private-ticket",
        "event_status" => "cancelled",
    ]),
    $edited["version"],
);
check(
    $catalogue->listing([], $now)["rows"][0]["title"] ===
        "Öffentlicher Termin 0" &&
        $catalogue->publicPage("private-rename") === null,
    "private changes cannot change programme title, status, date or URL",
);
check(
    $catalogue->next(new DateTimeImmutable("2030-01-10T00:00:00+01:00"))[
        "id"
    ] === $ids[0],
    "homepage reads the same earliest published original",
);

$fold = publish($pages, [
    "slug" => "veranstaltungen/zeitumstellung",
    "title" => "UTC-Faltung",
    "template" => "event",
    "event_start" => "2026-10-25T02:30",
    "event_timezone" => "Europe/Berlin",
    "event_start_utc" => "2026-10-25T01:30:00Z",
    "event_end" => "2026-10-25T03:00:00Z",
    "event_ticket_url" => "https://example.invalid/tickets",
    "event_price" => "12,50",
    "event_address" => "Straße 1",
]);
check(
    EventDetails::start($catalogue->publicId($fold["id"]))->format("H:i") ===
        "01:30",
    "event catalogue preserves the second occurrence of the autumn clock change",
);
$ics = unfolded($feeds->ics($fold["id"]));
check(
    substr_count($ics, "BEGIN:VEVENT\r\n") === 1 &&
        str_contains($ics, "DTSTART:20261025T013000Z") &&
        str_contains($ics, "DTEND:20261025T030000Z"),
    "single-event ICS preserves exact UTC start and end",
);
check(
    str_contains($ics, "UID:" . $fold["id"] . "@kulturhus-native.invalid"),
    "calendar UID survives product branding",
);
$manager = new Manager(
    $db,
    $access,
    $root . "/media",
    "synthetic-csrf",
    false,
    true,
);
$post = array_replace($pages->get($fold["id"]), [
    "operation" => "page-save",
    "csrf" => "synthetic-csrf",
    "event_end_local" => "2026-10-25T04:00",
    "title" => "UTC-Faltung redigiert",
]);
$manager->action("events", $post, []);
$stored = $pages->get($fold["id"]);
check(
    $stored["event_start_utc"] === "2026-10-25T01:30:00Z" &&
        $stored["event_end"] === "2026-10-25T03:00:00Z",
    "actual editor action preserves unchanged original UTC anchors",
);
check(
    $stored["event_price"] === "12.50",
    "editor accepts German decimal entry without float storage",
);
$endFold = publish($pages, [
    "title" => "UTC-Endfaltung",
    "slug" => "veranstaltungen/endfaltung",
    "template" => "event",
    "event_start" => "2026-10-25T02:30",
    "event_start_utc" => "2026-10-25T00:30:00Z",
    "event_end" => "2026-10-25T01:45:00Z",
]);
$manager->action(
    "events",
    array_replace($endFold, [
        "operation" => "page-save",
        "csrf" => "synthetic-csrf",
        "event_end_local" => "2026-10-25T02:45",
    ]),
    [],
);
check(
    $pages->get($endFold["id"])["event_end"] === "2026-10-25T01:45:00Z",
    "editor preserves the second UTC occurrence of an unchanged autumn end time",
);
$changed = Templates::normalize([
    "title" => "Zeitprüfung",
    "slug" => "zeitpruefung",
    "template" => "event",
    "event_start" => "2030-01-12T19:00",
    "event_end_local" => "2030-01-12T20:15",
]);
check(
    $changed["event_end"] === "2030-01-12T19:15:00Z",
    "local end is normalized once to its actual UTC instant",
);
rejects(
    fn() => Templates::normalize([
        "title" => "Zeitprüfung",
        "slug" => "zeitpruefung",
        "event_start" => "2030-01-12T19:00",
        "event_end_local" => "2030-01-12T18:00",
    ]),
    "end before start rejected",
);
rejects(
    fn() => Templates::normalize([
        "title" => "Zeitprüfung",
        "slug" => "zeitpruefung",
        "event_start" => "2026-03-29T01:30",
        "event_end_local" => "2026-03-29T02:30",
    ]),
    "missing spring clock-change end time is not silently shifted",
);
foreach (
    [
        "javascript:alert(1)",
        "http://example.invalid",
        "https://user:password@example.invalid",
        "https://example.invalid/\r\nx",
    ]
    as $url
) {
    rejects(
        fn() => Templates::normalize([
            "title" => "Linkprüfung",
            "slug" => "linkpruefung",
            "event_ticket_url" => $url,
        ]),
        "unsafe event ticket URL rejected",
    );
}

$image = imagecreatetruecolor(20, 20);
imagepng($image, $root . "/fixture.png");
unset($image);
$publicImage = $media->upload(
    $root . "/fixture.png",
    "public.png",
    "Öffentliches Veranstaltungsbild",
);
$privateImage = $media->upload(
    $root . "/fixture.png",
    "private.png",
    "PRIVATE-IMAGE",
);
$importer = new Eventbrite($db, $access);
$raw = [
    "id" => "1900",
    "name" => ["text" => "Öffentliches Absageoriginal"],
    "description" => ["text" => "Öffentliches Programm"],
    "start" => ["utc" => "2030-01-20T18:00:00Z", "timezone" => "Europe/Berlin"],
    "end" => ["utc" => "2030-01-20T20:00:00Z"],
    "venue" => [
        "name" => "Saal",
        "address" => ["localized_address_display" => "Teststraße 20"],
    ],
    "status" => "live",
    "url" => "https://www.eventbrite.de/e/1900",
    "is_free" => true,
];
$import = $importer->import([Eventbrite::normalize($raw)]);
$cancelId = $import[0]["id"];
$row = $pages->get($cancelId);
$row = $pages->save(
    array_replace($row, ["media_ids" => [$publicImage["id"]]]),
    $row["version"],
);
$row = $pages->publish($cancelId, $row["version"]);
$publishedRevision = $row["published_version"];
$slug = $row["published_slug"];
$row = $pages->save(
    array_replace($row, [
        "title" => "PRIVATE-CANCEL-TITLE",
        "body" => "PRIVATE-CANCEL-BODY",
        "media_ids" => [$privateImage["id"]],
        "event_ticket_url" => "https://example.invalid/private-cancel-ticket",
    ]),
    $row["version"],
);
$history = new PathHistory($db, $access);
$history->remember($cancelId, "alte-absageadresse", $row["version"]);
$raw["status"] = "canceled";
$raw["name"]["text"] = "Externe unveröffentlichte Absagefassung";
check(
    $importer->import([Eventbrite::normalize($raw)])[0]["status"] ===
        "cancelled-conflict",
    "external cancellation preserves a privately edited draft",
);
$notice = $catalogue->publicPage($slug);
check(
    $pages->publicPage($slug) === null &&
        $notice["cancellation_notice"] &&
        $notice["published_version"] === $publishedRevision,
    "cancellation retracts normal publication and references the real previous public revision",
);
check(
    $notice["title"] === "Öffentliches Absageoriginal" &&
        $notice["body"] === "Öffentliches Programm" &&
        $notice["event_status"] === "cancelled",
    "cancellation notice never copies private or unapproved external title/body",
);
check(
    str_contains(
        $manager->main("events", $cancelId),
        "öffentlicher Absagehinweis",
    ),
    "original editor identifies the still-public cancellation and its explicit offline action",
);
check(
    $history->resolve("alte-absageadresse") === $slug,
    "old published links resolve directly to the original cancellation notice",
);
rejects(
    fn() => $pages->save([
        "slug" => $slug,
        "title" => "Falsches Ersatzoriginal",
    ]),
    "public cancellation path cannot be reassigned to a different page",
);
check(
    $media->publicVariant($publicImage["id"], "thumb") !== null &&
        $media->publicVariant($privateImage["id"], "thumb") === null,
    "only media from the original public cancellation snapshot remains readable",
);
$originalTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$beforeImageReads=ownOriginalDatabaseHash($db,$originalTables);
$originalThumb=$media->variant($publicImage['id'],'thumb');$originalThumbBytes=file_get_contents($originalThumb['path']);
check(
    $media->publicVariant($publicImage['id'],'thumb',$notice)['sha256']===$originalThumb['sha256']&&
    $media->publicVariant($privateImage['id'],'thumb',$notice)===null&&
    $media->publicVariant($publicImage['id'],'thumb',['id'=>str_repeat('f',32)])===null,
    'cancelled media stays bound to its exact public original and explicit page identity',
);
$httpImages=ownEventCancellationHttp($root,$root.'/media',[
    ['GET','/media/'.$publicImage['id'].'/thumb'],
    ['GET','/media/'.$privateImage['id'].'/thumb'],
    ['HEAD','/media/'.$publicImage['id'].'/thumb'],
]);
check($httpImages[0]['status']===200&&($httpImages[0]['headers']['content-type']??null)==='image/png'&&$httpImages[0]['body']===$originalThumbBytes,'actual anonymous HTTP serves only the exact earlier published cancellation pixels');
check($httpImages[1]['status']===404&&!str_contains($httpImages[1]['body'],'PRIVATE'),'actual anonymous HTTP refuses the pending private replacement');
check($httpImages[2]['status']===200&&$httpImages[2]['body']===''&&($httpImages[2]['headers']['content-type']??null)==='image/png'&&($httpImages[2]['headers']['content-length']??null)===(string)strlen($originalThumbBytes)&&($httpImages[2]['headers']['cache-control']??null)===($httpImages[0]['headers']['cache-control']??null),'actual cancellation-image HEAD reports the exact original type and length without a body');
check(ownOriginalDatabaseHash($db,$originalTables)===$beforeImageReads&&file_get_contents($originalThumb['path'])===$originalThumbBytes,'all cancellation image reads preserve every original table, published revision and pixel');
$currentDraft=$pages->get($cancelId);
$publishedRow=Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? AND revision=?',[$cancelId,$publishedRevision])->fetch();
$restrictedDraft=$pages->save(array_replace($currentDraft,['page_policy'=>['public'=>false]]),$currentDraft['version']);
$restrictedOriginals=ownOriginalDatabaseHash($db,$originalTables);
check($catalogue->publicId($cancelId)===null&&$media->publicVariant($publicImage['id'],'thumb')===null&&$media->publicVariant($publicImage['id'],'thumb',$notice)===null,'current public page revocation also closes the earlier cancellation image and its explicit original context');
$restrictedHttp=ownEventCancellationHttp($root,$root.'/media',[['GET','/media/'.$publicImage['id'].'/thumb']]);
check($restrictedHttp[0]['status']===404&&ownOriginalDatabaseHash($db,$originalTables)===$restrictedOriginals,'actual anonymous image request observes current public revocation without rewriting originals');
$pages->save(array_replace($restrictedDraft,['page_policy'=>$currentDraft['page_policy']??[]]),$restrictedDraft['version']);
check($catalogue->publicId($cancelId)['published_version']===$publishedRevision&&$media->publicVariant($publicImage['id'],'thumb',$notice)['sha256']===$originalThumb['sha256'],'explicit policy restoration still uses the same earlier approved image and publication');
$restoredHttp=ownEventCancellationHttp($root,$root.'/media',[['GET','/media/'.$publicImage['id'].'/thumb']]);
check($restoredHttp[0]['status']===200&&$restoredHttp[0]['body']===$originalThumbBytes&&Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? AND revision=?',[$cancelId,$publishedRevision])->fetch()===$publishedRow,'actual restored image keeps exact pixels and the complete earlier immutable published row');
check(
    $notice["event_address"] === "Teststraße 20" &&
        $notice["event_price"] === "0.00",
    "provider venue and known free entry stay attached to their published original",
);
$ics = unfolded($feeds->ics($cancelId));
check(
    str_contains($ics, "STATUS:CANCELLED") &&
        str_contains($ics, "UID:" . $cancelId . "@kulturhus-native.invalid") &&
        $notice["event_sequence"] > $publishedRevision,
    "cancelled ICS retains UID and advances its original sequence",
);
$export = json_decode($feeds->eventsJson(), true, 512, JSON_THROW_ON_ERROR);
$jsonNotice = array_values(
    array_filter($export["events"], fn($event) => $event["id"] === $cancelId),
)[0];
check(
    $jsonNotice["status"] === "cancelled" &&
        $jsonNotice["ticket_url"] === null &&
        !str_contains($feeds->eventsJson(), "PRIVATE-CANCEL"),
    "JSON exports the cancellation without private content or active ticket action",
);
check(
    !in_array(
        $cancelId,
        array_column(
            (new PublishedEvents($db, $access->club()))->between(
                "2030-01-01",
                "2030-02-01",
            ),
            "id",
        ),
        true,
    ),
    "upcoming newsletter selection excludes cancelled originals",
);
$calendar = Kulturhus\Verein\WebsiteCalendar::fromNative(
    $db,
    "manager-club",
    $access->club(),
    "https://example.invalid",
);
$entry = $calendar->appointment("manager-club", "website:" . $cancelId);
check(
    $entry["cancelled"] &&
        $entry["title"] === "Abgesagt: Öffentliches Absageoriginal" &&
        str_ends_with($entry["source_url"], "/".trim($slug,"/")."/"),
    "manager calendar retains the same original cancellation and detail link",
);

$site = new Site(
    $pages,
    $media,
    new Cache($root . "/cache"),
    "https://example.invalid",
    $access->club(),
    null,
    null,
    "/vereinsverwaltung/",
    ["name" => "Verein <Gemeinsam>"],
);
$html = $site->page($slug);
check(
    str_contains($html, "Diese Veranstaltung wurde abgesagt.") &&
        !str_contains($html, "PRIVATE-CANCEL") &&
        !str_contains($html, ">Tickets</a>"),
    "public detail renders explicit cancellation with no private draft or ticket button",
);
$doc = new DOMDocument();
@$doc->loadHTML($html);
$xpath = new DOMXPath($doc);
$json = $xpath->query('//script[@type="application/ld+json"]')->item(0)
    ->textContent;
$ld = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
check(
    $ld["eventStatus"] === "https://schema.org/EventCancelled" &&
        $ld["organizer"]["name"] === "Verein <Gemeinsam>" &&
        $ld["startDate"] === "2030-01-20T19:00:00+01:00",
    "Event structured data uses the original date, cancellation and current separate club identity",
);
$html = $site->page("veranstaltungen", 1, ["q" => "%_ literal.*"]);
check(
    str_contains($html, "Bühne %_ literal.*") &&
        !str_contains($html, "PRIVATE-DRAFT"),
    "public programme renderer applies the catalogue filter",
);
check(
    !str_contains($html, 'href="https://www.eventbrite.de/e/1900"'),
    "programme cannot acquire an unrelated ticket link",
);

$raw["name"]["text"] = "Weitere externe Änderung";
$importer->import([Eventbrite::normalize($raw)]);
check(
    $catalogue->publicPage($slug)["published_version"] === $publishedRevision,
    "repeated cancellation never replaces the approved original with a draft",
);
$row = $pages->get($cancelId);
$pages->unpublish($cancelId, $row["version"]);
check(
    $catalogue->publicPage($slug) === null &&
        $site->page($slug) === null &&
        $media->publicVariant($publicImage["id"], "thumb") === null,
    "explicit offline action removes cancellation notice, cached response and public media",
);
$beforeWithdrawnReads=ownOriginalDatabaseHash($db,$originalTables);
$withdrawnImage=ownEventCancellationHttp($root,$root.'/media',[['GET','/media/'.$publicImage['id'].'/thumb']]);
check($withdrawnImage[0]['status']===404&&ownOriginalDatabaseHash($db,$originalTables)===$beforeWithdrawnReads&&file_get_contents($originalThumb['path'])===$originalThumbBytes,'explicit withdrawal closes the actual HTTP image without rewriting its original bytes');
check(
    $history->resolve("alte-absageadresse") === null,
    "offline cancellation no longer resolves through an old alias",
);
$raw["id"] = "1901";
$raw["name"]["text"] = "Nie veröffentlichte Absage";
$cold = $importer->import([Eventbrite::normalize($raw)])[0];
check(
    $catalogue->publicId($cold["id"]) === null,
    "cancellation of an unpublished import never invents a public page",
);
$raw["id"] = "1902";
$raw["name"]["text"] = "Wiederaufgenommener Termin";
$raw["status"] = "live";
$revived = $importer->import([Eventbrite::normalize($raw)])[0];
$row = $pages->get($revived["id"]);
$pages->publish($row["id"], $row["version"]);
$raw["status"] = "canceled";
$importer->import([Eventbrite::normalize($raw)]);
check(
    $catalogue->publicId($revived["id"])["cancellation_notice"],
    "clean provider cancellation retains a published original",
);
$raw["status"] = "live";
$importer->import([Eventbrite::normalize($raw)]);
check(
    $catalogue->publicId($revived["id"]) === null,
    "provider revival clears the old notice and leaves the revised event private",
);
$row = $pages->get($revived["id"]);
$pages->publish($row["id"], $row["version"]);
check(
    $catalogue->publicId($revived["id"])["event_status"] === "scheduled",
    "revived provider event requires a new deliberate publication",
);

// Existing references are rebuilt from public revisions, not draft payloads, during explicit CMS maintenance.
Db::run($db, "DELETE FROM khcms_events WHERE club_id=? AND is_notice=0", [
    $access->club(),
]);
Schema::install($db);
check(
    $catalogue->publicId($ids[0])["title"] === "Öffentlicher Termin 0" &&
        $catalogue->publicId($draft["id"]) === null,
    "schema maintenance rebuilds only immutable published event references",
);
$row = $pages->get($ids[1]);
$pages->archive($row["id"], $row["version"]);
check(
    $catalogue->publicId($row["id"]) === null,
    "archive immediately removes its event catalogue reference",
);
$reader = new Access($db, "reader");
$readCatalogue = (new Pages($db, $reader))->events();
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='reader' AND module='website'",
);
rejects(
    fn() => $readCatalogue->listing([], $now),
    "previous catalogue instance observes immediate website read revocation",
);
rejects(
    fn() => iterator_to_array($readCatalogue->all()),
    "batched feed projection rechecks current website read permissions",
);
echo "$checks native event catalogue checks passed; isolated MySQL, no external services.\n";
