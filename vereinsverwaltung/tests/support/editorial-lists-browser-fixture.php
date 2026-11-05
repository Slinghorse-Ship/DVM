<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, DataModel};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\Website\PageOverview;
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
$templates = array_column($model->templates(), null, "key");
if (!isset($templates["browser_editorial_event"])) {
    $model->saveTemplate([
        "key" => "browser_editorial_event",
        "label" => "Own editorial event template",
        "renderer" => "event",
        "fields" => [],
    ]);
} elseif (
    $templates["browser_editorial_event"]["label"] !==
    "Own editorial event template"
) {
    throw new RuntimeException("Own event template occupied.");
}
$rows = [];
foreach (range(0, 68) as $i) {
    $slug = "synthetic-editorial-event-" . $i;
    $title = "Own editorial event " . sprintf("%02d", $i);
    $old =
        $pages->search([["field" => "slug", "value" => $slug]], 1)[0] ?? null;
    if ($old && $old["title"] !== $title) {
        throw new RuntimeException("Own event source occupied.");
    }
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
    $input = [
        "title" => $title,
        "slug" => $slug,
        "template" => $i % 5 === 0 ? "browser_editorial_event" : "event",
        "event_start" => $date,
        "event_location" => "Own editorial hall " . $i,
        "event_address" => "Own editorial street " . $i,
        "body_html" =>
            "<p>Own original event <strong>body " .
            sprintf("%02d", $i) .
            "</strong></p>",
        "nav_visible" => false,
    ];
    if ($old) {
        $input["id"] = $old["id"];
    }
    $row = $pages->save($input, $old["version"] ?? 0);
    if ($i < 20) {
        $row = $pages->publish($row["id"], $row["version"]);
        if ($i >= 10) {
            $row["body_html"] = "<p>Own changed event body</p>";
            $row = $pages->save($row, $row["version"]);
        }
    }
    $rows[] = ["id" => $row["id"], "slug" => $slug, "title" => $title];
}
$tail = array_column(array_slice($rows, 66), "id");
sort($tail, SORT_STRING);
$fixture = [
    "rows" => $rows,
    "date_asc" => [
        ...array_column(array_slice($rows, 57, 9), "id"),
        ...array_column(array_slice($rows, 0, 57), "id"),
        ...$tail,
    ],
    "date_desc" => [
        ...array_reverse(array_column(array_slice($rows, 0, 57), "id")),
        ...array_reverse(array_column(array_slice($rows, 57, 9), "id")),
        ...$tail,
    ],
    "overview" => (new PageOverview($db, $access))->read(),
];
// Store only proof metadata/identities, not the original full recent drafts.
$fixture["overview"]["recent"] = array_map(
    static fn($row) => ["id" => $row["id"], "title" => $row["title"]],
    $fixture["overview"]["recent"],
);
$path =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) .
    "/integrated-manager-runtime/editorial-lists-browser.json";
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own 69-event bounded list and current overview metadata prepared; no external transport.\n";
