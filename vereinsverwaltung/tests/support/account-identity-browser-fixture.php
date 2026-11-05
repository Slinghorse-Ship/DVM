<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/autoload.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\MemberAccounts;
use Kulturhus\Native\Auth\Runtime;
use Kulturhus\Verein\{Registry, Settings};
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException(
        "Eigene synthetische Manager-Fixture erforderlich.",
    );
}
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
$db = TestSupport::db();
$root = dirname(__DIR__, 3);
$runtime = $root . "/.local/native-cms-integration/integrated-manager-runtime";
$file = $root . "/.local/browser-tests/native-account-identity.json";
if (is_file($file)) {
    $fixture = json_decode(
        file_get_contents($file),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    if (
        !Db::run($db, "SELECT 1 FROM native_auth_accounts WHERE id=?", [
            $fixture["account_id"],
        ])->fetchColumn()
    ) {
        throw new RuntimeException(
            "Bestehende eigene Identitäts-Fixture prüfen.",
        );
    }
} else {
    $registry = new Registry($db, "manager-admin");
    $name = "Browserprüfung Kontozuordnung";
    if (
        Db::run(
            $db,
            "SELECT 1 FROM contacts WHERE club_id='manager-demo' AND name=?",
            [$name],
        )->fetchColumn()
    ) {
        throw new RuntimeException(
            "Unvollständige eigene Identitäts-Fixture prüfen.",
        );
    }
    $contact = $registry->saveContact(
        null,
        0,
        "person",
        $name,
        "native-identity-browser@example.invalid",
        "Synthetischer Testweg",
    );
    $registry->addMembership($contact, "", "aktiv", "2020-01-01", false);
    $auth = Runtime::service($db, $runtime . "/auth.key");
    $accounts = new MemberAccounts(
        $db,
        $auth,
        SecretBox::fromFile($runtime . "/private.key"),
    );
    $account = $accounts->prepare("manager-demo", $contact);
    $password = bin2hex(random_bytes(20)) . "aA9!";
    $auth->acceptInvitation($account["setup_token"], $password);
    unset($account["setup_token"]);
    $role = (new Settings($db, "manager-admin"))->saveRole("", 0, [
        "name" => "Browserprüfung lesende Organisation",
        "description" => "Eigene synthetische Prüfrolle",
        "members_access" => "read",
        "finance_access" => "none",
        "settings_access" => false,
    ]);
    $fixture = $account + [
        "contact" => $contact,
        "name" => $name,
        "password" => $password,
        "role" => $role,
    ];
    $old = umask(0077);
    try {
        file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($file, 0600);
    } finally {
        umask($old);
    }
}
echo "Eigene native Kontozuordnungs-Fixture bereit; bestehende Originale und Zugangsdaten bleiben erhalten.\n";
