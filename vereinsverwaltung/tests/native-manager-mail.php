<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/autoload.php";

use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge, Schema};
use Kulturhus\Native\Delivery\MailAccount;
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{
    ContactArchive,
    Database,
    InvoiceMailbox,
    MailChannelSettings,
};
use Kulturhus\Verein\Infrastructure\Runtime\ServerKey;

$db = TestSupport::db();
Database::migrate($db);
Schema::install($db);
\Kulturhus\Native\Cms\Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks++;
};
$deny = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (DomainException) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
};
Db::run(
    $db,
    "INSERT INTO clubs VALUES('mail-a','Mailtest'),('mail-b','Anderer Verein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('mail-admin','mail-a','members',1),('other-admin','mail-b','members',1)",
);
$club = ManagerBridge::clubKey("mail-a");
$box = new SecretBox(random_bytes(32));
$key = random_bytes(32);
$account = new MailAccount($db, $box, static fn() => $key);
Db::run($db, "INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)", [
    "old-admin",
    $club,
    "Frühere Administration",
]);
Db::run(
    $db,
    "INSERT INTO native_grants VALUES('old-admin','website','manage')",
);
$smtp = [
    "host" => "smtp.example.invalid",
    "port" => 587,
    "username" => "newsletter@example.invalid",
    "password" => "Synthetic-original-secret",
    "from" => "newsletter@example.invalid",
    "reply_to" => "reply@example.invalid",
];
$account->save(new Access($db, "old-admin"), $smtp, 0);
$access = (new ManagerBridge($db, "mail-admin"))->access();
$other = (new ManagerBridge($db, "other-admin"))->access();
$check(
    $account->status($access)["shared"] &&
        $account->status($access)["legacy_version"] === 1,
    "existing native access is visible as a transfer candidate",
);
$deny(
    fn() => $account->save($access, $smtp, 1),
    "integrated manager cannot create duplicate SMTP store",
);
$deny(
    fn() => $account->configuration($club),
    "native worker never silently bypasses the chosen common route",
);
$deny(
    fn() => $account->migrate($access, 7, 1),
    "stale common version prevents transfer",
);
$check(
    $account->status($access)["legacy_version"] === 1,
    "failed transfer retains original ciphertext",
);
$db->beginTransaction();
$account->migrate($access, 0, 1);
$db->rollBack();
$check(
    $account->status($access)["legacy_version"] === 1 &&
        $account->status($access)["version"] === 0,
    "outer rollback restores both credential stores",
);
$account->migrate($access, 0, 1);
$sharedConfiguration=$account->configuration($club);
$sharedState=(new MailChannelSettings($db,"mail-admin",$key))->state();
$sharedName=$sharedState['data']['channels'][$sharedState['data']['routes']['newsletter']]['name'];
$check(
    array_intersect_key($sharedConfiguration,$smtp) === $smtp &&
        count($sharedConfiguration)===count($smtp)+1 && $sharedConfiguration['name']===$sharedName,
    "actual old credentials preserved in selected encrypted common channel with its current displayed sender name",
);
$check(
    $account->status($access)["configured"] &&
        $account->status($access)["legacy_version"] === 0,
    "successful transfer leaves one common credential authority",
);
$deny(
    fn() => $account->migrate($access, 1, 1),
    "repeat transfer cannot create duplicate access",
);
$channels = new MailChannelSettings($db, "mail-admin", $key);
$state = $channels->state();
$stored = Db::run($db, "SELECT * FROM mail_channel_settings WHERE club_id=?", [
    "mail-a",
])->fetch();
$check(
    !str_contains(json_encode($stored), "Synthetic-original-secret") &&
        !str_contains(json_encode($state), "Synthetic-original-secret"),
    "database metadata and public state contain no plaintext password",
);
$state["data"]["routes"]["newsletter"] = "board";
$channels->save($state["data"], 1);
$deny(
    fn() => $account->configuration($club),
    "changing purpose route is effective without stale native fallback",
);
$state = $channels->state();
$state["data"]["channels"]["board"] = array_replace(
    $state["data"]["channels"]["board"],
    [
        "override" => true,
        "host" => "board.example.invalid",
        "user" => "board@example.invalid",
        "from" => "board@example.invalid",
        "password" => "Synthetic-board-secret",
    ],
);
$channels->save($state["data"], 2);
$check(
    $account->configuration($club)["password"] === "Synthetic-board-secret",
    "worker resolves current explicitly selected SMTP channel",
);
$oldMailRow=Db::run($db,'SELECT * FROM mail_channel_settings WHERE club_id=?',['mail-a'])->fetch();
$oldMailConfig=json_decode($oldMailRow['config'],true,32,JSON_THROW_ON_ERROR);unset($oldMailConfig['routes']['tickets']);
Db::run($db,'UPDATE mail_channel_settings SET config=? WHERE club_id=?',[json_encode($oldMailConfig,JSON_THROW_ON_ERROR),'mail-a']);
$historicalMailRow=Db::run($db,'SELECT * FROM mail_channel_settings WHERE club_id=?',['mail-a'])->fetch();
$check($account->configuration($club,'tickets')['password']==='Synthetic-board-secret','new ticket purpose resolves existing board credentials when historical routing has no ticket entry');
$check(Db::run($db,'SELECT * FROM mail_channel_settings WHERE club_id=?',['mail-a'])->fetch()===$historicalMailRow,'reading new purpose preserves full original saved routing and encrypted keys');
$invoice = new InvoiceMailbox($db, "mail-admin", $key);
$invoice->save(
    array_replace(InvoiceMailbox::defaults(), [
        "email" => "invoices@example.invalid",
        "smtp_enabled" => true,
        "smtp_host" => "invoices.example.invalid",
        "smtp_user" => "invoices@example.invalid",
        "smtp_password" => "Synthetic-invoice-secret",
    ]),
    0,
);
$state = $channels->state();
$state["data"]["routes"]["newsletter"] = "invoices";
$channels->save($state["data"], 3);
$check(
    $account->configuration($club)["password"] === "Synthetic-invoice-secret",
    "native route reuses existing invoice SMTP encryption without changing IMAP state",
);
$deny(
    fn() => (new MailAccount(
        $db,
        $box,
        static fn() => random_bytes(32),
    ))->configuration($club),
    "wrong master key does not fall back or send",
);
$deny(
    fn() => $account->configuration($other->club()),
    "another club never inherits SMTP credentials",
);
$deny(
    fn() => $account->send($club, ["to" => "nobody@example.invalid"]),
    "development send guard stops before transport configuration",
);
Db::run(
    $db,
    "INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'mail',1,?,?)",
    [$club, $box->seal("mail:" . $club, $smtp), $access->user()["id"]],
);
$state = $channels->state();
$state["data"]["routes"]["newsletter"] = "newsletter";
$channels->save($state["data"], 4);
$deny(
    fn() => $account->migrate($access, 5, 1),
    "transfer refuses to overwrite already configured common channel",
);
$check(
    $account->status($access)["legacy_version"] === 1 &&
        array_intersect_key($account->configuration($club),$smtp) === $smtp,
    "conflict retains both earlier access and current common settings for review",
);
$check(
    !str_contains(
        json_encode($db->query("SELECT * FROM audit_events")->fetchAll()),
        "Synthetic-original-secret",
    ),
    "audit remains free of credential values",
);
$freshDirectory = sys_get_temp_dir().'/kh-mail-fresh-'.bin2hex(random_bytes(12));
try {
    $freshKey=ServerKey::syntheticArchive($freshDirectory);
    $keyPath=$freshDirectory.'/contact-archive.key';
    $bytes=file_get_contents($keyPath);
    $check(strlen($freshKey)===32 && is_dir($freshDirectory) && (fileperms($freshDirectory)&0077)===0 &&
        (fileperms($keyPath)&0077)===0, 'fresh checkout creates private synthetic archive directory and key');
    $check(ServerKey::syntheticArchive($freshDirectory)===$freshKey && file_get_contents($keyPath)===$bytes,
        'synthetic restart preserves historical key bytes and derivation');
    file_put_contents($keyPath,'incomplete');
    $deny(fn()=>ServerKey::syntheticArchive($freshDirectory),'damaged existing synthetic key is never replaced');
} finally { if(is_file($freshDirectory.'/contact-archive.key'))unlink($freshDirectory.'/contact-archive.key'); if(is_dir($freshDirectory))rmdir($freshDirectory); }
$uiKey = tempnam(sys_get_temp_dir(), "kh-mail-ui-");
chmod($uiKey, 0600);
file_put_contents($uiKey, random_bytes(32));
$oldUiKey = getenv("KH_NATIVE_PRIVATE_KEY_FILE");
putenv("KH_NATIVE_PRIVATE_KEY_FILE=" . $uiKey);
try {
    $html = (new \Kulturhus\Native\Web\Settings\Workspace(
        $db,
        $access,
        "/unused",
        "synthetic-csrf",
    ))->manager();
} finally {
    unlink($uiKey);
    putenv(
        $oldUiKey === false
            ? "KH_NATIVE_PRIVATE_KEY_FILE"
            : "KH_NATIVE_PRIVATE_KEY_FILE=" . $oldUiKey,
    );
}
$check(
    str_contains($html, "section=mail#mail-postfaecher") &&
        str_contains($html, "section=mail#rechnungsfach") &&
        !str_contains($html, 'name="host"'),
    "integrated UI exposes one mail settings route and preserved IMAP editor",
);
Db::run($db, "UPDATE actors SET is_superuser=0 WHERE id='mail-admin'");
$deny(
    fn() => $account->migrate($access, 5, 1),
    "live authority revocation prevents transfer",
);
$deny(
    fn() => $account->status($access),
    "revoked administrator cannot inspect settings",
);

$path = tempnam(sys_get_temp_dir(), "kh-server-key-");
chmod($path, 0600);
file_put_contents($path, random_bytes(32));
$prior = [];
foreach (
    ["APP_SECRET", "VEREIN_MODE", "VEREIN_AUTH", "KH_NATIVE_PRIVATE_KEY_FILE"]
    as $name
) {
    $prior[$name] = getenv($name);
}
try {
    putenv("APP_SECRET=synthetic-existing-master-key-at-least-32");
    putenv("VEREIN_MODE=production");
    putenv("VEREIN_AUTH=native");
    putenv("KH_NATIVE_PRIVATE_KEY_FILE=" . $path);
    $check(
        ContactArchive::serverKey() ===
            hash("sha256", "synthetic-existing-master-key-at-least-32", true),
        "existing APP_SECRET retains historical encryption key",
    );
    putenv("APP_SECRET");
    $check(
        ContactArchive::serverKey() === ServerKey::nativeFile($path),
        "native manager and worker derive identical persistent server key without ProcessWire",
    );
    chmod($path, 0644);
    clearstatcache(true, $path);
    $deny(
        fn() => ServerKey::nativeFile($path),
        "unprotected key file rejected",
    );
} finally {
    unlink($path);
    foreach ($prior as $name => $value) {
        putenv($value === false ? $name : $name . "=" . $value);
    }
}
echo json_encode(
    [
        "suite" => "native-manager-mail",
        "passed" => $checks,
        "live_transport" => false,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;
