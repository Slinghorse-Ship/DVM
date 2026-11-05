<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Integration\MemberAccounts;
use Kulturhus\Native\Auth\Runtime;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Verein\Registry;

if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException(
        "Eigene synthetische Manager-Fixture erforderlich.",
    );
}
putenv("VEREIN_AUTH=native");
putenv("KH_NATIVE_MANAGER=1");
$db = TestSupport::db();
$root = dirname(__DIR__, 3);
$runtime = $root . "/.local/native-cms-integration/integrated-manager-runtime";
$directory = $root . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$file = $directory . "/native-member-accounts.json";
if (is_file($file)) {
    $fixture = json_decode(
        file_get_contents($file),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    foreach ($fixture["people"] as &$person) {
        $row = Db::run(
            $db,
            "SELECT verified FROM native_auth_accounts WHERE id=?",
            [$person["account_id"]],
        )->fetch();
        if (!$row) {
            throw new RuntimeException(
                "Bestehende eigene Kontofixture prüfen.",
            );
        }
        $person["fresh"] = !$row["verified"];
    }
    unset($person);
} else {
    $registry = new Registry($db, "manager-admin");
    $accounts = new MemberAccounts(
        $db,
        Runtime::service($db, $runtime . "/auth.key"),
        SecretBox::fromFile($runtime . "/private.key"),
    );
    $fixture = ["people" => []];
    $family = null;
    foreach (["Erste", "Zweite"] as $index => $label) {
        $name = $label . " Browserfamilienperson";
        if (
            Db::run(
                $db,
                "SELECT 1 FROM contacts WHERE club_id='manager-demo' AND name=?",
                [$name],
            )->fetchColumn()
        ) {
            throw new RuntimeException(
                "Unvollständige eigene Kontofixture prüfen.",
            );
        }
        $contact = $registry->saveContact(
            null,
            0,
            "person",
            $name,
            "native-browser-family@example.invalid",
            "Synthetischer Testweg",
        );
        $registry->addMembership(
            $contact,
            "",
            "aktiv",
            "2020-01-01",
            false,
            $family ? ["expected" => "", "target" => $family] : [],
        );
        if (!$family) {
            $registry->assignFamily(
                $contact,
                "",
                "new",
                "Native Browser-Testfamilie",
            );
            $family = $registry->family($contact)["id"];
        }
        $account = $accounts->prepare("manager-demo", $contact);
        $fixture["people"][] = $account + [
            "contact" => $contact,
            "name" => $name,
            "password" => bin2hex(random_bytes(20)) . "aA9!",
            "fresh" => true,
        ];
    }
}
$old = umask(0077);
try {
    file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR));
    chmod($file, 0600);
} finally {
    umask($old);
}
echo "Eigene native Familienkonten-Fixture bereit; persönliche Kennungen und Schlüssel bleiben bei Wiederholung erhalten.\n";
