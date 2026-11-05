<?php declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    exit(1);
}
umask(0077);
require dirname(__DIR__, 2) . "/src/bootstrap.php";
require dirname(__DIR__, 2) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\{Schema, Subscribers};
if ((TestSupport::config()["label"] ?? "") !== "integrated-manager") {
    throw new RuntimeException("Own integrated synthetic fixture required.");
}
$db = TestSupport::db();
Schema::install($db);
$guard = static function (): void {
    if (PHP_SAPI !== "cli") {
        throw new DomainException("Explicit synthetic fixture only.");
    }
};
$access = (new ManagerBridge($db, "manager-admin", "native", $guard))->access();
$subscribers = new Subscribers($db, $access);
$directory = dirname(__DIR__, 3) . "/.local/browser-tests";
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$fixture = [];
foreach (
    [
        "target" => "Newsletter-Erneuerung Browser",
        "available" => "Newsletter-Erneuerung Auswahl",
    ]
    as $key => $name
) {
    $email = "newsletter-renewal-" . $key . "@example.invalid";
    $id = Db::run(
        $db,
        "SELECT id FROM khn_newsletter_subscribers WHERE club_id=? AND email=?",
        [$access->club(), $email],
    )->fetchColumn();
    if (!$id) {
        $row = $subscribers->requestConsent(
            $email,
            $name,
            ["events", "news"],
            "Eigener synthetischer Bestätigungsantrag im Browser",
            static fn($token) => null,
        );
        $id = $row["id"];
        Db::run(
            $db,
            "UPDATE khn_newsletter_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE club_id=? AND subscriber_id=? AND kind='confirm'",
            [$access->club(), $id],
        );
    }
    $row = $subscribers->get($id);
    if ($row["status"] !== "pending" || $row["name"] !== $name) {
        throw new RuntimeException(
            "Existing synthetic renewal original changed; inspect before replacing.",
        );
    }
    $fixture[$key] = $id;
}
$fixture['repeat'] = Db::run($db,
    "SELECT id,preview_hash FROM native_newsletter_confirmation_batches WHERE club_id=? AND subscriber_id=? AND actor_id=? ORDER BY created_at,id LIMIT 1",
    [$access->club(),$fixture['target'],$access->user()['id']])->fetch() ?: null;
file_put_contents(
    $directory . "/newsletter-renewals.json",
    json_encode($fixture, JSON_THROW_ON_ERROR),
);
echo "Newsletter renewal originals ready; no mail or worker transport activated.\n";
