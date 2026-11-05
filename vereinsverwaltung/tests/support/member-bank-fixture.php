<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";

use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\Registry;

if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException("Eigene lokale Manager-Fixture erforderlich.");
}
$db = TestSupport::db();
$registry = new Registry($db, "manager-admin");
$statement = $db->prepare(
    "SELECT id FROM contacts WHERE club_id='manager-demo' AND name=? AND archived_at IS NULL",
);
$statement->execute(["Browserprüfung native Mitgliedsbankdaten"]);
$rows = $statement->fetchAll(PDO::FETCH_COLUMN);
if (count($rows) > 1) {
    throw new RuntimeException("Mehrere synthetische Prüforiginale.");
}
$contact =
    $rows[0] ??
    $registry->saveContact(
        null,
        0,
        "person",
        "Browserprüfung native Mitgliedsbankdaten",
        "",
        "",
    );
$membership = $db->prepare(
    "SELECT 1 FROM memberships WHERE contact_id=? AND club_id='manager-demo'",
);
$membership->execute([$contact]);
if (!$membership->fetchColumn()) {
    $registry->addMembership(
        $contact,
        "BANK-BROWSER-NATIVE",
        "aktiv",
        "2020-01-01",
    );
}
$keyRoot = dirname(__DIR__, 3) . "/.local/vereinsverwaltung/member-bank-keys";
if (!is_dir($keyRoot) && !mkdir($keyRoot, 0700, true)) {
    throw new RuntimeException(
        "Privater synthetischer Bankschlüsselordner fehlt.",
    );
}
if (is_link($keyRoot) || !chmod($keyRoot, 0700)) {
    throw new RuntimeException("Bankschlüsselordner nicht privat.");
}
$key = $keyRoot . "/v1.key";
if (!file_exists($key) && !is_link($key)) {
    $handle = fopen($key, "x");
    if (
        !$handle ||
        !chmod($key, 0600) ||
        fwrite($handle, random_bytes(32)) !== 32
    ) {
        throw new RuntimeException(
            "Synthetischer Bankschlüssel konnte nicht angelegt werden.",
        );
    }
    fclose($handle);
}
if (
    is_link($key) ||
    strlen(file_get_contents($key)) !== 32 ||
    fileperms($key) & 0077
) {
    throw new RuntimeException(
        "Bestehenden synthetischen Bankschlüssel prüfen.",
    );
}
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
file_put_contents(
    $directory . "/member-bank-native.json",
    json_encode(
        [
            "contact" => $contact,
            "url" =>
                "/vereinsverwaltung/?view=portal&tab=bank&person=" . $contact,
        ],
        JSON_THROW_ON_ERROR,
    ),
);
chmod($directory . "/member-bank-native.json", 0600);
echo "Eigene synthetische Bank-Fixture bereit; Original und Schlüssel bleiben bei Wiederholung erhalten.\n";
