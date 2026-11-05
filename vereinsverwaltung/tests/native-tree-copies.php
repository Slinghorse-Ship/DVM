<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    DataModel,
    Pages,
    Media,
    TreeCopies,
    TreeCopyAddresses,
    Conflict,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Web\Website\PageChildrenPanel;
use Kulturhus\Native\Integration\Routes;
$db = TestSupport::db();
if (($argv[1] ?? "") === "tree-race") {
    file_put_contents($argv[5] . ".ready", "ready");
    $until = microtime(true) + 10;
    while (!is_file($argv[6])) {
        if (microtime(true) > $until) {
            exit(3);
        }
        usleep(10000);
    }
    try {
        $result = (new TreeCopies($db, new Access($db, "admin")))->create(
            $argv[2],
            (int) $argv[3],
            ["title" => "Concurrent tree", "slug" => $argv[4]],
        );
        echo json_encode([
            "status" => "created",
            "count" => count($result["pages"]),
        ]);
    } catch (Conflict) {
        echo '{"status":"conflict"}';
    }
    exit();
}
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$pages = new Pages($db, $access);
$model = new DataModel($db, $access);
$copies = new TreeCopies($db, $access);
$n = 0;
function treeCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function treeReject(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        treeCheck($e instanceof $class, $label . " (" . get_class($e) . ")");
        return;
    }
    throw new RuntimeException($label);
}
function treeCount(PDO $db, string $table = "khcms_pages"): int
{
    return (int) $db->query("SELECT COUNT(*) FROM " . $table)->fetchColumn();
}
$directory = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/tree-copy";
mkdir($directory, 0700);
$image = imagecreatetruecolor(80, 60);
imagefill($image, 0, 0, imagecolorallocate($image, 15, 90, 65));
imagepng($image, $directory . "/own-pixels.png");
imagedestroy($image);
$media = new Media($db, $access, $directory . "/media");
$photo = $media->upload(
    $directory . "/own-pixels.png",
    "own-tree.png",
    "Own synthetic original tree photo",
);
$pixelBefore = [];
foreach (["original", "large", "medium", "thumb"] as $variant) {
    $pixelBefore[$variant] = hash_file(
        "sha256",
        $media->variant($photo["id"], $variant)["path"],
    );
}
$outside = $pages->save([
    "title" => "Outside page reference",
    "slug" => "tree-outside",
]);
$parent = $pages->save(["title" => "Outside parent", "slug" => "tree-parent"]);
$parent = $pages->publish($parent["id"], $parent["version"]);
$definitions = [];
foreach (
    [
        "inner" => "page-reference",
        "outer" => "page-reference",
        "photo" => "media-reference",
        "notes" => "richtext",
    ]
    as $key => $type
) {
    $definitions[$key] = $model->saveField([
        "key" => $key,
        "label" => "Tree " . $key,
        "type" => $type,
        "required" => in_array($key, ["outer", "photo"], true),
    ]);
}
$model->saveTemplate([
    "key" => "tree_page",
    "label" => "Own tree page",
    "renderer" => "standard",
    "fields" => array_keys($definitions),
]);
$values = [
    "outer" => $outside["id"],
    "photo" => $photo["id"],
    "notes" => "<p>Original <strong>formatting</strong></p>",
];
$root = $pages->save([
    "title" => "Original tree root",
    "slug" => "tree-original",
    "template" => "tree_page",
    "parent_id" => $parent["id"],
    "body_html" => "<p>Published root</p>",
    "custom_fields" => $values,
    "media_ids" => [$photo["id"]],
]);
$root = $pages->publish($root["id"], $root["version"]);
$child = $pages->save([
    "title" => "Original child",
    "slug" => "tree-original/child",
    "template" => "tree_page",
    "parent_id" => $root["id"],
    "body_html" => "<p>Published child</p>",
    "custom_fields" => $values + ["inner" => $root["id"]],
]);
$child = $pages->publish($child["id"], $child["version"]);
$grand = $pages->save([
    "title" => "Original album grandchild",
    "slug" => "galerie/own-tree-album",
    "template" => "gallery",
    "parent_id" => $child["id"],
    "media_ids" => [$photo["id"]],
    "gallery_items" => [
        [
            "id" => $photo["id"],
            "caption" => "Own original caption",
            "tags" => ["Own original tag"],
        ],
    ],
]);
$grand = $pages->publish($grand["id"], $grand["version"]);
$event = $pages->save([
    "title" => "Original event sibling",
    "slug" => "tree-original/event",
    "template" => "event",
    "parent_id" => $root["id"],
    "event_start" => "2050-01-12T12:00",
    "event_location" => "Own original place",
    "media_ids" => [$photo["id"]],
]);
$event = $pages->publish($event["id"], $event["version"]);
$draftOnly = $pages->save([
    "title" => "Private only child",
    "slug" => "tree-original/private",
    "parent_id" => $root["id"],
]);
$publicOnly = $pages->save([
    "title" => "Published hierarchy child",
    "slug" => "tree-original/published",
    "template" => "tree_page",
    "parent_id" => $root["id"],
    "body_html" => "<p>Published hierarchy body</p>",
    "custom_fields" => $values,
]);
$publicOnly = $pages->publish($publicOnly["id"], $publicOnly["version"]);
$publicOnly["parent_id"] = $parent["id"];
$publicOnly["body_html"] = "<p>Private moved body</p>";
$publicOnly = $pages->save($publicOnly, $publicOnly["version"]);
$root["custom_fields"]["inner"] = $grand["id"];
$root = $pages->save($root, $root["version"]);
$root = $pages->publish($root["id"], $root["version"]);
$root["body_html"] = "<p>Private root text " . $root["id"] . "</p>";
$root = $pages->save($root, $root["version"]);
$child["body_html"] = "<p>Private child</p>";
$child = $pages->save($child, $child["version"]);
$originalIds = array_column(
    [$root, $child, $grand, $event, $draftOnly, $publicOnly, $parent, $outside],
    "id",
);
$before = array_map(fn($id) => $pages->get($id), $originalIds);
$publicBefore = [];
foreach ([$root, $child, $grand, $event, $publicOnly, $parent] as $row) {
    $publicBefore[$row["slug"]] = json_encode($pages->publicPage($row["slug"]));
}
$generation = $pages->generation();
$count = treeCount($db);
$revisions = treeCount($db, "khcms_revisions");
$draft = $copies->create($root["id"], $root["version"], [
    "title" => "Own draft tree",
    "slug" => "tree-copy-draft",
    "basis" => "draft",
]);
treeCheck(
    count($draft["pages"]) === 5 && count($draft["mapping"]) === 5,
    "draft hierarchy includes only current descendants",
);
treeCheck(
    isset($draft["mapping"][$draftOnly["id"]]) &&
        !isset($draft["mapping"][$publicOnly["id"]]),
    "draft moved/outside child stays outside the chosen subtree",
);
treeCheck(
    treeCount($db) === $count + 5,
    "actual subtree has independent five new identities",
);
$newRoot = $draft["root"];
$newChild = $pages->get($draft["mapping"][$child["id"]]);
$newGrand = $pages->get($draft["mapping"][$grand["id"]]);
treeCheck(
    $newRoot["parent_id"] === $parent["id"] &&
        $newChild["parent_id"] === $newRoot["id"] &&
        $newGrand["parent_id"] === $newChild["id"],
    "actual root boundary and all internal parent edges are correct",
);
treeCheck(
    $newRoot["custom_fields"]["inner"] === $newGrand["id"] &&
        $newChild["custom_fields"]["inner"] === $newRoot["id"],
    "forward and backward typed references bind to copied originals",
);
treeCheck(
    $newRoot["custom_fields"]["outer"] === $outside["id"] &&
        $newChild["custom_fields"]["outer"] === $outside["id"],
    "references outside the subtree remain unchanged",
);
treeCheck(
    $newRoot["custom_fields"]["photo"] === $photo["id"] &&
        $newRoot["media_ids"] === [$photo["id"]],
    "media references retain existing actual assets",
);
treeCheck(
    $newRoot["body_html"] === $root["body_html"] &&
        str_contains($newRoot["body_html"], $root["id"]),
    "ordinary text and embedded identity strings are never blindly replaced",
);
treeCheck(
    $newRoot["custom_fields"]["notes"] === $root["custom_fields"]["notes"],
    "formatted original custom fields retain exact sanitized HTML",
);
treeCheck(
    $newGrand["gallery_items"] === $grand["gallery_items"] &&
        str_starts_with($newGrand["slug"], "galerie/"),
    "mixed album descendant retains caption, tags, pixels and native namespace",
);
treeCheck(
    $newRoot["version"] === 2 &&
        $newChild["version"] === 2 &&
        count($pages->revisions($newRoot["id"])) === 2,
    "atomic forward-reference pass retains normal private revision history",
);
foreach ($draft["pages"] as $row) {
    treeCheck(
        $row["published_revision"] === null &&
            !$row["nav_visible"] &&
            !$row["archived"] &&
            $pages->publicPage($row["slug"]) === null,
        "copy remains private and outside menus: " . $row["title"],
    );
}
$public = $copies->create($root["id"], $root["version"], [
    "title" => "Own published tree",
    "slug" => "tree-copy-published",
    "basis" => "published",
]);
treeCheck(
    count($public["pages"]) === 5 &&
        !isset($public["mapping"][$draftOnly["id"]]) &&
        isset($public["mapping"][$publicOnly["id"]]),
    "published tree follows actual immutable hierarchy, never current draft edges",
);
treeCheck(
    $public["root"]["body_html"] === "<p>Published root</p>" &&
        $pages->get($public["mapping"][$child["id"]])["body_html"] ===
            "<p>Published child</p>",
    "published basis retains actual original bodies despite private edits",
);
treeCheck(
    $pages->get($public["mapping"][$publicOnly["id"]])["body_html"] ===
        "<p>Published hierarchy body</p>",
    "published moved child copies original body and original parent",
);
treeCheck(
    $pages->get($public["mapping"][$publicOnly["id"]])["parent_id"] ===
        $public["root"]["id"],
    "published child is attached to the new published-basis root",
);
treeCheck(
    array_map(fn($id) => $pages->get($id), $originalIds) === $before,
    "all original drafts, versions and parent/reference values are exact",
);
foreach ($publicBefore as $slug => $json) {
    treeCheck(
        json_encode($pages->publicPage($slug)) === $json,
        "actual original public snapshot unchanged: " . $slug,
    );
}
foreach ($pixelBefore as $variant => $hash) {
    treeCheck(
        hash_file("sha256", $media->variant($photo["id"], $variant)["path"]) ===
            $hash,
        "original actual media bytes unchanged: " . $variant,
    );
}
treeCheck(
    $pages->generation() === $generation,
    "subtree operations never invalidate original public cache",
);
treeCheck(
    $pages->events()->publicId($draft["mapping"][$event["id"]]) === null,
    "copied private event has no public catalogue entry",
);

$collisionSlug = TreeCopyAddresses::child("tree-conflict", [
    "id" => $grand["id"],
    "payload" => $grand,
]);
$pages->save([
    "title" => "Own occupied generated child URI",
    "slug" => $collisionSlug,
]);
$count = treeCount($db);
$revisions = treeCount($db, "khcms_revisions");
treeReject(
    fn() => $copies->create($root["id"], $root["version"], [
        "title" => "Failed tree",
        "slug" => "tree-conflict",
    ]),
    Conflict::class,
    "late mixed-child address conflict rolls back prior creations",
);
treeCheck(
    treeCount($db) === $count &&
        treeCount($db, "khcms_revisions") === $revisions &&
        $pages->search([["field" => "slug", "value" => "tree-conflict"]]) ===
            [],
    "conflict leaves no root, child or partial revision",
);
foreach (
    [
        ["title" => "Bad", "slug" => "tree-bad", "basis" => "other"],
        ["title" => "Bad", "slug" => "tree-bad", "parent_id" => $outside["id"]],
        ["title" => [], "slug" => "tree-bad"],
        ["title" => "Bad", "slug" => str_repeat("a", 121)],
    ]
    as $i => $options
) {
    treeReject(
        fn() => $copies->create($root["id"], $root["version"], $options),
        InvalidArgumentException::class,
        "closed subtree request " . $i,
    );
}
treeReject(
    fn() => $copies->create($root["id"], $root["version"] - 1, [
        "title" => "Bad",
        "slug" => "tree-stale",
    ]),
    Conflict::class,
    "stale root version rejects whole subtree",
);
treeReject(
    fn() => $copies->create($draftOnly["id"], $draftOnly["version"], [
        "title" => "Bad",
        "slug" => "tree-no-original",
        "basis" => "published",
    ]),
    Conflict::class,
    "published basis cannot silently include an unpublished root",
);
treeReject(
    fn() => (new TreeCopies($db, new Access($db, $actors["reader"])))->create(
        $root["id"],
        $root["version"],
        ["title" => "Bad", "slug" => "tree-reader"],
    ),
    DomainException::class,
    "reader cannot create subtree",
);
treeReject(
    fn() => (new TreeCopies($db, new Access($db, $actors["outsider"])))->create(
        $root["id"],
        $root["version"],
        ["title" => "Bad", "slug" => "tree-outsider"],
    ),
    RuntimeException::class,
    "other club cannot inspect or copy subtree",
);
$count = treeCount($db);
$db->beginTransaction();
$copies->create($root["id"], $root["version"], [
    "title" => "Outer rollback",
    "slug" => "tree-outer-rollback",
]);
$db->rollBack();
treeCheck(
    treeCount($db) === $count,
    "successful subtree remains in the caller transaction and rolls back fully",
);
$release = $directory . "/race-release";
$processes = [];
for ($i = 0; $i < 2; ++$i) {
    $prefix = $directory . "/race-" . $i;
    $process = proc_open(
        [
            ...TestSupport::phpCommand(),
            __FILE__,
            "tree-race",
            $root["id"],
            (string) $root["version"],
            "tree-concurrent",
            $prefix,
            $release,
        ],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Tree race process unavailable");
    }
    fclose($pipes[0]);
    $processes[] = [$process, $pipes, $prefix];
}
$deadline = microtime(true) + 10;
while (
    !is_file($processes[0][2] . ".ready") ||
    !is_file($processes[1][2] . ".ready")
) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException("Tree race readiness timeout");
    }
    usleep(10000);
}
file_put_contents($release, "go");
$results = [];
foreach ($processes as [$process, $pipes]) {
    $result = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    treeCheck(
        proc_close($process) === 0 && $errors === "",
        "actual concurrent tree process exits cleanly",
    );
    $results[] = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
}
$statuses = array_column($results, "status");
sort($statuses);
treeCheck(
    $statuses === ["conflict", "created"] && treeCount($db) === $count + 5,
    "actual simultaneous subtree requests create exactly one entire five-page tree",
);

$manager = new Manager($db, $access, $directory . "/media", "own-tree-csrf");
$result = $manager->action(
    "website",
    [
        "csrf" => "own-tree-csrf",
        "operation" => "page-copy",
        "id" => $root["id"],
        "version" => (string) $root["version"],
        "copy_title" => "Controller tree",
        "copy_slug" => "tree-controller",
        "copy_tree" => "1",
    ],
    [],
);
treeCheck(
    $result["view"] === "website" &&
        str_starts_with($result["notice"], "5 Seiten") &&
        $pages->get($result["item"])["published_revision"] === null,
    "actual controller creates entire tree and opens private root",
);
foreach (
    [
        ["copy_tree" => ["1"]],
        ["copy_tree" => "yes"],
        ["id" => [$root["id"]]],
        ["operation" => ["page-copy"]],
    ]
    as $i => $bad
) {
    treeReject(
        fn() => $manager->action(
            "website",
            array_replace(
                [
                    "csrf" => "own-tree-csrf",
                    "operation" => "page-copy",
                    "id" => $root["id"],
                    "version" => (string) $root["version"],
                    "copy_title" => "Bad",
                    "copy_slug" => "tree-controller-bad",
                ],
                $bad,
            ),
            [],
        ),
        InvalidArgumentException::class,
        "actual controller refuses malformed subtree fields " . $i,
    );
}
treeReject(
    fn() => $manager->action(
        "website",
        [
            "csrf" => "wrong",
            "operation" => "page-copy",
            "id" => $root["id"],
            "version" => (string) $root["version"],
            "copy_title" => "Bad",
            "copy_slug" => "tree-bad-csrf",
            "copy_tree" => "1",
        ],
        [],
    ),
    DomainException::class,
    "subtree controller retains original CSRF boundary",
);
$model->saveField(
    array_replace($definitions["notes"], ["max" => 199]),
    $definitions["notes"]["version"],
);
$count = treeCount($db);
treeReject(
    fn() => $copies->create($root["id"], $root["version"], [
        "title" => "Changed model",
        "slug" => "tree-changed-model",
    ]),
    Conflict::class,
    "changed model in source fails before partial subtree creation",
);
treeCheck(treeCount($db) === $count, "model rejection creates no partial tree");

$self = $pages->save([
    "title" => "Own self reference source",
    "slug" => "tree-self",
    "template" => "tree_page",
    "custom_fields" => $values,
]);
$self["custom_fields"]["inner"] = $self["id"];
$self = $pages->save($self, $self["version"]);
$selfCopy = $copies->create($self["id"], $self["version"], [
    "title" => "Own self reference copy",
    "slug" => "tree-self-copy",
]);
treeCheck(
    count($selfCopy["pages"]) === 1 &&
        $selfCopy["root"]["custom_fields"]["inner"] === $selfCopy["root"]["id"],
    "self page reference binds to the new identity inside a one-page subtree",
);
treeCheck(
    $pages->get($self["id"])["custom_fields"]["inner"] === $self["id"],
    "original self reference stays unchanged",
);
$rssRoot = $pages->save([
    "title" => "Own RSS tree root",
    "slug" => "aktuelles/tree-rss-root",
    "template" => "news",
    "news_date" => "2050-01-01",
    "news_channels" => ["rss"],
    "body_html" => "<p>Own original RSS root</p>",
]);
$rssRoot = $pages->review($rssRoot["id"], "approved", $rssRoot["version"]);
$rssRoot = $pages->publish($rssRoot["id"], $rssRoot["version"]);
$rssChild = $pages->save([
    "title" => "Own RSS tree child",
    "slug" => "aktuelles/tree-rss-child",
    "template" => "news",
    "parent_id" => $rssRoot["id"],
    "news_date" => "2050-01-02",
    "news_channels" => ["rss"],
    "body_html" => "<p>Own original RSS child</p>",
]);
$rssChild = $pages->review($rssChild["id"], "approved", $rssChild["version"]);
$rssChild = $pages->publish($rssChild["id"], $rssChild["version"]);
$rssBefore = json_encode($pages->publicPage($rssRoot["slug"], "rss"));
$rssCopy = $copies->create($rssRoot["id"], $rssRoot["version"], [
    "title" => "Own private RSS tree",
    "slug" => "aktuelles/tree-rss-copy",
    "basis" => "published",
]);
treeCheck(
    count($rssCopy["pages"]) === 2 &&
        $pages->get($rssCopy["mapping"][$rssChild["id"]])["parent_id"] ===
            $rssCopy["root"]["id"],
    "actual published RSS-only originals are not excluded by website visibility",
);
treeCheck(
    $rssCopy["root"]["news_channels"] === ["rss"] &&
        $rssCopy["root"]["news_review"] === "draft" &&
        $rssCopy["root"]["published_revision"] === null,
    "RSS tree copies retain channels and require their own publication decision",
);
treeCheck(
    json_encode($pages->publicPage($rssRoot["slug"], "rss")) === $rssBefore &&
        $pages->publicPage($rssRoot["slug"]) === null,
    "original RSS bytes and website exclusion stay exact",
);
$cycleA = $pages->save([
    "title" => "Own corrupt fixture A",
    "slug" => "tree-cycle-a",
]);
$cycleB = $pages->save([
    "title" => "Own corrupt fixture B",
    "slug" => "tree-cycle-b",
    "parent_id" => $cycleA["id"],
]);
Db::run(
    $db,
    "UPDATE khcms_pages SET draft_json=JSON_SET(draft_json,'$.parent_id',?) WHERE id=? AND club_id=?",
    [$cycleB["id"], $cycleA["id"], $access->club()],
);
$count = treeCount($db);
treeReject(
    fn() => $copies->create($cycleA["id"], $cycleA["version"], [
        "title" => "Cycle copy",
        "slug" => "tree-cycle-copy",
    ]),
    InvalidArgumentException::class,
    "corrupt source hierarchy rejects an actual cycle before writes",
);
treeCheck(
    treeCount($db) === $count,
    "cycle rejection leaves no partial new tree",
);

$large = $pages->save([
    "title" => "Own bounded source root",
    "slug" => "tree-large",
]);
for ($i = 0; $i < 499; ++$i) {
    $pages->save([
        "title" => "Own bounded descendant " . $i,
        "slug" => "tree-large-child-" . $i,
        "parent_id" => $large["id"],
    ]);
}
$largeCopy = $copies->create($large["id"], $large["version"], [
    "title" => "Own exact 500 tree",
    "slug" => "tree-exact-500",
]);
treeCheck(
    count($largeCopy["pages"]) === 500 && count($largeCopy["mapping"]) === 500,
    "exact 500-page subtree copies despite larger unrelated club inventory",
);
treeCheck(
    count(
        array_filter(
            $largeCopy["pages"],
            fn($row) => $row["parent_id"] === $largeCopy["root"]["id"],
        ),
    ) === 499,
    "every one of the 499 real child rows has the new parent",
);
$panel = new PageChildrenPanel($db, $access);
$_GET = [];
$firstPanel = $panel->render($largeCopy["root"]);
treeCheck(
    substr_count($firstPanel, "data-child-page=") === 25 &&
        str_contains($firstPanel, "499 Unterseiten · Seite 1 von 20"),
    "shared child panel renders only 25 database rows with exact count",
);
$_GET = ["children_page" => "20"];
$lastPanel = $panel->render($largeCopy["root"]);
treeCheck(
    substr_count($lastPanel, "data-child-page=") === 24 &&
        str_contains($lastPanel, "Seite 20 von 20"),
    "shared child panel reaches every remaining original on last page",
);
$_GET = ["children_page" => "999999"];
treeCheck(
    $panel->render($largeCopy["root"]) === $lastPanel,
    "child page clamp preserves exact final slice",
);
$translated = Routes::translate(
    "/?" .
        http_build_query([
            "view" => "website",
            "item" => $largeCopy["root"]["id"],
            "children_page" => "2",
        ]),
);
parse_str(parse_url($translated, PHP_URL_QUERY), $route);
treeCheck(
    ($route["children_page"] ?? "") === "2" &&
        ($route["native_item"] ?? "") === $largeCopy["root"]["id"],
    "manager route translation retains exact child page and parent identity",
);
foreach ([["1"], "0", "-1", "1; DROP TABLE khcms_pages"] as $invalid) {
    $_GET = ["children_page" => $invalid];
    treeReject(
        fn() => $panel->render($largeCopy["root"]),
        InvalidArgumentException::class,
        "shared child panel refuses invalid page input",
    );
}
$_GET = [];
$pages->save([
    "title" => "Own overflowing descendant",
    "slug" => "tree-large-overflow",
    "parent_id" => $large["id"],
]);
$count = treeCount($db);
$revisions = treeCount($db, "khcms_revisions");
treeReject(
    fn() => $copies->create($large["id"], $large["version"], [
        "title" => "Overflow tree",
        "slug" => "tree-overflow",
    ]),
    InvalidArgumentException::class,
    "501-page subtree rejects instead of silently truncating",
);
treeCheck(
    treeCount($db) === $count &&
        treeCount($db, "khcms_revisions") === $revisions,
    "overflow traversal performs no partial writes",
);
echo json_encode(
    [
        "suite" => "native-tree-copies",
        "checks" => $n,
        "storage" => "fresh synthetic MySQL and actual media",
        "external_requests" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
