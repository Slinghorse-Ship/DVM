<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Media, DataModel};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Shared\Access;
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
    throw new RuntimeException("Own synthetic browser fixture required.");
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
$pages = new Pages($db, $access);
$model = new DataModel($db, $access);
$prefix = "browser_condition_";
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
    if ($id) {
        $old = $pages->get($id);
        if ($old["title"] !== $input["title"]) {
            throw new RuntimeException(
                "Unexpected synthetic condition original.",
            );
        }
        return $pages->save(
            ["id" => $id, "slug" => $slug] + $input,
            $old["version"],
        );
    }
    return $pages->save(["slug" => $slug] + $input);
};
$source = $ownPage("synthetic-condition-reference", [
    "title" => "Own browser conditional referenced original",
    "nav_visible" => false,
]);
$media = new Media($db, $access, $runtime . "/media");
$imageIds = Db::run(
    $db,
    "SELECT id FROM khcms_media WHERE club_id=? AND original_name=? AND alt=?",
    [
        $access->club(),
        "own-browser-conditions.png",
        "Own browser condition picture",
    ],
)->fetchAll(PDO::FETCH_COLUMN);
if (count($imageIds) > 1) {
    throw new RuntimeException("Unexpected condition pixels.");
}
if ($imageIds) {
    $image = ["id" => $imageIds[0]];
} else {
    $pixels = imagecreatetruecolor(40, 24);
    imagefill($pixels, 0, 0, imagecolorallocate($pixels, 24, 95, 50));
    imagepng($pixels, $runtime . "/own-condition-pixels.png");
    unset($pixels);
    $image = $media->upload(
        $runtime . "/own-condition-pixels.png",
        "own-browser-conditions.png",
        "Own browser condition picture",
    );
}
$rule = static fn($field, $operator, $value = null) => [
    "match" => "all",
    "conditions" => [
        ["field" => $prefix . $field, "operator" => $operator] +
        (in_array($operator, ["empty", "filled"], true)
            ? []
            : ["value" => $value]),
    ],
];
$specs = [
    "active" => ["type" => "boolean", "required" => true],
    "count" => ["type" => "integer"],
    "amount" => ["type" => "decimal"],
    "day" => ["type" => "date"],
    "category" => [
        "type" => "select",
        "options" => ["Musik", "Theater", '100%_! " <marker>'],
    ],
    "formatted" => ["type" => "richtext"],
    "page" => ["type" => "page-reference"],
    "image" => ["type" => "media-reference"],
    "details" => [
        "type" => "text",
        "required" => true,
        "show_when" => [
            "match" => "all",
            "conditions" => [
                [
                    "field" => $prefix . "active",
                    "operator" => "eq",
                    "value" => true,
                ],
                [
                    "field" => $prefix . "count",
                    "operator" => "gte",
                    "value" => 10,
                ],
            ],
        ],
    ],
    "reply" => [
        "type" => "text",
        "required_when" => [
            "match" => "any",
            "conditions" => [
                [
                    "field" => $prefix . "category",
                    "operator" => "eq",
                    "value" => "Musik",
                ],
                [
                    "field" => $prefix . "amount",
                    "operator" => "gt",
                    "value" => "999999999999999999.999998",
                ],
            ],
        ],
    ],
    "rich_note" => [
        "type" => "richtext",
        "show_when" => $rule("active", "eq", true),
        "required_when" => $rule("count", "gt", 10),
    ],
    "date_note" => [
        "type" => "text",
        "show_when" => $rule("day", "gte", "2026-10-10"),
    ],
    "caption" => [
        "type" => "text",
        "show_when" => $rule("formatted", "contains", "<marker>"),
    ],
    "page_note" => [
        "type" => "text",
        "show_when" => $rule("page", "eq", $source["id"]),
    ],
    "image_note" => ["type" => "text", "show_when" => $rule("image", "filled")],
    "rule" => ["type" => "text", "show_when" => $rule("active", "eq", true)],
];
$definitions = array_column($model->fields(), null, "key");
foreach ($specs as $suffix => $spec) {
    $key = $prefix . $suffix;
    $input =
        ["key" => $key, "label" => "Own browser condition " . $suffix] + $spec;
    if (isset($definitions[$key])) {
        if (
            $definitions[$key]["label"] !== $input["label"] ||
            $definitions[$key]["type"] !== $spec["type"]
        ) {
            throw new RuntimeException("Unexpected synthetic rule field.");
        }
        if ($suffix === "rule") {
            $model->saveField($input, $definitions[$key]["version"]);
        }
    } else {
        $model->saveField($input);
    }
}
$templateKey = $prefix . "template";
$templates = array_column($model->templates(), null, "key");
$keys = array_map(
    static fn($key) => $prefix . $key,
    array_diff(array_keys($specs), ["rule"]),
);
if (!isset($templates[$templateKey])) {
    $model->saveTemplate([
        "key" => $templateKey,
        "label" => "Own browser conditional template",
        "fields" => array_values($keys),
    ]);
} elseif (
    $templates[$templateKey]["label"] !== "Own browser conditional template" ||
    $templates[$templateKey]["fields"] !== array_values($keys)
) {
    throw new RuntimeException("Unexpected synthetic condition template.");
}
$values = [
    "active" => false,
    "count" => 8,
    "amount" => "0",
    "day" => "2026-10-10",
    "category" => "Theater",
    "formatted" => "<p>Own &lt;marker&gt;</p>",
    "page" => $source["id"],
    "image" => $image["id"],
    "details" => "Original hidden details",
    "reply" => null,
    "rich_note" => "<p>Original hidden rich note</p>",
    "date_note" => "Original date note",
    "caption" => "Original literal caption",
    "page_note" => "Original related note",
    "image_note" => "Original image note",
];
$values = array_combine(
    array_map(static fn($key) => $prefix . $key, array_keys($values)),
    array_values($values),
);
$editor = $ownPage("synthetic-conditions-editor", [
    "title" => "Own browser conditional editor",
    "template" => $templateKey,
    "custom_fields" => $values,
    "nav_visible" => false,
]);
$original = $ownPage("synthetic-conditions-public", [
    "title" => "Own unchanged conditional public original",
    "body" => "Original published content and pixels remain unchanged.",
    "media_ids" => [$image["id"]],
    "nav_visible" => false,
]);
$original = $pages->publish($original["id"], $original["version"]);
$out = [
    "prefix" => $prefix,
    "editor" => ["id" => $editor["id"], "version" => $editor["version"]],
    "source" => ["id" => $source["id"]],
    "image" => $image["id"],
    "public_slug" => $original["slug"],
    "values" => $values,
    "rule_version" => $model->field($prefix . "rule")["version"],
];
$fixturePath = $standalone
    ? dirname(__DIR__, 3) .
        "/.local/browser-tests/field-conditions-standalone.json"
    : $runtime . "/field-conditions-browser.json";
file_put_contents($fixturePath, json_encode($out, JSON_THROW_ON_ERROR));
chmod($fixturePath, 0600);
echo "Own conditional editor and rule fixture ready.\n";
