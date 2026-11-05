<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/src/autoload.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Auth\{Service, Mail};
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\{MemberAccounts, ManagerBridge};
use Kulturhus\Verein\{Database, Registry};

$db = TestSupport::db();
Database::migrate($db);
\Kulturhus\Native\Integration\Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
\Kulturhus\Native\Auth\Schema::install($db);
putenv("VEREIN_AUTH=native");
putenv("KH_NATIVE_MANAGER=1");
Db::run(
    $db,
    "INSERT INTO clubs VALUES('reset-test','Reset-Testverein'),('foreign-reset','Anderer Verein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('reset-admin','reset-test','members',1),('foreign-reset-admin','foreign-reset','members',1)",
);
$access = (new ManagerBridge($db, "reset-admin"))->access();
$nativeClub = ManagerBridge::clubKey("reset-test");
$foreign = (new ManagerBridge($db, "foreign-reset-admin"))->access();
foreach ([$access, $foreign] as $a) {
    Db::run(
        $db,
        "INSERT INTO native_club_settings VALUES(?,1,?,?,UTC_TIMESTAMP())",
        [
            $a->club(),
            json_encode(
                [
                    "name" => "Reset-Testverein",
                    "origin" => "https://reset.example.invalid",
                    "address" => "",
                    "logo_id" => "",
                ],
                JSON_THROW_ON_ERROR,
            ),
            $a->user()["id"],
        ],
    );
}
$now = time();
$auth = new Service($db, random_bytes(32), static function () use (&$now): int {
    return $now;
});
$boxKey = random_bytes(32);
$box = new SecretBox($boxKey);
$members = new MemberAccounts($db, $auth, $box);
$registry = new Registry($db, "reset-admin");
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL " . $label);
    }
    $checks++;
    echo "PASS " . $label . "\n";
};
$deny = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
    } catch (DomainException) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
};
$person = static function (
    string $name,
    string $email,
    ?string $family = null,
) use ($registry, $members, $auth): array {
    $contact = $registry->saveContact(
        null,
        0,
        "person",
        $name,
        $email,
        "Synthetic test address",
    );
    $registry->addMembership(
        $contact,
        "",
        "aktiv",
        "2020-01-01",
        false,
        $family ? ["expected" => "", "target" => $family] : [],
    );
    $account = $members->prepare("reset-test", $contact);
    $auth->acceptInvitation(
        $account["setup_token"],
        "Original personal password 2026!",
    );
    return $account + ["contact" => $contact];
};
$first = $person("First Reset Person", "shared-reset@example.invalid");
$registry->assignFamily($first["contact"], "", "new", "Reset-Testfamilie");
$family = $registry->family($first["contact"])["id"];
$second = $person(
    "Second Reset Person",
    "shared-reset@example.invalid",
    $family,
);
Mail::requestReset(
    $db,
    $auth,
    $box,
    "shared-reset@example.invalid",
    "ambiguous-reset",
);
Mail::requestReset(
    $db,
    $auth,
    $box,
    "unknown@example.invalid",
    "unknown-reset",
);
$check(
    (int) $db->query("SELECT COUNT(*) FROM native_auth_mail")->fetchColumn() ===
        0,
    "ambiguous and unknown requests create no arbitrary account mail",
);
$keyFile = tempnam(sys_get_temp_dir(), "dvm-reset-key-");
file_put_contents($keyFile, $boxKey);
chmod($keyFile, 0600);
putenv("KH_NATIVE_PRIVATE_KEY_FILE=" . $keyFile);
try {
    $web = \Kulturhus\Native\Web\Account::forManager(
        $db,
        $auth,
        str_repeat("a", 64),
        "anonymous-http",
    );
    $session = [];
    $result = $web->action(
        [
            "csrf" => str_repeat("a", 64),
            "operation" => "request-reset",
            "email" => $second["username"],
        ],
        $session,
    );
    $row = $db
        ->query("SELECT * FROM native_auth_mail WHERE status='queued'")
        ->fetch();
    $check(
        $row["account_id"] === $second["account_id"] &&
            !isset($result["token"]) &&
            !isset($result["account_id"]),
        "actual anonymous manager form queues only the exact personal account",
    );
    $payload = $box->open("auth-mail:" . $row["id"], $row["cipher"]);
    preg_match("~[?&]token=([a-f0-9]{64})~", $payload["text"], $match);
    $token = $match[1];
    $check(
        !str_contains(json_encode($result), $token) &&
            !str_contains(json_encode($row), $token) &&
            !str_contains(json_encode($session), $token),
        "reset credential is encrypted and absent from anonymous responses, sessions and row metadata",
    );
    $messages = [];
    $send = static function (array $mail) use (&$messages): void {
        $messages[] = $mail;
    };
    Mail::run($db, $box, $nativeClub, $send);
    $check(
        !$messages &&
            $db
                ->query(
                    "SELECT status FROM native_auth_mail WHERE id=" .
                        $db->quote($row["id"]),
                )
                ->fetchColumn() === "queued",
        "synthetic policy blocks even an injected mail transport",
    );
    foreach (
        [
            "VEREIN_MODE",
            "KULTURHUS_LOCAL_DEMO",
            "KULTURHUS_DISABLE_MAIL",
            "APP_ENV",
        ]
        as $env
    ) {
        putenv($env);
    }
    putenv("APP_URL=https://reset.example.invalid");
    Mail::run($db, $box, $foreign->club(), $send);
    $check(
        !$messages,
        "another club worker cannot deliver original account mail",
    );
    $delivery = Mail::run($db, $box, $nativeClub, $send);
    $check(
        $delivery["sent"] === 1 &&
            count($messages) === 1 &&
            str_contains($messages[0]["text"], $second["username"]) &&
            str_contains($messages[0]["text"], $token),
        "private worker sends the original personal reset through injected transport",
    );
    $check(
        Db::run($db, "SELECT cipher FROM native_auth_mail WHERE id=?", [
            $row["id"],
        ])->fetchColumn() === null,
        "confirmed reset delivery discards the protected one-time credential",
    );
    Mail::run($db, $box, $nativeClub, $send);
    $check(count($messages) === 1, "confirmed reset mail is never replayed");
    $auth->resetPassword($token, "Changed second password 2026!");
    $check(
        $auth->passwordLogin(
            $second["username"],
            "Changed second password 2026!",
            "changed-second",
        )["status"] === "authenticated",
        "mailed reset link changes the exact account password",
    );
    $check(
        $auth->passwordLogin(
            $first["username"],
            "Original personal password 2026!",
            "unchanged-first",
        )["status"] === "authenticated",
        "other family password remains unchanged",
    );
    $now += 901;
    Mail::requestReset($db, $auth, $box, $second["username"], "superseded-one");
    Mail::requestReset($db, $auth, $box, $second["username"], "superseded-two");
    $before = count($messages);
    $delivery = Mail::run($db, $box, $nativeClub, $send);
    $check(
        $delivery["blocked"] === 1 &&
            $delivery["sent"] === 1 &&
            count($messages) === $before + 1,
        "superseded token is blocked and only current original reset is delivered",
    );
    $now += 901;
    Mail::requestReset($db, $auth, $box, $first["username"], "uncertain-one");
    Mail::run($db, $box, $nativeClub, static function (): never {
        throw new RuntimeException("Synthetic uncertain delivery");
    });
    $before = count($messages);
    Mail::run($db, $box, $nativeClub, $send);
    $check(
        count($messages) === $before &&
            (int) $db
                ->query(
                    "SELECT COUNT(*) FROM native_auth_mail WHERE status='uncertain'",
                )
                ->fetchColumn() === 1,
        "uncertain reset delivery is recorded without automatic repetition",
    );
    $disabled = $person(
        "Disabled Reset Person",
        "disabled-reset@example.invalid",
    );
    Mail::requestReset($db, $auth, $box, $disabled["username"], "disabled-one");
    Db::run($db, "UPDATE actors SET active=0 WHERE contact_id=?", [
        $disabled["contact"],
    ]);
    $delivery = Mail::run($db, $box, $nativeClub, $send);
    $check(
        $delivery["blocked"] === 1,
        "original manager deactivation stops a queued reset before delivery",
    );
    $expired = $person("Expired Reset Person", "expired-reset@example.invalid");
    Mail::requestReset($db, $auth, $box, $expired["username"], "expired-one");
    Db::run(
        $db,
        "UPDATE native_auth_mail SET expires_at=? WHERE account_id=?",
        [time() - 1, $expired["account_id"]],
    );
    Mail::run($db, $box, $nativeClub, null);
    $check(
        Db::run(
            $db,
            'SELECT cipher IS NULL AND status=\'blocked\' FROM native_auth_mail WHERE account_id=?',
            [$expired["account_id"]],
        )->fetchColumn() == 1,
        "expired protected reset is discarded even when transport is disabled",
    );
    $alias = $person("Alias Reset Person", "alias-reset@example.invalid");
    Db::run($db,"UPDATE native_auth_mail SET status='sending',cipher='expired-protected-fixture' WHERE account_id=?",[$expired['account_id']]);
    Mail::run($db,$box,$nativeClub,null);
    $check(Db::run($db,"SELECT cipher IS NULL AND status='uncertain' FROM native_auth_mail WHERE account_id=?",[$expired['account_id']])->fetchColumn()==1,'expired interrupted reset discards cipher and retains uncertainty without replay');
    $auth->requestReset($alias["username"], "alias-username-one");
    $auth->requestReset("alias-reset@example.invalid", "alias-email-one");
    $auth->requestReset($alias["username"], "alias-username-two");
    $deny(
        fn() => $auth->requestReset(
            "alias-reset@example.invalid",
            "alias-email-two",
        ),
        "account-wide reset throttle cannot be bypassed by alternating email and username",
    );
} finally {
    unlink($keyFile);
    putenv("KH_NATIVE_PRIVATE_KEY_FILE");
}
echo "$checks native account mail checks passed; actual original family accounts, native passwords, protected queue and synthetic transport.\n";
