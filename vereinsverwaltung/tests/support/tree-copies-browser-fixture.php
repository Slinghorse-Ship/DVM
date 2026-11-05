<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, DataModel, Media};
use Kulturhus\Native\Integration\ManagerBridge;
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException("Own synthetic manager fixture required.");
}
$db = TestSupport::db();
$access = (new ManagerBridge(
    $db,
    "manager-admin",
    "native",
    static fn() => null,
))->access();
$pages = new Pages($db, $access);
$model = new DataModel($db, $access);
$definitions = array_column($model->fields(), null, "key");
if (!isset($definitions["browser_tree_link"])) {
    $model->saveField([
        "key" => "browser_tree_link",
        "label" => "Own tree link",
        "type" => "page-reference",
    ]);
} elseif (
    $definitions["browser_tree_link"]["label"] !== "Own tree link" ||
    $definitions["browser_tree_link"]["type"] !== "page-reference"
) {
    throw new RuntimeException("Own tree field occupied.");
}
$templates = array_column($model->templates(), null, "key");
if (!isset($templates["browser_tree_page"])) {
    $model->saveTemplate([
        "key" => "browser_tree_page",
        "label" => "Own tree page",
        "renderer" => "standard",
        "fields" => ["browser_tree_link"],
    ]);
} elseif ($templates["browser_tree_page"]["label"] !== "Own tree page") {
    throw new RuntimeException("Own tree template occupied.");
}
$directory =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/integrated-manager-runtime";
$media = new Media($db, $access, $directory . "/media");
$photo = null;
foreach ($media->list() as $entry) {
    if ($entry["alt"] === "Own synthetic tree copy fixture pixels") {
        $photo = $entry;
    }
}
if (!$photo) {
    $image = imagecreatetruecolor(160, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, 34, 98, 64));
    imagepng($image, $directory . "/tree-copy-pixels.png");
    imagedestroy($image);
    $photo = $media->upload(
        $directory . "/tree-copy-pixels.png",
        "own-tree-copy.png",
        "Own synthetic tree copy fixture pixels",
    );
}
function treeFixtureSave(Pages $pages, array $input): array
{
    $old =
        $pages->search(
            [["field" => "slug", "value" => $input["slug"]]],
            1,
        )[0] ?? null;
    if ($old && $old["title"] !== $input["title"]) {
        throw new RuntimeException("Own tree source address occupied.");
    }
    if ($old) {
        $input["id"] = $old["id"];
    }
    return $pages->save($input, $old["version"] ?? 0);
}
$root = treeFixtureSave($pages, [
    "title" => "Own synthetic tree root",
    "slug" => "synthetic-tree-root",
    "template" => "browser_tree_page",
    "body_html" => "<p>Published original tree root</p>",
    "nav_visible" => false,
]);
$root = $pages->publish($root["id"], $root["version"]);
$child = treeFixtureSave($pages, [
    "title" => "Own synthetic tree child",
    "slug" => "synthetic-tree-child",
    "template" => "browser_tree_page",
    "parent_id" => $root["id"],
    "body_html" => "<p>Published original tree child</p>",
    "custom_fields" => ["browser_tree_link" => $root["id"]],
    "nav_visible" => false,
]);
$child = $pages->publish($child["id"], $child["version"]);
$album = treeFixtureSave($pages, [
    "title" => "Own synthetic tree album",
    "slug" => "galerie/synthetic-tree-album",
    "template" => "gallery",
    "parent_id" => $child["id"],
    "body_html" => "<p>Published original tree album</p>",
    "media_ids" => [$photo["id"]],
    "gallery_items" => [
        [
            "id" => $photo["id"],
            "caption" => "Own original tree caption",
            "tags" => ["Own original tree tag"],
        ],
    ],
    "nav_visible" => false,
]);
$album = $pages->publish($album["id"], $album["version"]);
$event = treeFixtureSave($pages, [
    "title" => "Own synthetic tree event",
    "slug" => "synthetic-tree-event",
    "template" => "event",
    "parent_id" => $root["id"],
    "body_html" => "<p>Published original tree event</p>",
    "event_start" => "2050-01-12T20:00",
    "event_location" => "Own synthetic tree location",
    "media_ids" => [$photo["id"]],
    "nav_visible" => false,
]);
$event = $pages->publish($event["id"], $event["version"]);
$private = treeFixtureSave($pages, [
    "title" => "Own synthetic tree private child",
    "slug" => "synthetic-tree-private",
    "parent_id" => $root["id"],
    "body_html" => "<p>Own never published child</p>",
    "nav_visible" => false,
]);
$root["custom_fields"]["browser_tree_link"] = $album["id"];
$root = $pages->save($root, $root["version"]);
$root = $pages->publish($root["id"], $root["version"]);
$root["body_html"] = "<p>Private original tree root</p>";
$root = $pages->save($root, $root["version"]);
$child["body_html"] = "<p>Private original tree child</p>";
$child = $pages->save($child, $child["version"]);
$fixture = [
    "source" => [
        "root" => $root,
        "child" => $child,
        "album" => $album,
        "event" => $event,
        "private" => $private,
    ],
    "photo" => $photo["id"],
];
$paging = treeFixtureSave($pages, [
    "title" => "Own synthetic child paging root",
    "slug" => "synthetic-tree-paging",
    "nav_visible" => false,
]);
$pagingChildren = [];
foreach (range(0, 54) as $i) {
    $row = treeFixtureSave($pages, [
        "title" => "Own paging child " . sprintf("%02d", $i),
        "slug" => "synthetic-tree-paging-" . sprintf("%02d", $i),
        "parent_id" => $paging["id"],
        "nav_visible" => false,
    ]);
    $pagingChildren[] = $row["id"];
}
$fixture["paging"] = ["root" => $paging["id"], "children" => $pagingChildren];
$path = $directory . "/tree-copies-browser.json";
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own five-page mixed tree and 55-child paging fixture prepared; no external transport.\n";
