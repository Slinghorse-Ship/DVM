<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Integration\StadtLandFunk\{Connection, Login, Http};

$db = TestSupport::db();
TestSupport::seed($db);
\Kulturhus\Native\Shared\RuntimeSchema::install($db);
$a = new Access($db, "admin");
$box = new SecretBox(str_repeat("s", 32));
$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
    echo "PASS $label\n";
}
function denied(callable $f, string $label): void
{
    try {
        $f();
    } catch (DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
$accounts = new Accounts($db, $box);
$meta = [
    "page_id" => "123",
    "instagram_id" => "456",
    "api_version" => "v25.0",
    "token" => "SYNTHETIC-SECRET",
];
$accounts->save($a, "meta", $meta, 0);
$old = $accounts->get($a->club(), "meta");
check(
    !str_contains(
        json_encode($accounts->status($a, "meta")),
        "SYNTHETIC-SECRET",
    ),
    "public status contains only safe account metadata",
);
check(
    !str_contains(
        Db::run(
            $db,
            "SELECT ciphertext FROM native_private_settings WHERE purpose='api-meta'",
        )->fetchColumn(),
        "SYNTHETIC-SECRET",
    ),
    "Meta token encrypted in actual MySQL",
);
$accounts->save(
    $a,
    "meta",
    array_replace($meta, ["token" => "", "api_version" => "v26.0"]),
    1,
);
check(
    $accounts->get($a->club(), "meta")["token"] === "SYNTHETIC-SECRET" &&
        Accounts::binding($old) ===
            Accounts::binding($accounts->get($a->club(), "meta")),
    "blank token retains same-account secret and binding",
);
denied(
    fn() => $accounts->save(
        $a,
        "meta",
        array_replace($meta, ["page_id" => "789", "token" => ""]),
        2,
    ),
    "target change requires an explicit new secret",
);
denied(fn() => $accounts->save($a, "meta", $meta, 1), "stale edit rejected");
denied(
    fn() => $accounts->save(new Access($db, "editor"), "meta", $meta, 0),
    "editor cannot change secrets",
);
$accounts->disconnect($a, "meta", 2);
$s = $accounts->status($a, "meta");
check(
    !$s["configured"] && $s["version"] === 3 && $s["details"] === [],
    "disconnect removes secret and retains monotonic version",
);
denied(
    fn() => $accounts->get($a->club(), "meta"),
    "disconnected provider cannot supply a worker secret",
);
denied(
    fn() => $accounts->save($a, "meta", $meta, 0),
    "old create form cannot reconnect a tombstone",
);
$accounts->save($a, "meta", $meta, 3);
check(
    Accounts::binding($accounts->get($a->club(), "meta")) !==
        Accounts::binding($old),
    "same target after disconnect has a fresh connection binding",
);
denied(
    fn() => $accounts->status($a, "unknown"),
    "provider allowlist applies to status as well",
);
check(
    !Http::allowed(
        "https://prod.digitale-doerfer.de.evil.example/grapevine/post/own",
    ) &&
        !Http::allowed(
            "https://user:secret@prod.digitale-doerfer.de/app/onboardingStatus",
        ),
    "provider origin rejects suffix confusion and URL credentials",
);

$state = "";
$challenge = "";
$phase = "good";
$expires = 7200;
$refreshLife = 2592000;
$loginCalls = 0;
$refreshCalls = 0;
$accountId = "person_1";
$areaId = "area_1";
$transport = function ($method, $url, $headers, $body) use (
    &$state,
    &$challenge,
    &$phase,
    &$expires,
    &$refreshLife,
    &$loginCalls,
    &$refreshCalls,
    &$accountId,
    &$areaId,
) {
    $reply = static fn($status, $body, $headers = []) => [
        "status" => $status,
        "headers" => $headers,
        "body" => is_array($body) ? json_encode($body) : $body,
    ];
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === "/realms/DD/protocol/openid-connect/auth") {
        $loginCalls++;
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $state = $query["state"];
        $challenge = $query["code_challenge"];
        check(
            $query["code_challenge_method"] === "S256" &&
                $query["redirect_uri"] === Login::CALLBACK,
            "fresh PKCE flow uses the registered app callback",
        );
        return $reply(
            200,
            '<form id="kc-form-login" action="' .
                ($phase === "evil-form"
                    ? "https://evil.example/steal"
                    : Http::ISSUER .
                        "/login-actions/authenticate?session_code=synthetic") .
                '"><input type="hidden" name="credentialId" value=""></form>',
        );
    }
    if ($path === "/realms/DD/login-actions/authenticate") {
        parse_str($body, $form);
        check(
            ($form["username"] ?? "") === "synthetic@example.invalid" &&
                str_starts_with($form["password"] ?? "", "Synthetic-"),
            "provider form receives the intended synthetic account only",
        );
        if ($phase === "mfa") {
            return $reply(200, "Additional step required");
        }
        return $reply(302, "", [
            "location" =>
                Login::CALLBACK .
                "?" .
                http_build_query([
                    "state" => $phase === "bad-state" ? "wrong" : $state,
                    "code" => "synthetic-code",
                    "iss" => Http::ISSUER,
                ]),
        ]);
    }
    if ($path === "/realms/DD/protocol/openid-connect/token") {
        parse_str($body, $form);
        if ($form["grant_type"] === "refresh_token") {
            $refreshCalls++;
            if (in_array($phase, ["expired-refresh", "mfa"], true)) {
                return $reply(400, ["error" => "invalid_grant"]);
            }
            if ($phase === "network-failure") {
                throw new RuntimeException("Synthetic offline transport");
            }
        } else {
            check(
                rtrim(
                    strtr(
                        base64_encode(
                            hash("sha256", $form["code_verifier"], true),
                        ),
                        "+/",
                        "-_",
                    ),
                    "=",
                ) === $challenge,
                "authorization code exchange verifies actual S256 challenge",
            );
        }
        return $reply(200, [
            "access_token" => "Synthetic-Access",
            "refresh_token" => "Synthetic-Refresh",
            "token_type" => "Bearer",
            "expires_in" => $expires,
            "refresh_expires_in" => $refreshLife,
        ]);
    }
    if ($path === "/app/onboardingStatus") {
        return $reply(200, [
            "ownPerson" => ["id" => $accountId],
            "homeGeoArea" => [
                "id" => $areaId,
                "name" => "Synthetisches Heimatgebiet",
            ],
            "homeGeoAreaSelectionStatus" => "VALID",
            "onboardingRequired" => false,
        ]);
    }
    throw new RuntimeException("Unexpected synthetic contract endpoint");
};
$login = new Login($transport);
$connection = new Connection($db, $box, $login);
$connection->connect($a, "synthetic@example.invalid", "Synthetic-Password", 0);
$status = $connection->status($a);
check(
    $status["password_saved"] &&
        $status["home_area_name"] === "Synthetisches Heimatgebiet" &&
        abs($status["expires_at"] - time() - 7200) < 5,
    "saved connection uses actual provider duration",
);
$cipher = Db::run(
    $db,
    "SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",
    [$a->club()],
)->fetchColumn();
$stored = $box->open("api:" . $a->club() . ":stadtlandfunk", $cipher);
check(
    $stored["password"] === "Synthetic-Password" &&
        !str_contains($cipher, "Synthetic-Password") &&
        !str_contains($cipher, "Synthetic-Access"),
    "reusable password and personal tokens are authenticated ciphertext in MySQL",
);
check(
    !str_contains(json_encode($status), "Synthetic-Password") &&
        !str_contains(json_encode($status), "Synthetic-Access") &&
        !str_contains(json_encode($status), "Synthetic-Refresh"),
    "status never exposes password or token values",
);
denied(
    fn() => $connection->status(new Access($db, "reader")),
    "reader cannot inspect personal connection",
);
$expire = function () use ($db, $a, $box) {
    $row = Db::run(
        $db,
        "SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",
        [$a->club()],
    )->fetchColumn();
    $data = $box->open("api:" . $a->club() . ":stadtlandfunk", $row);
    $data["expires_at"] = time() - 1;
    Db::run(
        $db,
        "UPDATE native_private_settings SET ciphertext=? WHERE club_id=? AND purpose='api-stadtlandfunk'",
        [$box->seal("api:" . $a->club() . ":stadtlandfunk", $data), $a->club()],
    );
};
$expire();
$before = $loginCalls;
$expires = 2592000;
$refreshLife = 0;
$connection->verify($a, 1);
$s = $connection->status($a);
check(
    $refreshCalls === 1 &&
        $loginCalls === $before &&
        abs($s["expires_at"] - time() - 2592000) < 5,
    "longer provider duration accepted and refresh precedes password login",
);
check(
    $s["refresh_expires_at"] === null,
    "zero refresh duration remains unspecified instead of an unlimited promise",
);
$expire();
$phase = "expired-refresh";
$before = $loginCalls;
$connection->verify($a, 2);
check(
    $loginCalls === $before + 1 && $connection->status($a)["version"] === 3,
    "explicit expired refresh triggers one saved-password PKCE login",
);
$expire();
$phase = "network-failure";
$before = $loginCalls;
try {
    $connection->verify($a, 3);
    throw new LogicException("Expected offline failure");
} catch (RuntimeException $e) {
    check(
        $loginCalls === $before && $connection->status($a)["version"] === 3,
        "network failure does not trigger another credential login or overwrite the connection",
    );
}
$phase = "mfa";
$before = $loginCalls;
denied(
    fn() => $connection->verify($a, 3),
    "additional provider login step stops renewal",
);
check(
    $loginCalls === $before + 1 && $connection->status($a)["version"] === 3,
    "additional step performs one login and preserves the prior connection",
);
$phase = "good";
$areaId = "changed_area";
denied(
    fn() => $connection->verify($a, 3),
    "provider target drift requires explicit reconnect",
);
$areaId = "area_1";
$connection->connect($a, "synthetic@example.invalid", "", 3, false);
check(
    !$connection->status($a)["password_saved"],
    "explicit no-password choice removes the encrypted saved password",
);
$expire();
$phase = "expired-refresh";
$before = $loginCalls;
denied(
    fn() => $connection->verify($a, 4),
    "expired refresh without saved password needs user login",
);
check(
    $loginCalls === $before,
    "no automatic credential login without saved password",
);
$phase = "bad-state";
denied(
    fn() => $connection->connect(
        $a,
        "synthetic@example.invalid",
        "Synthetic-New",
        4,
    ),
    "wrong OAuth state cannot replace stored connection",
);
$phase = "evil-form";
denied(
    fn() => $connection->connect(
        $a,
        "synthetic@example.invalid",
        "Synthetic-New",
        4,
    ),
    "unexpected login action never receives account credentials",
);
$connection->disconnect($a, 4);
check(
    !$connection->status($a)["configured"] &&
        $connection->status($a)["version"] === 5,
    "personal disconnect removes password and tokens with monotonic version",
);
denied(
    fn() => $connection->connect(
        $a,
        "synthetic@example.invalid",
        "Synthetic-New",
        0,
    ),
    "stale personal create form cannot restore removed credentials",
);
echo "$n social account checks passed; fresh MySQL and explicit synthetic provider transport, no live login.\n";
