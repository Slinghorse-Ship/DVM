<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{DataModel, Pages};
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
$model = new DataModel($db, $access);
$defs = array_column($model->fields(), null, "key");
foreach (
    [
        "browser_formatted_required" => true,
        "browser_formatted_optional" => false,
    ]
    as $key => $required
) {
    if (!isset($defs[$key])) {
        $model->saveField([
            "key" => $key,
            "label" => $required
                ? "Synthetischer Pflichttext"
                : "Synthetischer Zusatztext",
            "type" => "richtext",
            "required" => $required,
            "min" => $required ? 3 : null,
            "max" => 120,
        ]);
    }
}
$templates = array_column($model->templates(), null, "key");
if (!isset($templates["browser_formatted_page"])) {
    $model->saveTemplate([
        "key" => "browser_formatted_page",
        "label" => "Synthetische formatierte Vorlage",
        "renderer" => "standard",
        "fields" => [
            "browser_formatted_required",
            "browser_formatted_optional",
        ],
    ]);
}
$pages = new Pages($db, $access);
$rows = $pages->search([
    ["field" => "slug", "op" => "eq", "value" => "synthetic-formatted-browser"],
]);
$data = [
    "title" => "Synthetische formatierte Vereinsseite",
    "slug" => "synthetic-formatted-browser",
    "template" => "browser_formatted_page",
    "body" => "Synthetischer Editorablauf ohne echte Veröffentlichung",
    "custom_fields" => [
        "browser_formatted_required" =>
            "<p>Musik <strong>&amp; Verein</strong> 🎶</p>",
        "browser_formatted_optional" => "<p>Optionaler Text</p>",
    ],
];
if ($rows) {
    $data["id"] = $rows[0]["id"];
}
$page = $pages->save($data, $rows[0]["version"] ?? 0);
$page = $pages->publish($page["id"], $page["version"]);
$root =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/integrated-manager-runtime";
file_put_contents(
    $root . "/richtext-fields-browser.json",
    json_encode(
        [
            "id" => $page["id"],
            "slug" => $page["slug"],
            "fields" => array_keys($data["custom_fields"]),
        ],
        JSON_THROW_ON_ERROR,
    ),
);
chmod($root . "/richtext-fields-browser.json", 0600);
echo "Own synthetic formatted-field fixture prepared; no external provider action.\n";
