<?php declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    exit(1);
}
require dirname(__DIR__, 2) . "/src/bootstrap.php";
require dirname(__DIR__, 2) . "/native/bootstrap.php";

use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Inventory, Registry};

if ((TestSupport::config()["label"] ?? "") !== "integrated-manager") {
    throw new RuntimeException("Own integrated synthetic fixture required.");
}
$db = TestSupport::db();
$actor = $db
    ->query(
        "SELECT id FROM actors WHERE active=1 AND is_superuser=1 ORDER BY id LIMIT 1",
    )
    ->fetchColumn();
if (!$actor) {
    throw new RuntimeException("Synthetic manager missing.");
}
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$path = $directory . "/calendar-loans.json";
if (is_file($path)) {
    echo "Existing synthetic loan calendar fixture.\n";
    exit();
}
$key = static fn(): string => bin2hex(random_bytes(16));
$inventory = new Inventory($db, $actor);
$registry = new Registry($db, $actor);
$person = $registry->saveContact(
    null,
    0,
    "person",
    "Synthetischer Materialkontakt",
    "",
    "",
);
$location = $inventory->saveLocation(
    "",
    0,
    ["name" => "Synthetisches Kalenderlager"],
    $key(),
);
$item = $inventory->saveItem(
    "",
    0,
    ["code" => "CAL-" . substr($key(), 0, 8), "name" => "Kalender-Bühnentisch"],
    $key(),
);
$inventory->move(
    $item,
    0,
    [
        "kind" => "receive",
        "quantity" => "2",
        "location" => $location,
        "reason" => "Synthetischer Kalenderbestand",
    ],
    $key(),
);
$start = new DateTimeImmutable(Inventory::today());
$due = $start->modify("+3 days");
$loan = $inventory->reserve(
    [
        "contact" => $person,
        "purpose" => "Kalender-Bühnentische",
        "starts_on" => $start->format("Y-m-d"),
        "due_on" => $due->format("Y-m-d"),
        "lines" => [
            [
                "item" => $item,
                "location" => $location,
                "quantity" => "1",
                "stock_version" => "1",
            ],
        ],
    ],
    $key(),
);
file_put_contents(
    $path,
    json_encode(
        [
            "loan" => $loan,
            "start" => $start->format("Y-m-d"),
            "due" => $due->format("Y-m-d"),
        ],
        JSON_THROW_ON_ERROR,
    ),
);
chmod($path, 0600);
echo "Synthetic stock and protected loan calendar fixture ready.\n";
