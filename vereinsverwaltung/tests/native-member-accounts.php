<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/src/bootstrap.php";
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\{
    MemberAccounts,
    MemberInvitationAccess,
    ManagerBridge,
    Schema,
};
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database, Registry, ManualMemberAccounts};

$db = TestSupport::db();
Database::migrate($db);
Schema::install($db);
\Kulturhus\Native\Auth\Schema::install($db);
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
Db::run(
    $db,
    "INSERT INTO clubs VALUES('member-test','Prüfverein'),('other-club','Anderer Verein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('member-admin','member-test','members',1),('other-admin','other-club','members',1)",
);
$access = (new ManagerBridge($db, "member-admin"))->access();
$key = random_bytes(32);
$auth = new Service($db, $key);
$box = new SecretBox(random_bytes(32));
$accounts = new MemberAccounts($db, $auth, $box);
$registry = new Registry($db, "member-admin");
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
$originalAdmin = $auth->createInvitation(
    $access,
    $access->user()["id"],
    "admin@example.invalid",
);
$auth->acceptInvitation(
    $originalAdmin["token"],
    "Original admin password 2026!",
);
$oldHash = Db::run(
    $db,
    "SELECT password_hash FROM native_auth_accounts WHERE id=?",
    [$originalAdmin["account"]["id"]],
)->fetchColumn();
$db->exec(
    "ALTER TABLE native_auth_accounts DROP INDEX login_name, DROP COLUMN login_name, DROP INDEX email, ADD UNIQUE KEY email(email)",
);
\Kulturhus\Native\Auth\Schema::install($db);
$check(
    Db::run($db, "SELECT password_hash FROM native_auth_accounts WHERE id=?", [
        $originalAdmin["account"]["id"],
    ])->fetchColumn() === $oldHash &&
        $auth->passwordLogin(
            "admin@example.invalid",
            "Original admin password 2026!",
            "original-admin",
        )["status"] === "authenticated",
    "explicit old schema upgrade preserves original ID and password",
);
$person = static function (
    string $name,
    string $email,
    bool $mail = false,
) use ($registry): string {
    $id = $registry->saveContact(null, 0, "person", $name, $email, "Prüfweg 1");
    $registry->addMembership($id, "", "aktiv", "2026-01-01", $mail);
    return $id;
};
$first = $person("Alex Müller", "family@example.invalid", false);
$registry->assignFamily($first, "", "new", "Familie Müller");
$family = $registry->family($first);
$prepared = $accounts->prepare("member-test", $first);
$check(
    is_string($prepared["account_id"]) &&
        strlen($prepared["account_id"]) === 32,
    "native account has its own immutable ID",
);
$check(
    str_starts_with($prepared["username"], "alex.mueller-"),
    "readable personal username",
);
$check(
    (int) $db
        ->query("SELECT COUNT(*) FROM processwire_identities")
        ->fetchColumn() === 0,
    "provisioning does not create ProcessWire bindings",
);
$row = Db::run($db, "SELECT * FROM native_auth_accounts WHERE id=?", [
    $prepared["account_id"],
])->fetch();
$check(
    $row["password_hash"] === null && !$row["active"] && !$row["verified"],
    "no initial password is stored or activated",
);
$original = Db::run(
    $db,
    "SELECT * FROM native_member_onboarding WHERE club_id=? AND contact_id=?",
    ["member-test", $first],
)->fetch();
$check(
    !str_contains(json_encode($original), $prepared["setup_token"]),
    "setup credential is encrypted at rest",
);
$again = $accounts->prepare("member-test", $first);
$check(
    $again === $prepared &&
        Db::run(
            $db,
            "SELECT invite_cipher FROM native_member_onboarding WHERE account_id=?",
            [$prepared["account_id"]],
        )->fetchColumn() === $original["invite_cipher"],
    "retry retains exact account, invite and encrypted original",
);
$auth->acceptInvitation(
    $prepared["setup_token"],
    "First personal password 2026!",
);
$login = $auth->passwordLogin(
    $prepared["username"],
    "First personal password 2026!",
    "member-first",
);
$bridge = ManagerBridge::fromNativeSession($db, $auth, $login["session_token"]);
$memberActor = $bridge->managerActor();
$bridge->access();
$check(
    \Kulturhus\Verein\Permissions::actor($db, $memberActor)["role"] ===
        "member",
    "personal member role has no inherited administration",
);
$deny(
    fn() => $bridge->access()->require("website", "edit"),
    "member cannot edit website",
);
$deny(
    fn() => $bridge->access()->require("accounts", "manage"),
    "member cannot administer accounts",
);
$check(
    (new Registry($db, $memberActor))->contact($first)["id"] === $first,
    "native session uses original member profile",
);
$relative = $registry->saveContact(
    null,
    0,
    "person",
    "Robin Müller",
    "family@example.invalid",
    "Prüfweg 1",
);
$registry->addMembership($relative, "", "aktiv", "2026-01-01", false, [
    "expected" => "",
    "target" => $family["id"],
]);
$second = $accounts->prepare("member-test", $relative);
$check(
    $second["account_id"] !== $prepared["account_id"] &&
        $second["username"] !== $prepared["username"],
    "family members retain independent account IDs and usernames",
);
$auth->acceptInvitation(
    $second["setup_token"],
    "Second personal password 2026!",
);
$secondLogin = $auth->passwordLogin(
    $second["username"],
    "Second personal password 2026!",
    "member-second",
);
$secondBridge = ManagerBridge::fromNativeSession(
    $db,
    $auth,
    $secondLogin["session_token"],
);
$secondBridge->access();
$check(
    (new Registry($db, $secondBridge->managerActor()))->contact($relative)[
        "id"
    ] === $relative,
    "second family session retains second original profile",
);
$deny(
    fn() => $auth->passwordLogin(
        "family@example.invalid",
        "First personal password 2026!",
        "shared-email",
    ),
    "shared address never chooses the first family account",
);
$check(
    $auth->requestReset("family@example.invalid", "shared-reset") === null,
    "shared address never chooses a reset target",
);
$reset = $auth->requestReset($second["username"], "personal-reset");
$check(
    $reset["account_id"] === $second["account_id"],
    "username reset belongs to exactly one original account",
);
$auth->resetPassword($reset["token"], "Second changed password 2026!");
$check(
    $auth->passwordLogin(
        $prepared["username"],
        "First personal password 2026!",
        "first-unchanged",
    )["status"] === "authenticated",
    "relative reset leaves first password unchanged",
);
$deny(
    fn() => $auth->resolve($secondLogin["session_token"]),
    "reset revokes only target original sessions",
);
$check(
    $auth->resolve($login["session_token"])["id"] === $prepared["account_id"],
    "other family session remains valid",
);
$third = $person("Unrelated Person", "family@example.invalid");
$deny(
    fn() => $accounts->prepare("member-test", $third),
    "unrelated person cannot adopt shared family email",
);
$check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM actors WHERE club_id=? AND contact_id=?",
        ["member-test", $third],
    )->fetchColumn() === 0,
    "collision rolls back person and account projection",
);
$deny(
    fn() => $auth->createInvitation(
        new MemberInvitationAccess($db, "member-test", $first),
        $secondLogin["account"]["actor_id"],
        "other@example.invalid",
        86400,
        "wrong.person",
    ),
    "constrained invitation authority cannot target a different member",
);
$notify = $person("Notify Person", "notify@example.invalid", true);
$notified = $accounts->prepare("member-test", $notify);
$messages = [];
$result = $accounts->run(
    "member-test",
    "https://manager.example.invalid",
    static function (array $message) use (&$messages): void {
        $messages[] = $message;
    },
);
$check(
    $result["sent"] === 1 && count($messages) === 1,
    "existing original mail request is dispatched once through injected transport",
);
$check(
    str_contains($messages[0]["text"], $notified["username"]) &&
        str_contains($messages[0]["text"], "/invite?token=") &&
        !str_contains($messages[0]["text"], "Startpasswort"),
    "invitation sends personal name and one-time setup, no password",
);
$check(
    Db::run(
        $db,
        "SELECT invite_cipher FROM native_member_onboarding WHERE account_id=?",
        [$notified["account_id"]],
    )->fetchColumn() === null,
    "confirmed delivery deletes protected setup credential",
);
$accounts->run(
    "member-test",
    "https://manager.example.invalid",
    static function (array $message) use (&$messages): void {
        $messages[] = $message;
    },
);
$check(
    count($messages) === 1,
    "repeated worker never resends confirmed delivery",
);
Db::run(
    $db,
    "UPDATE native_member_onboarding SET expires_at=? WHERE account_id=?",
    [time() - 1, $notified["account_id"]],
);
$deny(
    fn() => $accounts->prepare("member-test", $notify),
    "expired delivered invitation is never silently replaced",
);
$unknown = $person("Uncertain Person", "uncertain@example.invalid", true);
$accounts->prepare("member-test", $unknown);
$accounts->run(
    "member-test",
    "https://manager.example.invalid",
    static function (): void {
        throw new RuntimeException("Synthetic uncertain transport");
    },
);
$check(
    ManualMemberAccounts::status($db, "member-admin", $unknown)[
        "mail_status"
    ] === "uncertain",
    "uncertain send remains explicit",
);
ManualMemberAccounts::request($db, "member-admin", $unknown, true);
$before = count($messages);
$accounts->run(
    "member-test",
    "https://manager.example.invalid",
    static function (array $message) use (&$messages): void {
        $messages[] = $message;
    },
);
$check(
    count($messages) === $before,
    "re-request does not replay uncertain send",
);
$left = $person("Former Person", "former@example.invalid");
$membership = $registry->memberships($left)[0];
$registry->endMembership($membership["id"], 1, "2026-09-01");
$deny(
    fn() => $accounts->prepare("member-test", $left),
    "ended membership does not receive an account",
);
$blocked = $person("Blocked Person", "blocked@example.invalid");
$blockedAccount = $accounts->prepare("member-test", $blocked);
Db::run(
    $db,
    "UPDATE native_users SET active=0 WHERE id=(SELECT actor_id FROM native_auth_accounts WHERE id=?)",
    [$blockedAccount["account_id"]],
);
$deny(
    fn() => $auth->acceptInvitation(
        $blockedAccount["setup_token"],
        "Blocked test password 2026!",
    ),
    "disabled invitation cannot activate an account",
);
$deny(
    fn() => $accounts->prepare("member-test", $blocked),
    "disabled account is never automatically reactivated",
);
$check(
    (int) Db::run(
        $db,
        'SELECT COUNT(*) FROM native_grants g JOIN native_auth_accounts a ON a.actor_id=g.actor_id WHERE a.id IN (?,?) AND g.module IN (\'website\',\'accounts\')',
        [$prepared["account_id"], $second["account_id"]],
    )->fetchColumn() === 0,
    "family creation grants neither website nor account authority",
);
$mime = \Kulturhus\Native\Delivery\MailMessage::compose(
    ["from" => "board@example.invalid", "reply_to" => "board@example.invalid"],
    [
        "to" => "member@example.invalid",
        "subject" => "Original PDF",
        "text" => "Separate contribution invoice",
        "html" => "<p>Invoice</p>",
        "attachments" => [
            [
                "name" => "invoice.pdf",
                "mime" => "application/pdf",
                "bytes" => "%PDF-original\x00bytes",
            ],
        ],
    ],
);
$check(
    count($mime->getAttachments()) === 1 &&
        $mime->getAttachments()[0]->getBody() === "%PDF-original\x00bytes",
    "native MIME preserves exact original attachment bytes",
);
$deny(
    fn() => \Kulturhus\Native\Delivery\MailMessage::compose(
        [
            "from" => "board@example.invalid",
            "reply_to" => "board@example.invalid",
        ],
        [
            "to" => "member@example.invalid",
            "subject" => "Bad attachment",
            "text" => "x",
            "html" => "x",
            "attachments" => [
                [
                    "name" => "../private.key",
                    "mime" => "application/pdf",
                    "bytes" => "x",
                ],
            ],
        ],
    ),
    "mail attachment cannot reference a private path",
);

// Real admission service: own board authority, original contribution and archived PDF.
use Kulturhus\Verein\{
    AdmissionVotes,
    MembershipApplications,
    Settings,
    SettingsSchema,
    Finance,
};
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('native-board','member-test','member',0)",
);
(new AdmissionVotes($db, "member-admin"))->grant(
    "native-board",
    0,
    "Schriftführer",
    true,
    true,
);
$settings = new Settings($db, "member-admin");
$clubData = SettingsSchema::defaults("club");
$clubData = array_replace($clubData, [
    "name" => "Prüfverein",
    "street" => "Testweg 1",
    "postcode" => "00000",
    "city" => "Testort",
]);
$settings->save("club", $clubData, 0);
$bank = SettingsSchema::defaults("bank");
$bank = array_replace($bank, [
    "account_id" => (new Finance($db, "member-admin"))->createAccount(
        "Synthetic contribution bank",
        "offline",
        "0",
        "2026-01-01",
    ),
    "holder" => "Prüfverein",
    "iban" => "DE89370400440532013000",
    "bic" => "COBADEFFXXX",
]);
$settings->save("bank", $bank, 0);
$applications = new MembershipApplications($db, "member-test", "native-board");
$config = $applications->settings()["config"];
$config["enabled"] = true;
(new MembershipApplications($db, "member-test", "member-admin"))->saveSettings(
    $config,
    0,
);
$answers = [
    "first_name" => "Admission",
    "last_name" => "Native",
    "birthday" => "1990-01-02",
    "email" => "admission@example.invalid",
    "address" => "Testweg 2\n00000 Testort",
    "membership_type" => "single",
];
$application = $applications->submit(
    $answers,
    bin2hex(random_bytes(16)),
    1,
    true,
);
$admitted = $applications->review(
    $application,
    1,
    "accepted",
    "Synthetic board decision",
    start: date("Y-m-d"),
    externalDecision: true,
    decisionDate: date("Y-m-d"),
    familyTarget: "single",
);
$check(
    Db::run(
        $db,
        "SELECT application_id FROM manual_member_accounts WHERE club_id=? AND contact_id=?",
        ["member-test", $admitted],
    )->fetchColumn() === $application,
    "automatic admission queues original native account with board scope",
);
$welcomeRow = Db::run(
    $db,
    "SELECT * FROM membership_welcome WHERE application_id=?",
    [$application],
)->fetch();
$invoiceBytes = Db::run(
    $db,
    "SELECT f.bytes FROM contribution_invoices i JOIN document_files f ON f.club_id=i.club_id AND f.document_id=i.document_id WHERE i.entry_id=? ORDER BY f.revision DESC LIMIT 1",
    [$welcomeRow["entry_id"]],
)->fetchColumn();
$welcomeMails = [];
$invoiceMails = [];
$welcomeSend = static function (array $mail) use (&$welcomeMails): void {
    $welcomeMails[] = $mail;
};
$invoiceSend = static function (array $mail) use (&$invoiceMails): void {
    $invoiceMails[] = $mail;
};
$disabled = \Kulturhus\Native\Integration\MemberDelivery::run(
    $db,
    $auth,
    $box,
    "member-test",
    "https://manager.example.invalid",
    $welcomeSend,
    $invoiceSend,
);
$check(
    $disabled["transport"] === "disabled" && !$welcomeMails && !$invoiceMails,
    "native admission respects synthetic no-mail policy with injected transport",
);
foreach (
    ["VEREIN_MODE", "KULTURHUS_LOCAL_DEMO", "KULTURHUS_DISABLE_MAIL", "APP_ENV"]
    as $env
) {
    putenv($env);
}
putenv("APP_URL=https://manager.example.invalid");
$delivered = \Kulturhus\Native\Integration\MemberDelivery::run(
    $db,
    $auth,
    $box,
    "member-test",
    "https://manager.example.invalid",
    $welcomeSend,
    $invoiceSend,
);
$check(
    $delivered["welcome"] === 2 &&
        count($welcomeMails) === 1 &&
        count($invoiceMails) === 1,
    "native admission sends welcome and separate original invoice through distinct routes",
);
$check(
    str_contains($welcomeMails[0]["text"], "/invite?token=") &&
        str_contains($welcomeMails[0]["text"], "Prüfverein") &&
        !str_contains($welcomeMails[0]["text"], "Startpasswort"),
    "welcome carries own setup link and configured club identity",
);
$check(
    $invoiceMails[0]["attachments"][0]["bytes"] === $invoiceBytes &&
        str_starts_with($invoiceBytes, "%PDF-"),
    "separate native contribution mail uses exact issued PDF revision",
);
\Kulturhus\Native\Integration\MemberDelivery::run(
    $db,
    $auth,
    $box,
    "member-test",
    "https://manager.example.invalid",
    $welcomeSend,
    $invoiceSend,
);
$check(
    count($welcomeMails) === 1 && count($invoiceMails) === 1,
    "repeated native admission worker never duplicates welcome or invoice",
);
$check(
    \Kulturhus\Verein\Permissions::actor($db, "native-board")[
        "finance_access"
    ] === "none",
    "native welcome provisioning grants board reviewer no general finance rights",
);
$pendingContact = $person(
    "Suspended Pending Access",
    "suspended-pending@example.invalid",
);
$pendingAccount = $accounts->prepare("member-test", $pendingContact);
$pendingVersion = (int) Db::run(
    $db,
    "SELECT version FROM native_auth_accounts WHERE id=?",
    [$pendingAccount["account_id"]],
)->fetchColumn();
$auth->setActive(
    $access,
    $pendingAccount["account_id"],
    false,
    $pendingVersion,
);
$deny(
    fn() => $auth->acceptInvitation(
        $pendingAccount["setup_token"],
        "Suspended pending password 2026!",
    ),
    "suspended pending invitation cannot be accepted",
);
$deny(
    fn() => $accounts->prepare("member-test", $pendingContact),
    "member access preparation cannot renew suspended pending account",
);
$nativePending = Db::run(
    $db,
    "SELECT actor_id FROM native_auth_accounts WHERE id=?",
    [$pendingAccount["account_id"]],
)->fetchColumn();
$deny(
    fn() => $auth->createInvitation(
        $access,
        $nativePending,
        "suspended-pending@example.invalid",
        86400,
        $pendingAccount["username"],
    ),
    "generic invitation action cannot bypass pending suspension",
);
$pendingState = Db::run(
    $db,
    "SELECT suspended,verified,password_hash FROM native_auth_accounts WHERE id=?",
    [$pendingAccount["account_id"]],
)->fetch();
$check(
    $pendingState["suspended"] &&
        !$pendingState["verified"] &&
        $pendingState["password_hash"] === null,
    "pending suspension preserves original invitation account without inventing credentials",
);
echo "$checks native member account checks passed; actual MySQL, original members, real native passwords/sessions, synthetic transport.\n";
