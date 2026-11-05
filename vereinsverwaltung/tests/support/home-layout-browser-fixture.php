<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Integration\ManagerBridge;
if (PHP_SAPI !== "cli") {
    throw new RuntimeException("CLI fixture only.");
}
$k=ownKBrowserFixture();
$standalone=$k['standalone'];
$db = TestSupport::db();
$access = $standalone
    ? new Access($db, "admin")
    : (new ManagerBridge(
        $db,
        "manager-admin",
        "native",
        static fn() => null,
    ))->access();
$pages = new Pages($db, $access);
$published = $pages->publicPage("start");
$title=$k['home_title'];
if(!$published||$published['title']!==$title||$published['body']!==$k['home_body'])throw new RuntimeException('Own exact original home required.');
$matches = $pages->search(
    [["field" => "slug", "op" => "eq", "value" => "start"]],
    2,
);
if (count($matches) !== 1 || $matches[0]["title"] !== $title) {
    throw new RuntimeException("Unexpected private home fixture.");
}
$page = $pages->get($matches[0]["id"]);
$directory=$k['directory'];
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$path =
    $directory .
    "/home-layout-" .
    ($standalone ? "standalone" : "integrated") .
    ".json";
if (file_exists($path)) {
    $original = json_decode(
        file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    if (
        $original["id"] !== $page["id"] ||
        $original["title"] !== $title ||
        $original["club"] !== $access->club()
    ) {
        throw new RuntimeException("Own saved original mismatch.");
    }
} else {
    if (isset($published["home_layout"]) || isset($page["home_layout"])) {
        throw new RuntimeException(
            "Configured home is not an owned default fixture.",
        );
    }
    $handle = fopen($path, "x");
    if (!$handle) {
        throw new RuntimeException("Own original occupied.");
    }
    fwrite(
        $handle,
        json_encode(
            [
                "id" => $page["id"],
                "revision" => $page["published_revision"],
                "title" => $title,
                "club" => $access->club(),
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
    fclose($handle);
    chmod($path, 0600);
}
echo "Own home original registered: " . $path . "\n";
