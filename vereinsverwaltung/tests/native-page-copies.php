<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    DataModel,
    Pages,
    PageCopies,
    Media,
    Conflict,
    PathHistory,
    NewsReview,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Integration\EmbeddedManager;

$db = TestSupport::db();
if (($argv[1] ?? "") === "copy-race") {
    $access = new Access($db, $argv[2]);
    file_put_contents($argv[6] . ".ready", "ready");
    $deadline = microtime(true) + 10;
    while (!is_file($argv[7])) {
        if (microtime(true) > $deadline) {
            exit(3);
        }
        usleep(10000);
    }
    try {
        $copy = (new PageCopies($db, $access))->create(
            $argv[3],
            (int) $argv[4],
            ["title" => "Concurrent private copy", "slug" => $argv[5]],
        );
        echo json_encode(
            ["status" => "created", "id" => $copy["id"]],
            JSON_THROW_ON_ERROR,
        );
    } catch (Conflict) {
        echo '{"status":"conflict"}';
    }
    exit();
}
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$pages = new Pages($db, $access);
$copies = new PageCopies($db, $access);
$model = new DataModel($db, $access);
$root = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/page-copies";
mkdir($root, 0700, true);
$n = 0;
function copyCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function copyReject(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $error) {
        copyCheck(
            $error instanceof $class,
            $label . " (" . get_class($error) . ")",
        );
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$parent = $pages->save([
    "title" => "Original parent",
    "slug" => "original-parent",
]);
$parent = $pages->publish($parent["id"], $parent["version"]);
$reference = $pages->save([
    "title" => "Original reference",
    "slug" => "original-reference",
]);
$image = imagecreatetruecolor(80, 60);
imagefill($image, 0, 0, imagecolorallocate($image, 24, 90, 60));
imagepng($image, $root . "/synthetic.png");
imagedestroy($image);
$media = new Media($db, $access, $root . "/media");
$photo = $media->upload(
    $root . "/synthetic.png",
    "synthetic.png",
    "Own synthetic copy pixels",
);
$photoProof = [];
foreach (["original", "large", "medium", "thumb"] as $size) {
    $photoProof[$size] = hash_file(
        "sha256",
        $media->variant($photo["id"], $size)["path"],
    );
}
foreach (
    [
        ["notes", "richtext"],
        ["linked_page", "page-reference"],
        ["linked_image", "media-reference"],
    ]
    as [$key, $type]
) {
    $model->saveField(["key" => $key, "label" => $key, "type" => $type]);
}
$model->saveTemplate([
    "key" => "copy_page",
    "label" => "Copy template",
    "renderer" => "standard",
    "fields" => ["notes", "linked_page", "linked_image"],
]);
$source = $pages->save([
    "title" => "Original saved page",
    "slug" => "original-copy-source",
    "template" => "copy_page",
    "parent_id" => $parent["id"],
    "body_html" => "<p><strong>Published original 🎶</strong></p>",
    "seo_title" => "Original SEO",
    "seo_description" => "Original description",
    "media_ids" => [$photo["id"]],
    "gallery_items" => [
        [
            "id" => $photo["id"],
            "caption" => "Original caption",
            "tags" => ["Original tag"],
        ],
    ],
    "custom_fields" => [
        "notes" => "<p>Published <em>notes</em></p>",
        "linked_page" => $reference["id"],
        "linked_image" => $photo["id"],
    ],
]);
$source = $pages->publish($source["id"], $source["version"]);
$publicOriginal = json_encode($pages->publicPage($source["slug"]));
$source["body_html"] = "<p>Latest <strong>private draft</strong></p>";
$source["custom_fields"]["notes"] = "<p>Current <em>draft notes</em></p>";
$source = $pages->save($source, $source["version"]);
$before = json_encode($source);
$revisions = json_encode($pages->revisions($source["id"]));
$generation = $pages->generation();
$draft = $copies->create($source["id"], $source["version"], [
    "title" => "Own copy",
    "slug" => "own-copy",
]);
copyCheck(
    $draft["id"] !== $source["id"] &&
        $draft["version"] === 1 &&
        $draft["published_revision"] === null,
    "copy has independent new identity and first private revision",
);
copyCheck(
    $draft["body_html"] === $source["body_html"] &&
        $draft["custom_fields"] === $source["custom_fields"],
    "latest saved rich content and typed values retained",
);
copyCheck(
    $draft["parent_id"] === $parent["id"] &&
        !$draft["nav_visible"] &&
        $draft["nav_order"] === 0 &&
        $draft["nav_label"] === "",
    "parent retained while new copy remains outside public navigation",
);
copyCheck(
    $draft["media_ids"] === $source["media_ids"] &&
        $draft["gallery_items"] === $source["gallery_items"],
    "actual image references, order, captions and tags retained",
);
copyCheck(
    $draft["seo_title"] === "Original SEO" &&
        $draft["seo_description"] === "Original description",
    "original editable metadata copied for review",
);
$published = $copies->create($source["id"], $source["version"], [
    "title" => "Public basis copy",
    "slug" => "public-basis-copy",
    "basis" => "published",
]);
$originalSnapshot = json_decode($publicOriginal, true);
copyCheck(
    $published["body_html"] === $originalSnapshot["body_html"] &&
        $published["custom_fields"] === $originalSnapshot["custom_fields"],
    "explicit published basis copies exact original values despite newer private draft",
);
copyCheck(
    $pages->publicPage($draft["slug"]) === null &&
        $pages->publicPage($published["slug"]) === null,
    "neither copy becomes public through creation",
);
copyCheck(
    json_encode($pages->get($source["id"])) === $before &&
        json_encode($pages->revisions($source["id"])) === $revisions,
    "source draft and every original revision remain unchanged",
);
copyCheck(
    json_encode($pages->publicPage($source["slug"])) === $publicOriginal &&
        $pages->generation() === $generation,
    "public original and cache generation remain unchanged",
);
foreach ($photoProof as $size => $hash) {
    copyCheck(
        hash_equals(
            $hash,
            hash_file("sha256", $media->variant($photo["id"], $size)["path"]),
        ),
        "original image bytes retained: " . $size,
    );
}
$count = (int) $db->query("SELECT COUNT(*) FROM khcms_pages")->fetchColumn();
copyReject(
    fn() => $copies->create($source["id"], $source["version"], [
        "title" => "Duplicate",
        "slug" => "own-copy",
    ]),
    Conflict::class,
    "repeated new address cannot create another copy",
);
copyReject(
    fn() => $copies->create($source["id"], $source["version"] - 1, [
        "title" => "Stale",
        "slug" => "stale-copy",
    ]),
    Conflict::class,
    "stale source version cannot be copied",
);
copyReject(
    fn() => (new PageCopies($db, new Access($db, $actors["reader"])))->create(
        $source["id"],
        $source["version"],
        ["title" => "Denied", "slug" => "denied-copy"],
    ),
    DomainException::class,
    "reader cannot create copy",
);
copyReject(
    fn() => (new PageCopies($db, new Access($db, $actors["outsider"])))->create(
        $source["id"],
        $source["version"],
        ["title" => "Other club", "slug" => "other-club-copy"],
    ),
    RuntimeException::class,
    "other club cannot copy source",
);
foreach (
    [
        ["title" => [], "slug" => "array-copy"],
        ["title" => "Forged", "slug" => "forged-copy", "id" => $source["id"]],
        ["title" => "Mode", "slug" => "mode-copy", "basis" => "revision:1"],
        ["title" => "Unsafe", "slug" => "../../unsafe"],
    ]
    as $options
) {
    copyReject(
        fn() => $copies->create($source["id"], $source["version"], $options),
        InvalidArgumentException::class,
        "invalid copy options are refused",
    );
}
copyReject(
    fn() => $copies->create($reference["id"], $reference["version"], [
        "title" => "Absent public",
        "slug" => "absent-public",
        "basis" => "published",
    ]),
    Conflict::class,
    "unpublished source cannot supply invented public basis",
);
copyCheck(
    (int) $db->query("SELECT COUNT(*) FROM khcms_pages")->fetchColumn() ===
        $count,
    "all rejected copies leave no target pages",
);
(new PathHistory($db, $access))->remember(
    $source["id"],
    "retained-original-address",
    $source["version"],
);
copyReject(
    fn() => $copies->create($source["id"], $source["version"], [
        "title" => "Reserved",
        "slug" => "retained-original-address",
    ]),
    Conflict::class,
    "historical original address cannot be claimed by copy",
);
$db->beginTransaction();
$transactional = $copies->create($source["id"], $source["version"], [
    "title" => "Nested",
    "slug" => "nested-copy",
]);
copyCheck($db->inTransaction(), "copy keeps caller transaction active");
$db->rollBack();
copyCheck(
    !$pages->search([
        ["field" => "slug", "op" => "eq", "value" => "nested-copy"],
    ]),
    "caller rollback removes new page and its revision",
);
$children = [];
$release = $root . "/race-release";
for ($i = 0; $i < 2; $i++) {
    $prefix = $root . "/race-" . $i;
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            "copy-race",
            $actors["admin"],
            $source["id"],
            (string) $source["version"],
            "concurrent-copy",
            $prefix,
            $release,
        ],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Race process missing");
    }
    fclose($pipes[0]);
    $children[] = [$process, $pipes, $prefix];
}
$deadline = microtime(true) + 10;
while (
    !is_file($children[0][2] . ".ready") ||
    !is_file($children[1][2] . ".ready")
) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException("Copy race readiness timeout");
    }
    usleep(10000);
}
file_put_contents($release, "go");
$results = [];
foreach ($children as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    copyCheck(
        proc_close($process) === 0,
        "real concurrent copy process completes without database error",
    );
    $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)["status"];
}
sort($results);
copyCheck(
    $results === ["conflict", "created"] &&
        count(
            $pages->search([
                ["field" => "slug", "op" => "eq", "value" => "concurrent-copy"],
            ]),
        ) === 1,
    "actual concurrent clicks create exactly one private copy",
);
$news = $pages->save([
    "title" => "Original RSS-only article",
    "slug" => "aktuelles/original-rss-copy",
    "template" => "news",
    "news_date" => "2026-10-10",
    "news_channels" => ["rss"],
    "body_html" => "<p>RSS original</p>",
]);
$news = $pages->review($news["id"], "approved", $news["version"]);
$news = $pages->publish($news["id"], $news["version"]);
$newsCopy = $copies->create($news["id"], $news["version"], [
    "title" => "RSS copy",
    "slug" => "aktuelles/rss-copy",
    "basis" => "published",
]);
copyCheck(
    NewsReview::status($newsCopy) === "draft" &&
        $newsCopy["news_channels"] === ["rss"] &&
        $newsCopy["published_revision"] === null,
    "RSS-only published basis retains channels and requires its own new review",
);
$event = $pages->save([
    "title" => "Own original event",
    "slug" => "veranstaltungen/own-original",
    "template" => "event",
    "event_start" => "2026-11-14T20:00",
    "event_location" => "Own synthetic place",
]);
$event = $pages->publish($event["id"], $event["version"]);
$eventProjection = json_encode($pages->events()->publicId($event["id"]));
$eventCopy = $copies->create($event["id"], $event["version"], [
    "title" => "Event copy",
    "slug" => "veranstaltungen/event-copy",
]);
copyCheck(
    $eventCopy["event_start_utc"] === $event["event_start_utc"] &&
        $eventCopy["event_location"] === $event["event_location"] &&
        $pages->events()->publicId($eventCopy["id"]) === null,
    "copied event details do not create public calendar event",
);
copyCheck(
    json_encode($pages->events()->publicId($event["id"])) === $eventProjection,
    "original event projection remains exact",
);
$album = $pages->save([
    "title" => "Original copy album",
    "slug" => "galerie/original-copy-album",
    "template" => "gallery",
    "media_ids" => [$photo["id"]],
    "gallery_items" => [
        [
            "id" => $photo["id"],
            "caption" => "Album original",
            "tags" => ["Album tag"],
        ],
    ],
    "body_html" => "<p>Album <em>original</em></p>",
]);
$album = $pages->publish($album["id"], $album["version"]);
$albumOriginal = json_encode($pages->publicPage($album["slug"]));
$albumCopy = $copies->create($album["id"], $album["version"], [
    "title" => "Copied album",
    "slug" => "galerie/copied-album",
]);
copyCheck(
    $albumCopy["gallery_items"] === $album["gallery_items"] &&
        $albumCopy["body_html"] === $album["body_html"] &&
        $albumCopy["published_revision"] === null,
    "album copy retains image order, captions, tags and description privately",
);
copyCheck(
    json_encode($pages->publicPage($album["slug"])) === $albumOriginal,
    "original published album remains exact",
);
$albumCopy["gallery_items"][0]["caption"] = "Own copied caption";
$albumCopy = $pages->save($albumCopy, $albumCopy["version"]);
copyCheck(
    $pages->get($album["id"])["gallery_items"][0]["caption"] ===
        "Album original",
    "editing copied image caption preserves original album metadata",
);
$manager = new Manager($db, $access, $root . "/media", "synthetic-csrf");
$result = $manager->action(
    "news",
    [
        "csrf" => "synthetic-csrf",
        "operation" => "page-copy",
        "id" => $news["id"],
        "version" => $news["version"],
        "copy_title" => "Routed news copy",
        "copy_slug" => "aktuelles/routed-news-copy",
        "basis" => "published",
    ],
    [],
);
copyCheck(
    $result["view"] === "news" &&
        $pages->get($result["item"])["published_revision"] === null,
    "real manager action routes copied article to its original editor type",
);
copyReject(
    fn() => $copies->create($news["id"], $news["version"], [
        "title" => "Wrong namespace",
        "slug" => "wrong-news-namespace",
    ]),
    InvalidArgumentException::class,
    "copied article cannot become uneditable through an incompatible address",
);
$model->saveTemplate([
    "key" => "custom_copy_news",
    "label" => "Custom news renderer",
    "renderer" => "news",
    "fields" => ["notes"],
]);
$custom = $pages->save([
    "title" => "Custom rendered source",
    "slug" => "custom-rendered-source",
    "template" => "custom_copy_news",
    "news_date" => "2026-10-10",
    "custom_fields" => ["notes" => "<p>Custom original</p>"],
]);
$customResult = $manager->action(
    "website",
    [
        "csrf" => "synthetic-csrf",
        "operation" => "page-copy",
        "id" => $custom["id"],
        "version" => $custom["version"],
        "copy_title" => "Custom copy",
        "copy_slug" => "custom-rendered-copy",
    ],
    [],
);
copyCheck(
    $customResult["view"] === "website" &&
        $pages->get($customResult["item"])["template"] === "custom_copy_news",
    "custom rendered copy keeps generic editor and its declarative fields",
);
copyReject(
    fn() => $manager->action(
        "website",
        [
            "csrf" => "wrong",
            "operation" => "page-copy",
            "id" => $source["id"],
            "version" => $source["version"],
            "copy_title" => "CSRF",
            "copy_slug" => "csrf-copy",
        ],
        [],
    ),
    DomainException::class,
    "copy action retains normal CSRF protection",
);
copyReject(
    fn() => $manager->action(
        "website",
        [
            "csrf" => "synthetic-csrf",
            "operation" => "page-copy",
            "id" => $source["id"],
            "version" => "3garbage",
            "copy_title" => "Forged version",
            "copy_slug" => "forged-version",
        ],
        [],
    ),
    InvalidArgumentException::class,
    "copy HTTP action rejects malformed source version",
);
$embedded = new EmbeddedManager($manager, $access);
try {
    $embedded->action(
        "website",
        [
            "csrf" => "synthetic-csrf",
            "operation" => "page-copy",
            "id" => $source["id"],
            "version" => $source["version"],
            "copy_title" => "Duplicate in real shell",
            "copy_slug" => "own-copy",
        ],
        [],
    );
    throw new RuntimeException("Expected shell input conflict");
} catch (DomainException $error) {
    copyCheck(
        $error->getPrevious() instanceof Conflict &&
            str_contains($error->getMessage(), "Adresse ist bereits vergeben"),
        "shared manager shell keeps actionable copy conflict and original cause",
    );
}
$html = $manager->main("website", $source["id"]);
copyCheck(
    str_contains($html, "page-copy-panel") &&
        str_contains($html, 'name="basis"') &&
        str_contains($html, "btn btn-solid theme-primary"),
    "original page editor exposes shared typed Bootstrap copy form",
);
copyCheck(
    str_contains($manager->main("news", $news["id"]), "page-copy-panel") &&
        str_contains($manager->main("media", $album["id"]), "page-copy-panel"),
    "article and album editors use the same copy form",
);
$readerManager = new Manager(
    $db,
    new Access($db, $actors["reader"]),
    $root . "/media",
    "synthetic-csrf",
);
copyCheck(
    !str_contains(
        $readerManager->main("website", $source["id"]),
        "page-copy-panel",
    ),
    "read-only original does not expose copy controls",
);
$album = $pages->archive($album["id"], $album["version"]);
$archivedCopy = $copies->create($album["id"], $album["version"], [
    "title" => "Archived source copy",
    "slug" => "galerie/archived-source-copy",
]);
copyCheck(
    !$archivedCopy["archived"] &&
        $archivedCopy["published_revision"] === null &&
        $pages->get($album["id"])["archived"],
    "archived source can supply a fresh private editable copy while remaining archived",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id=? AND module=? AND capability=?",
    [$actors["admin"], "website", "edit"],
);
copyReject(
    fn() => $copies->create($source["id"], $source["version"], [
        "title" => "Revoked",
        "slug" => "revoked-copy",
    ]),
    DomainException::class,
    "current edit rights cannot be reused after revocation",
);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)",
    [$actors["admin"], "website", "edit"],
);
$definition = $model->field("notes");
$model->saveField(
    ["key" => "notes", "label" => "Changed notes", "type" => "richtext"],
    $definition["version"],
);
copyReject(
    fn() => $copies->create($source["id"], $source["version"], [
        "title" => "Changed model",
        "slug" => "changed-model-copy",
    ]),
    Conflict::class,
    "changed content model cannot silently reinterpret original copy values",
);
echo json_encode(
    [
        "checks" => $n,
        "mysql" => true,
        "real_concurrency" => true,
        "external_actions" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
