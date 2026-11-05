<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/autoload.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\{
    ManagerBridge,
    MemberAccounts,
    AccountIdentity,
};
use Kulturhus\Verein\{Database, Registry, Settings};
$db = TestSupport::db();
Database::migrate($db);
\Kulturhus\Native\Integration\Schema::install($db);
\Kulturhus\Native\Auth\Schema::install($db);
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
Db::run(
    $db,
    "INSERT INTO clubs VALUES('identity-test','Identitätsverein'),('identity-other','Anderer Verein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('identity-admin','identity-test','members',1),('identity-other-admin','identity-other','members',1),('identity-editor','identity-test','editor',0)",
);
$box = new SecretBox(random_bytes(32));
$auth = new Service($db, random_bytes(32));
$projected = (new ManagerBridge($db, "identity-admin"))->access();
$invite = $auth->createInvitation(
    $projected,
    $projected->user()["id"],
    "identity-admin@example.invalid",
);
$auth->acceptInvitation($invite["token"], "Identity admin password 2026!");
$adminLogin = $auth->passwordLogin(
    "identity-admin@example.invalid",
    "Identity admin password 2026!",
    "identity-admin-browser",
);
$access = ManagerBridge::fromNativeSession(
    $db,
    $auth,
    $adminLogin["session_token"],
)->access();
$identities = new AccountIdentity($db, $access, $auth, $box);
$accounts = new MemberAccounts($db, $auth, $box);
$registry = new Registry($db, "identity-admin");
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
    bool $verify = true,
) use ($registry, $accounts, $auth): array {
    $contact = $registry->saveContact(
        null,
        0,
        "person",
        $name,
        $email,
        "Testadresse",
    );
    $registry->addMembership($contact, "", "aktiv", "2020-01-01", false);
    $account = $accounts->prepare("identity-test", $contact);
    if ($verify) {
        $auth->acceptInvitation(
            $account["setup_token"],
            "Member identity password 2026!",
        );
    }
    return $account + ["contact" => $contact];
};
$target = $person(
    "Original Identity Person",
    "identity-person@example.invalid",
);
$other = $person("Other Identity Person", "identity-second@example.invalid");
$current = static function (string $id) use ($identities): array {
    foreach ($identities->directory() as $row) {
        if ($row["id"] === $id) {
            return $row;
        }
    }
    throw new RuntimeException("Own identity missing");
};
$row = $current($target["account_id"]);
$actor = $row["manager_actor"];
$memberLogin = $auth->passwordLogin(
    $target["username"],
    "Member identity password 2026!",
    "identity-member-browser",
);
$memberBridge = ManagerBridge::fromNativeSession(
    $db,
    $auth,
    $memberLogin["session_token"],
);
$memberBridge->access();
$roleData = [
    "name" => "Lesende Organisation",
    "description" => "Synthetic role",
    "members_access" => "read",
    "finance_access" => "none",
    "settings_access" => false,
];
$settings = new Settings($db, "identity-admin");
$role = $settings->saveRole("", 0, $roleData);
$identities->assign(
    $row["id"],
    $row["version"],
    0,
    $target["contact"],
    $role,
    $target["contact"],
    true,
    false,
);
$assigned = $current($row["id"]);
$check(
    $assigned["manager_actor"] === $actor &&
        $assigned["native_id"] === $row["native_id"] &&
        $assigned["contact_id"] === $target["contact"],
    "role assignment preserves original actor, native identity and personal profile",
);
$check(
    $assigned["role_id"] === $role &&
        $memberBridge->access()->managerActor() === $actor &&
        \Kulturhus\Verein\Permissions::actor($db, $actor)["members_access"] ===
            "read",
    "current original role assignment takes effect in existing native session",
);
$check(
    \Kulturhus\Verein\Permissions::actor($db, $actor)["finance_access"] ===
        "none" && !$assigned["is_superuser"],
    "role assignment grants neither finances nor technical administration",
);
$deny(
    fn() => $identities->assign(
        $row["id"],
        $row["version"],
        0,
        $target["contact"],
        "",
        $target["contact"],
        true,
        false,
    ),
    "stale account form cannot overwrite current assignment",
);
$deny(
    fn() => $identities->assign(
        $assigned["id"],
        $assigned["version"],
        0,
        $target["contact"],
        "",
        $target["contact"],
        true,
        false,
    ),
    "separate stale role version cannot bypass account version",
);
$own = $current($invite["account"]["id"]);
$deny(
    fn() => $identities->assign(
        $own["id"],
        $own["version"],
        0,
        "",
        "",
        "",
        false,
        false,
    ),
    "own original administrator binding cannot be disabled",
);
$deny(
    fn() => $auth->setActive($access, $own["id"], false, $own["version"]),
    "direct native account action cannot suspend its own administrator",
);
$otherAccess = (new ManagerBridge($db, "identity-other-admin"))->access();
$foreignInvite = $auth->createInvitation(
    $otherAccess,
    $otherAccess->user()["id"],
    "foreign-identity@example.invalid",
);
$auth->acceptInvitation(
    $foreignInvite["token"],
    "Foreign identity password 2026!",
);
$foreignRole = (new Settings($db, "identity-other-admin"))->saveRole(
    "",
    0,
    array_replace($roleData, ["name" => "Foreign Role"]),
);
$check(
    !in_array(
        $foreignInvite["account"]["id"],
        array_column($identities->directory(), "id"),
        true,
    ),
    "directory contains only the original own club",
);
$deny(
    fn() => $identities->assign(
        $foreignInvite["account"]["id"],
        2,
        0,
        "",
        "",
        "",
        false,
        false,
    ),
    "foreign account cannot be assigned",
);
$deny(
    fn() => $identities->assign(
        $assigned["id"],
        $assigned["version"],
        $assigned["role_version"],
        $target["contact"],
        $foreignRole,
        $target["contact"],
        true,
        false,
    ),
    "foreign role cannot enter original personal account",
);
$deny(
    fn() => $identities->assign(
        $assigned["id"],
        $assigned["version"],
        $assigned["role_version"],
        $target["contact"],
        "",
        $other["contact"],
        true,
        true,
    ),
    "another personal account profile cannot be adopted",
);
$replacement = $registry->saveContact(
    null,
    0,
    "person",
    "Corrected Original Profile",
    "identity-person@example.invalid",
    "Testadresse",
);
$registry->addMembership($replacement, "", "aktiv", "2020-01-01", false);
$deny(
    fn() => $identities->assign(
        $assigned["id"],
        $assigned["version"],
        $assigned["role_version"],
        $target["contact"],
        "",
        $replacement,
        true,
        false,
    ),
    "profile rebinding requires explicit confirmation",
);
$hash = Db::run(
    $db,
    "SELECT password_hash FROM native_auth_accounts WHERE id=?",
    [$assigned["id"]],
)->fetchColumn();
$identities->assign(
    $assigned["id"],
    $assigned["version"],
    $assigned["role_version"],
    $target["contact"],
    "",
    $replacement,
    true,
    true,
);
$rebound = $current($assigned["id"]);
$check(
    $rebound["manager_actor"] === $actor &&
        $rebound["contact_id"] === $replacement &&
        $rebound["role_id"] === $role,
    "explicit profile correction preserves account and original additional role",
);
$check(
    Db::run($db, "SELECT password_hash FROM native_auth_accounts WHERE id=?", [
        $assigned["id"],
    ])->fetchColumn() === $hash,
    "profile correction never copies or changes personal password",
);
$check(
    Db::run(
        $db,
        "SELECT contact_id FROM native_member_onboarding WHERE account_id=?",
        [$assigned["id"]],
    )->fetchColumn() === $replacement,
    "onboarding stays bound to corrected original profile",
);
$check(
    Db::run(
        $db,
        "SELECT state FROM manual_member_accounts WHERE club_id=? AND contact_id=?",
        ["identity-test", $target["contact"]],
    )->fetchColumn() === "needs_review",
    "previous original access queue cannot deliver into corrected profile",
);
$check(
    (new Registry($db, $memberBridge->managerActor()))->contact($replacement)[
        "id"
    ] === $replacement,
    "existing native session resolves corrected original profile live",
);
$deny(
    fn() => $identities->setSuperuser(
        $rebound["id"],
        $rebound["version"],
        true,
        false,
    ),
    "technical administration requires explicit confirmation",
);
$identities->setSuperuser($rebound["id"], $rebound["version"], true, true);
$technical = $current($rebound["id"]);
$check(
    $memberBridge->access()->require("accounts", "manage")["id"] ===
        $technical["native_id"],
    "explicit technical permission takes effect through original account",
);
$check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM admission_board WHERE club_id=? AND actor_id=?",
        ["identity-test", $actor],
    )->fetchColumn() === 0,
    "technical permission creates no personal board vote",
);
$identities->setSuperuser($technical["id"], $technical["version"], false, true);
$regular = $current($technical["id"]);
$deny(
    fn() => $memberBridge->access()->require("accounts", "manage"),
    "technical revocation acts immediately on existing session",
);
$identities->assign(
    $regular["id"],
    $regular["version"],
    $regular["role_version"],
    $replacement,
    "",
    $replacement,
    false,
    false,
);
$blocked = $current($regular["id"]);
$deny(
    fn() => $auth->resolve($memberLogin["session_token"]),
    "native identity suspension revokes existing original sessions",
);
$deny(
    fn() => $auth->passwordLogin(
        $target["username"],
        "Member identity password 2026!",
        "blocked-identity",
    ),
    "suspended original account cannot authenticate",
);
$identities->assign(
    $blocked["id"],
    $blocked["version"],
    $blocked["role_version"],
    $replacement,
    "",
    $replacement,
    true,
    false,
);
$check(
    $auth->passwordLogin(
        $target["username"],
        "Member identity password 2026!",
        "reenabled-identity",
    )["status"] === "authenticated",
    "explicit re-enable preserves original credentials",
);
$pending = $person(
    "Pending Original Person",
    "pending-identity@example.invalid",
    false,
);
$pendingRow = $current($pending["account_id"]);
$newContact = $registry->saveContact(
    null,
    0,
    "person",
    "Pending Corrected Person",
    "pending-identity@example.invalid",
    "Testadresse",
);
$registry->addMembership($newContact, "", "aktiv", "2020-01-01", false);
$identities->assign(
    $pendingRow["id"],
    $pendingRow["version"],
    0,
    $pending["contact"],
    "",
    $newContact,
    true,
    true,
);
$prepared = $accounts->prepare("identity-test", $newContact);
$check(
    $prepared["setup_token"] === $pending["setup_token"],
    "pending profile correction reseals and retains exact original invitation",
);
$auth->acceptInvitation(
    $prepared["setup_token"],
    "Pending corrected password 2026!",
);
$pendingLogin = $auth->passwordLogin(
    $prepared["username"],
    "Pending corrected password 2026!",
    "pending-corrected",
);
$pendingBridge = ManagerBridge::fromNativeSession(
    $db,
    $auth,
    $pendingLogin["session_token"],
);
$pendingBridge->access();
$check(
    (new Registry($db, $pendingBridge->managerActor()))->contact($newContact)[
        "id"
    ] === $newContact,
    "corrected pending invitation opens exact original replacement profile",
);
$history = Db::run(
    $db,
    "SELECT before_json,after_json FROM native_identity_changes WHERE account_id=? ORDER BY changed_at,id",
    [$target["account_id"]],
)->fetchAll();
$check(
    count($history) === 6 &&
        str_contains(json_encode($history), $target["contact"]) &&
        str_contains(json_encode($history), $replacement),
    "all explicit identity operations retain before/after original profile history",
);
$check(
    !str_contains(json_encode($history), $hash) &&
        !str_contains(json_encode($history), "password_hash"),
    "identity history contains no password hashes or authentication secrets",
);
$panel = \Kulturhus\Native\Web\IdentityPanel::render(
    $db,
    $access,
    $auth,
    $box,
    str_repeat("a", 64),
);
$check(
    str_contains($panel, "Corrected Original Profile") &&
        str_contains($panel, $target["username"]) &&
        !str_contains($panel, "ProcessWire"),
    "native account panel shows actual personal names and own identifiers",
);
$check(
    !str_contains($panel, $pending["setup_token"]) &&
        !str_contains($panel, $hash),
    "native identity panel never exposes protected credentials",
);
$check(
    (int) $db
        ->query("SELECT COUNT(*) FROM processwire_identities")
        ->fetchColumn() === 0,
    "all account assignment and technical actions operate without ProcessWire bindings",
);
echo "$checks native identity checks passed; original MySQL actors, real accounts/sessions, personal profile correction and live rights.\n";
