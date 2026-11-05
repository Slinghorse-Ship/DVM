<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, DataModel};
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
$labels = [
    "browser_rank" => "Own numeric sequence",
    "browser_details" => rtrim('Own formatted details " ' . str_repeat("long ", 24)),
];
$definitions = array_column($model->fields(), null, "key");
foreach (
    ["browser_rank" => "integer", "browser_details" => "richtext"]
    as $key => $type
) {
    if (
        isset($definitions[$key]) &&
        ($definitions[$key]["label"] !== $labels[$key] ||
            $definitions[$key]["type"] !== $type)
    ) {
        throw new RuntimeException("Own field name already occupied.");
    }
    if (!isset($definitions[$key])) {
        $model->saveField([
            "key" => $key,
            "label" => $labels[$key],
            "type" => $type,
        ]);
    }
}
$templates = array_column($model->templates(), null, "key");
if (!isset($templates["browser_query_page"])) {
    $model->saveTemplate([
        "key" => "browser_query_page",
        "label" => "Own query template",
        "renderer" => "standard",
        "fields" => array_keys($labels),
    ]);
} elseif ($templates["browser_query_page"]["label"] !== "Own query template") {
    throw new RuntimeException("Own template name already occupied.");
}
$fixture = [];
foreach (range(0, 56) as $i) {
    $slug = "synthetic-query-" . sprintf("%02d", $i);
    $title = "Own bounded browser " . sprintf("%02d", $i);
    $old =
        $pages->search([["field" => "slug", "value" => $slug]], 1)[0] ?? null;
    if ($old && $old["title"] !== $title) {
        throw new RuntimeException("Own page name already occupied.");
    }
    $input = [
        "title" => $title,
        "slug" => $slug,
        "template" => "browser_query_page",
        "nav_visible" => false,
        "body_html" =>
            "<p>Own <strong>readable body</strong> " .
            sprintf("%02d", $i) .
            "</p>",
        "custom_fields" => [
            "browser_rank" => 56 - $i,
            "browser_details" =>
                "<p>Details <strong>" .
                sprintf("%02d", 56 - $i) .
                "</strong></p>",
        ],
    ];
    if ($old) {
        $input["id"] = $old["id"];
    }
    $row = $pages->save($input, $old["version"] ?? 0);
    if ($i < 20) {
        $row = $pages->publish($row["id"], $row["version"]);
        if ($i >= 10) {
            $row["body_html"] = "<p>Own changed private content</p>";
            $row = $pages->save($row, $row["version"]);
        }
    }
    $fixture[] = ["id" => $row["id"], "slug" => $slug, "title" => $title];
}
$special = [
    "title" => 'Own escaped 100%_! " <marker>',
    "slug" => "synthetic-query-literal",
    "nav_visible" => false,
];
$old =
    $pages->search([["field" => "slug", "value" => $special["slug"]]], 1)[0] ??
    null;
if ($old && $old["title"] !== $special["title"]) {
    throw new RuntimeException("Own special name already occupied.");
}
if ($old) {
    $special["id"] = $old["id"];
}
$pages->save($special, $old["version"] ?? 0);
$path =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) .
    "/integrated-manager-runtime/cms-queries-browser.json";
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own 57 bounded editorial pages and literal search fixture prepared; no external transport.\n";
