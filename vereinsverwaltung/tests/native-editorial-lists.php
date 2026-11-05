<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    Pages,
    DataModel,
    Navigation,
    PageSummary,
    EventYears,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\WebsiteWorkspace;
use Kulturhus\Native\Web\Website\{EventSearch, PageOverview};
$db = TestSupport::db();
if (($argv[1] ?? "") === "bounded-memory") {
    $access = new Access($db, "admin");
    $overview = (new PageOverview($db, $access))->read();
    $events = (new EventSearch($db, $access))->listing([
        "q" => "Own bounded event",
        "status" => "all",
        "sort" => "date_asc",
        "page" => 1,
        "period" => "all",
        "year" => "",
    ]);
    echo json_encode([
        "recent" => count($overview["recent"]),
        "events" => count($events["rows"]),
        "total" => $overview["counts"]["total"],
        "memory" => memory_get_peak_usage(true),
    ]);
    exit();
}
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$pages = new Pages($db, $access);
$model = new DataModel($db, $access);
$n = 0;
function editorialCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function editorialReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $e) {
        editorialCheck($e instanceof $class, $label);
        return;
    }
    throw new RuntimeException($label);
}
$model->saveTemplate([
    "key" => "own_event",
    "label" => "Own event template",
    "renderer" => "event",
    "fields" => [],
]);
$sources = [];
$past = [];
$future = [];
$undated = [];
for ($i = 0; $i < 69; ++$i) {
    $date =
        $i < 57
            ? (new DateTimeImmutable("2050-01-01T12:00"))
                ->modify("+" . $i . " days")
                ->format("Y-m-d\TH:i")
            : ($i < 66
                ? (new DateTimeImmutable("2001-01-01T12:00"))
                    ->modify("+" . ($i - 57) . " days")
                    ->format("Y-m-d\TH:i")
                : "");
    $row = $pages->save([
        "title" => "Own bounded event " . sprintf("%02d", $i),
        "slug" => "editorial-event-" . $i,
        "template" => $i % 5 === 0 ? "own_event" : "event",
        "event_start" => $date,
        "event_location" => "Own hall " . $i,
        "event_address" => "Own street " . $i,
        "body_html" =>
            "<p>Own visible event <strong>body " .
            sprintf("%02d", $i) .
            "</strong></p>",
        "nav_visible" => false,
    ]);
    if ($i < 20) {
        $row = $pages->publish($row["id"], $row["version"]);
        if ($i >= 10) {
            $row["body_html"] = "<p>Own changed event</p>";
            $row = $pages->save($row, $row["version"]);
        }
    }
    $sources[] = $row;
    if ($i < 57) {
        $future[] = $row;
    } elseif ($i < 66) {
        $past[] = $row;
    } else {
        $undated[] = $row;
    }
}
$today = (new DateTimeImmutable(
    "today",
    new DateTimeZone("Europe/Berlin"),
))->format("Y-m-d");
foreach (["00:00", "23:59"] as $time) {
    $pages->save([
        "title" => "Own today boundary " . $time,
        "slug" => "own-today-" . str_replace(":", "-", $time),
        "template" => "event",
        "event_start" => $today . "T" . $time,
        "nav_visible" => false,
    ]);
}
$far = $pages->save([
    "title" => "Own year outside previous hardcoded range",
    "slug" => "own-year-2500",
    "template" => "event",
    "event_start" => "2500-01-01T12:00",
    "nav_visible" => false,
]);
$pages->save([
    "title" => "Ordinary page with date",
    "slug" => "ordinary-date",
    "event_start" => "2499-01-01T12:00",
    "nav_visible" => false,
]);
$search = new EventSearch($db, $access);
$filter = [
    "q" => "Own bounded event",
    "status" => "all",
    "sort" => "date_asc",
    "page" => 1,
    "period" => "all",
    "year" => "",
];
$result = $search->listing($filter);
editorialCheck(
    $result["total"] === 69 &&
        count($result["rows"]) === 25 &&
        $result["last"] === 3,
    "69 real event drafts return one 25-row SQL page",
);
$dated = [...$past, ...$future];
editorialCheck(
    array_column($result["rows"], "id") ===
        array_column(array_slice($dated, 0, 25), "id"),
    "date ascending keeps past before future and includes custom event renderers",
);
$second = $search->listing(array_replace($filter, ["page" => 2]));
editorialCheck(
    array_column($second["rows"], "id") ===
        array_column(array_slice($dated, 25, 25), "id"),
    "second SQL event page has stable contiguous dated rows",
);
$last = $search->listing(array_replace($filter, ["page" => 999999]));
editorialCheck(
    $last["page"] === 3 && count($last["rows"]) === 19,
    "event list clamps oversize page to remaining 19 rows",
);
$descending = $search->listing(array_replace($filter, ["sort" => "date_desc"]));
editorialCheck(
    array_column($descending["rows"], "id") ===
        array_column(array_slice(array_reverse($dated), 0, 25), "id"),
    "date descending sorts actual stored timestamps before unknown dates",
);
$tail = $search->listing(
    array_replace($filter, ["sort" => "date_desc", "page" => 3]),
);
editorialCheck(
    count(
        array_filter(
            array_slice($tail["rows"], -3),
            fn($row) => $row["event_start"] === "",
        ),
    ) === 3,
    "undated events remain last in either sort direction",
);
$title = $search->listing(array_replace($filter, ["sort" => "title_asc"]));
editorialCheck(
    array_column($title["rows"], "id") ===
        array_column(array_slice($sources, 0, 25), "id"),
    "title order has independent stable pagination",
);
foreach (["upcoming" => 60, "archive" => 9] as $period => $count) {
    editorialCheck(
        $search->listing(array_replace($filter, ["period" => $period]))[
            "total"
        ] === $count,
        "actual " .
            $period .
            " selection preserves all undated and dated rules",
    );
}
editorialCheck(
    $search->listing(
        array_replace($filter, [
            "q" => "Own today boundary",
            "period" => "upcoming",
        ]),
    )["total"] === 2,
    "whole current Berlin day remains in upcoming list at either hour",
);
foreach (["2050" => 57, "2001" => 9, "2040" => 0] as $year => $count) {
    editorialCheck(
        $search->listing(array_replace($filter, ["year" => (string) $year]))[
            "total"
        ] === $count,
        "calendar year " . (string) $year . " uses exact stored day boundaries",
    );
}
$farList = $search->listing(
    array_replace($filter, ["q" => "", "year" => "2500"]),
);
editorialCheck(
    $farList["total"] === 1 && $farList["rows"][0]["id"] === $far["id"],
    "all saved four-digit years can be selected beyond the old 2299 limit",
);
$years = (new EventYears($db, $access))->values();
editorialCheck(
    $years === ["2500", "2050", substr($today, 0, 4), "2001"],
    "year choices include only distinct actual event draft years in reverse order",
);
foreach (
    ["published" => 20, "changed" => 10, "draft" => 49]
    as $status => $count
) {
    editorialCheck(
        $search->listing(array_replace($filter, ["status" => $status]))[
            "total"
        ] === $count,
        "event publication " .
            $status .
            " uses the same count and slice filter",
    );
}
editorialCheck(
    $search->listing(array_replace($filter, ["q" => "VISIBLE EVENT BODY 00"]))[
        "rows"
    ][0]["id"] === $sources[0]["id"],
    "case folded body search crosses original formatting",
);
editorialCheck(
    $search->listing(array_replace($filter, ["q" => "own STREET 56"]))[
        "rows"
    ][0]["id"] === $sources[56]["id"],
    "event street address is searchable as actual saved text",
);
editorialCheck(
    $search->listing(
        array_replace($filter, ["q" => "event 00 editorial-event-0"]),
    )["rows"][0]["id"] === $sources[0]["id"],
    "old cross-field event search remains available",
);
editorialCheck(
    $search->listing(array_replace($filter, ["q" => "%' OR 1=1 --"]))[
        "total"
    ] === 0,
    "SQL-looking event text stays literal",
);
foreach (
    [
        ["q" => []],
        ["q" => "bad\ntext"],
        ["q" => "\xff"],
        ["page" => 0],
        ["period" => "other"],
        ["year" => []],
        ["year" => "20"],
        ["sort" => "date_asc; DROP TABLE khcms_pages"],
        ["status" => "other"],
    ]
    as $i => $bad
) {
    editorialReject(
        fn() => $search->listing(array_replace($filter, $bad)),
        "event search refuses malformed input " . $i,
    );
}
$empty = $search->listing(
    array_replace($filter, ["q" => "Not in any source", "page" => 999999]),
);
editorialCheck(
    $empty["total"] === 0 && $empty["page"] === 1 && $empty["rows"] === [],
    "empty event list has coherent boundaries",
);
$workspace = new WebsiteWorkspace($db, $access, "own-csrf");
$html = $workspace->events([
    "q" => $filter["q"],
    "period" => "all",
    "year" => "2050",
    "sort" => "date_desc",
    "page" => "2",
]);
editorialCheck(
    substr_count($html, "data-website-row=") === 25 &&
        str_contains($html, "57 Veranstaltungen gefunden · Seite 2 von 3"),
    "actual workspace renders one event slice and true total",
);
editorialCheck(
    str_contains($html, "year=2050") &&
        str_contains($html, "sort=date_desc") &&
        str_contains($html, "page=3"),
    "event follow-page links retain original year, period and sort",
);
editorialCheck(
    str_contains(
        $html,
        'btn btn-solid theme-secondary">Eventbrite synchronisieren',
    ),
    "manual import action uses actual Bootstrap 6 classes",
);
$reader = new Access($db, "reader");
editorialCheck(
    !str_contains(
        (new WebsiteWorkspace($db, $reader))->events(),
        'operation" value="eventbrite-sync',
    ),
    "read-only event list exposes no import action",
);

$menuPages = [];
foreach (
    ["start" => true, "visible-menu" => true, "override-menu" => false]
    as $slug => $visible
) {
    $row = $pages->save([
        "title" => "Own menu " . $slug,
        "slug" => $slug,
        "nav_visible" => $visible,
    ]);
    $menuPages[$slug] = $pages->publish($row["id"], $row["version"]);
}
$rss = $pages->save([
    "title" => "Own RSS only menu source",
    "slug" => "aktuelles/own-rss-menu",
    "template" => "news",
    "news_date" => "2050-01-01",
    "news_channels" => ["rss"],
    "nav_visible" => true,
]);
$rss = $pages->publish($rss["id"], $rss["version"]);
$pages->save([
    "title" => "Root gallery container",
    "slug" => "galerie",
    "template" => "gallery",
    "nav_visible" => false,
]);
$pages->save([
    "title" => "Own actual album",
    "slug" => "galerie/editorial-album",
    "template" => "gallery",
    "nav_visible" => false,
]);
$nav = new Navigation($db, $access);
$nav->save(
    [
        [
            "id" => $menuPages["visible-menu"]["id"],
            "label" => "Hidden override",
            "visible" => false,
            "position" => 2,
        ],
        [
            "id" => $menuPages["override-menu"]["id"],
            "label" => "Shown override",
            "visible" => true,
            "position" => 1,
        ],
    ],
    false,
    $nav->state()["version"],
);
editorialCheck(
    $nav->count() === 2 && $nav->count("start") === 1,
    "menu SQL count honors both false/true overrides and root exclusion",
);
editorialCheck(
    $nav->count() === count($nav->publicLinks()),
    "menu aggregate matches original public link selection",
);
editorialCheck(
    (new Navigation($db, new Access($db, "outsider")))->count() === 0,
    "menu count does not cross club boundaries",
);
// A different table-default collation is a valid hosting setup, not an installation error.
$db->exec(
    "ALTER TABLE khcms_navigation CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci",
);
editorialCheck(
    $nav->count("start") === 1,
    "actual mixed native table collations retain safe owner-bound menu lookup",
);
$all = $pages->list();
$expected = [
    "total" => count($all),
    "published" => 0,
    "changed" => 0,
    "website" => 0,
    "events" => 0,
    "news" => 0,
    "media" => 0,
];
foreach ($all as $row) {
    $view = match (
        $row["template_definition"]["renderer"] ?? $row["template"]
    ) {
        "event" => "events",
        "news" => "news",
        "gallery" => "media",
        default => "website",
    };
    if ($view !== "media" || $row["slug"] !== "galerie") {
        ++$expected[$view];
    }
    if ($row["published_revision"] !== null) {
        ++$expected["published"];
    }
    if (
        $row["published_revision"] !== null &&
        $row["version"] > $row["published_revision"]
    ) {
        ++$expected["changed"];
    }
}
editorialCheck(
    (new PageSummary($db, $access))->read() === $expected,
    "SQL metadata summary matches original all-page behavior and root album exclusion",
);
$overview = (new PageOverview($db, $access))->read();
editorialCheck(
    array_column($overview["recent"], "id") ===
        array_column(array_slice($all, 0, 8), "id"),
    "exact eight recent records retain old modification/identity ordering",
);
editorialCheck(
    $overview["menu"] === 1 && $overview["counts"] === $expected,
    "overview takes consistent counts, menu and recent content snapshot",
);
$overviewHtml = $workspace->overview();
editorialCheck(
    substr_count($overviewHtml, 'class="website-card-count"') === 5 &&
        str_contains(
            $overviewHtml,
            "<strong>" . $expected["total"] . "</strong> Inhalte",
        ),
    "overview exposes actual totals per implemented area",
);
$generation = $pages->generation();
$original = json_encode($pages->publicPage($sources[0]["slug"]));
$workspace->events(["period" => "all"]);
$workspace->overview();
editorialCheck(
    $pages->generation() === $generation &&
        json_encode($pages->publicPage($sources[0]["slug"])) === $original,
    "all new listing/summary paths preserve original public snapshot and cache generation",
);

for ($i = 0; $i < 200; ++$i) {
    $pages->save([
        "title" => "Own large unrelated draft " . $i,
        "slug" => "large-editorial-body-" . $i,
        "body" => str_repeat("x", 99000),
        "nav_visible" => false,
    ]);
}
$process = proc_open(
    [
        ...TestSupport::phpCommand(),
        "-d",
        "memory_limit=16M",
        "-d",
        "zend.exception_ignore_args=1",
        __FILE__,
        "bounded-memory",
    ],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException("Memory proof process unavailable");
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
editorialCheck(
    $status === 0 && $errors === "",
    "actual fresh 16 MiB PHP process lists events and overview with 19.8 MB unrelated draft bodies",
);
$memory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
editorialCheck(
    $memory["recent"] === 8 &&
        $memory["events"] === 25 &&
        $memory["total"] === $expected["total"] + 200,
    "memory-limited subprocess still has all correct aggregate totals and bounded slices",
);
Db::run($db, "DELETE FROM native_grants WHERE actor_id=? AND module=?", [
    "reader",
    "website",
]);
editorialReject(
    fn() => (new EventSearch($db, $reader))->listing($filter),
    "event list honors immediate read revocation",
    DomainException::class,
);
editorialReject(
    fn() => (new PageOverview($db, $reader))->read(),
    "overview honors immediate read revocation",
    DomainException::class,
);
editorialReject(
    fn() => (new Navigation($db, $reader))->count(),
    "menu count honors immediate read revocation",
    DomainException::class,
);
echo json_encode(
    [
        "suite" => "native-editorial-lists",
        "checks" => $n,
        "storage" => "fresh synthetic MySQL",
        "memory_proof" => $memory,
        "external_requests" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
