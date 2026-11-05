<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/src/bootstrap.php";
use Kulturhus\Native\Shared\{Db, Identity, SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Integration\Jobs\{OriginalJobs, ApplicationNewsletter};
use Kulturhus\Native\Communication\NewsletterGateway;
use Kulturhus\Native\Delivery\Outbox;
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{
    Settings,
    SettingsSchema,
    Finance,
    Documents,
    ClubMailings,
    MembershipApplications,
    ApplicationConfirmation,
    InvoiceMailbox,
    ClubChat,
    PushNotifications,
};

$db = TestSupport::db();
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
$key = random_bytes(32);
putenv("APP_SECRET=" . bin2hex($key));
$box = new SecretBox(random_bytes(32));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL " . $label);
    }
    ++$checks;
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
$requestKey = static fn() => bin2hex(random_bytes(16));
Db::run(
    $db,
    "INSERT INTO clubs VALUES('jobs-a','Originalverein'),('jobs-b','Fremdverein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('jobs-admin','jobs-a','members',1),('foreign-admin','jobs-b','members',1),('jobs-reader','jobs-a','member',0)",
);
$own = (new ManagerBridge(
    $db,
    "jobs-admin",
    "native",
    static fn() => null,
))->access();
$foreign = (new ManagerBridge(
    $db,
    "foreign-admin",
    "native",
    static fn() => null,
))->access();
$origin = "https://jobs.example.invalid";
(new Settings($db, "jobs-admin"))->save(
    "club",
    array_replace(SettingsSchema::defaults("club"), [
        "name" => "Originalverein",
        "street" => "Testweg 1",
        "postcode" => "00000",
        "city" => "Teststadt",
    ]),
    0,
);
(new Settings($db, "foreign-admin"))->save(
    "club",
    array_replace(SettingsSchema::defaults("club"), [
        "name" => "Fremdverein",
        "street" => "Testweg 2",
        "postcode" => "00000",
        "city" => "Teststadt",
    ]),
    0,
);
(new Identity($db, $own))->save(["club_version" => 1, "origin" => $origin], 0);
(new Identity($db, $foreign))->save(
    [
        "name" => "Fremdverein",
        "address" => "Testweg 2",
        "origin" => "https://foreign.example.invalid",
        "club_version" => 1,
    ],
    0,
);
$run = static fn(
    ?callable $send = null,
    array $extra = [],
) => OriginalJobs::run($db, "jobs-a", $origin, $key, $box, $send, ...$extra);
$calls = [];
$fake = static function (string $purpose, array $message) use (&$calls): void {
    $calls[] = [$purpose, $message];
};
$deny(
    fn() => OriginalJobs::run(
        $db,
        "jobs-a",
        "https://foreign.example.invalid",
        $key,
        $box,
        $fake,
    ),
    "fixed current club origin required before transport",
);
$deny(
    fn() => OriginalJobs::run($db, "missing", $origin, $key, $box, $fake),
    "unknown original club cannot dispatch",
);
$deny(
    fn() => $run($fake, [
        "workerActor" => "foreign-admin",
        "automationStart" => "2026-01-01",
    ]),
    "foreign financial automation rejected before any mail",
);
$deny(
    fn() => $run($fake, [
        "workerActor" => "jobs-reader",
        "automationStart" => "2026-01-01",
    ]),
    "ordinary actor cannot activate contribution automation",
);
$deny(
    fn() => $run($fake, ["workerActor" => "jobs-admin"]),
    "automation cannot silently choose its own start date",
);
$deny(
    fn() => $run($fake, [
        "workerActor" => "jobs-admin",
        "automationStart" => "2026-02-30",
    ]),
    "invalid automation start rejected",
);
$check($calls === [], "invalid configuration never entered any transport");
$intake = new MembershipApplications($db, "jobs-a", "jobs-admin");
$configuration = $intake->settings()["config"];
$configuration["enabled"] = true;
$intake->saveSettings($configuration, 0);
$answers = [
    "first_name" => "Test",
    "last_name" => "Originalantrag",
    "birthday" => "1990-01-02",
    "email" => "application@example.invalid",
    "address" => "Testweg 1",
];
$confirmation = ApplicationConfirmation::enqueue(
    $db,
    "jobs-a",
    $answers,
    $requestKey(),
    1,
    true,
    true,
);
Db::run(
    $db,
    "INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('mail-person','jobs-a','person','Originalempfänger','mailing@example.invalid','Testweg 1'),('foreign-person','jobs-b','person','Fremdempfänger','foreign@example.invalid','Testweg 2')",
);
$mailingService = new ClubMailings($db, "jobs-admin");
$mailing = $mailingService->save(
    "",
    "Originalbetreff",
    "Originalnachricht",
    ["mail-person"],
    $requestKey(),
);
foreach (
    ["APP_ENV", "KULTURHUS_LOCAL_DEMO", "KULTURHUS_DISABLE_MAIL"]
    as $name
) {
    putenv($name);
}
putenv("APP_URL=" . $origin);
putenv("VEREIN_PUBLIC_URL=" . $origin);
putenv("VEREIN_MODE=synthetic");
$check(
    $run($fake)["transport"] === "disabled" && $calls === [],
    "synthetic environment leaves callbacks untouched",
);
putenv("VEREIN_MODE=production");
putenv("APP_ENV=production");
$mailingService->queue(
    $mailing,
    $mailingService->get($mailing)["preview_hash"],
);
$foreignMail = new ClubMailings($db, "foreign-admin");
$foreignId = $foreignMail->save(
    "",
    "Fremdbetreff",
    "Fremdnachricht",
    ["foreign-person"],
    $requestKey(),
);
$foreignMail->queue($foreignId, $foreignMail->get($foreignId)["preview_hash"]);
Db::run(
    $db,
    "UPDATE club_outbox SET state='sending',updated_at=? WHERE mailing_id=?",
    [gmdate("c", time() - 1000), $foreignId],
);
$foreignBefore = $foreignMail->deliveries($foreignId);
$check(
    $run()["transport"] === "disabled" && $calls === [],
    "omitted send flag is transport-free",
);
$sent = $run($fake);
$check(
    $sent["application_confirmations"] === 1 && $sent["club_mailings"] === 1,
    "native adapters process original confirmation and queued correspondence",
);
$check(
    count($calls) === 2 &&
        array_column($calls, 0) === ["membership", "invitations"],
    "separated SMTP purposes preserved",
);
$check(
    str_contains(
        $calls[0][1]["text"],
        $origin . "/mitglied-werden/?confirm_application=",
    ) && str_contains($calls[0][1]["subject"], "Originalverein"),
    "original application route and configured club name preserved",
);
$check(
    $calls[1][1]["subject"] === "Originalbetreff" &&
        str_contains($calls[1][1]["text"], "Originalnachricht"),
    "original correspondence bytes delivered",
);
$check(
    $foreignBefore === $foreignMail->deliveries($foreignId),
    "own worker does not reclassify interrupted foreign correspondence",
);
$check(
    $run($fake)["club_mailings"] === 0 && count($calls) === 2,
    "confirmed original deliveries never replay",
);
$uncertain = $mailingService->save(
    "",
    "Unklar",
    "Unklare Originalnachricht",
    ["mail-person"],
    $requestKey(),
);
$mailingService->queue(
    $uncertain,
    $mailingService->get($uncertain)["preview_hash"],
);
$run(
    static fn() => throw new RuntimeException("synthetic ambiguous transport"),
);
$check(
    $mailingService->deliveries($uncertain)[0]["state"] === "uncertain",
    "ambiguous original mail is held",
);
$run($fake);
$check(count($calls) === 2, "held original mail is not automatically resent");

$answers["email"] = "doi-application@example.invalid";
$doiRequest = ApplicationConfirmation::enqueue(
    $db,
    "jobs-a",
    $answers,
    $requestKey(),
    1,
    true,
    true,
);
$privateToken = Db::run(
    $db,
    "SELECT mail_token FROM application_confirmations WHERE id=?",
    [$doiRequest],
)->fetchColumn();
$application = ApplicationConfirmation::confirm(
    $db,
    "jobs-a",
    $privateToken,
    $intake,
);
$gateway = new NewsletterGateway($db, $own->club(), $box);
$prepared = ApplicationNewsletter::run($db, "jobs-a", $gateway);
$check(
    $prepared["prepared"] === 1,
    "confirmed original opt-in prepares one encrypted native DOI",
);
$link = Db::run(
    $db,
    "SELECT * FROM native_application_newsletters WHERE club_id=? AND request_id=?",
    [$own->club(), $doiRequest],
)->fetch();
$subscriber = Db::run(
    $db,
    "SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND id=?",
    [$own->club(), $link["subscriber_id"]],
)->fetch();
$check(
    $subscriber["status"] === "pending" &&
        $subscriber["consent_evidence"] ===
            "membership-confirmed:" . $application,
    "application confirmation does not grant newsletter subscription",
);
$jobsBefore = (int) Db::run(
    $db,
    "SELECT COUNT(*) FROM native_delivery_jobs",
)->fetchColumn();
ApplicationNewsletter::run($db, "jobs-a", $gateway);
$check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM native_delivery_jobs",
    )->fetchColumn() === $jobsBefore,
    "restart preserves DOI token, subscriber and idempotent original link",
);
$payloads = [];
$outbox = new Outbox($db, $box);
$outbox->enqueue(
    $foreign->club(),
    "confirm",
    "foreign-job",
    ["to" => "foreign@example.invalid"],
    gmdate("Y-m-d H:i:s"),
);
$foreignJob = Db::run(
    $db,
    "SELECT * FROM native_delivery_jobs WHERE club_id=?",
    [$foreign->club()],
)->fetch();
$outbox->run(
    static function ($club, $message) use (&$payloads): string {
        $payloads[] = $message;
        return "private-synthetic-id";
    },
    50,
    ["confirm"],
    $own->club(),
);
$check(
    count($payloads) === 1 &&
        $foreignJob ===
            Db::run($db, "SELECT * FROM native_delivery_jobs WHERE id=?", [
                $foreignJob["id"],
            ])->fetch(),
    "native outbox never claims or changes foreign job",
);
ApplicationNewsletter::run($db, "jobs-a", $gateway);
$check(
    Db::run(
        $db,
        "SELECT newsletter_status FROM application_confirmations WHERE id=?",
        [$doiRequest],
    )->fetchColumn() === "done",
    "original queue completes after simulated DOI delivery",
);
preg_match("/token=([a-f0-9]{64})/", $payloads[0]["text"], $doiToken);
$check(
    isset($doiToken[1]) && $gateway->redeem($doiToken[1], "confirm"),
    "actual delivered one-time DOI confirms selected subscriber",
);
$check(!$gateway->redeem($doiToken[1], "confirm"), "DOI replay rejected");
$deny(
    fn() => (new NewsletterGateway(
        $db,
        $foreign->club(),
        $box,
    ))->applicationRequest($doiRequest),
    "foreign gateway cannot adopt own confirmed application",
);
$answers["email"] = "blocked-application@example.invalid";
$blockedRequest = ApplicationConfirmation::enqueue(
    $db,
    "jobs-a",
    $answers,
    $requestKey(),
    1,
    true,
    true,
);
$privateToken = Db::run(
    $db,
    "SELECT mail_token FROM application_confirmations WHERE id=?",
    [$blockedRequest],
)->fetchColumn();
ApplicationConfirmation::confirm($db, "jobs-a", $privateToken, $intake);
Db::run(
    $db,
    "INSERT INTO native_newsletter_blocks VALUES(?,?,UTC_TIMESTAMP())",
    [$own->club(), $box->fingerprint("email:" . $answers["email"])],
);
ApplicationNewsletter::run($db, "jobs-a", $gateway);
$check(
    Db::run(
        $db,
        "SELECT newsletter_status FROM application_confirmations WHERE id=?",
        [$blockedRequest],
    )->fetchColumn() === "skipped",
    "original pending request does not override newsletter block",
);

$mailbox = new InvoiceMailbox($db, "jobs-admin", $key);
$mailbox->save(
    array_replace(InvoiceMailbox::defaults(), [
        "email" => "invoices@example.invalid",
        "imap_host" => "imap.example.invalid",
        "imap_user" => "invoices@example.invalid",
        "imap_password" => "private-synthetic-password",
        "enabled" => true,
    ]),
    0,
);
$readCalls = 0;
$originalMail = "Subject: Original invoice\r\n\r\nOriginal RFC822 bytes\r\n";
$pdf = "%PDF-1.4 synthetic original receipt";
$read = static function () use (&$readCalls, $originalMail, $pdf): array {
    ++$readCalls;
    return [
        "uidvalidity" => 22,
        "messages" => [
            [
                "uid" => 10,
                "subject" => "Original Rechnung",
                "sender" => "supplier@example.invalid",
                "date" => "2026-01-01",
                "original" => $originalMail,
                "attachments" => [["name" => "original.pdf", "bytes" => $pdf]],
            ],
        ],
    ];
};
$run(null, ["readInbox" => $read]);
$check($readCalls === 0, "reader callback alone cannot enable invoice receive");
$received = $run(null, ["receive" => true, "readInbox" => $read]);
$check(
    $readCalls === 1 && $received["invoice_inbox"]["new"] === 1,
    "explicit native receive imports original invoice attachment",
);
$check(
    Db::run($db, "SELECT bytes FROM invoice_mail_originals WHERE club_id=?", [
        "jobs-a",
    ])->fetchColumn() === $originalMail,
    "private RFC822 original preserved byte for byte",
);
$document = Db::run(
    $db,
    "SELECT document_id FROM invoice_mail_imports WHERE club_id=?",
    ["jobs-a"],
)->fetchColumn();
$check(
    (new Documents($db, "jobs-admin"))->file($document)["bytes"] === $pdf,
    "actual original receipt PDF preserved",
);
$check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM finance_entries",
    )->fetchColumn() === 0,
    "invoice intake creates no financial posting",
);
$cursor = Db::run(
    $db,
    "SELECT last_uid FROM invoice_mailboxes WHERE club_id=?",
    ["jobs-a"],
)->fetchColumn();
$deny(
    fn() => $run(null, [
        "receive" => true,
        "readInbox" => static fn() => throw new RuntimeException(
            "private failure",
        ),
    ]),
    "failed receive gives a domain error",
);
$check(
    Db::run($db, "SELECT last_uid FROM invoice_mailboxes WHERE club_id=?", [
        "jobs-a",
    ])->fetchColumn() === $cursor,
    "failed receive retains original cursor",
);

$settings = new Settings($db, "jobs-admin");
$settings->save(
    "club",
    array_replace(SettingsSchema::defaults("club"), [
        "name" => "Originalverein",
        "street" => "Testweg 1",
        "postcode" => "00000",
        "city" => "Teststadt",
    ]),
    1,
);
$finance = new Finance($db, "jobs-admin");
$account = $finance->createAccount(
    "Originalbank",
    "offline",
    "0",
    "2026-01-01",
);
$settings->save(
    "bank",
    array_replace(SettingsSchema::defaults("bank"), [
        "account_id" => $account,
        "holder" => "Originalverein",
        "iban" => "DE89370400440532013000",
    ]),
    0,
);
Db::run(
    $db,
    "INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('payer','jobs-a','person','Originalzahler','payer@example.invalid','Testweg 1')",
);
Db::run(
    $db,
    "INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('payer-membership','jobs-a','payer','N1','aktiv','2025-01-01')",
);
$preview = $run(null, [
    "workerActor" => "jobs-admin",
    "automationStart" => "2026-01-01",
    "today" => "2026-01-01",
]);
$check(
    $preview["contributions"]["status"] === "preview" &&
        (int) Db::run(
            $db,
            "SELECT COUNT(*) FROM finance_entries",
        )->fetchColumn() === 0,
    "financial run remains preview without send",
);
$annual = $run($fake, [
    "workerActor" => "jobs-admin",
    "automationStart" => "2026-01-01",
    "today" => "2026-01-01",
]);
$check(
    $annual["contributions"]["created"] === 1 &&
        $annual["contributions"]["sent"] === 1,
    "native worker issues original annual invoice and delivers PDF",
);
$invoiceMail = end($calls);
$check(
    $invoiceMail[0] === "contributions" &&
        str_starts_with($invoiceMail[1]["attachments"][0]["bytes"], "%PDF-"),
    "annual invoice retains separate contribution purpose",
);
$issued = Db::run(
    $db,
    "SELECT document_id FROM contribution_invoices WHERE club_id=?",
    ["jobs-a"],
)->fetchColumn();
$check(
    $invoiceMail[1]["attachments"][0]["bytes"] ===
        (new Documents($db, "jobs-admin"))->file($issued)["bytes"],
    "worker passes frozen original contribution PDF bytes",
);
$again = $run($fake, [
    "workerActor" => "jobs-admin",
    "automationStart" => "2026-01-01",
    "today" => "2026-01-01",
]);
$check(
    $again["contributions"]["status"] === "already_completed" &&
        (int) Db::run(
            $db,
            "SELECT COUNT(*) FROM finance_entries",
        )->fetchColumn() === 1,
    "daily completion survives repeated workers without duplicate invoices",
);
$check(
    !str_contains(json_encode($annual), "payer@example.invalid") &&
        !str_contains(json_encode($annual), "Originalzahler"),
    "worker result contains counts without private recipients",
);
$check(
    $run($fake, [
        "workerActor" => "jobs-admin",
        "automationStart" => "2026-01-01",
        "today" => "2025-12-31",
    ])["contributions"]["status"] === "not_started",
    "configured automation start respected",
);

Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role) VALUES('push-one','jobs-a','member'),('push-two','jobs-a','member'),('foreign-one','jobs-b','member'),('foreign-two','jobs-b','member')",
);
$ec = openssl_pkey_new([
    "private_key_type" => OPENSSL_KEYTYPE_EC,
    "curve_name" => "prime256v1",
]);
$details = openssl_pkey_get_details($ec);
$vapid = [
    "publicKey" => rtrim(
        strtr(
            base64_encode(
                "\x04" .
                    str_pad($details["ec"]["x"], 32, "\0", STR_PAD_LEFT) .
                    str_pad($details["ec"]["y"], 32, "\0", STR_PAD_LEFT),
            ),
            "+/",
            "-_",
        ),
        "=",
    ),
];
$subscription = [
    "endpoint" => "https://fcm.googleapis.com/fcm/send/private-synthetic-own",
    "keys" => [
        "p256dh" => $vapid["publicKey"],
        "auth" => rtrim(
            strtr(base64_encode(random_bytes(16)), "+/", "-_"),
            "=",
        ),
    ],
];
(new PushNotifications($db, "push-two"))->save($subscription);
$subscription["endpoint"] =
    "https://fcm.googleapis.com/fcm/send/private-synthetic-foreign";
(new PushNotifications($db, "foreign-two"))->save($subscription);
$chat = new ClubChat($db, "push-one");
$chatId = $chat->create("Originalchat", ["push-two"]);
$chat->send($chatId, "Private original message", $requestKey());
$foreignChat = new ClubChat($db, "foreign-one");
$foreignChatId = $foreignChat->create("Fremdchat", ["foreign-two"]);
$foreignChat->send($foreignChatId, "Private foreign message", $requestKey());
$foreignPush = Db::run(
    $db,
    "SELECT d.* FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id WHERE s.club_id=?",
    ["jobs-b"],
)->fetch();
$pushCalls = [];
$pushTransport = static function (array $subscription, string $message) use (
    &$pushCalls,
): string {
    $pushCalls[] = $message;
    return "sent";
};
$run(null, ["pushSend" => $pushTransport]);
$check($pushCalls === [], "push callback alone cannot enable transport");
$push = $run(null, ["push" => true, "pushSend" => $pushTransport]);
$check(
    $push["push"]["sent"] === 1 && count($pushCalls) === 1,
    "native worker dispatches actual original chat push once",
);
$check(
    !str_contains($pushCalls[0], "Private original message") &&
        !str_contains($pushCalls[0], "Originalchat"),
    "push retains original privacy boundary",
);
$check(
    $foreignPush ===
        Db::run(
            $db,
            "SELECT d.* FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id WHERE s.club_id=?",
            ["jobs-b"],
        )->fetch(),
    "native push never claims or changes foreign delivery",
);
$run(null, ["push" => true, "pushSend" => $pushTransport]);
$check(count($pushCalls) === 1, "confirmed original push not replayed");
$check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM processwire_identities",
    )->fetchColumn() === 0,
    "all tested original jobs need no ProcessWire identity",
);
$private = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/original-worker";
mkdir($private, 0700);
foreach (["private.key", "auth.key"] as $name) {
    file_put_contents($private . "/" . $name, random_bytes(32));
    chmod($private . "/" . $name, 0600);
}
$fixture = TestSupport::config();
$workerConfiguration = [
    "dsn" =>
        "mysql:host=127.0.0.1;port=13371;dbname=" .
        $fixture["database"] .
        ";charset=utf8mb4",
    "username" => $fixture["username"],
    "password" => $fixture["password"],
    "key_file" => $private . "/private.key",
    "manager_key_file" => $private . "/private.key",
    "auth_key_file" => $private . "/auth.key",
    "club_id" => "jobs-a",
    "origin" => $origin,
];
file_put_contents(
    $private . "/worker.php",
    "<?php return " . var_export($workerConfiguration, true) . ";",
);
chmod($private . "/worker.php", 0600);
$cli = static function (array $options) use ($private): array {
    $process = proc_open(
        [
            PHP_BINARY,
            dirname(__DIR__) . "/bin/native-delivery-worker.php",
            "--config=" . $private . "/worker.php",
            ...$options,
        ],
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes,
    );
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $error];
};
[$exit, $stdout, $stderr] = $cli([]);
$result = json_decode($stdout, true);
$check(
    $exit === 0 &&
        $stderr === "" &&
        $result["transport"] === "disabled" &&
        isset($result["original_jobs"]),
    "actual private native CLI completes the original transport-free path",
);
$outbox->enqueue(
    $own->club(),
    "confirm",
    "development-held",
    ["to" => "held@example.invalid"],
    gmdate("Y-m-d H:i:s"),
);
$held = Db::run(
    $db,
    "SELECT * FROM native_delivery_jobs WHERE club_id=? AND status='pending'",
    [$own->club()],
)->fetch();
putenv("VEREIN_MODE=synthetic");
[$exit, $stdout, $stderr] = $cli(["--send"]);
$check(
    $exit === 0 &&
        $stderr === "" &&
        json_decode($stdout, true)["transport"] === "disabled" &&
        $held ===
            Db::run($db, "SELECT * FROM native_delivery_jobs WHERE id=?", [
                $held["id"],
            ])->fetch(),
    "development send flag does not claim or corrupt original pending jobs",
);
[$invalidExit, $invalidOut] = $cli(["--limit=-1", "--send"]);
$check(
    $invalidExit === 1 &&
        $invalidOut === "" &&
        $held ===
            Db::run($db, "SELECT * FROM native_delivery_jobs WHERE id=?", [
                $held["id"],
            ])->fetch(),
    "invalid limit is rejected before job claims",
);
putenv("VEREIN_MODE=production");
$runs = \Kulturhus\Native\Integration\Jobs\WorkerRuns::listing($db, $own);
$check(
    count($runs) === 2 &&
        array_unique(array_column($runs, "status")) === ["completed"],
    "real private CLI records completed own runs",
);
$check(
    \Kulturhus\Native\Integration\Jobs\WorkerRuns::listing($db, $foreign) ===
        [],
    "worker history is club isolated",
);
$reader = (new ManagerBridge(
    $db,
    "jobs-reader",
    "native",
    static fn() => null,
))->access();
$deny(
    fn() => \Kulturhus\Native\Integration\Jobs\WorkerRuns::listing(
        $db,
        $reader,
    ),
    "ordinary member cannot inspect operational history",
);
$id = \Kulturhus\Native\Integration\Jobs\WorkerRuns::begin($db, $own->club(), [
    "send" => true,
    "secret" => "never-persist",
]);
\Kulturhus\Native\Integration\Jobs\WorkerRuns::finish($db, $own->club(), $id, [
    "drafts" => 1,
    "private" => "never-persist",
    "deliveries" => [
        [
            "status" => "uncertain",
            "to" => "private@example.invalid",
            "token" => "never-persist",
        ],
    ],
]);
$row = Db::run($db, "SELECT * FROM native_worker_runs WHERE id=?", [
    $id,
])->fetch();
$check(
    $row["status"] === "needs_review" &&
        !str_contains(json_encode($row), "never-persist") &&
        !str_contains(json_encode($row), "private@example.invalid"),
    "uncertain result records review status without private payload",
);
$panel = \Kulturhus\Native\Web\Settings\WorkerPanel::render($db, $own);
$check(
    str_contains($panel, "Zustellungen prüfen") &&
        str_contains($panel, 'scope="col"') &&
        !str_contains($panel, "private@example.invalid"),
    "read-only operational panel displays actual original statuses accessibly",
);
\Kulturhus\Native\Integration\Jobs\WorkerRuns::finish(
    $db,
    $own->club(),
    $id,
    null,
);
$check(
    Db::run($db, "SELECT status FROM native_worker_runs WHERE id=?", [
        $id,
    ])->fetchColumn() === "needs_review",
    "completed run cannot be overwritten by a later stale completion",
);

// The actual configured production entry point executes only explicitly enabled own publications.
$publicationClock = static fn(): int => time() - 120;
$makePlan = static function ($access, string $suffix) use (
    $db,
    $publicationClock,
): array {
    $pages = new \Kulturhus\Native\Cms\Pages($db, $access);
    $page = $pages->save([
        "slug" => "worker-publication-" . $suffix,
        "title" => "Privater ursprünglicher Entwurf " . $suffix,
        "body" => "Unveränderte freigegebene Fassung",
    ]);
    $schedule = new \Kulturhus\Native\Cms\PublicationSchedule(
        $db,
        $access,
        $publicationClock,
    );
    $job = $schedule->plan($page["id"], 1, time() - 60);
    return [$page, $job, $pages];
};
$accountId = bin2hex(random_bytes(16));
Db::run(
    $db,
    "INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)",
    [$accountId, $own->user()["id"], "publication@example.invalid", time()],
);
[$foreignPage, $foreignPlan] = $makePlan($foreign, "foreign");
Db::run(
    $db,
    "UPDATE khcms_publication_schedule SET status='processing',due_at=? WHERE id=?",
    [time() - 90, $foreignPlan["id"]],
);
$foreignPlanBefore = Db::run(
    $db,
    "SELECT * FROM khcms_publication_schedule WHERE id=?",
    [$foreignPlan["id"]],
)->fetch();
[$plannedPage, $publicationPlan, $publicationPages] = $makePlan($own, "own");
$originalPlan = Db::run(
    $db,
    "SELECT * FROM khcms_publication_schedule WHERE id=?",
    [$publicationPlan["id"]],
)->fetch();
putenv("KULTURHUS_DISABLE_WEBSITE");
[$exit, $stdout] = $cli([]);
$check(
    $exit === 0 &&
        json_decode($stdout, true)["publication"] === "disabled" &&
        $originalPlan ===
            Db::run(
                $db,
                "SELECT * FROM khcms_publication_schedule WHERE id=?",
                [$publicationPlan["id"]],
            )->fetch(),
    "omitted publication option preserves the original due plan",
);
putenv("KULTURHUS_DISABLE_WEBSITE=1");
[$exit, $stdout] = $cli(["--publish"]);
$check(
    $exit === 0 &&
        json_decode($stdout, true)["publication"] === "disabled" &&
        $originalPlan ===
            Db::run(
                $db,
                "SELECT * FROM khcms_publication_schedule WHERE id=?",
                [$publicationPlan["id"]],
            )->fetch(),
    "development publication block leaves the due original untouched",
);
putenv("KULTURHUS_DISABLE_WEBSITE");
[$exit, $stdout, $stderr] = $cli(["--publish", "--limit=1"]);
$publicationResult = json_decode($stdout, true);
$check(
    $exit === 0 &&
        $stderr === "" &&
        $publicationResult["publication"] === "enabled" &&
        $publicationResult["publications"]["published"] === 1 &&
        $publicationResult["transport"] === "disabled",
    "actual private CLI publishes the approved own original without mail transport",
);
$check(
    $publicationPages->publicPage($plannedPage["slug"])["body"] ===
        $plannedPage["body"] &&
        $publicationPages->get($plannedPage["id"])["version"] === 2,
    "production worker exposes the exact approved version",
);
$check(
    $foreignPlanBefore ===
        Db::run($db, "SELECT * FROM khcms_publication_schedule WHERE id=?", [
            $foreignPlan["id"],
        ])->fetch(),
    "production limit and reconciliation never touch the earlier foreign processing plan",
);
$check(
    !str_contains($stdout, "Unveränderte freigegebene Fassung") &&
        !str_contains($stdout, "publication@example.invalid") &&
        !str_contains($stdout, $plannedPage["id"]),
    "publication worker returns only outcome counters",
);
[$exit, $stdout] = $cli(["--publish"]);
$check(
    $exit === 0 &&
        json_decode($stdout, true)["publications"]["published"] === 0 &&
        $publicationPages->get($plannedPage["id"])["version"] === 2,
    "repeated production publication creates no second revision",
);
[$changedPage, $changedPlan] = $makePlan($own, "changed");
$changedPage = $publicationPages->save(
    array_replace($changedPage, ["body" => "Späterer privater Entwurf"]),
    1,
);
[$exit, $stdout] = $cli(["--publish"]);
$check(
    $exit === 0 &&
        json_decode($stdout, true)["publications"]["conflicted"] === 1 &&
        $publicationPages->publicPage($changedPage["slug"]) === null &&
        $publicationPages->get($changedPage["id"])["body"] ===
            "Späterer privater Entwurf",
    "production conflict preserves newer private draft",
);
[$blockedPage, $blockedPlan] = $makePlan($own, "blocked");
Db::run($db, "UPDATE native_auth_accounts SET active=0 WHERE id=?", [
    $accountId,
]);
[$exit, $stdout] = $cli(["--publish"]);
$check(
    $exit === 0 &&
        json_decode($stdout, true)["publications"]["blocked"] === 1 &&
        $publicationPages->publicPage($blockedPage["slug"]) === null,
    "current suspended account blocks production publication",
);
Db::run($db, "UPDATE native_auth_accounts SET active=1 WHERE id=?", [
    $accountId,
]);
$panel = \Kulturhus\Native\Web\Settings\WorkerPanel::render($db, $own);
$check(
    str_contains($panel, "Website veröffentlichen") &&
        str_contains($panel, "Veröffentlichungen oder Zustellungen prüfen"),
    "operational panel names actual publication mode and review outcomes",
);
[$exit, $stdout] = $cli(["--publish", "--meta"]);
$check(
    $exit === 1 && $stdout === "",
    "Meta cannot implicitly combine with website publication",
);
[$exit, $stdout] = $cli(["--fixture", "--publish"]);
$check(
    $exit === 1 && $stdout === "",
    "fixture option cannot activate production publication",
);
$socialPlans = new \Kulturhus\Native\Communication\CheckPlans($db);
$socialPlans->save(
    $own,
    "meta",
    [],
    true,
    0,
    new DateTimeImmutable("-10 minutes"),
);
$heldBeforeSocial = Db::run(
    $db,
    "SELECT * FROM native_delivery_jobs ORDER BY id",
)->fetchAll();
[$exit, $stdout] = $cli(["--social-check"]);
$socialResult = json_decode($stdout, true);
$check(
    $exit === 0 &&
        $socialResult["worker"] === "native-social-check" &&
        $socialResult["transport"] === "read-only" &&
        $socialResult["deliveries"] === [],
    "actual separate social CLI cannot dispatch mail or publish content",
);
$check(
    $socialResult["social_checks"]["meta"] === "needs_review" &&
        Db::run(
            $db,
            "SELECT * FROM native_delivery_jobs ORDER BY id",
        )->fetchAll() === $heldBeforeSocial,
    "missing provider connection reports review while original delivery jobs stay unchanged",
);
$socialRuns = \Kulturhus\Native\Integration\Jobs\WorkerRuns::listing($db, $own);
$socialRun = array_values(
    array_filter(
        $socialRuns,
        static fn($r) => json_decode($r["modes_json"], true)["social-check"] ??
            false,
    ),
)[0];
$check(
    $socialRun["status"] === "needs_review" &&
        json_decode($socialRun["summary_json"], true)["social_checks"][
            "meta"
        ] === "needs_review",
    "social worker history records actual review outcome without private provider content",
);
// Existing history has counted six-row pages. A same-second random run identifier
// must not make this assertion depend on whether its row happens to be on page one.
$socialPage=null;
for($page=1;;$page++){
    $runPage=\Kulturhus\Native\Integration\Jobs\RunCatalogue::page($db,$own,['worker_page'=>(string)$page]);
    foreach($runPage['rows']as $row)if($row['modes_json']===$socialRun['modes_json']&&$row['summary_json']===$socialRun['summary_json']&&$row['started_at']===$socialRun['started_at'])$socialPage=$page;
    if($socialPage!==null||$page>=$runPage['pages'])break;
}
$previousWorkerQuery=$_GET;$_GET=['worker_page'=>(string)$socialPage,'worker_state'=>'all'];
try{$socialPanel=\Kulturhus\Native\Web\Settings\WorkerPanel::render($db,$own);}finally{$_GET=$previousWorkerQuery;}
$check($socialPage!==null&&str_contains($socialPanel,"Social-Kommentare und Nachrichten prüfen"),
    "operational panel names the separate read-only social mode on its actual counted history page");
foreach (
    [["--social-check", "--send"], ["--social-check", "--fixture"]]
    as $invalid
) {
    [$exit, $stdout] = $cli($invalid);
    $check(
        $exit === 1 && $stdout === "",
        "social read mode rejects combined transport or fixture options",
    );
}

// The actual public intake freezes the full application before email confirmation.
$legalPages=new \Kulturhus\Native\Cms\Pages($db,$own);
foreach(['verein/satzung'=>'Original Satzung zur Antragstellung','datenschutz'=>'Original Datenschutzhinweise zur Antragstellung']as$slug=>$body){$legal=$legalPages->save(['slug'=>$slug,'title'=>$slug,'body_html'=>'<p>'.$body.'</p>']);$legalPages->publish($legal['id'],$legal['version']);}
$beforeApplications=(int)$db->query('SELECT COUNT(*) FROM membership_applications')->fetchColumn();$reviewInput=array_replace($answers,['email'=>'own-full-application@example.invalid','membership_type'=>'family','family_members'=>"Own child one\nOwn child two"]);$reviewKey=$requestKey();$currentSettings=$intake->resolvedSettings();$reviewId=$intake->submit($reviewInput,$reviewKey,$currentSettings['version'],true,false,true,true);
$record=Db::run($db,'SELECT * FROM application_confirmations WHERE id=?',[$reviewId])->fetch();$payload=json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR);$review=$payload['review'];
$check($payload['input']===$reviewInput&&$review['answers']['family_members']==="Own child one\nOwn child two"&&count($review['legal'])===2&&(int)$db->query('SELECT COUNT(*) FROM membership_applications')->fetchColumn()===$beforeApplications,'unconfirmed intake freezes the whole family application and both published legal texts without creating a board application');
$bundle=\Kulturhus\Verein\People\Members\ConfirmationMail::message($review,'Bitte bestätigen: '.$origin.'/mitglied-werden/?confirm_application='.$record['mail_token'],'Bitte Mitgliedsantrag bestätigen',$origin);
$check(str_contains($bundle['text'],'Own child two')&&str_contains($bundle['html'],'Original Satzung')===false&&str_contains($bundle['html'],$origin.'/verein/satzung/')&&str_contains($bundle['html'],'Newsletter gewünscht')&&str_contains($bundle['html'],'Mitgliedsantrag bestätigen</a>'),'applicant email contains complete answers consents a labelled confirmation button and exact public statute link');
$check(count($bundle['attachments'])===1&&str_starts_with($bundle['attachments'][0]['bytes'],'%PDF-'),'full original application and the frozen statutory privacy texts render into a real applicant PDF attachment');
$oldLegal=$review['legal'];$changed=$legalPages->get($legal['id']);$changed['body']='Different later public privacy text';$legalPages->save($changed,$changed['version']);$check(json_decode(Db::run($db,'SELECT payload FROM application_confirmations WHERE id=?',[$reviewId])->fetchColumn(),true)['review']['legal']===$oldLegal,'later website changes preserve every previously submitted legal original in the confirmation');
$confirmedId=ApplicationConfirmation::confirm($db,'jobs-a',$record['mail_token'],$intake);$check($intake->application($confirmedId)['status']==='pending'&&ApplicationConfirmation::confirm($db,'jobs-a',$record['mail_token'],$intake)===$confirmedId,'full frozen confirmation remains one-time and creates only the normal pending board application');
// Bot budgets apply across sessions and sender IPs, without producing an outgoing job.
$limits=new SecretBox(random_bytes(32));$mailCount=(int)$db->query('SELECT COUNT(*) FROM application_confirmations')->fetchColumn();
for($i=1;$i<=8;$i++)$check(\Kulturhus\Native\Shared\PublicFormLimits::membership($db,$own->club(),$limits,'198.51.100.42','own-limit-'.$i.'@example.invalid'),'legitimate bounded public intake '.$i);
$check(!\Kulturhus\Native\Shared\PublicFormLimits::membership($db,$own->club(),$limits,'198.51.100.42','own-limit-9@example.invalid'),'new email addresses cannot bypass the shared IP submission budget');
for($i=1;$i<=3;$i++)$check(\Kulturhus\Native\Shared\PublicFormLimits::membership($db,$own->club(),$limits,'203.0.113.'.$i,'own-distributed@example.invalid'),'bounded distributed-address request '.$i);
$check(!\Kulturhus\Native\Shared\PublicFormLimits::membership($db,$own->club(),$limits,'203.0.113.4','own-distributed@example.invalid'),'rotating IPs cannot mail-bomb one recipient beyond its separate daily limit');
$trap=\Kulturhus\Verein\PublicMembershipForm::handle($intake,['csrf'=>'own-public-csrf','configuration_version'=>(string)$currentSettings['version'],'contact_website'=>'filled by bot']+$reviewInput,'own-public-csrf',true,false);$check(!$trap['success']&&$trap['error']!==''&&(int)$db->query('SELECT COUNT(*) FROM application_confirmations')->fetchColumn()===$mailCount,'filled bot field creates no confirmation request or outgoing mail and retains a visible form error');
putenv("KULTURHUS_DISABLE_WEBSITE=1");
echo json_encode(
    ["checks" => $checks, "passed" => true, "live_transport" => false],
    JSON_THROW_ON_ERROR,
) . "\n";
