<?php
declare(strict_types=1);
ini_set("zend.exception_ignore_args", "1");
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";

use Kulturhus\Native\Cms\{Articles, Pages, PathHistory};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\TestSupport;

if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException(
        "Eigene synthetische Manager-Fixture erforderlich.",
    );
}
$db = TestSupport::db();
$access = (new ManagerBridge(
    $db,
    "manager-admin",
    "native",
    static fn() => null,
))->access();
$pages = new Pages($db, $access);
$articles = new Articles($db, $access);
$existing = array_column($pages->list(), null, "slug");
$fixture = [];
foreach ([1440, 820, 390] as $width) {
    $slug = "aktuelles/kanalbrowser-" . $width;
    $title = "DVM Kanalprüfung " . $width;
    $old = $existing[$slug] ?? null;
    if ($old && $old["title"] !== $title) {
        throw new RuntimeException("Prüfpfad bereits anderweitig belegt.");
    }
    $input = [
        "title" => $title,
        "slug" => $slug,
        "news_date" => "2050-01-12",
        "body" =>
            "Eigenes synthetisches Beitragsoriginal für die Kanalprüfung.",
        "news_channels" => ["website", "rss"],
        "nav_visible" => false,
    ];
    if ($old) {
        $input["id"] = $old["id"];
    }
    $p = $articles->save($input, $old["version"] ?? 0);
    $p = $articles->publish($p["id"], $p["version"]);
    $alias = "aktuelles/alter-kanalbrowser-" . $width;
    (new PathHistory($db, $access))->remember($p["id"], $alias, $p["version"]);
    $fixture[] = [
        "width" => $width,
        "id" => $p["id"],
        "slug" => $slug,
        "alias" => $alias,
        "title" => $title,
    ];
}
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
file_put_contents(
    $directory . "/news-channels-fixture.json",
    json_encode($fixture, JSON_THROW_ON_ERROR),
);
chmod($directory . "/news-channels-fixture.json", 0600);
echo "Eigene synthetische News-Kanaloriginale bereit; kein externer Transport.\n";
