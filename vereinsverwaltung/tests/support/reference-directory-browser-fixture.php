<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Schema};
use Kulturhus\Native\Shared\{Access, Db};
if (
    PHP_SAPI !== "cli" ||
    realpath((string) getenv("KH_NATIVE_TEST_CONFIG")) !==
        "/workspace/.setup/native-demo.json"
) {
    throw new RuntimeException("Own local cloud demo fixture required.");
}
$config = TestSupport::config();
if (
    ($config["owner"] ?? "") !== "cloud-onboarding-synthetic" ||
    ($config["label"] ?? "") !== "demo"
) {
    throw new RuntimeException("Own synthetic demo database required.");
}
$db = TestSupport::db();
Schema::install($db);
$pages = new Pages($db, new Access($db, "admin"));
$rows = [];
foreach (range(0, 68) as $index) {
    $slug = "synthetic-directory-" . sprintf("%02d", $index);
    $title = "Own directory page " . sprintf("%02d", $index);
    $id = Db::run(
        $db,
        "SELECT id FROM khcms_pages WHERE club_id=? AND slug=?",
        ["synthetic-a", $slug],
    )->fetchColumn();
    if ($id) {
        $row = $pages->get($id);
        if ($row["title"] !== $title) {
            throw new RuntimeException(
                "Unexpected synthetic fixture original.",
            );
        }
    } else {
        $row = $pages->save([
            "slug" => $slug,
            "title" => $title,
            "body" => "Own local directory fixture, never published.",
            "nav_visible" => false,
        ]);
    }
    $rows[] = ["id" => $row["id"], "slug" => $row["slug"]];
}
$directory = Db::run(
    $db,
    "SELECT id FROM khcms_pages WHERE club_id=? ORDER BY updated_at DESC,id",
    ["synthetic-a"],
)->fetchAll(PDO::FETCH_COLUMN);
$path =
    dirname(__DIR__, 3) .
    "/.local/browser-tests/reference-directory-browser.json";
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0700, true);
}
file_put_contents(
    $path,
    json_encode(
        ["rows" => $rows, "directory" => $directory],
        JSON_THROW_ON_ERROR,
    ),
);
chmod($path, 0600);
echo "Own standalone reference directory ready.\n";
