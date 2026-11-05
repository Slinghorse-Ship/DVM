<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge, Accounts};
use Kulturhus\Native\Communication\Attention;
use Kulturhus\Native\Cms\Pages;
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException("Own synthetic manager fixture required.");
}
$db = TestSupport::db();
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$access = (new ManagerBridge(
    $db,
    "manager-admin",
    "native",
    static fn() => null,
))->access();
$club = $access->club();
$runtime =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/integrated-manager-runtime";
$box = SecretBox::fromFile($runtime . "/private.key");
$accounts = new Accounts($db, $box);
$status = $accounts->status($access, "meta");
$accounts->save(
    $access,
    "meta",
    [
        "page_id" => "123",
        "instagram_id" => "456",
        "api_version" => "v25.0",
        "token" => "Synthetic-browser-meta-secret",
    ],
    $status["version"],
);
$pages = new Pages($db, $access);
$existing = array_column($pages->list(), null, "slug");
$event =
    $existing["veranstaltungen/social-browser-original"] ??
    $pages->save([
        "slug" => "veranstaltungen/social-browser-original",
        "template" => "event",
        "title" => "Eigener Social-Browsertermin",
        "event_start" => "2050-01-03T19:00",
        "event_end" => "2050-01-03T20:00:00Z",
        "event_timezone" => "Europe/Berlin",
        "event_location" => "Vereinssaal",
        "event_address" => "Testweg 8",
        "body" => "Eigene veröffentlichte Originalangaben.",
    ]);
if ($event["published_revision"] === null) {
    $pages->publish($event["id"], $event["version"]);
}
$registry=new \Kulturhus\Verein\Registry($db,'manager-admin');
$calendar=$db->prepare("SELECT id FROM appointments WHERE club_id='manager-demo' AND title=?");$calendar->execute(['Kalender-Social-Browsertermin']);
if (!$calendar->fetchColumn()) $registry->saveAppointment(null,0,[
    'title'=>'Kalender-Social-Browsertermin','start'=>'2050-01-03T19:00','end'=>'2050-01-03T21:00',
    'location'=>'Eigener Vereinssaal','description'=>'Originaler Termin aus dem Vereinskalender.','activity_kind'=>'event',
],[]);
$data = [
    "username" => "synthetic@example.invalid",
    "password" => "Synthetic-browser-personal-secret",
    "access_token" => "Synthetic-browser-access-secret",
    "refresh_token" => "Synthetic-browser-refresh-secret",
    "expires_at" => time() + 7200,
    "refresh_expires_at" => time() + 86400,
    "account_id" => "person_browser",
    "home_area_id" => "area_browser",
    "home_area_name" => "Synthetischer Testort",
    "actor_id" => $access->user()["id"],
    "authority" => "manager",
    "connection_id" => "synthetic-browser-connection",
    "changed_at" => gmdate("Y-m-d H:i:s"),
    "checked_at" => gmdate("Y-m-d H:i:s"),
];
Db::run(
    $db,
    "INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,?) ON DUPLICATE KEY UPDATE version=version+1,ciphertext=VALUES(ciphertext)",
    [
        $club,
        $box->seal("api:" . $club . ":stadtlandfunk", $data),
        $access->user()["id"],
    ],
);
$attention = new Attention($db, $box);
$attention->observe(
    $access,
    "stadtlandfunk",
    "comment",
    "synthetic-event",
    [
        [
            "id" => "browser_private_comment",
            "text" => "Wann öffnet der Saal? <script>unsafe()</script>",
            "name" => "Synthetische Besucherin",
            "title" => "Eigener Social-Browsertermin",
        ],
    ],
    true,
);
$rows = $attention->listing($access, "all");
foreach ($rows as $row) {
    if ($row["channel"] === "stadtlandfunk") {
        $attention->setState($access, $row["id"], "new", (int) $row["version"]);
    }
}
echo "Explicit synthetic accounts, own published event and encrypted private comment ready; no provider request.\n";
