<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Communication\{
    Schema,
    Subscribers,
    NewsletterGateway,
    ConfirmationReminders,
};
use Kulturhus\Native\Communication\Confirmations\{Renewals, Message};
use Kulturhus\Native\Delivery\Outbox;

$db = TestSupport::db();
$access = new Access($db, "admin");
$box = new SecretBox(str_repeat("w", 32));
if (($argv[1] ?? "") === "--queue") {
    echo json_encode(
        (new Renewals($db, $access, $box))->queue(
            $argv[2],
            $argv[3],
            $argv[4],
            true,
        ),
        JSON_THROW_ON_ERROR,
    );
    exit();
}
if (($argv[1] ?? "") === "--endpoint") {
    $input = json_decode(
        stream_get_contents(STDIN),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    $_SERVER["REQUEST_METHOD"] = $input["method"];
    $_SERVER["REQUEST_URI"] = "/newsletter/bestaetigen/";
    $_SESSION = ["newsletter_csrf" => str_repeat("q", 64)];
    $_GET = ["token" => $input["token"]];
    $_POST = $input["post"] ?? [];
    ob_start();
    Kulturhus\Native\Web\NewsletterEndpoints::serve($db, $access->club(), $box);
    $html = ob_get_clean();
    echo json_encode(
        ["status" => http_response_code() ?: 200, "html" => $html],
        JSON_THROW_ON_ERROR,
    );
    exit();
}
TestSupport::seed($db);
Schema::install($db);
(new Identity($db, $access))->save(
    [
        "name" => "Gemeinsamer Bestätigungsverein",
        "origin" => "https://example.invalid",
        "address" => "Testweg 1\n00000 Musterort",
    ],
    0,
);
$club = $access->club();
$subscribers = new Subscribers($db, $access);
$gateway = new NewsletterGateway($db, $club, $box);
$renewals = new Renewals($db, $access, $box);
$worker = new Outbox($db, $box);
$count = 0;
function check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
    echo "PASS $label\n";
}
function denied(callable $action, string $label): void
{
    try {
        $action();
    } catch (DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
function pending(): array
{
    global $db, $club, $subscribers;
    static $number = 0;
    ++$number;
    $old = "";
    $row = $subscribers->requestConsent(
        "renewal" . $number . "@example.invalid",
        "Original Name " . $number,
        ["news", "events"],
        "Original synthetischer Einwilligungsantrag",
        static function ($token) use (&$old) {
            $old = $token;
        },
    );
    Db::run(
        $db,
        "UPDATE khn_newsletter_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE club_id=? AND subscriber_id=? AND kind='confirm'",
        [$club, $row["id"]],
    );
    return [$row["id"], $old];
}
function queueOne(string $id): string
{
    global $renewals;
    $p = $renewals->preview($id);
    return $renewals->queue(
        $id,
        $p["hash"],
        bin2hex(random_bytes(16)),
        true,
    )[0];
}
function endpoint(string $method, string $token, array $post = []): array
{
    $process = proc_open(
        [...TestSupport::phpCommand(), __FILE__, "--endpoint"],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    fwrite(
        $pipes[0],
        json_encode(compact("method", "token", "post"), JSON_THROW_ON_ERROR),
    );
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== "") {
        throw new RuntimeException("Synthetic public endpoint failed.");
    }
    return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
}
function confirmToken(array $payload): string
{
    preg_match(
        "~/newsletter/bestaetigen/\?token=([a-f0-9]{64})~",
        $payload["text"],
        $m,
    );
    return $m[1] ?? "";
}

[$first, $old] = pending();
$before = $subscribers->get($first);
$preview = $renewals->preview($first);
check(
    $preview["counts"]["eligible"] === 1,
    "expired original link remains eligible for explicit renewal",
);
check(
    !str_contains(json_encode($preview), "renewal1@") &&
        !str_contains(json_encode($preview), $old),
    "preview contains only counts and an opaque signature",
);
denied(
    fn() => $renewals->queue(
        $first,
        $preview["hash"],
        bin2hex(random_bytes(16)),
        false,
    ),
    "explicit unchecked confirmation is mandatory",
);
denied(
    fn() => (new Renewals($db, new Access($db, "editor"), $box))->preview(),
    "editor cannot inspect administrative pending signup selection",
);
denied(
    fn() => (new Renewals($db, new Access($db, "outsider"), $box))->preview(
        $first,
    ),
    "another club cannot select the request",
);
$request = bin2hex(random_bytes(16));
$ids = $renewals->queue($first, $preview["hash"], $request, true);
check(
    count($ids) === 1 &&
        $renewals->queue($first, $preview["hash"], $request, true) === $ids,
    "double click returns the exact original queued job",
);
denied(
    fn() => $renewals->queue("", $preview["hash"], $request, true),
    "batch cannot be reused for a different recipient scope",
);
check(
    $subscribers->get($first) === $before,
    "queue preserves name topics evidence dates status and version",
);
check(
    Db::run(
        $db,
        "SELECT token_hash FROM khn_newsletter_tokens WHERE subscriber_id=? AND kind='confirm'",
        [$first],
    )->fetchColumn() === hash("sha256", $old),
    "queue does not consume or replace the original token",
);
check(
    $renewals->preview($first)["counts"]["queued"] === 1,
    "pending renewal is counted and cannot be queued twice",
);
denied(
    fn() => (new ConfirmationReminders($db, $access, $box))->queue($first),
    "automatic reminder path cannot race a pending manual renewal",
);
$now = (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify(
    "+2 hours",
);
$messages = [];
$result = (new Outbox($db, $box, static fn() => $now))->run(
    function ($club, $payload) use (&$messages) {
        $independent = TestSupport::db();
        check(
            Db::run(
                $independent,
                "SELECT COUNT(*) FROM khn_newsletter_tokens WHERE token_hash=?",
                [$payload["confirmation_hash"]],
            )->fetchColumn() == 1,
            "usable renewed link is committed before any SMTP call",
        );
        $messages[] = $payload;
        return "synthetic-renewal-accepted";
    },
    50,
    ["renewal"],
);
check(
    count($messages) === 1 && $result[0]["status"] === "sent",
    "private worker delivers one synthetic renewed confirmation",
);
$token = confirmToken($messages[0]);
check(
    strlen($token) === 64 && $token !== $old,
    "processing generates a fresh random confirmation link",
);
$expiry = Db::run(
    $db,
    "SELECT expires_at FROM khn_newsletter_tokens WHERE subscriber_id=? AND kind='confirm'",
    [$first],
)->fetchColumn();
check(
    $expiry === $now->modify("+48 hours")->format("Y-m-d H:i:s"),
    "new link has 48 hours from processing rather than queue time",
);
check(
    $subscribers->get($first) === $before,
    "delivery preserves the original signup and version",
);
check(
    count($subscribers->history($first)) === 2 &&
        end($messages)["subject"] === "Newsletter-Anmeldung nochmal bestätigen",
    "renewal is separately audited without rewriting original consent",
);
check(
    str_contains($messages[0]["html"], Message::INSTRUCTION) &&
        str_contains($messages[0]["text"], Message::INSTRUCTION),
    "HTML and plain text both explain the required website confirmation",
);
check(
    str_contains($messages[0]["html"], "Jetzt nochmal bestätigen") &&
        str_contains($messages[0]["html"], "Gemeinsamer Bestätigungsverein") &&
        str_contains($messages[0]["text"], "/impressum/") &&
        str_contains($messages[0]["text"], "/datenschutz/"),
    "renewal uses common branding and legal footer links",
);
check(
    $renewals->preview($first)["counts"]["cooldown"] === 1,
    "successful transport starts the 48 hour manual cooldown",
);
check(
    $renewals->preview($first, $now->modify("+48 hours"))["counts"][
        "eligible"
    ] === 1,
    "another explicit renewal becomes available at the full 48 hour boundary",
);
check(
    !$gateway->redeem($old, "confirm"),
    "expired original link cannot confirm after renewal",
);
foreach (["GET", "HEAD"] as $method) {
    $response = endpoint($method, $token);
    check(
        $response["status"] === 200 &&
            $subscribers->get($first)["status"] === "pending",
        $method . " preview never consumes confirmation",
    );
}
$response = endpoint("POST", $token, ["token" => $token]);
check(
    $response["status"] === 403 &&
        $subscribers->get($first)["status"] === "pending",
    "POST without correct CSRF cannot confirm",
);
$response = endpoint("POST", $token, [
    "token" => $token,
    "csrf" => str_repeat("q", 64),
]);
check(
    $response["status"] === 200 &&
        $subscribers->get($first)["status"] === "confirmed",
    "actual public POST with CSRF confirms the renewed token",
);
check(
    !$gateway->redeem($token, "confirm"),
    "renewed confirmation is single use",
);
check(
    $renewals->preview($first)["counts"]["invalid"] === 1,
    "confirmed signup is excluded from manual renewal",
);

[$changed] = pending();
$p = $renewals->preview($changed);
Db::run(
    $db,
    "UPDATE khn_newsletter_subscribers SET name=?,version=version+1 WHERE id=?",
    ["Changed original", $changed],
);
denied(
    fn() => $renewals->queue(
        $changed,
        $p["hash"],
        bin2hex(random_bytes(16)),
        true,
    ),
    "stale selection cannot queue a changed original",
);
$changedJob = queueOne($changed);
Db::run(
    $db,
    'UPDATE khn_newsletter_subscribers SET purposes=JSON_ARRAY(\'events\'),version=version+1 WHERE id=?',
    [$changed],
);
$calls = 0;
$r = $worker->run(
    function () use (&$calls) {
        ++$calls;
        return "unexpected";
    },
    50,
    ["renewal"],
);
check(
    $calls === 0 &&
        $r[0]["id"] === $changedJob &&
        $r[0]["status"] === "cancelled",
    "worker rechecks the unchanged original topics and version",
);
[$revoked] = pending();
queueOne($revoked);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='newsletter' AND capability='manage'",
);
$r = $worker->run(
    function () use (&$calls) {
        ++$calls;
        return "unexpected";
    },
    50,
    ["renewal"],
);
check(
    $calls === 0 && $r[0]["status"] === "cancelled",
    "worker rechecks current administrative authority before preparing a token",
);
Db::run($db, "INSERT INTO native_grants VALUES('admin','newsletter','manage')");
check(
    $renewals->preview($revoked)["counts"]["eligible"] === 1,
    "failure before preparation permits a new manual request",
);

[$uncertain] = pending();
$uncertainJob = queueOne($uncertain);
$uncertainMessage = [];
$r = $worker->run(
    function ($club, $payload) use (&$uncertainMessage) {
        $uncertainMessage = $payload;
        throw new RuntimeException("synthetic SMTP acceptance timeout");
    },
    50,
    ["renewal"],
);
check(
    $r[0]["status"] === "uncertain",
    "unknown SMTP result is recorded without asserting delivery",
);
$uncertainToken = confirmToken($uncertainMessage);
check(
    Db::run(
        $db,
        "SELECT COUNT(*) FROM khn_newsletter_tokens WHERE token_hash=?",
        [hash("sha256", $uncertainToken)],
    )->fetchColumn() == 1,
    "usable link survives transaction rollback after uncertain SMTP",
);
check(
    $renewals->preview(
        $uncertain,
        (new DateTimeImmutable())->modify("+90 days"),
    )["counts"]["uncertain"] === 1,
    "uncertain transport remains blocked even after the cooldown",
);
$worker->run(
    function () use (&$calls) {
        ++$calls;
        return "unexpected";
    },
    50,
    ["renewal"],
);
check($calls === 0, "uncertain SMTP attempt is never automatically replayed");
check(
    $gateway->redeem($uncertainToken, "confirm"),
    "recipient can confirm a link received from an uncertain transport",
);
check(
    $renewals->history($uncertain)[0]["status"] === "uncertain" &&
        !array_key_exists("ciphertext", $renewals->history($uncertain)[0]),
    "history exposes actual delivery state without private mail payload",
);

[$withdrawn] = pending();
$withdrawnJob = queueOne($withdrawn);
$cancel = $gateway->unsubscribeToken($withdrawn);
check(
    $gateway->redeem($cancel, "unsubscribe"),
    "public withdrawal applies before the queued reminder",
);
$r = $worker->run(
    function () use (&$calls) {
        ++$calls;
        return "unexpected";
    },
    50,
    ["renewal"],
);
check(
    !$r && $calls === 0,
    "withdrawal cancels queued renewal before any transport",
);
$cipher = Db::run(
    $db,
    "SELECT ciphertext FROM native_delivery_jobs WHERE id=?",
    [$withdrawnJob],
)->fetchColumn();
check(
    $box->open("job:" . $club . ":" . $withdrawnJob, $cipher) === [],
    "withdrawal strips recipient address and private links from renewal payload",
);
[$blocked] = pending();
$email = $subscribers->get($blocked)["email"];
Db::run(
    $db,
    "INSERT INTO native_newsletter_blocks VALUES(?,?,UTC_TIMESTAMP())",
    [$club, $box->fingerprint("email:" . $email)],
);
check(
    $renewals->preview($blocked)["counts"]["invalid"] === 1,
    "suppressed address is excluded even when a pending request remains",
);
[$invalid] = pending();
Db::run(
    $db,
    "UPDATE khn_newsletter_subscribers SET consent_evidence='' WHERE id=?",
    [$invalid],
);
check(
    $renewals->preview($invalid)["counts"]["invalid"] === 1,
    "missing original evidence is excluded",
);

[$race] = pending();
$p = $renewals->preview($race);
$request = bin2hex(random_bytes(16));
$children = [];
for ($i = 0; $i < 2; $i++) {
    $process = proc_open(
        [
            ...TestSupport::phpCommand(),
            __FILE__,
            "--queue",
            $race,
            $p["hash"],
            $request,
        ],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
$raceResults = [];
foreach ($children as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(
        proc_close($process) === 0 && $errors === "",
        "concurrent manual request completes without diagnostics",
    );
    $raceResults[] = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
}
check(
    $raceResults[0] === $raceResults[1] && count($raceResults[0]) === 1,
    "two processes queue exactly the same single renewal",
);
$worker->run(static fn() => "synthetic-race", 50, ["renewal"]);

// Prepare 25 independent valid originals. Bulk selection is independent of UI pagination.
for ($i = 0; $i < 25; $i++) {
    pending();
}
$p = $renewals->preview();
$eligible = $p["counts"]["eligible"];
$bulkRequest = bin2hex(random_bytes(16));
$bulk = $renewals->queue("", $p["hash"], $bulkRequest, true);
check(
    count($bulk) === $eligible && $eligible >= 25,
    "bulk request includes all eligible originals rather than one contact page",
);
$sent = 0;
$r = $worker->run(
    function () use (&$sent) {
        ++$sent;
        return "synthetic-bulk";
    },
    200,
    ["renewal"],
);
check(
    count($r) === 20 && $sent === 20,
    "manual confirmation transport is bounded to 20 per background run",
);
$r = $worker->run(
    function () use (&$sent) {
        ++$sent;
        return "synthetic-bulk-next";
    },
    200,
    ["renewal"],
);
check(
    $sent === count($bulk) && count($r) === count($bulk) - 20,
    "remaining individual jobs resume in the next run without duplicates",
);
check(
    $renewals->queue("", $p["hash"], $bulkRequest, true) === $bulk,
    "bulk double click remains idempotent after every job has been processed",
);
pending();
$keyFile = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/renewals-private.key";
file_put_contents($keyFile, str_repeat("w", 32));
chmod($keyFile, 0600);
putenv("KH_NATIVE_PRIVATE_KEY_FILE=" . $keyFile);
$_GET = ["q" => "Original", "page" => 1];
$html = (new Kulturhus\Native\Web\Communication\Contacts(
    $db,
    $access,
    "",
    str_repeat("c", 64),
))->manager("newsletter");
$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
$checkboxes = $xpath->query('//input[@name="renewal_confirmed"]');
check(
    $checkboxes->length >= 1,
    "recipient UI includes the explicit manual bulk preparation form",
);
foreach ($checkboxes as $checkbox) {
    check(
        $checkbox->hasAttribute("required") &&
            !$checkbox->hasAttribute("checked"),
        "manual consent checkbox is required and never preselected",
    );
}
check(
    str_contains($html, "unabhängig von Suche und aktueller Seite") &&
        !str_contains($html, "confirmation_hash") &&
        !str_contains($html, $uncertainToken),
    "UI explains full scope and exposes no confirmation token",
);
denied(
    fn() => (new Kulturhus\Native\Web\Communication\Actions(
        $db,
        $access,
        "",
        str_repeat("c", 64),
    ))->action(
        "newsletter",
        ["operation" => "newsletter-renew-confirmation", "csrf" => "wrong"],
        [],
    ),
    "manual HTTP action requires the manager CSRF token",
);
[$failed, $failedOld] = pending();
$failedJob = queueOne($failed);
Db::run($db, "UPDATE native_delivery_jobs SET ciphertext=? WHERE id=?", [
    "damaged-preparation-payload",
    $failedJob,
]);
$r = $worker->run(
    static fn() => throw new RuntimeException("Unexpected transport"),
    50,
    ["renewal"],
);
check(
    $r[0]["status"] === "cancelled" &&
        $renewals->preview($failed)["counts"]["eligible"] === 1,
    "damaged preparation without transport can be queued again manually",
);
check(
    Db::run(
        $db,
        "SELECT token_hash FROM khn_newsletter_tokens WHERE subscriber_id=? AND kind='confirm'",
        [$failed],
    )->fetchColumn() === hash("sha256", $failedOld),
    "failed preparation retains the original signup token",
);
queueOne($failed);
$later = (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify(
    "+5 days",
);
$r = (new Outbox($db, $box, static fn() => $later))->run(
    static fn() => "synthetic-delayed-processing",
    50,
    ["renewal"],
);
check(
    $r[0]["status"] === "sent" &&
        Db::run(
            $db,
            "SELECT expires_at FROM khn_newsletter_tokens WHERE subscriber_id=? AND kind='confirm'",
            [$failed],
        )->fetchColumn() === $later->modify("+48 hours")->format("Y-m-d H:i:s"),
    "delayed processing still issues the full 48 hour link",
);
[$blockedLater] = pending();
queueOne($blockedLater);
Db::run(
    $db,
    "INSERT INTO native_newsletter_blocks VALUES(?,?,UTC_TIMESTAMP())",
    [
        $club,
        $box->fingerprint("email:" . $subscribers->get($blockedLater)["email"]),
    ],
);
$r = $worker->run(
    static fn() => throw new RuntimeException("Unexpected blocked transport"),
    50,
    ["renewal"],
);
check(
    $r[0]["status"] === "cancelled",
    "suppression added after queue is rechecked before token preparation",
);

// Real MIME bytes, without creating any SMTP connection.
require dirname(__DIR__, 2) . "/vendor/autoload.php";
Kulturhus\Native\Cms\Schema::install($db);
$mediaDirectory = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/renewal-logo";
mkdir($mediaDirectory, 0700, true);
$image = imagecreatetruecolor(500, 100);
imagefill($image, 0, 0, imagecolorallocate($image, 22, 63, 51));
imagepng($image, $mediaDirectory . "/logo.png");
unset($image);
$logo = (new Kulturhus\Native\Cms\Media(
    $db,
    $access,
    $mediaDirectory . "/media",
))->upload(
    $mediaDirectory . "/logo.png",
    "logo.png",
    "Gemeinsames Vereinslogo",
);
(new Identity($db, $access))->save(
    [
        "name" => "Gemeinsamer Bestätigungsverein",
        "origin" => "https://example.invalid",
        "address" => "Original Vereinsanschrift",
        "logo_id" => $logo["id"],
    ],
    1,
);
$brand = Message::brand($db, $club, $mediaDirectory . "/media");
$mail = Message::compose(
    $brand,
    "https://example.invalid/newsletter/bestaetigen/?token=" .
        str_repeat("a", 64),
    "https://example.invalid/newsletter/abmelden/?token=" . str_repeat("b", 64),
    true,
) + ["to" => "mime@example.invalid"];
$mime = Kulturhus\Native\Delivery\MailMessage::compose(
    ["from" => "club@example.invalid", "reply_to" => "reply@example.invalid"],
    $mail,
)->toString();
check(
    str_contains($mime, "Content-ID: <newsletter-logo@kulturhus>") &&
        str_contains($mime, "Content-Disposition: inline"),
    "actual MIME contains the configured inline club logo",
);
check(
    str_contains($mime, "multipart/alternative") &&
        str_contains($mime, "Content-Type: image/png"),
    "actual mail retains both HTML and plain text alongside the image",
);
$pixels = getimagesizefromstring(base64_decode($brand["logo_png"], true));
check(
    $pixels[0] === 320 && $pixels[1] === 64,
    "mail uses checked local club pixels at the bounded original aspect ratio",
);
denied(
    fn() => Message::brand($db, $club, "/does-not-exist"),
    "configured club logo cannot silently disappear from a renewal",
);
denied(
    fn() => Kulturhus\Native\Delivery\MailMessage::compose(
        [
            "from" => "club@example.invalid",
            "reply_to" => "reply@example.invalid",
        ],
        array_replace($mail, ["logo_png" => "not-an-image"]),
    ),
    "invalid stored logo is rejected before opening SMTP",
);
denied(
    fn() => Kulturhus\Native\Delivery\MailMessage::compose(
        [
            "from" => "club@example.invalid",
            "reply_to" => "reply@example.invalid",
        ],
        array_replace($mail, [
            "headers" => [
                "List-Unsubscribe" =>
                    "https://example.invalid/\r\nBcc: forged@example.invalid",
            ],
        ]),
    ),
    "injected mail header is rejected before opening SMTP",
);
putenv("KH_NATIVE_MANAGER_MEDIA_ROOT");
putenv("KH_NATIVE_MEDIA_ROOT");
(new NewsletterGateway($db, $club, $box, $mediaDirectory . "/media"))->signup(
    "branded-signup@example.invalid",
    "Logo Anmeldung",
    ["events"],
    "192.0.2.99",
    true,
);
$initial = [];
$worker->run(
    function ($club, $payload) use (&$initial) {
        $initial[] = $payload;
        return "synthetic-branded-initial-confirmation";
    },
    50,
    ["confirm"],
);
check(
    count($initial) === 1 &&
        $initial[0]["logo_png"] === $brand["logo_png"] &&
        str_contains($initial[0]["text"], Message::INSTRUCTION),
    "initial signup also uses the trusted explicit private media root without environment fallbacks",
);
echo "$count native renewed confirmation checks passed; synthetic MySQL and fake mail transport only.\n";
