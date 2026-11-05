<?php
declare(strict_types=1);
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require dirname(__DIR__) . "/src/bootstrap.php";

use Kulturhus\Native\Cms\{Pages, EventPublications, PublicationSchedule};
use Kulturhus\Native\Events\Eventbrite;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Registry, PushNotifications, ManagerNotifications};

final class PublicationDispatchPDO extends PDO
{
    public ?Closure $afterClaim = null;
}
final class PublicationDispatchStatement extends PDOStatement
{
    protected function __construct(
        private PublicationDispatchPDO $connection,
    ) {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (
            $this->connection->afterClaim &&
            str_starts_with(
                $this->queryString,
                "UPDATE manager_push_deliveries SET state='sending'",
            ) &&
            $this->rowCount() === 1
        ) {
            $after = $this->connection->afterClaim;
            $this->connection->afterClaim = null;
            $after();
        }
        return $result;
    }
}

$db = TestSupport::db();
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
putenv("KH_NATIVE_MANAGER=1");
putenv("VEREIN_AUTH=native");
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL " . $label);
    }
    ++$checks;
    echo "PASS " . $label . "\n";
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
    "INSERT INTO clubs VALUES('notify-a','Eigener Testverein'),('notify-b','Fremdverein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('notify-admin','notify-a','members',1),('notify-reader','notify-a','member',0),('notify-later','notify-a','member',0),('notify-editor','notify-a','editor',0),('notify-foreign-admin','notify-b','members',1),('notify-foreign-reader','notify-b','member',0)",
);
foreach (
    [
        ["notify-reader", "notify-admin"],
        ["notify-later", "notify-admin"],
        ["notify-foreign-reader", "notify-foreign-admin"],
    ]
    as [$actor, $admin]
) {
    $registry = new Registry($db, $admin);
    $contact = $registry->saveContact(
        null,
        0,
        "person",
        "Synthetisch " . $actor,
        $actor . "@example.invalid",
        "Testweg 1",
    );
    $registry->addMembership($contact, "", "aktiv", "2000-01-01", false);
    Db::run($db, "UPDATE actors SET contact_id=? WHERE id=?", [
        $contact,
        $actor,
    ]);
}
$ec = openssl_pkey_new([
    "private_key_type" => OPENSSL_KEYTYPE_EC,
    "curve_name" => "prime256v1",
]);
$detail = openssl_pkey_get_details($ec);
$publicKey = rtrim(
    strtr(
        base64_encode(
            "\x04" .
                str_pad($detail["ec"]["x"], 32, "\0", STR_PAD_LEFT) .
                str_pad($detail["ec"]["y"], 32, "\0", STR_PAD_LEFT),
        ),
        "+/",
        "-_",
    ),
    "=",
);
foreach (["notify-reader", "notify-later", "notify-foreign-reader"] as $actor) {
    (new PushNotifications($db, $actor))->save([
        "endpoint" => "https://fcm.googleapis.com/fcm/send/synthetic-" . $actor,
        "keys" => [
            "p256dh" => $publicKey,
            "auth" => rtrim(
                strtr(base64_encode(random_bytes(16)), "+/", "-_"),
                "=",
            ),
        ],
    ]);
}
$reader = new ManagerNotifications($db, "notify-reader");
$later = new ManagerNotifications($db, "notify-later");
$reader->save(["events"], 0);
$later->save([], 0);
(new ManagerNotifications($db, "notify-foreign-reader"))->save(["events"], 0);
$deny(
    fn() => (new ManagerNotifications($db, "notify-editor"))->save(
        ["events"],
        0,
    ),
    "editor cannot opt into private member notifications",
);
$nativeClub = ManagerBridge::clubKey("notify-a");
$nativeActor = ManagerBridge::actorKey("notify-admin", "notify-a");
Db::run($db, "INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)", [
    $nativeActor,
    $nativeClub,
    "Synthetischer früherer Redakteur",
]);
foreach (["read", "edit", "publish", "manage"] as $cap) {
    Db::run($db, "INSERT INTO native_grants VALUES(?,'website',?)", [
        $nativeActor,
        $cap,
    ]);
}
$nativeAccess = new Access($db, $nativeActor);
$pages = new Pages($db, $nativeAccess);
$count = static fn(string $club = "notify-a"): int => (int) Db::run(
    $db,
    "SELECT COUNT(*) FROM manager_notifications WHERE club_id=? AND topic='events'",
    [$club],
)->fetchColumn();
$states = static fn(string $id): array => Db::run(
    $db,
    "SELECT d.state FROM manager_push_deliveries d JOIN manager_notifications n ON n.id=d.notification_id WHERE n.club_id='notify-a' AND n.source_id=? ORDER BY d.subscription_id",
    ["website:" . $id],
)->fetchAll(PDO::FETCH_COLUMN);
$counter = 0;
$future = intdiv(time(), 60) * 60 + 10 * 86400;
$make = static function (
    string $title,
    ?int $start = null,
    string $status = "scheduled",
) use (&$pages, &$counter, $future): array {
    $start ??= $future;
    $date = new DateTimeImmutable("@" . $start);
    return $pages->save([
        "slug" => "notification-event-" . ++$counter,
        "template" => "event",
        "title" => $title,
        "body" => "Privater Veranstaltungsinhalt",
        "event_start" => $date
            ->setTimezone(new DateTimeZone("Europe/Berlin"))
            ->format("Y-m-d\TH:i"),
        "event_start_utc" => $date
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z"),
        "event_timezone" => "Europe/Berlin",
        "event_status" => $status,
    ]);
};
$publish = static function (array $page) use (&$pages): array {
    return $pages->publish($page["id"], $page["version"]);
};
$old = $publish($make("Früher öffentlich"));
$previous = $publish($make("Früher öffentlich, wieder offline"));
$previous = $pages->unpublish($previous["id"], $previous["version"]);
$draft = $make("Alter unveröffentlichter Entwurf");
$past = $publish($make("Historischer Termin", $future - 20 * 86400));
$archived = $make("Früher archivierter Entwurf");
$archived = $pages->archive($archived["id"], $archived["version"]);
$check(
    $count() === 0,
    "standalone native publication never invents a manager club or transport",
);
// Reconstruct the real previous-package state from untouched CMS originals and revision history.
$db->exec("DROP TABLE khcms_event_publications");
EventPublications::install($db);
$history = Db::run(
    $db,
    "SELECT * FROM khcms_event_publications ORDER BY club_id,page_id",
)->fetchAll();
EventPublications::install($db);
$check(
    $history ===
        Db::run(
            $db,
            "SELECT * FROM khcms_event_publications ORDER BY club_id,page_id",
        )->fetchAll() && $count() === 0,
    "schema baseline is persistent, repeatable and enqueue-free",
);
$check(
    (int) Db::run(
        $db,
        "SELECT published_seen FROM khcms_event_publications WHERE page_id=?",
        [$draft["id"]],
    )->fetchColumn() === 0,
    "baseline retains eligibility of a never-published draft",
);
// The synthetic previous-package fixture supplies its original binding explicitly; projection must not claim an unrelated native account.
Db::run(
    $db,
    "INSERT INTO native_manager_identities(native_id,manager_actor_id,manager_club_id) VALUES(?,?,?)",
    [$nativeActor, "notify-admin", "notify-a"],
);
$access = (new ManagerBridge(
    $db,
    "notify-admin",
    "native",
    static fn() => null,
))->access();
$foreign = (new ManagerBridge(
    $db,
    "notify-foreign-admin",
    "native",
    static fn() => null,
))->access();
$pages = new Pages($db, $access);
$old = $publish(
    $pages->save(
        array_replace($old, ["body" => "Öffentliche redaktionelle Korrektur"]),
        $old["version"],
    ),
);
$previous = $publish($previous);
$past = $publish(
    $pages->save(
        array_replace($past, [
            "event_start" => $old["event_start"],
            "event_start_utc" => $old["event_start_utc"],
        ]),
        $past["version"],
    ),
);
$archived = $publish($pages->unarchive($archived["id"], $archived["version"]));
$check(
    $count() === 0,
    "old originals, historical republish, date correction and archive restoration never announce anew",
);
$draft = $publish($draft);
$check(
    $count() === 1 && $states($draft["id"]) === ["pending"],
    "first publication of original old draft queues existing own opt-in only",
);
$event = $make("Erste neue Veröffentlichung");
$check($count() === 1, "saving a private event draft queues no hint");
$event = $publish($event);
$check(
    $count() === 2 &&
        $states($event["id"]) === ["pending"] &&
        $count("notify-b") === 0,
    "new own public event keeps original notification and recipient boundaries",
);
$deny(
    fn() => EventPublications::published($db, $nativeClub, $event["id"]),
    "observer cannot act outside publication transaction",
);
$deny(
    fn() => Db::atomic(
        $db,
        fn() => EventPublications::published(
            $db,
            $foreign->club(),
            $event["id"],
        ),
    ),
    "observer cannot accept a foreign original",
);
$event = $publish(
    $pages->save(
        array_replace($event, ["body" => "Freigegebene Korrektur"]),
        $event["version"],
    ),
);
$check(
    $count() === 2 && $states($event["id"]) === ["pending"],
    "editing and republishing never duplicate announcement",
);
$later->save(["events"], $later->state()["version"]);
$event = $publish($event);
$check(
    $states($event["id"]) === ["pending"],
    "late opt-in receives no replay for existing public event",
);
$rescheduled = new DateTimeImmutable("@" . ($future + 86400));
$event = $pages->save(
    array_replace($event, [
        "title" => "Späterer privater Veranstaltungstitel",
        "event_start" => $rescheduled
            ->setTimezone(new DateTimeZone("Europe/Berlin"))
            ->format("Y-m-d\TH:i"),
        "event_start_utc" => $rescheduled
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z"),
    ]),
    $event["version"],
);
$stored = static fn(): int => strtotime(
    Db::run(
        $db,
        "SELECT starts_at FROM manager_notifications WHERE club_id='notify-a' AND source_id=?",
        ["website:" . $event["id"]],
    )->fetchColumn(),
);
$check(
    $stored() === $future && $states($event["id"]) === ["pending"],
    "unpublished reschedule cannot change current public notification",
);
$publicState = (new \Kulturhus\Native\Cms\Events(
    $db,
    $nativeClub,
))->publicationState($event["id"]);
$check(
    $publicState["title"] === "Erste neue Veröffentlichung" &&
        !str_contains(
            json_encode($publicState),
            "Späterer privater Veranstaltungstitel",
        ) &&
        !array_key_exists("body", $publicState),
    "delivery metadata uses published title and excludes private draft and article body",
);
$event = $publish($event);
$check(
    $stored() === $future + 86400 && $states($event["id"]) === ["pending"],
    "published reschedule updates validity without new recipients",
);
$event = $pages->unpublish($event["id"], $event["version"]);
$check(
    $states($event["id"]) === ["cancelled"],
    "withdrawal cancels pending original hint",
);
$event = $publish($event);
$check(
    $count() === 2 && $states($event["id"]) === ["cancelled"],
    "republish does not restore cancelled delivery",
);
$cancelled = $publish($make("Zunächst abgesagt", null, "cancelled"));
$cancelled = $publish(
    $pages->save(
        array_replace($cancelled, ["event_status" => "scheduled"]),
        $cancelled["version"],
    ),
);
$check(
    $count() === 2,
    "cancelled first publication and later revival remain silent",
);
$sold = $publish($make("Zunächst ausverkauft", null, "sold_out"));
$sold = $publish(
    $pages->save(
        array_replace($sold, ["event_status" => "scheduled"]),
        $sold["version"],
    ),
);
$check(
    $count() === 2,
    "excluded first publication never becomes a new announcement after status editing",
);
$historical = $publish($make("Neuer Archivimport", $future - 20 * 86400));
$historical = $publish(
    $pages->save(
        array_replace($historical, [
            "event_start" => $old["event_start"],
            "event_start_utc" => $old["event_start_utc"],
        ]),
        $historical["version"],
    ),
);
$check(
    $count() === 2,
    "new past publication remains historical after future date correction",
);
$meta = $make("Explizite Archivquelle");
Db::run(
    $db,
    "INSERT INTO native_event_sources(club_id,source,external_id,page_id,source_hash,metadata_json,imported_version,state) VALUES(?,'meta-archive','meta:9876543210',?,?,?,?,'active')",
    [$nativeClub, $meta["id"], str_repeat("a", 64), "{}", 1],
);
$meta = $publish($meta);
$check(
    $count() === 2,
    "Meta archive source remains excluded even with a future displayed date",
);
$queued = $publish($make("Erst für beide Mitglieder"));
$check(
    $states($queued["id"]) === ["pending", "pending"],
    "new publication uses current two opt-ins as separate device originals",
);
$queued = $pages->archive($queued["id"], $queued["version"]);
$check(
    $states($queued["id"]) === ["cancelled", "cancelled"],
    "archive cancels both pending hints",
);
$queued = $publish($pages->unarchive($queued["id"], $queued["version"]));
$check(
    $states($queued["id"]) === ["cancelled", "cancelled"],
    "archive restoration never replays a hint",
);
$changedType = $publish($make("Termin wird normale Seite"));
$changedType = $publish(
    $pages->save(
        array_replace($changedType, ["template" => "standard"]),
        $changedType["version"],
    ),
);
$check(
    $states($changedType["id"]) === ["cancelled", "cancelled"],
    "publication as another content type withdraws event hints",
);
$provider = new Eventbrite($db, $access);
$source = Eventbrite::normalize([
    "id" => "1234567890",
    "url" => "https://www.eventbrite.com/e/synthetic-1234567890",
    "status" => "live",
    "name" => ["text" => "Anbieteroriginal"],
    "start" => [
        "utc" => gmdate("Y-m-d\TH:i:s\Z", $future),
        "timezone" => "Europe/Berlin",
    ],
    "end" => ["utc" => gmdate("Y-m-d\TH:i:s\Z", $future + 3600)],
]);
$before = $count();
$imported = $provider->import([$source])[0];
$check(
    $count() === $before,
    "actual Eventbrite import creates only a private draft",
);
$importedPage = $publish($pages->get($imported["id"]));
$check(
    $count() === $before + 1 &&
        $states($importedPage["id"]) === ["pending", "pending"],
    "first approved provider draft announces exactly once",
);
$provider->import([$source]);
$check(
    $count() === $before + 1,
    "identical provider reimport does not announce again",
);
$source["status"] = "canceled";
$provider->import([$source]);
$check(
    $states($importedPage["id"]) === ["cancelled", "cancelled"] &&
        !EventPublications::eligible($db, "notify-a", $importedPage["id"]),
    "provider cancellation and public cancellation notice cannot dispatch active hint",
);
$source["status"] = "live";
$provider->import([$source]);
$importedPage = $publish($pages->get($importedPage["id"]));
$check(
    $count() === $before + 1 &&
        $states($importedPage["id"]) === ["cancelled", "cancelled"],
    "provider revival and explicit republish preserve original first-publication history",
);
$foreignPages = new Pages($db, $foreign);
$foreignPage = $foreignPages->save([
    "slug" => "foreign-public-event",
    "title" => "Fremder Termin",
    "template" => "event",
    "event_start" => $old["event_start"],
    "event_start_utc" => $old["event_start_utc"],
]);
$foreignPage = $foreignPages->publish($foreignPage["id"], 1);
$check(
    !EventPublications::eligible($db, "notify-a", $foreignPage["id"]) &&
        $count("notify-b") === 1,
    "native delivery eligibility and fanout stay club bound",
);
$forgedBefore = $count();
ManagerNotifications::eventPublished(
    $db,
    "notify-a",
    "website:" . $foreignPage["id"],
    gmdate("c", $future),
);
$check(
    $count() === $forgedBefore,
    "native enqueue rejects a foreign original even with a plausible future timestamp",
);
$scheduled = $make("Geplante erste Veröffentlichung");
$scheduledCount = $count();
Db::run(
    $db,
    "INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)",
    [
        bin2hex(random_bytes(16)),
        $nativeActor,
        "scheduled@example.invalid",
        time(),
    ],
);
$schedule = new PublicationSchedule(
    $db,
    $access,
    static fn(): int => time() - 120,
);
$scheduledJob = $schedule->plan($scheduled["id"], 1, time() - 60);
$check(
    $count() === $scheduledCount && $states($scheduled["id"]) === [],
    "scheduled approval remains private and enqueue-free until execution",
);
$due = PublicationSchedule::runDue($db, limit: 1, club: $nativeClub);
$check(
    count($due) === 1 &&
        $due[0]["status"] === "published" &&
        $count() === $scheduledCount + 1 &&
        $states($scheduled["id"]) === ["pending", "pending"],
    "scheduled first publication composes with original topic fanout",
);
$check(
    PublicationSchedule::runDue($db, club: $nativeClub) === [] &&
        $count() === $scheduledCount + 1,
    "repeated scheduled worker cannot duplicate event announcement",
);
$rollback = $make("Atomare Veröffentlichung mit Hinweis");
$beforeRollback = $count();
$db->exec(
    "CREATE TRIGGER publication_notification_failure BEFORE INSERT ON manager_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic enqueue failure'",
);
try {
    $failed = false;
    try {
        $publish($rollback);
    } catch (PDOException) {
        $failed = true;
    }
    $check(
        $failed &&
            $pages->get($rollback["id"])["version"] === 1 &&
            $pages->publicPage($rollback["slug"]) === null &&
            $count() === $beforeRollback &&
            !Db::run(
                $db,
                "SELECT 1 FROM khcms_event_publications WHERE page_id=?",
                [$rollback["id"]],
            )->fetchColumn(),
        "enqueue failure rolls back public original, catalogue and first-publication history together",
    );
} finally {
    $db->exec("DROP TRIGGER publication_notification_failure");
}
$rollback = $publish($rollback);
$check(
    $count() === $beforeRollback + 1 &&
        $states($rollback["id"]) === ["pending", "pending"],
    "retry after atomic rollback creates one original announcement",
);
$calls = [];
$fake = static function (array $subscription, string $payload) use (
    &$calls,
): string {
    $calls[] = [$subscription["endpoint"], $payload];
    return "sent";
};
$sent = ManagerNotifications::dispatch($db, $fake, 20, "notify-a");
$check(
    $sent === 5 &&
        count($calls) === 5 &&
        json_decode($calls[0][1], true) === ["type" => "events"],
    "actual original dispatcher sends only generic topic and approved remaining own hint",
);
$check(
    ManagerNotifications::dispatch($db, $fake, 20, "notify-a") === 0 &&
        count($calls) === 5,
    "confirmed publication hint is never replayed",
);
$check(
    !str_contains(json_encode($calls), "Privater Veranstaltungsinhalt") &&
        !str_contains(json_encode($calls), "notify-foreign-reader"),
    "fake transport contains neither event body nor foreign device",
);
// Actual between-claim-and-send changes, using a second real connection and original queue.
$configuration = TestSupport::config();
$racing = new PublicationDispatchPDO(
    "mysql:host=127.0.0.1;port=13371;dbname=" .
        $configuration["database"] .
        ";charset=utf8mb4",
    $configuration["username"],
    $configuration["password"],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$racing->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
    PublicationDispatchStatement::class,
    [$racing],
]);
$race = $publish($make("Rückzug nach Claim"));
$racing->afterClaim = static function () use ($pages, $race): void {
    $pages->unpublish($race["id"], $race["version"]);
};
$check(
    ManagerNotifications::dispatch($racing, $fake, 20, "notify-a") === 0 &&
        count($calls) === 5 &&
        $states($race["id"]) === ["cancelled", "cancelled"],
    "withdrawal after actual claim stops before provider callback",
);
$later->save([], $later->state()["version"]);
$optOut = $publish($make("Widerruf nach Claim"));
$racing->afterClaim = static function () use ($reader): void {
    $reader->save([], $reader->state()["version"]);
};
$check(
    ManagerNotifications::dispatch($racing, $fake, 20, "notify-a") === 0 &&
        count($calls) === 5 &&
        $states($optOut["id"]) === ["cancelled"],
    "opt-out after actual claim stops before provider callback",
);
$reader->save(["events"], $reader->state()["version"]);
$device = $publish($make("Geräteabmeldung nach Claim"));
$racing->afterClaim = static function () use ($db): void {
    Db::run(
        $db,
        "DELETE FROM push_subscriptions WHERE actor_id='notify-reader'",
    );
};
$check(
    ManagerNotifications::dispatch($racing, $fake, 20, "notify-a") === 0 &&
        count($calls) === 5 &&
        $states($device["id"]) === [],
    "device removal after actual claim stops before provider callback",
);
$check(
    !class_exists("ProcessWire\\ProcessWire", false) &&
        !class_exists("ProcessWire\\Page", false),
    "all publication and delivery cases run without ProcessWire classes",
);
echo json_encode(
    ["checks" => $checks, "passed" => true, "live_transport" => false],
    JSON_THROW_ON_ERROR,
) . "\n";
