<?php
declare(strict_types=1);
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/bootstrap.php";

use Kulturhus\Native\Cms\{
    Articles,
    DataModel,
    Media,
    NewsChannels,
    Pages,
    PathHistory,
    PublicationSchedule,
    Schema,
};
use Kulturhus\Native\Integration\{ManagerBridge, PublicSite};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\{Articles as ArticleView, Feeds, Manager};
use Kulturhus\Native\Web\Website\{NewsChannelFields, PageEditor};
use Kulturhus\Verein\Database;

$checks = [];
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks[] = $label;
}
function rejects(callable $action, string $label): void
{
    try {
        $action();
    } catch (InvalidArgumentException | DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException("FAIL: accepted " . $label);
}
function titles(string $rss): array
{
    $dom = new DOMDocument();
    if (!$dom->loadXML($rss, LIBXML_NONET)) {
        throw new RuntimeException("RSS XML unavailable.");
    }
    $result = [];
    foreach ($dom->getElementsByTagName("item") as $item) {
        $result[] = $item->getElementsByTagName("title")->item(0)->textContent;
    }
    return $result;
}
$db = TestSupport::db();
Database::migrate($db);
$actors = TestSupport::seed($db);
Schema::install($db);
$club = "news-channel-" . bin2hex(random_bytes(6));
$nativeClub = ManagerBridge::clubKey($club);
Db::run($db, "INSERT INTO clubs(id,name) VALUES(?,?)", [
    $club,
    "Synthetischer Newsverein",
]);
foreach (["admin", "editor", "reader"] as $actor) {
    Db::run($db, "UPDATE native_users SET club_id=? WHERE id=?", [
        $nativeClub,
        $actor,
    ]);
}
$access = new Access($db, $actors["admin"]);
$pages = new Pages($db, $access);
$articles = new Articles($db, $access);
$reader = new Access($db, $actors["reader"]);
$root =
    dirname((string) getenv("KH_NATIVE_TEST_CONFIG")) .
    "/news-channels-" .
    bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$media = new Media($db, $access, $root . "/media");
$image = imagecreatetruecolor(20, 20);
imagepng($image, $root . "/picture.png");
unset($image);
$asset = $media->upload(
    $root . "/picture.png",
    "picture.png",
    "Synthetisches privates Beitragsbild",
);
$feeds = new Feeds($pages, "http://127.0.0.1:8196", "Synthetischer Newsverein");
$pathHistory = new PathHistory($db, $access);
$items = [];
foreach (
    [
        "both" => ["website", "rss"],
        "website" => ["website"],
        "rss" => ["rss"],
        "none" => [],
    ]
    as $name => $channels
) {
    $p = $articles->save([
        "title" => "ORIGINAL_" . $name,
        "slug" => "aktuelles/" . $name,
        "news_date" => "2050-01-12",
        "body" => "Öffentlicher Originaltext " . $name,
        "excerpt" => "Originalkurztext " . $name,
        "nav_visible" => true,
        "news_channels" => $channels,
        "media_ids" => $name === "none" ? [$asset["id"]] : [],
    ]);
    $items[$name] = $articles->publish($p["id"], $p["version"]);
}
foreach (["start", "aktuelles", "satzung"] as $slug) {
    $p = $pages->save(["slug" => $slug, "title" => "STANDARD_" . $slug]);
    $pages->publish($p["id"], $p["version"]);
}
$foreign = new Articles($db, new Access($db, $actors["outsider"]));
$p = $foreign->save([
    "slug" => "aktuelles/foreign",
    "title" => "FOREIGN_NEWS",
    "news_date" => "2050-01-12",
    "news_channels" => ["rss"],
]);
$foreign->publish($p["id"], $p["version"]);
$private = $articles->save([
    "title" => "PRIVATE_NEWS",
    "slug" => "aktuelles/private",
    "news_date" => "2050-01-12",
]);
check(
    $articles->publicCount() === 2 && count($articles->publicList()) === 2,
    "website SQL list and count contain both and website originals only",
);
check(
    $pages->publicPage($items["rss"]["slug"]) === null &&
        $pages->publicPage($items["rss"]["slug"], "rss")["title"] ===
            "ORIGINAL_rss",
    "RSS-only original has no public website URL",
);
check(
    $pages->publicPage($items["none"]["slug"]) === null &&
        $pages->publicPage($items["none"]["slug"], "rss") === null,
    "explicit empty channel group exposes no original",
);
check(
    $media->publicVariant($asset["id"], "thumb") === null,
    "no-channel publication never grants its private media public access",
);
$menu = array_column($pages->navigation(), "title");
check(
    in_array("ORIGINAL_website", $menu, true) &&
        !in_array("ORIGINAL_rss", $menu, true) &&
        !in_array("ORIGINAL_none", $menu, true),
    "public menu uses current website channels rather than publication pointer alone",
);
$rss = titles($feeds->rss("news"));
sort($rss);
check(
    $rss === ["ORIGINAL_both", "ORIGINAL_rss"],
    "separate RSS news stream respects exact saved channel names",
);
check(
    !in_array("ORIGINAL_website", titles($feeds->rss()), true),
    "existing all-publications RSS also obeys RSS opt-in",
);
check(
    $feeds->search("ORIGINAL_rss") === [] &&
        $feeds->search("ORIGINAL_website")[0]["title"] === "ORIGINAL_website",
    "website search uses website channels",
);
check(
    !str_contains($feeds->rss("news"), "PRIVATE_NEWS") &&
        !str_contains($feeds->rss("news"), "FOREIGN_NEWS"),
    "RSS excludes unpublished and foreign originals",
);
$pathHistory->remember(
    $items["rss"]["id"],
    "aktuelles/old-rss",
    $items["rss"]["version"],
);
check(
    $pathHistory->resolve("aktuelles/old-rss") === null,
    "old aliases cannot expose a website-disabled original",
);

$both = $articles->save(
    [
        "id" => $items["both"]["id"],
        "title" => "PRIVATE_EDIT_TITLE",
        "news_channels" => [],
    ],
    $items["both"]["version"],
);
check(
    $pages->publicPage("aktuelles/both")["title"] === "ORIGINAL_both" &&
        in_array("ORIGINAL_both", titles($feeds->rss("news")), true),
    "draft title and channel edits leave both old public originals intact",
);
$both = $articles->publish($both["id"], $both["version"]);
check(
    $pages->publicPage("aktuelles/both") === null &&
        !in_array("ORIGINAL_both", titles($feeds->rss()), true) &&
        $feeds->search("PRIVATE_EDIT_TITLE") === [],
    "explicit republish withdraws both channels without exposing private edits",
);
$both = $articles->restore($both["id"], 2, $both["version"]);
check(
    $both["news_channels"] === NewsChannels::ALL &&
        $pages->publicPage("aktuelles/both") === null,
    "revision restore retains original channels in a private draft",
);
$both = $articles->publish($both["id"], $both["version"]);
check(
    $pages->publicPage("aktuelles/both")["title"] === "ORIGINAL_both",
    "explicit reapproval restores website channel",
);

foreach (
    [
        null,
        "website",
        ["website rss"],
        ["websites"],
        ["rss,website"],
        [true],
        ["website", "rss", "website"],
        ["wrong" => "website"],
    ]
    as $bad
) {
    rejects(
        fn() => $articles->save(
            ["id" => $both["id"], "news_channels" => $bad],
            $both["version"],
        ),
        "malformed or invented channel rejected without saving",
    );
}
check(
    NewsChannels::normalize(["rss", "website"]) === NewsChannels::ALL,
    "channel order is canonical before approval fingerprints",
);
check(
    NewsChannelFields::fromPost(["news_channels_present" => "1"]) === [] &&
        NewsChannelFields::fromPost([]) === null,
    "unchecked controls and old client without controls are distinct",
);
rejects(
    fn() => NewsChannelFields::fromPost(["news_channels_present" => ["1"]]),
    "array form marker is rejected",
);
rejects(
    fn() => $pages->publicPage("aktuelles/rss", "website OR 1"),
    "public channel cannot inject SQL",
);
rejects(
    fn() => $pages->publicList(101),
    "public page projection is bounded before database selection",
);
rejects(fn() => $feeds->rss("wrong"), "unknown RSS selection rejected");
$view = new ArticleView($db, $access, $root . "/media", "synthetic-csrf");
$markup = $view->manager($items["rss"]["id"], []);
check(
    str_contains($markup, "news_channels_present") &&
        str_contains($markup, 'value="rss" checked') &&
        !str_contains($markup, 'value="website" checked'),
    "article form shows the stored RSS-only selection",
);
check(
    !str_contains($markup, "aus dem Kulturhus"),
    "news manager uses the general club product language",
);
check(
    !str_contains(
        (new ArticleView(
            $db,
            $reader,
            $root . "/media",
            "synthetic-csrf",
        ))->manager($items["rss"]["id"], []),
        "news_channels_present",
    ),
    "reader has no channel mutation controls",
);
$generic = (new PageEditor(
    $db,
    $access,
    $root . "/media",
    "synthetic-csrf",
    true,
))->manager($items["rss"]["id"]);
check(
    str_contains($generic, 'value="rss" checked') &&
        !str_contains($generic, 'value="website" checked'),
    "generic page editor shares the same channel selection",
);

$ui = $articles->save([
    "title" => "ORIGINAL_ui",
    "slug" => "aktuelles/ui",
    "news_date" => "2050-01-12",
    "body" => "Originaler Redaktionstext",
]);
$ui = $articles->publish($ui["id"], $ui["version"]);
$savePost = [
    "csrf" => "synthetic-csrf",
    "operation" => "article-save",
    "id" => $ui["id"],
    "version" => (string) $ui["version"],
    "title" => $ui["title"],
    "slug" => "ui",
    "news_date" => "2050-01-12",
    "body_html" => "<p>Neuer gespeicherter Text</p>",
    "news_channels_present" => "1",
    "news_channels" => ["rss"],
];
$view->action($savePost);
$ui = $articles->get($ui["id"]);
check(
    $ui["news_channels"] === ["rss"] &&
        $pages->publicPage($ui["slug"]) !== null,
    "actual article save adapter persists RSS choice without changing current website",
);
$view->action([
    "csrf" => "synthetic-csrf",
    "operation" => "article-publish",
    "id" => $ui["id"],
    "version" => (string) $ui["version"],
]);
$ui = $articles->get($ui["id"]);
check(
    $pages->publicPage($ui["slug"]) === null &&
        in_array("ORIGINAL_ui", titles($feeds->rss("news")), true),
    "actual article publication applies the saved RSS-only choice",
);
check(
    str_contains($view->manager($ui["id"], []), "Veröffentlicht · RSS-Feed") &&
        !str_contains(
            $view->manager($ui["id"], []),
            "Veröffentlichten Beitrag ansehen",
        ),
    "editor status reflects original RSS publication and omits unavailable website link",
);
$savePost["version"] = (string) $ui["version"];
unset($savePost["news_channels_present"], $savePost["news_channels"]);
$view->action($savePost);
$ui = $articles->get($ui["id"]);
check(
    $ui["news_channels"] === ["rss"],
    "older article save client cannot reset the stored channels to defaults",
);
$manager = new Manager(
    $db,
    $access,
    $root . "/media",
    "synthetic-csrf",
    false,
    true,
);
$manager->action(
    "website",
    [
        "csrf" => "synthetic-csrf",
        "operation" => "page-save",
        "id" => $ui["id"],
        "version" => (string) $ui["version"],
        "template" => "news",
        "title" => $ui["title"],
        "slug" => $ui["slug"],
        "news_date" => "2050-01-12",
        "body" => "Gemeinsamer Seiteneditor",
        "news_channels_present" => "1",
    ],
    [],
);
check(
    $articles->get($ui["id"])["news_channels"] === [],
    "generic page save adapter persists an explicitly unchecked group",
);
$placeholder = $articles->save([
    "title" => "Neue Website-Neuigkeit",
    "slug" => "aktuelles/placeholder",
    "news_date" => "2050-01-12",
    "body" => "Unfertiger Platzhalter",
]);
rejects(
    fn() => $articles->publish($placeholder["id"], $placeholder["version"]),
    "placeholder title cannot be released as public news",
);
check(
    $articles->get($placeholder["id"])["published_revision"] === null,
    "placeholder rejection leaves original draft unpublished",
);

// Historical fixture is created before any approval. Preserve its exact old JSON shape.
$old = $articles->save([
    "title" => "ORIGINAL_legacy_plan",
    "slug" => "aktuelles/legacy-plan",
    "news_date" => "2050-01-12",
    "body" => "Altplanoriginal",
    "nav_visible" => true,
]);
$payload = json_decode(
    Db::run($db, "SELECT draft_json FROM khcms_pages WHERE id=?", [
        $old["id"],
    ])->fetchColumn(),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
unset($payload["news_channels"]);
$originalJson = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
Db::run($db, "UPDATE khcms_pages SET draft_json=? WHERE id=?", [
    $originalJson,
    $old["id"],
]);
Db::run(
    $db,
    "UPDATE khcms_revisions SET payload_json=? WHERE page_id=? AND revision=1",
    [$originalJson, $old["id"]],
);
$due = time() + 3600;
$schedule = new PublicationSchedule($db, $access);
$job = $schedule->plan($old["id"], 1, $due);
$result = PublicationSchedule::runDue(
    $db,
    fn() => $access,
    20,
    $due + 1,
    $nativeClub,
);
check(
    count($result) === 1 && $result[0]["status"] === "published",
    "pre-channel approved native plan still executes with original hash",
);
$oldPublicJson = Db::run(
    $db,
    "SELECT r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision WHERE p.id=?",
    [$old["id"]],
)->fetchColumn();
check(
    $oldPublicJson === $originalJson &&
        $pages->publicPage("aktuelles/legacy-plan") !== null,
    "historical publication preserves exact original bytes and both-channel default",
);
check(
    in_array(
        "ORIGINAL_legacy_plan",
        array_column($pages->navigation(), "title"),
        true,
    ),
    "historical visible article without channel field keeps website navigation",
);
$old = $articles->save(
    ["id" => $old["id"], "nav_visible" => true],
    $pages->get($old["id"])["version"],
);
$old = $articles->publish($old["id"], $old["version"]);
check(
    in_array(
        "ORIGINAL_legacy_plan",
        array_column($pages->navigation(), "title"),
        true,
    ),
    "new explicit edit persists channels and menu visibility",
);

$_SESSION = ["csrf" => "synthetic-csrf"];
$call = static function (string $uri, string $method = "GET") use (
    $db,
    $reader,
    $root,
    $club,
): array {
    header_remove();
    http_response_code(200);
    $_SERVER["REQUEST_URI"] = $uri;
    $_SERVER["REQUEST_METHOD"] = $method;
    unset($_SERVER["HTTP_IF_NONE_MATCH"]);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
    $_POST = [];
    ob_start();
    try {
        $handled = PublicSite::serve(
            $db,
            $reader,
            $root . "/media",
            $root,
            $club,
            "http://127.0.0.1:8196",
        );
        $body = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    return [$handled, http_response_code(), $body];
};
foreach (
    ["/aktuelles/rss/", "/site/aktuelles/rss", "/aktuelles/old-rss/"]
    as $uri
) {
    [$handled, $status, $body] = $call($uri);
    check(
        $handled && $status === 404 && !str_contains($body, "ORIGINAL_rss"),
        "RSS-only original and aliases unavailable on website: " . $uri,
    );
}
foreach (["/feeds/news/", "/feeds/news.rss.xml"] as $uri) {
    [$handled, $status, $body] = $call($uri);
    check(
        $handled &&
            $status === 200 &&
            in_array("ORIGINAL_rss", titles($body), true) &&
            !in_array("ORIGINAL_website", titles($body), true),
        "actual public news feed routing: " . $uri,
    );
    [, $status, $body] = $call($uri, "HEAD");
    check($status === 200 && $body === "", "RSS HEAD returns no body: " . $uri);
    [, $status] = $call($uri, "POST");
    check($status === 405, "RSS POST rejected: " . $uri);
}
[, $status, $body] = $call("/sitemap.xml");
check(
    $status === 200 &&
        !str_contains($body, "aktuelles/rss") &&
        str_contains($body, "aktuelles/website"),
    "sitemap observes the same website channel",
);
$event = $pages->save([
    "slug" => "veranstaltungen/feed-test",
    "title" => "ORIGINAL_event",
    "template" => "event",
    "event_start" => "2050-01-12T19:30",
    "event_address" => "Testweg 1\n12345 Teststadt",
    "event_location" => "Vereinssaal",
]);
$pages->publish($event["id"], $event["version"]);
$mixed = $feeds->rss("mixed");
check(
    in_array("ORIGINAL_event", titles($mixed), true) &&
        !in_array("ORIGINAL_event", titles($feeds->rss("news")), true),
    "combined legacy RSS adds events while news RSS stays typed",
);
check(
    str_contains($mixed, "12.01.2050") &&
        str_contains($mixed, "19:30 Uhr") &&
        str_contains($mixed, "Vereinssaal") &&
        str_contains($mixed, "Testweg 1 12345 Teststadt"),
    "legacy event RSS includes Berlin date, time, venue and one-line address",
);
check(
    !array_filter(
        titles($mixed),
        static fn(string $title): bool => str_starts_with($title, "STANDARD_"),
    ),
    "legacy mixed stream excludes ordinary website pages",
);
$model = new DataModel($db, $access);
$model->saveTemplate([
    "key" => "club_news",
    "label" => "Eigene Neuigkeiten",
    "renderer" => "news",
    "fields" => [],
]);
$custom = $pages->save([
    "title" => "ORIGINAL_custom_news",
    "slug" => "aktuelles/custom-news",
    "template" => "club_news",
    "news_date" => "2050-01-12",
    "news_channels" => ["rss"],
]);
$custom = $pages->publish($custom["id"], $custom["version"]);
check(
    $pages->publicPage($custom["slug"]) === null &&
        in_array("ORIGINAL_custom_news", titles($feeds->rss("news")), true),
    "custom captured news renderer observes the same website and RSS channels",
);
$model->saveTemplate(
    [
        "key" => "club_news",
        "label" => "Jetzt allgemeine Seite",
        "renderer" => "standard",
        "fields" => [],
    ],
    1,
);
check(
    $pages->publicPage($custom["slug"]) === null &&
        in_array("ORIGINAL_custom_news", titles($feeds->rss("news")), true),
    "later template changes cannot bypass the original public channel decision",
);
rejects(
    fn() => $pages->publicList(50, 0, "rss", ["news') OR 1 --"]),
    "public renderer filter cannot inject selector SQL",
);
for ($i = 0; $i < 52; $i++) {
    $p = $articles->save([
        "title" => "BOUNDED_NEWS_" . $i,
        "slug" => "aktuelles/limit-" . $i,
        "news_date" => "2050-01-12",
        "news_channels" => ["rss"],
    ]);
    $articles->publish($p["id"], $p["version"]);
}
check(
    count(titles($feeds->rss("news"))) === 50 &&
        count(titles($feeds->rss("mixed"))) === 50,
    "both legacy RSS selections keep their fifty-original limit",
);
check(
    count(titles($feeds->rss())) > 50,
    "existing all-publications native feed remains available independently",
);
check(
    !array_filter(
        get_declared_classes(),
        static fn(string $class): bool => str_starts_with(
            $class,
            "ProcessWire\\",
        ),
    ),
    "native news and RSS load no ProcessWire classes",
);
echo json_encode(
    [
        "suite" => "native-news-channels",
        "passed" => count($checks),
        "external_transport" => false,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;
