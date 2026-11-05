<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db, SecretBox};
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException(
        "Eigene synthetische Manager-Fixture erforderlich.",
    );
}
$root = dirname(__DIR__, 3);
$fixture = json_decode(
    file_get_contents(
        $root . "/.local/browser-tests/native-member-accounts.json",
    ),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$person = $fixture["people"][0];
$db = TestSupport::db();
$rows = Db::run(
    $db,
    "SELECT m.* FROM native_auth_mail m JOIN native_auth_tokens t ON t.token_hash=m.token_hash AND t.account_id=m.account_id WHERE m.account_id=? AND m.status='queued' AND t.purpose='reset' AND t.consumed_at IS NULL AND t.expires_at>?",
    [$person["account_id"], time()],
)->fetchAll();
if (count($rows) !== 1) {
    throw new RuntimeException(
        "Genau eine eigene Original-Reset-Anfrage erforderlich.",
    );
}
$row = $rows[0];
$box = SecretBox::fromFile(
    $root .
        "/.local/native-cms-integration/integrated-manager-runtime/private.key",
);
$payload = $box->open("auth-mail:" . $row["id"], $row["cipher"]);
if (
    !preg_match("~[?&]token=([a-f0-9]{64})~", $payload["text"], $match) ||
    !str_contains($payload["text"], $person["username"])
) {
    throw new RuntimeException("Eigene geschützte Reset-Nachricht prüfen.");
}
$file = $root . "/.local/browser-tests/native-account-reset.json";
$old = umask(0077);
try {
    file_put_contents(
        $file,
        json_encode(["token" => $match[1]], JSON_THROW_ON_ERROR),
    );
    chmod($file, 0600);
} finally {
    umask($old);
}
echo "Eigene über HTTP angeforderte Reset-Kennung privat vorbereitet; kein Mailtransport.\n";
