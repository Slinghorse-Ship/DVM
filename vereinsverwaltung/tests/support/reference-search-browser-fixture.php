<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Media, DataModel};
use Kulturhus\Native\Shared\Db;
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
$root =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/integrated-manager-runtime";
$pages = new Pages($db, $access);
$media = new Media($db, $access, $root . "/media");
$model = new DataModel($db, $access);
$definitions = array_column($model->fields(), null, "key");
foreach (
    [
        "browser_lookup_page" => ["Own lookup page link", "page-reference"],
        "browser_lookup_image" => [
            "Own lookup picture link",
            "media-reference",
        ],
    ]
    as $key => [$label, $type]
) {
    if (
        isset($definitions[$key]) &&
        ($definitions[$key]["label"] !== $label ||
            $definitions[$key]["type"] !== $type)
    ) {
        throw new RuntimeException("Own lookup field name occupied.");
    }
    if (!isset($definitions[$key])) {
        $model->saveField([
            "key" => $key,
            "label" => $label,
            "type" => $type,
            "required" => $type === "page-reference",
        ]);
    }
}
$templates = array_column($model->templates(), null, "key");
if (!isset($templates["browser_lookup_template"])) {
    $model->saveTemplate([
        "key" => "browser_lookup_template",
        "label" => "Own lookup template",
        "fields" => ["browser_lookup_page", "browser_lookup_image"],
    ]);
} elseif (
    $templates["browser_lookup_template"]["label"] !== "Own lookup template"
) {
    throw new RuntimeException("Own lookup template name occupied.");
}
$pixels = imagecreatetruecolor(80, 50);
imagefill($pixels, 0, 0, imagecolorallocate($pixels, 30, 90, 60));
imagepng($pixels, $root . "/reference-search-pixels.png");
unset($pixels);
$images = [];
for ($i = 0; $i < 58; $i++) {
    $name = "own-lookup-" . sprintf("%02d", $i) . ".png";
    $alt = "Own lookup picture " . sprintf("%02d", $i);
    $existing = Db::run(
        $db,
        "SELECT id FROM khcms_media WHERE club_id=? AND original_name=? AND alt=? LIMIT 2",
        [$access->club(), $name, $alt],
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($existing) > 1) {
        throw new RuntimeException("Own lookup picture ambiguous.");
    }
    $entry = $existing
        ? ["id" => $existing[0]]
        : $media->upload($root . "/reference-search-pixels.png", $name, $alt);
    $images[] = ["id" => $entry["id"], "name" => $name, "alt" => $alt];
}
$rows = [];
for ($i = 0; $i < 69; $i++) {
    $title =
        "Own lookup page " .
        sprintf("%02d", $i) .
        ($i === 68 ? ' 100%_! " <marker> Ä' : "");
    $slug = "synthetic-lookup-" . sprintf("%02d", $i);
    $old =
        $pages->search([["field" => "slug", "value" => $slug]], 1)[0] ?? null;
    if ($old && $old["title"] !== $title) {
        throw new RuntimeException("Own lookup page occupied.");
    }
    $input = [
        "title" => $title,
        "slug" => $slug,
        "body_html" => "<p>Own private lookup body " . $i . "</p>",
        "nav_visible" => false,
        "media_ids" => $i === 0 ? [$images[0]["id"]] : [],
    ];
    if ($old) {
        $input["id"] = $old["id"];
    }
    $row = $pages->save($input, $old["version"] ?? 0);
    if ($i === 0) {
        $row = $pages->publish($row["id"], $row["version"]);
    }
    $rows[] = ["id" => $row["id"], "slug" => $slug, "title" => $title];
}
$title = "Own lookup editor";
$slug = "synthetic-lookup-editor";
$old = $pages->search([["field" => "slug", "value" => $slug]], 1)[0] ?? null;
if ($old && $old["title"] !== $title) {
    throw new RuntimeException("Own lookup editor occupied.");
}
$input = [
    "title" => $title,
    "slug" => $slug,
    "template" => "browser_lookup_template",
    "nav_visible" => false,
    "parent_id" => $rows[68]["id"],
    "custom_fields" => [
        "browser_lookup_page" => $rows[68]["id"],
        "browser_lookup_image" => $images[57]["id"],
    ],
    "body_html" => "<p>Own unmodified editor content</p>",
];
if ($old) {
    $input["id"] = $old["id"];
}
$editor = $pages->save($input, $old["version"] ?? 0);
$imageHash = hash_file(
    "sha256",
    $media->variant($images[57]["id"], "original")["path"],
);
file_put_contents(
    $root . "/reference-search-browser.json",
    json_encode(
        [
            "rows" => $rows,
            "images" => $images,
            "editor" => [
                "id" => $editor["id"],
                "version" => $editor["version"],
            ],
            "image_hash" => $imageHash,
        ],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
    ),
);
chmod($root . "/reference-search-browser.json", 0600);
echo "Own 69-page/58-picture reference catalogue and selected originals prepared; no external transport.\n";
