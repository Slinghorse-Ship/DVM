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
$file = $root . "/.local/browser-tests/native-email-factor.json";
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
    $name = "Browserprüfung E-Mail-Schutz";
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
        "native-email-factor-browser@example.invalid",
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
    $fixture = $account + [
        "contact" => $contact,
        "name" => $name,
        "password" => $password,
    ];
    $old = umask(0077);
    try {
        file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($file, 0600);
    } finally {
        umask($old);
    }
}
// Test preparation only: reset rate counters of this explicitly owned synthetic account.
// Delivery calls never reset limits; the isolated backend suite verifies their enforcement.
if (!isset($argv[1])) {
    foreach (
        [
            "email-security",
            "email-security-code",
            "email-login",
            "factor-account",
            "stepup-password",
            "stepup-factor",
        ]
        as $scope
    ) {
        Db::run($db, "DELETE FROM native_auth_limits WHERE scope_hash=?", [
            hash("sha256", $scope . ":" . $fixture["account_id"]),
        ]);
    }
}
if (isset($argv[1])) {
    $purpose = $argv[1];
    if (!in_array($purpose, ["enable", "disable", "login", "stepup"], true)) {
        throw new RuntimeException("Eigener Faktorprüfzweck erforderlich.");
    }
    $queued = Db::run(
        $db,
        "SELECT id FROM native_auth_email_codes WHERE account_id=? AND purpose=? AND status='queued' ORDER BY created_at DESC,id DESC LIMIT 1",
        [$fixture["account_id"], $purpose],
    )->fetchColumn();
    if (!$queued) {
        throw new RuntimeException("Eigene aktive Codeanforderung fehlt.");
    }
    $account = Db::run(
        $db,
        "SELECT a.email,u.club_id FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=?",
        [$fixture["account_id"]],
    )->fetch();
    foreach (
        [
            "KULTURHUS_DISABLE_MAIL" => "0",
            "KULTURHUS_LOCAL_DEMO" => "0",
            "VEREIN_MODE" => "production",
            "APP_ENV" => "production",
        ]
        as $key => $value
    ) {
        putenv($key . "=" . $value);
    }
    $codes = [];
    $protected = Db::run(
        $db,
        "SELECT cipher FROM native_auth_email_codes WHERE id=?",
        [$queued],
    )->fetchColumn();
    $expected = SecretBox::fromFile($runtime . "/auth.key")->open(
        "email-factor:" . $queued,
        $protected,
    )["text"];
    $auth = Runtime::service($db, $runtime . "/auth.key");
    $auth->deliverEmailFactors($account["club_id"], static function (
        array $message,
    ) use (&$codes, $account, $expected): void {
        if (
            $message["to"] === $account["email"] &&
            $message["text"] === $expected &&
            preg_match(
                "/Sicherheitscode: ([0-9]{8})/",
                $message["text"],
                $match,
            )
        ) {
            $codes[] = $match[1];
        }
    });
    if (count($codes) !== 1) {
        throw new RuntimeException("Eigene simulierte Codezustellung prüfen.");
    }
    $output = $root . "/.local/browser-tests/native-email-code.json";
    $old = umask(0077);
    try {
        file_put_contents(
            $output,
            json_encode(["code" => $codes[0]], JSON_THROW_ON_ERROR),
        );
        chmod($output, 0600);
    } finally {
        umask($old);
    }
}
echo "Eigene E-Mail-Faktorprüfung bereit; kein externer Versand. Private Codes bleiben lokal.\n";
