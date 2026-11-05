<?php
declare(strict_types=1);
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\Cms\{Pages, PathHistory};
use Kulturhus\Native\Events\Eventbrite;
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
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$existing = array_column($pages->list(), null, "slug");
if (!isset($existing["veranstaltungen"])) {
    $p = $pages->save([
        "slug" => "veranstaltungen",
        "title" => "Veranstaltungen",
        "excerpt" => "Vereinsprogramm",
    ]);
    $pages->publish($p["id"], $p["version"]);
}
$ids = [];
for ($i = 1; $i <= 24; $i++) {
    $slug = "veranstaltungen/programmbrowser-" . $i;
    $p =
        $existing[$slug] ??
        $pages->save([
            "slug" => $slug,
            "title" => "Programmprüfung " . $i,
            "template" => "event",
            "event_start" =>
                "2050-01-" .
                str_pad((string) ($i + 1), 2, "0", STR_PAD_LEFT) .
                "T19:00",
            "event_timezone" => "Europe/Berlin",
            "event_end" =>
                "2050-01-" .
                str_pad((string) ($i + 1), 2, "0", STR_PAD_LEFT) .
                "T20:30:00Z",
            "event_location" => "Synthetischer Vereinssaal",
            "event_address" => "Testweg 12",
            "body" =>
                "Eigenes synthetisches Terminoriginal für den Programm-Browser.",
            "event_price" => "0.00",
            "event_ticket_url" => "https://example.invalid/tickets",
        ]);
    if (!str_starts_with($p["title"], "Programmprüfung ")) {
        throw new RuntimeException("Prüfpfad bereits anderweitig belegt.");
    }
    $corrected = false;
    if ($p["nav_visible"] && !$p["has_unpublished_changes"]) {
        $p = $pages->save(
            array_replace($p, ["nav_visible" => false]),
            $p["version"],
        );
        $corrected = true;
    }
    if ($p["published_revision"] === null || $corrected) {
        $p = $pages->publish($p["id"], $p["version"]);
    }
    $ids[] = $p["id"];
}
$slug = "veranstaltungen/programmbrowser-archiv";
$p =
    $existing[$slug] ??
    $pages->save([
        "slug" => $slug,
        "title" => "Programmprüfung Archiv",
        "template" => "event",
        "event_start" => "2020-01-02T19:00",
        "event_location" => "Synthetischer Vereinssaal",
    ]);
if ($p["published_revision"] === null) {
    $pages->publish($p["id"], $p["version"]);
}
$raw = [
    "id" => "9000000099",
    "name" => ["text" => "Programmprüfung Absage"],
    "description" => ["text" => "Eigenes öffentliches Absageoriginal"],
    "start" => ["utc" => "2050-01-26T18:00:00Z", "timezone" => "Europe/Berlin"],
    "end" => ["utc" => "2050-01-26T20:30:00Z"],
    "venue" => [
        "name" => "Synthetischer Vereinssaal",
        "address" => ["localized_address_display" => "Testweg 12"],
    ],
    "status" => "live",
    "url" => "https://www.eventbrite.de/e/9000000099",
];
$cancel = $pages->events()->publicPage("veranstaltungen/eventbrite-9000000099");
if (!$cancel) {
    $importer = new Eventbrite($db, $access);
    $result = $importer->import([Eventbrite::normalize($raw)]);
    $p = $pages->get($result[0]["id"]);
    if ($p["published_revision"] === null) {
        $p = $pages->publish($p["id"], $p["version"]);
    }
    $raw["status"] = "canceled";
    $importer->import([Eventbrite::normalize($raw)]);
    $cancel = $pages->events()->publicId($p["id"]);
}
if (!$cancel || !$cancel["cancellation_notice"]) {
    throw new RuntimeException("Synthetisches Absageoriginal nicht verfügbar.");
}
$history = new PathHistory($db, $access);
$alias = "programmbrowser-absage-alt";
if (
    !in_array(
        $alias,
        array_column($history->listing($cancel["id"]), "slug"),
        true,
    )
) {
    $history->remember(
        $cancel["id"],
        $alias,
        $pages->get($cancel["id"])["version"],
    );
}
$fixture = [
    "programme" => "/veranstaltungen/?q=Programmpr%C3%BCfung&jahr=2050",
    "detail" => "/veranstaltungen/programmbrowser-1/",
    "archive" => "/veranstaltungen/?archiv=1&q=Programmpr%C3%BCfung&jahr=2020",
    "cancel" => "/veranstaltungen/eventbrite-9000000099/",
    "event_id" => $ids[0],
    "cancel_id" => $cancel["id"],
    "editor" => Kulturhus\Native\Integration\Routes::url("events", $ids[0]),
    "total" => 25,
];
file_put_contents(
    $directory . "/event-catalogue.json",
    json_encode($fixture, JSON_THROW_ON_ERROR),
);
chmod($directory . "/event-catalogue.json", 0600);
echo "Eigene synthetische Programm- und Absageoriginale bereit; kein externer Dienst aufgerufen.\n";
