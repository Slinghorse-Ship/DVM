<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/src/autoload.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\{MemberAccounts, ManagerBridge};
use Kulturhus\Verein\{Database, Registry, MemberBankStepUp, ContactArchive};
if (($argv[1] ?? "") === "--race-email-factor") {
    $data = json_decode(
        stream_get_contents(STDIN),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    $raceAuth = new Service(
        TestSupport::db(),
        base64_decode($data["key"]),
        static fn() => (int) $data["now"],
    );
    while (microtime(true) < $data["start"]) {
        usleep(1000);
    }
    try {
        $raceAuth->verifySecondFactor(
            $data["challenge"],
            $data["code"],
            $data["client"],
        );
        echo "ok";
    } catch (DomainException) {
        echo "denied";
    }
    exit();
}
$db = TestSupport::db();
Database::migrate($db);
foreach (
    [
        Kulturhus\Native\Integration\Schema::class,
        Kulturhus\Native\Communication\Schema::class,
        Kulturhus\Native\Auth\Schema::class,
    ]
    as $schema
) {
    $schema::install($db);
}
putenv("VEREIN_AUTH=native");
putenv("KH_NATIVE_MANAGER=1");
Db::run($db, "INSERT INTO clubs VALUES('email-test','E-Mail-Testverein')");
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('email-admin','email-test','members',1)",
);
$access = (new ManagerBridge($db, "email-admin"))->access();
$club = $access->club();
Db::run(
    $db,
    "INSERT INTO native_club_settings VALUES(?,1,?,?,UTC_TIMESTAMP())",
    [
        $club,
        json_encode([
            "name" => "E-Mail-Testverein",
            "origin" => "https://email.example.invalid",
            "address" => "",
            "logo_id" => "",
        ]),
        $access->user()["id"],
    ],
);
$now = time();
$clock = static function () use (&$now): int {
    return $now;
};
$authKey = random_bytes(32);
$auth = new Service($db, $authKey, $clock);
$box = new SecretBox(random_bytes(32));
$members = new MemberAccounts($db, $auth, $box);
$registry = new Registry($db, "email-admin");
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
$person = static function (string $name, ?string $family = null) use (
    $registry,
    $members,
    $auth,
): array {
    $c = $registry->saveContact(
        null,
        0,
        "person",
        $name,
        "shared-email-factor@example.invalid",
        "Synthetic address",
    );
    $registry->addMembership(
        $c,
        "",
        "aktiv",
        "2020-01-01",
        false,
        $family ? ["expected" => "", "target" => $family] : [],
    );
    $a = $members->prepare("email-test", $c);
    $auth->acceptInvitation(
        $a["setup_token"],
        "Original personal password 2026!",
    );
    return $a + ["contact" => $c];
};
$first = $person("First Email Factor");
$registry->assignFamily($first["contact"], "", "new", "E-Mail-Testfamilie");
$second = $person(
    "Second Email Factor",
    $registry->family($first["contact"])["id"],
);
$password = "Original personal password 2026!";
$login = $auth->passwordLogin($first["username"], $password, "device-one");
$session = $login["session_token"];
$other = $auth->passwordLogin($first["username"], $password, "device-two")[
    "session_token"
];
$family = $auth->passwordLogin($second["username"], $password, "family-device")[
    "session_token"
];
$deny(
    fn() => $auth->beginEmailFactor($session, "wrong", true),
    "wrong password creates no activation code",
);
$check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_auth_email_codes")
        ->fetchColumn() === 0,
    "no factor mail without password proof",
);
$pending = $auth->beginEmailFactor($session, $password, true);
$check(
    !$auth->resolve($session)["email_factor"] && !isset($pending["code"]),
    "activation remains pending and returns no code",
);
$messages = [];
$send = static function (array $message) use (&$messages): void {
    $messages[] = $message;
};
$check(
    $auth->deliverEmailFactors($club, $send)["sent"] === 0 && $messages === [],
    "synthetic environment blocks security transport",
);
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
$check(
    $auth->deliverEmailFactors("foreign-club", $send)["sent"] === 0,
    "foreign club cannot deliver own factor",
);
$check(
    $auth->deliverEmailFactors($club, $send)["sent"] === 1,
    "private fake transport delivers own activation code",
);
$code = static function () use (&$messages): string {
    preg_match("/Sicherheitscode: ([0-9]{8})/", end($messages)["text"], $m);
    if (!isset($m[1])) {
        throw new RuntimeException("Expected security mail");
    }
    return $m[1];
};
$activate = $code();
$row = Db::run($db, "SELECT * FROM native_auth_email_codes WHERE id=?", [
    hash("sha256", $pending["challenge"]),
])->fetch();
$check(
    $row["cipher"] === null && !str_contains(json_encode($row), $activate),
    "confirmed delivery discards encrypted code and exposes no plaintext",
);
$deny(
    fn() => $auth->confirmEmailFactor(
        $other,
        $pending["challenge"],
        $activate,
        true,
    ),
    "activation cannot be adopted by another own session",
);
$deny(
    fn() => $auth->confirmEmailFactor(
        $session,
        $pending["challenge"],
        "00000000",
        true,
    ),
    "wrong activation code rejected",
);
$auth->confirmEmailFactor($session, $pending["challenge"], $activate, true);
$check(
    $auth->resolve($session)["email_factor"] &&
        !$auth->resolve($session)["totp_enabled"],
    "confirmed email factor enables only the original account",
);
$deny(
    fn() => $auth->resolve($other),
    "activation revokes other sessions of same account",
);
$check(
    $auth->resolve($family)["actor_id"] ===
        ManagerBridge::actorKey(
            Db::run(
                $db,
                "SELECT manager_actor_id FROM native_manager_identities i JOIN native_auth_accounts a ON a.actor_id=i.native_id WHERE a.id=?",
                [$second["account_id"]],
            )->fetchColumn(),
            "email-test",
        ),
    "other family account remains authenticated",
);
$deny(
    fn() => $auth->beginTotp($session, $password),
    "Authenticator cannot replace active email factor",
);
$deny(
    fn() => $auth->confirmEmailFactor(
        $session,
        $pending["challenge"],
        $activate,
        true,
    ),
    "activation code cannot be replayed",
);
$deny(
    fn() => $auth->passwordLogin(
        "shared-email-factor@example.invalid",
        $password,
        "ambiguous",
    ),
    "shared family email cannot choose an account for MFA",
);
$challenge = $auth->passwordLogin(
    $first["username"],
    $password,
    "login-client",
);
$check(
    $challenge["status"] === "second_factor" &&
        $challenge["method"] === "email" &&
        !isset($challenge["session_token"]),
    "password alone never authenticates email-factor account",
);
$deny(
    fn() => $auth->verifySecondFactor(
        $challenge["challenge"],
        $activate,
        "login-client",
    ),
    "activation code cannot authenticate a login",
);
$auth->deliverEmailFactors($club, $send);
$loginCode = $code();
$deny(
    fn() => $auth->verifySecondFactor(
        $challenge["challenge"],
        $loginCode,
        "foreign-client",
    ),
    "login code is bound to original client",
);
$complete = $auth->verifySecondFactor(
    $challenge["challenge"],
    $loginCode,
    "login-client",
);
$token = $complete["session_token"];
$check(
    $auth->resolve($token)["actor_id"] ===
        Db::run($db, "SELECT actor_id FROM native_auth_accounts WHERE id=?", [
            $first["account_id"],
        ])->fetchColumn(),
    "password and delivered code authenticate exact family person",
);
$deny(
    fn() => $auth->verifySecondFactor(
        $challenge["challenge"],
        $loginCode,
        "login-client",
    ),
    "login code is consumed once",
);
$state = $auth->reauthenticationState($token);
$before = (int) $db
    ->query("SELECT COUNT(*) FROM native_auth_sessions")
    ->fetchColumn();
$auth->reauthenticatePassword($token, $password);
$auth->deliverEmailFactors($club, $send);
$bankCode = $code();
$check(
    $state["mfa"] && $state["email"] === "",
    "bank proof reports factor without exposing delivery address",
);
$deny(
    fn() => $auth->reauthenticateFactor(
        $session,
        $bankCode,
        $auth->reauthenticationState($session)["stamp"],
    ),
    "bank code cannot move to another session",
);
$check(
    $auth->reauthenticateFactor($token, $bankCode, $state["stamp"]),
    "bank reauthentication consumes its own email code",
);
$check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_auth_sessions")
        ->fetchColumn() === $before,
    "bank confirmation creates no new login",
);
$deny(
    fn() => $auth->reauthenticateFactor($token, $bankCode, $state["stamp"]),
    "bank code replay rejected",
);
$stale = $auth->passwordLogin($first["username"], $password, "stale-client");
$reset = $auth->requestReset($first["username"], "reset-client");
$auth->resetPassword($reset["token"], "Changed personal password 2026!");
$password = "Changed personal password 2026!";
$check(
    $auth->deliverEmailFactors($club, $send)["sent"] === 0 &&
        Db::run($db, "SELECT status FROM native_auth_email_codes WHERE id=?", [
            hash("sha256", $stale["challenge"]),
        ])->fetchColumn() === "blocked",
    "password reset blocks previously queued login code",
);
$deny(
    fn() => $auth->verifySecondFactor(
        $stale["challenge"],
        "00000000",
        "stale-client",
    ),
    "password reset invalidates earlier factor challenge",
);
$challenge = $auth->passwordLogin($first["username"], $password, "after-reset");
$auth->deliverEmailFactors($club, $send);
$token = $auth->verifySecondFactor(
    $challenge["challenge"],
    $code(),
    "after-reset",
)["session_token"];
$check(
    $auth->resolve($token)["email_factor"],
    "password reset preserves the enabled factor",
);
$now += 901;
$pending = $auth->beginEmailFactor($token, $password, false);
$auth->deliverEmailFactors($club, $send);
$disable = $code();
$deny(
    fn() => $auth->confirmEmailFactor(
        $token,
        $pending["challenge"],
        $disable,
        true,
    ),
    "disable code cannot enable another factor transition",
);
$auth->confirmEmailFactor($token, $pending["challenge"], $disable, false);
$check(
    !$auth->resolve($token)["email_factor"] &&
        $auth->passwordLogin($first["username"], $password, "disabled-client")[
            "status"
        ] === "authenticated",
    "confirmed disable restores original password login",
);
$now += 901;
$pending = $auth->beginEmailFactor($token, $password, true);
$auth->deliverEmailFactors($club, static function (array $message) use (
    &$messages,
): void {
    $messages[] = $message;
    throw new RuntimeException("Uncertain synthetic delivery");
});
$uncertain = $code();
$check(
    $auth->deliverEmailFactors($club, $send)["sent"] === 0,
    "uncertain security mail never automatically resends",
);
$deny(
    fn() => $auth->confirmEmailFactor(
        $token,
        $pending["challenge"],
        $uncertain,
        true,
    ),
    "uncertain delivery cannot change account security",
);
$now += 301;
$auth->deliverEmailFactors($club, null);
$check(
    (int) $db
        ->query(
            "SELECT COUNT(*) FROM native_auth_email_codes WHERE cipher IS NOT NULL AND expires_at<=" .
                $now,
        )
        ->fetchColumn() === 0,
    "expired protected codes discarded without transport",
);
$pending = $auth->beginEmailFactor($token, $password, true);
Db::run($db, "UPDATE actors SET active=0 WHERE id=?", [
    Db::run(
        $db,
        "SELECT manager_actor_id FROM native_manager_identities i JOIN native_auth_accounts a ON a.actor_id=i.native_id WHERE a.id=?",
        [$first["account_id"]],
    )->fetchColumn(),
]);
$check(
    $auth->deliverEmailFactors($club, $send)["blocked"] === 1,
    "original actor suspension blocks queued email code",
);
Db::run($db, "UPDATE actors SET active=1 WHERE id=?", [
    Db::run(
        $db,
        "SELECT manager_actor_id FROM native_manager_identities i JOIN native_auth_accounts a ON a.actor_id=i.native_id WHERE a.id=?",
        [$first["account_id"]],
    )->fetchColumn(),
]);
$now += 901;
$pending = $auth->beginEmailFactor($token, $password, true);
$auth->deliverEmailFactors($club, $send);
$auth->confirmEmailFactor($token, $pending["challenge"], $code(), true);
$now += 901;
$concurrent = $auth->passwordLogin(
    $first["username"],
    $password,
    "race-client",
);
$auth->deliverEmailFactors($club, $send);
$concurrentCode = $code();
$children = [];
$start = microtime(true) + 0.8;
for ($i = 0; $i < 2; $i++) {
    $process = proc_open(
        [...TestSupport::phpCommand(), __FILE__, "--race-email-factor"],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Cannot start isolated factor race");
    }
    fwrite(
        $pipes[0],
        json_encode(
            [
                "key" => base64_encode($authKey),
                "now" => $now,
                "start" => $start,
                "challenge" => $concurrent["challenge"],
                "code" => $concurrentCode,
                "client" => "race-client",
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
    fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
$results = [];
foreach ($children as [$process, $pipes]) {
    $results[] = trim(stream_get_contents($pipes[1]));
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $stderr !== "") {
        throw new RuntimeException(
            "Isolated factor race failed; private diagnostics withheld.",
        );
    }
}
sort($results);
$check(
    $results === ["denied", "ok"],
    "two real processes consume delivered email code exactly once",
);
$challenge = $auth->passwordLogin($first["username"], $password, "rate-client");
$auth->deliverEmailFactors($club, $send);
$valid = $code();
for ($i = 0; $i < 6; $i++) {
    $deny(
        fn() => $auth->verifySecondFactor(
            $challenge["challenge"],
            "00000000",
            "rate-client",
        ),
        "bounded failed code attempt " . ($i + 1),
    );
}
$deny(
    fn() => $auth->verifySecondFactor(
        $challenge["challenge"],
        $valid,
        "rate-client",
    ),
    "code limit cannot be bypassed by a valid final guess",
);
$now += 301;
$deny(
    fn() => $auth->verifySecondFactor(
        $challenge["challenge"],
        $valid,
        "rate-client",
    ),
    "expired delivered login code remains invalid",
);
$check(
    (int) $db
        ->query("SELECT COUNT(*) FROM processwire_identities")
        ->fetchColumn() === 0,
    "email factor operates without ProcessWire identities",
);
echo $checks .
    " native email-factor checks passed; real original MySQL accounts, encrypted fake delivery and isolated family sessions.\n";
