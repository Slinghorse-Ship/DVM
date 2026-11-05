<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Auth\{Service, Schema, Sessions};
use Kulturhus\Native\Shared\{Db, Access};
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','accounts','manage')",
);
$now = time();
$auth = new Service($db, random_bytes(32), static function () use (&$now): int {
    return $now;
});
$admin = new Access($db, "admin");
foreach (
    [
        "reader" => "reader-session@example.invalid",
        "editor" => "editor-session@example.invalid",
    ]
    as $actor => $email
) {
    $invite = $auth->createInvitation($admin, $actor, $email);
    $auth->acceptInvitation(
        $invite["token"],
        "Original session password 2026!",
    );
}
$password = "Original session password 2026!";
$session = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "first",
)["session_token"];
$other = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "second",
)["session_token"];
$foreign = $auth->passwordLogin(
    "editor-session@example.invalid",
    $password,
    "foreign",
)["session_token"];
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
$check(
    $auth->sessionDuration($session)["mode"] === "standard",
    "existing native default remains eight hours",
);
$deny(
    fn() => $auth->selectSessionDuration($session, "foreign-account"),
    "unknown duration cannot select another account",
);
$check(
    $auth->resolve($session)["actor_id"] === "reader",
    "rejected selection preserves original login",
);
$selected = $auth->selectSessionDuration($session, "day");
$day = $selected["session_token"];
$deny(
    fn() => $auth->resolve($session),
    "duration change revokes previous current-device token",
);
$check(
    $auth->resolve($other)["actor_id"] === "reader" &&
        $auth->resolve($foreign)["actor_id"] === "editor",
    "other device and another person remain unchanged",
);
$check(
    $selected["expires_at"] === $now + 86400 &&
        $selected["account"]["actor_id"] === "reader",
    "selected day remains on original account",
);
$now += 86399;
$auth->resolve($day);
$expires = Db::run(
    $db,
    "SELECT expires_at FROM native_auth_sessions WHERE token_hash=?",
    [hash("sha256", $day)],
)->fetchColumn();
$check(
    (int) $expires === $now + 86400,
    "active day preference extends from actual use",
);
$now += 86400;
$deny(
    fn() => $auth->resolve($day),
    "full unused day expires before a request can extend it",
);
$login = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "new-device",
);
$month = $auth->selectSessionDuration($login["session_token"], "month")[
    "session_token"
];
$created = $now;
$now += 2591999;
$check(
    $auth->resolve($month)["actor_id"] === "reader",
    "selected month survives app restart and 30-day inactivity boundary",
);
$changed = $auth->changePassword(
    $month,
    $password,
    "Changed session password 2026!",
);
$month = $changed["session_token"];
$check(
    $auth->sessionDuration($month)["mode"] === "month",
    "password change retains current device duration",
);
$password = "Changed session password 2026!";
$logout = $auth->selectSessionDuration($month, "logout")["session_token"];
$created = $now;
$now += 2592001;
$check(
    $auth->resolve($logout)["actor_id"] === "reader",
    "until-logout mode has no 30-day idle expiration",
);
$auth->logout($logout);
$deny(
    fn() => $auth->resolve($logout),
    "explicit logout ends persistent current-device login",
);
$login = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "bounded-device",
);
$persistent = $auth->selectSessionDuration($login["session_token"], "logout")[
    "session_token"
];
$now += Sessions::MAX_SECONDS;
$deny(
    fn() => $auth->resolve($persistent),
    "persistent storage has a finite 400-day upper bound",
);
$login = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "revoked-device",
);
$auth->revokeSessions($login["session_token"]);
$deny(
    fn() => $auth->selectSessionDuration($login["session_token"], "month"),
    "duration cannot revive revoked authentication",
);
$login = $auth->passwordLogin(
    "reader-session@example.invalid",
    $password,
    "schema-device",
);
$before = Db::run(
    $db,
    "SELECT * FROM native_auth_sessions WHERE token_hash=?",
    [hash("sha256", $login["session_token"])],
)->fetch();
Schema::install($db);
$after = Db::run($db, "SELECT * FROM native_auth_sessions WHERE token_hash=?", [
    hash("sha256", $login["session_token"]),
])->fetch();
$check(
    $before === $after,
    "explicit schema maintenance preserves original sessions and preferences",
);
echo $checks .
    " native device-duration checks passed; real MySQL sessions, original people, idle boundaries and revocation.\n";
