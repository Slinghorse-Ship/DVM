<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";

use Kulturhus\Native\Cms\{Pages, Schema};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\TestSupport;

$config = TestSupport::config();
if (PHP_SAPI !== "cli" || ($config["label"] ?? "") !== "integrated-manager") {
    throw new RuntimeException("Isolierte Manager-Fixture erforderlich.");
}
$db = TestSupport::db();
Schema::install($db);
$access = (new ManagerBridge(
    $db,
    "manager-admin",
    "native",
    static fn() => null,
))->access();
$pages = new Pages($db, $access);
$rows = array_values(
    array_filter(
        $pages->list(),
        static fn($p) => $p["title"] === "Browserprüfung alte Seitenlinks",
    ),
);
if (count($rows) > 1) {
    throw new RuntimeException("Mehrere gleichnamige Prüforiginale.");
}
$page =
    $rows[0] ??
    $pages->save([
        "title" => "Browserprüfung alte Seitenlinks",
        "slug" => "seitenhistorie-browser-alt",
        "body" => "Eigenes synthetisches Original für die Seitenhistorie.",
        "nav_visible" => false,
    ]);
if ($page["published_revision"] === null) {
    $page = $pages->publish($page["id"], $page["version"]);
}
$fixture = [
    "id" => $page["id"],
    "url" => Kulturhus\Native\Integration\Routes::url("website", $page["id"]),
    "old" => "seitenhistorie-browser-alt",
    "current" => "seitenhistorie-browser-neu",
    "import" => "seitenhistorie-browser-uebernommen",
];
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
file_put_contents(
    $directory . "/path-history.json",
    json_encode($fixture, JSON_THROW_ON_ERROR),
);
chmod($directory . "/path-history.json", 0600);
echo "Eigenes synthetisches Seitenoriginal vorbereitet; bestehende Versionen bleiben erhalten.\n";
