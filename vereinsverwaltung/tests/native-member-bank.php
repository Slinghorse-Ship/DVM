<?php
declare(strict_types=1);

putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
putenv("VEREIN_PROCESSWIRE_ROOT=/does-not-exist");
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/bootstrap.php";
require_once dirname(__DIR__) . "/src/Web/View.php";

use Kulturhus\Native\Auth\{Runtime, Service, Totp};
use Kulturhus\Native\Integration\{ManagerBridge, MemberBankAuthentication};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database, MemberBankStepUp, MemberBankVault, Registry};

if (($argv[1] ?? "") === "--controller") {
    $input = json_decode(
        stream_get_contents(STDIN),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    putenv("KH_NATIVE_AUTH_KEY_FILE=" . $input["root"] . "/auth.key");
    putenv("VEREIN_MEMBER_BANK_KEY_PATH=" . $input["root"] . "/bank.key");
    putenv("APP_SECRET=" . str_repeat("synthetic-native-bank-", 3));
    session_save_path($input["root"] . "/sessions");
    session_id($input["session"]);
    session_start();
    $_SESSION["actor"] = "bank-member";
    $_SESSION["csrf"] = str_repeat("c", 64);
    $_SESSION["auth_token"] = $input["token"];
    $_GET = [
        "view" => "portal",
        "tab" => "bank",
        "person" => $input["contact"],
    ];
    $_POST = $input["post"];
    if (
        ($_POST["action"] ?? "") === "member-bank-confirm" &&
        ($_POST["challenge"] ?? "") === "current"
    ) {
        $_POST["challenge"] =
            $_SESSION["member_bank_pending_v1"]["challenge"] ?? "absent";
    }
    $_SERVER["REQUEST_METHOD"] = $_POST ? "POST" : "GET";
    $production = true;
    $db = TestSupport::db();
    ob_start();
    register_shutdown_function(static function (): void {
        $html = ob_get_clean();
        $state = serialize($_SESSION);
        echo json_encode(
            [
                "error" => $GLOBALS["bankError"] ?? "",
                "pending" => isset($_SESSION["member_bank_pending_v1"]),
                "revealed" => $GLOBALS["bankRevealed"] ?? false,
                "private_html" => str_contains(
                    $html,
                    'value="DE89370400440532013000"',
                ),
                "native_factor" => str_contains(
                    $html,
                    "Authenticator- oder Wiederherstellungscode",
                ),
                "email_factor" => str_contains($html, "Code aus der E-Mail"),
                "session_plain" =>
                    str_contains($state, "DE89370400440532013000") ||
                    str_contains($state, "Synthetischer Kontoinhaber") ||
                    str_contains($state, "Native bank password 2026!"),
                "post_cleared" =>
                    !isset($_POST["password"]) && !isset($_POST["code"]),
                "legacy_loaded" => class_exists(
                    "ProcessWire\\ProcessWire",
                    false,
                ),
                "fatal" => in_array(
                    error_get_last()["type"] ?? 0,
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true,
                ),
            ],
            JSON_THROW_ON_ERROR,
        );
    });
    require dirname(__DIR__) . "/src/People/Members/member-bank-controller.php";
    require dirname(__DIR__) . "/views/People/Members/member-bank.php";
    exit();
}

$db = TestSupport::db();
Database::migrate($db);
foreach (
    [
        Kulturhus\Native\Integration\Schema::class,
        Kulturhus\Native\Cms\Schema::class,
        Kulturhus\Native\Auth\Schema::class,
    ]
    as $schema
) {
    $schema::install($db);
}
$db->exec(
    "INSERT INTO clubs VALUES('bank-club','Synthetischer Bankverein'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('bank-admin','bank-club','members',1),('bank-member','bank-club','member',0),('bank-other','bank-club','member',0)",
);
$registry = new Registry($db, "bank-admin");
$contact = $registry->saveContact(
    null,
    0,
    "person",
    "Synthetisches Bankmitglied",
    "",
    "",
);
$registry->addMembership($contact, "BANK-NATIVE-1", "aktiv", "2020-01-01");
Db::run($db, "UPDATE actors SET contact_id=? WHERE id=?", [
    $contact,
    "bank-member",
]);
$access = (new ManagerBridge(
    $db,
    "bank-admin",
    "native",
    static fn() => null,
))->access();
// A disabled obsolete binding must not become the authority for native accounts.
$db->exec("INSERT INTO processwire_identities VALUES(456,'bank-member',0,1)");
$root = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/member-bank-runtime";
mkdir($root, 0700);
mkdir($root . "/sessions", 0700);
foreach (["auth.key", "bank.key"] as $name) {
    file_put_contents($root . "/" . $name, random_bytes(32));
    chmod($root . "/" . $name, 0600);
}
putenv("KH_NATIVE_AUTH_KEY_FILE=" . $root . "/auth.key");
putenv("VEREIN_MEMBER_BANK_KEY_PATH=" . $root . "/bank.key");
putenv("APP_SECRET=" . str_repeat("synthetic-native-bank-", 3));
$auth = Runtime::service($db);
$password = "Native bank password 2026!";
$invite = $auth->createInvitation(
    $access,
    ManagerBridge::actorKey("bank-member", "bank-club"),
    "native-bank@example.invalid",
);
$auth->acceptInvitation($invite["token"], $password);
$token = $auth->passwordLogin(
    "native-bank@example.invalid",
    $password,
    "bank-fixture",
)["session_token"];
$sessionId = bin2hex(random_bytes(16));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    ++$checks;
};
$deny = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (DomainException) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected denial: " . $label);
};
$run = static function (array $post = []) use (
    $root,
    $sessionId,
    $contact,
    &$token,
    $check,
): array {
    if($post)$post=array_replace(['member_bank_contact'=>$contact],$post);
    $process = proc_open(
        [...TestSupport::phpCommand(), __FILE__, "--controller"],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    fwrite(
        $pipes[0],
        json_encode(
            [
                "root" => $root,
                "session" => $sessionId,
                "contact" => $contact,
                "token" => $token,
                "post" => $post,
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $result = json_decode($out, true);
    $check(
        proc_close($process) === 0 &&
            $err === "" &&
            is_array($result) &&
            !$result["fatal"],
        "actual native controller completes",
    );
    $check(
        !$result["session_plain"] &&
            !$result["legacy_loaded"] &&
            $result["post_cleared"],
        "private pending state and no ProcessWire runtime",
    );
    return $result;
};
$post = [
    "csrf" => str_repeat("c", 64),
    "action" => "member-bank-begin",
    "operation" => "save",
    "version" => "0",
    "password" => $password,
    "data" => [
        "holder" => "Synthetischer Kontoinhaber",
        "iban" => "DE89370400440532013000",
        "bic" => "",
    ],
];
$bad = $run(array_replace($post, ["csrf" => "wrong"]));
$check(
    $bad["error"] !== "" && !$bad["pending"],
    "CSRF blocks native password proof",
);
$bad = $run(array_replace($post, ["password" => "wrong"]));
$check(
    $bad["error"] !== "" &&
        !$bad["pending"] &&
        !$db->query("SELECT COUNT(*) FROM member_bank_grants")->fetchColumn(),
    "wrong native password issues no grant",
);
$saved = $run($post);
$check(
    $saved["error"] === "" && !$saved["pending"],
    "native password-only save works",
);
$check(
    (int) $db
        ->query("SELECT version FROM member_bank_records")
        ->fetchColumn() === 1,
    "original vault stores exact bank revision",
);
$masked = $run();
$check(
    $masked["error"] === "" && !$masked["private_html"],
    "native GET masks saved values",
);

// Enroll one step behind so the current TOTP is still unused for the bank proof.
$key = file_get_contents($root . "/auth.key");
$now = time() - 30;
$enrollment = new Service($db, $key, static fn() => $now);
$setup = $enrollment->beginTotp($token, $password);
$recovery = $enrollment->confirmTotp(
    $token,
    Totp::code($setup["secret"], $now),
)["recovery_codes"];
$reveal = [
    "csrf" => str_repeat("c", 64),
    "action" => "member-bank-begin",
    "operation" => "reveal",
    "version" => "1",
    "password" => $password,
];
$pending = $run($reveal);
$check(
    $pending["error"] === "" &&
        $pending["pending"] &&
        !$pending["private_html"],
    "native authenticator challenge without email transport: " .
        json_encode($pending),
);
$read = $run();
$check(
    $read["pending"] &&
        !$read["private_html"] &&
        $read["native_factor"] &&
        !$read["email_factor"],
    "native pending GET has the authenticator form and never decrypts bank data",
);
$confirm = [
    "csrf" => str_repeat("c", 64),
    "action" => "member-bank-confirm",
    "challenge" => "current",
    "code" => "00000000",
];
$bad = $run($confirm);
$check(
    $bad["error"] !== "" && $bad["pending"] && !$bad["revealed"],
    "legacy email code cannot authorize native bank access",
);
$confirm["code"] = Totp::code($setup["secret"], time());
$read = $run($confirm);
$check(
    $read["error"] === "" &&
        $read["revealed"] &&
        $read["private_html"] &&
        !$read["pending"],
    "actual native TOTP releases the exact original bank data once",
);
$replay = $run($confirm);
$check(
    $replay["error"] !== "" && !$replay["revealed"],
    "controller challenge is single use",
);
$read = $run();
$check(
    !$read["revealed"] && !$read["private_html"],
    "later GET cannot reuse native reveal",
);
$records = $db->query("SELECT * FROM member_bank_records")->fetchAll();
$history = $db->query("SELECT * FROM member_bank_history")->fetchAll();
$run($reveal);
$confirm["code"] = $recovery[0];
$read = $run($confirm);
$check(
    $read["revealed"] && $read["error"] === "",
    "native recovery code can prove bank access",
);
$run($reveal);
$read = $run($confirm);
$check(
    $read["error"] !== "" && !$read["revealed"],
    "consumed native recovery code cannot be reused",
);
$check(
    $db->query("SELECT * FROM member_bank_records")->fetchAll() === $records &&
        $db->query("SELECT * FROM member_bank_history")->fetchAll() ===
            $history,
    "native reveals preserve original bank records and history",
);

session_save_path($root . "/sessions");
session_id(bin2hex(random_bytes(16)));
session_start();
$_SESSION["auth_token"] = $token;
$step = MemberBankStepUp::production($db, "bank-member");
$binding = [
    "contact" => $contact,
    "action" => "reveal",
    "version" => 1,
    "draft_hash" => MemberBankVault::payloadHash("reveal", []),
];
// Prior real controller checks have consumed password budget; move only the
// dedicated synthetic fixture's limiter window to test an independent case.
$db->exec("UPDATE native_auth_limits SET expires_at=0");
$pending = $step->begin($binding, $password);
$deny(
    fn() => $step->confirm(
        $pending["challenge"],
        array_replace($binding, ["version" => 2]),
        $recovery[1],
    ),
    "changed operation revision invalidates native proof",
);
$pending = $step->begin($binding, $password);
$otherSession = $_SESSION;
$otherStep = new MemberBankStepUp(
    $db,
    "bank-member",
    $otherSession,
    hash("sha256", "different-browser"),
    fn($p) => $auth->reauthenticatePassword($token, $p),
    fn() => $auth->reauthenticationState($token),
    static fn() => false,
    static function (): void {},
    hash_hkdf(
        "sha256",
        \Kulturhus\Verein\ContactArchive::serverKey(),
        32,
        "member-bank-stepup-v1",
    ),
    null,
    fn($code, $stamp) => $auth->reauthenticateFactor($token, $code, $stamp),
);
$deny(
    fn() => $otherStep->confirm($pending["challenge"], $binding, $recovery[1]),
    "native proof cannot move to another browser session",
);
$db->exec("UPDATE native_auth_limits SET expires_at=0");
$pending = $step->begin($binding, $password);
$nativeLogin = $auth->passwordLogin(
    "native-bank@example.invalid",
    $password,
    "second-bank-browser",
);
$secondToken = $auth->verifySecondFactor(
    $nativeLogin["challenge"],
    $recovery[2],
    "second-bank-browser",
)["session_token"];
$deny(
    fn() => MemberBankAuthentication::identity(
        $db,
        "bank-other",
        $auth,
        $secondToken,
    ),
    "valid native login cannot become a different manager actor",
);
$auth->logout($token);
$deny(
    fn() => $step->confirm($pending["challenge"], $binding, $recovery[1]),
    "session revoked while pending blocks native proof",
);
$deny(
    fn() => MemberBankAuthentication::identity(
        $db,
        "bank-other",
        $auth,
        $token,
    ),
    "native identity cannot become another actor",
);
$check(
    $auth->accounts($access)[0]["totp_enabled"],
    "bank proof does not change native factor settings",
);
$_SESSION["auth_token"] = $secondToken;
$db->exec("UPDATE native_auth_limits SET expires_at=0");
$secondStep = MemberBankStepUp::production($db, "bank-member");
$pending = $secondStep->begin($binding, $password);
$grant = $secondStep->confirm($pending["challenge"], $binding, $recovery[1]);
$vault = new MemberBankVault(
    $db,
    "bank-member",
    $root . "/bank.key",
    1,
    static fn() => MemberBankAuthentication::identity(
        $db,
        "bank-member",
        $auth,
        $secondToken,
    ),
);
$auth->logout($secondToken);
$deny(
    fn() => $vault->reveal(
        $contact,
        1,
        [],
        $grant,
        hash("sha256", session_id()),
    ),
    "session revoked after proof blocks vault consumption",
);
$check(
    Db::run($db, "SELECT used_at FROM member_bank_grants WHERE token_hash=?", [
        hash("sha256", $grant),
    ])->fetchColumn() === null,
    "failed native identity check does not consume the original grant",
);

$nativeLogin = $auth->passwordLogin(
    "native-bank@example.invalid",
    $password,
    "limit-browser-a",
);
$thirdToken = $auth->verifySecondFactor(
    $nativeLogin["challenge"],
    $recovery[3],
    "limit-browser-a",
)["session_token"];
$nativeLogin = $auth->passwordLogin(
    "native-bank@example.invalid",
    $password,
    "limit-browser-b",
);
$fourthToken = $auth->verifySecondFactor(
    $nativeLogin["challenge"],
    $recovery[4],
    "limit-browser-b",
)["session_token"];
$db->exec("UPDATE native_auth_limits SET expires_at=0");
for ($i = 0; $i < 5; ++$i) {
    $deny(
        fn() => $auth->reauthenticatePassword($thirdToken, "wrong"),
        "bad passwords remain limited after failed transactions",
    );
}
$deny(
    fn() => $auth->reauthenticatePassword($fourthToken, $password),
    "another browser cannot reset account-wide password limit",
);
$stamp = $auth->reauthenticationState($thirdToken)["stamp"];
for ($i = 0; $i < 5; ++$i) {
    $deny(
        fn() => $auth->reauthenticateFactor($thirdToken, "00000000", $stamp),
        "failed factor attempts persist",
    );
}
$deny(
    fn() => $auth->reauthenticateFactor(
        $fourthToken,
        $recovery[5],
        $auth->reauthenticationState($fourthToken)["stamp"],
    ),
    "another session cannot reset account-wide factor limit",
);
$check(
    $db->query("SELECT * FROM member_bank_records")->fetchAll() === $records &&
        $db->query("SELECT * FROM member_bank_history")->fetchAll() ===
            $history,
    "all rejected native checks preserve bank originals",
);
require __DIR__.'/support/member-bank-date-contracts.php';
session_destroy();
echo "$checks native member bank checks passed; real native accounts, MySQL, controller and vault; no ProcessWire, mail or payment transport.\n";
