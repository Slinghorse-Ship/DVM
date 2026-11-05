<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Media, DataModel};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Integration\ManagerBridge;
$standalone = ($argv[1] ?? "") === "standalone";
$config = TestSupport::config();
if (
    PHP_SAPI !== "cli" ||
    ($standalone
        ? realpath((string) getenv("KH_NATIVE_TEST_CONFIG")) !==
                "/workspace/.setup/native-demo.json" ||
            ($config["label"] ?? "") !== "demo" ||
            ($config["owner"] ?? "") !== "cloud-onboarding-synthetic"
        : ($config["label"] ?? "") !== "integrated-manager")
) {
    throw new RuntimeException("Own synthetic group fixture required.");
}
$db = TestSupport::db();
$access = $standalone
    ? new Access($db, "admin")
    : (new ManagerBridge(
        $db,
        "manager-admin",
        "native",
        static fn() => null,
    ))->access();
$runtime =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) .
    ($standalone
        ? "/runtime-" . $config["database"]
        : "/integrated-manager-runtime");
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$prefix = "browser_group_";
$ownPage = static function (string $slug, array $input) use (
    $db,
    $access,
    $pages,
): array {
    $id = Db::run(
        $db,
        "SELECT id FROM khcms_pages WHERE club_id=? AND slug=?",
        [$access->club(), $slug],
    )->fetchColumn();
    if (!$id) {
        return $pages->save(["slug" => $slug] + $input);
    }
    $old = $pages->get($id);
    if ($old["title"] !== $input["title"]) {
        throw new RuntimeException("Unexpected original group fixture.");
    }
    return $pages->save(
        ["id" => $id, "slug" => $slug] + $input,
        $old["version"],
    );
};
$source = $ownPage("synthetic-group-reference", [
    "title" => "Own browser grouped reference",
    "nav_visible" => false,
]);
$media = new Media($db, $access, $runtime . "/media");
$ids = Db::run(
    $db,
    "SELECT id FROM khcms_media WHERE club_id=? AND original_name=? AND alt=?",
    [$access->club(), "own-browser-groups.png", "Own group picture"],
)->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) > 1) {
    throw new RuntimeException("Unexpected original group pixels.");
}
if ($ids) {
    $image = ["id" => $ids[0]];
} else {
    $pixels = imagecreatetruecolor(48, 32);
    imagefill($pixels, 0, 0, imagecolorallocate($pixels, 20, 90, 60));
    imagepng($pixels, $runtime . "/own-group-pixels.png");
    unset($pixels);
    $image = $media->upload(
        $runtime . "/own-group-pixels.png",
        "own-browser-groups.png",
        "Own group picture",
    );
}
$rule = [
    "match" => "all",
    "conditions" => [
        ["field" => $prefix . "active", "operator" => "eq", "value" => true],
    ],
];
$specs = [
    "active" => ["type" => "boolean", "required" => true],
    "amount" => ["type" => "decimal"],
    "title_note" => ["type" => "text", "required" => true],
    "formatted" => ["type" => "richtext", "required_when" => $rule],
    "page" => ["type" => "page-reference"],
    "image" => ["type" => "media-reference"],
    "conditional" => [
        "type" => "text",
        "show_when" => $rule,
        "required" => true,
    ],
];
$existing = array_column($model->fields(), null, "key");
foreach ($specs as $suffix => $spec) {
    $key = $prefix . $suffix;
    $input = ["key" => $key, "label" => "Own grouped " . $suffix] + $spec;
    if (!isset($existing[$key])) {
        $model->saveField($input);
    } elseif ($existing[$key]["label"] !== $input["label"]) {
        throw new RuntimeException("Unexpected own grouped definition.");
    }
}
$keys = array_map(static fn($key) => $prefix . $key, array_keys($specs));
$groups = [
    [
        "key" => "basics",
        "label" => "Allgemeine Angaben",
        "columns" => 2,
        "fields" => [$prefix . "active", $prefix . "amount"],
    ],
    [
        "key" => "body",
        "label" => "Beschreibung <wörtlich>",
        "description" => "Hinweise bleiben als Text erhalten.",
        "collapsed" => true,
        "fields" => [$prefix . "title_note"],
    ],
    [
        "key" => "format",
        "label" => "Formatierter Inhalt",
        "parent" => "body",
        "collapsed" => true,
        "fields" => [$prefix . "formatted"],
    ],
    [
        "key" => "references",
        "label" => "Seite und Bild",
        "parent" => "format",
        "collapsed" => true,
        "columns" => 2,
        "fields" => [$prefix . "page", $prefix . "image"],
    ],
    [
        "key" => "conditional",
        "label" => "Bedingte Zusatzangaben",
        "collapsed" => true,
        "fields" => [$prefix . "conditional"],
    ],
];
$templateKey = $prefix . "template";
$templates = array_column($model->templates(), null, "key");
$templateInput = [
    "key" => $templateKey,
    "label" => "Own browser grouped template",
    "fields" => $keys,
    "groups" => $groups,
];
if (!isset($templates[$templateKey])) {
    $template = $model->saveTemplate($templateInput);
} else {
    if ($templates[$templateKey]["label"] !== $templateInput["label"]) {
        throw new RuntimeException("Unexpected grouped template.");
    }
    $template = $model->saveTemplate(
        $templateInput,
        $templates[$templateKey]["version"],
    );
}
$values = [
    $prefix . "active" => false,
    $prefix . "amount" => "999999999999999999.999998",
    $prefix . "title_note" => "Original grouped title note",
    $prefix .
    "formatted" => "<p>Own <strong>grouped</strong> &lt;marker&gt;</p>",
    $prefix . "page" => $source["id"],
    $prefix . "image" => $image["id"],
    $prefix . "conditional" => null,
];
$editor = $ownPage("synthetic-groups-editor", [
    "title" => "Own browser grouped editor",
    "template" => $templateKey,
    "custom_fields" => $values,
    "nav_visible" => false,
]);
$original = $ownPage("synthetic-groups-public", [
    "title" => "Own browser grouped public original",
    "template" => $templateKey,
    "custom_fields" => $values,
    "body" => "Original structured publication stays unchanged.",
    "media_ids" => [$image["id"]],
    "nav_visible" => false,
]);
$original = $pages->publish($original["id"], $original["version"]);
$out = [
    "prefix" => $prefix,
    "template" => $templateKey,
    "template_version" => $template["version"],
    "editor" => ["id" => $editor["id"], "version" => $editor["version"]],
    "source" => $source["id"],
    "image" => $image["id"],
    "public_slug" => $original["slug"],
    "values" => $values,
    "groups" => $model->template($templateKey)["groups"],
];
$path = $standalone
    ? dirname(__DIR__, 3) . "/.local/browser-tests/field-groups-standalone.json"
    : $runtime . "/field-groups-browser.json";
file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own grouped editor and template fixture ready." . "\n";
