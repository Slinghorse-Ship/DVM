<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Communication\{Campaigns, Schema, Subscribers, Workspace};
use Kulturhus\Native\Communication\Digests\{Plans, Schedule};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Delivery\Outbox;
$db = TestSupport::db();
if (($argv[1] ?? "") === "--plan-worker") {
    echo count(
        Campaigns::runDigests(
            $db,
            new SecretBox(str_repeat("d", 32)),
            new DateTimeImmutable($argv[2]),
        ),
    );
    exit();
}
TestSupport::seed($db);
Schema::install($db);
Kulturhus\Native\Cms\Schema::install($db);
$count = 0;
function check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $count++;
    echo "PASS " . $label . PHP_EOL;
}
function denied(callable $action, string $label): void
{
    try {
        $action();
    } catch (DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
function at(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone("Europe/Berlin"));
}
$window = Schedule::at("calendar", at("2026-09-23 12:00"));
check(
    $window["period_key"] === "month-2026-09" && !$window["ready"],
    "no late current-period preparation",
);
$window = Schedule::at("calendar", at("2026-09-24 09:59:59"));
check(!$window["ready"], "preparation honors the exact 10:00 Berlin boundary");
$window = Schedule::at("calendar", at("2026-09-24 10:00"));
check(
    $window["period_key"] === "quarter-2026-Q4" &&
        $window["ready"] &&
        str_starts_with($window["end"], "2027-01-01"),
    "next quarter prepared seven days before its first month",
);
check(
    $window["send_at"] === "2026-10-01T10:00:00+02:00" &&
        $window["deadline"] === "2026-10-03T23:59:59+02:00",
    "three-day Berlin sending window preserved",
);
$window = Schedule::at("calendar", at("2026-10-25 09:00:00Z"));
check(
    $window["period_key"] === "month-2026-11" &&
        $window["prepare_at"] === "2026-10-25T10:00:00+01:00",
    "preparation survives Berlin daylight-saving change",
);
check(
    Schedule::at("calendar", at("2026-12-25 10:00"))["period_key"] ===
        "quarter-2027-Q1",
    "quarter and year rollover",
);
check(
    Schedule::at("calendar", at("2026-10-20 10:00"), 12, 0)["send_at"] ===
        "2026-11-01T00:00:00+01:00",
    "explicit days and midnight hour supported",
);
denied(
    fn() => Schedule::at("calendar", at("2026-10-01"), 0, 10),
    "invalid preparation settings rejected",
);
denied(fn() => Schedule::key("month-2026-13"), "invalid period key rejected");
$access = new Access($db, "admin");
$club = $access->club();
$box = new SecretBox(str_repeat("d", 32));
$plans = new Plans($db, $access);
$photoRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/digest-photo';mkdir($photoRoot,0700,true);$campaigns = new Campaigns($db, $access, $box,$photoRoot.'/media');
(new Identity($db, $access))->save(
    [
        "name" => "Perioden-Prüfverein",
        "address" => "Testweg 1",
        "origin" => "https://example.invalid",
    ],
    0,
);
$pages = new Pages($db, $access);
$year = (int) gmdate("Y") + 2;
$key = "quarter-" . $year . "-Q1";
$event = static function (
    string $slug,
    string $date,
    bool $publish = true,
    string $body = "Originaltermin",
) use ($pages): array {
    $p = $pages->save([
        "slug" => $slug,
        "title" => $slug,
        "template" => "event",
        "body" => $body,
        "event_start" => $date,
        "event_location" => "Testsaal",
    ]);
    return $publish ? $pages->publish($p["id"], $p["version"]) : $p;
};
$jan = $event("januar", $year . "-01-18T19:00");
$photo=imagecreatetruecolor(40,30);imagepng($photo,$photoRoot.'/event.png');unset($photo);
$asset=(new \Kulturhus\Native\Cms\Media($db,$access,$photoRoot.'/media'))->upload($photoRoot.'/event.png','event.png','Eigenes Veranstaltungsfoto');
$jan['media_ids']=[$asset['id']];$jan=$pages->save($jan,$jan['version']);$jan=$pages->publish($jan['id'],$jan['version']);
$feb = $event("februar", $year . "-02-15T19:00");
$march = $event("maerz", $year . "-03-10T19:00");
$event("privater-entwurf", $year . "-02-20T19:00", false);
$event("naechstes-quartal", $year . "-04-01T00:00");
$blocks1850=\Kulturhus\Native\Communication\NewsletterEvents::catalogue($db,$access,at($year.'-01-01'));
check(count($blocks1850)===4&&$blocks1850[0]['title']==='januar'&&$blocks1850[0]['media_ref']===$asset['id']&&$blocks1850[0]['url']==='https://example.invalid/januar/','automatic editor catalogue reads chronological published events with their actual photo and canonical URL, omitting private drafts');
$catalogueBefore=$pages->get($jan['id']);$eventEdition=$work1850=new Workspace($db,$access,'newsletter',$photoRoot.'/media');$frozen1850=$eventEdition->save(['title'=>'Own frozen visual event editor','blocks'=>[$blocks1850[0]]]);$proofHtml1850=\Kulturhus\Native\Communication\Content::preview($frozen1850,static fn(string $id):string=>'https://example.invalid/test-image/'.$id);
check(str_contains($proofHtml1850,'https://example.invalid/januar/')&&str_contains($proofHtml1850,$asset['id'])&&\Kulturhus\Native\Communication\ImageUsage::refs($frozen1850)===[$asset['id']]&&$pages->get($jan['id'])===$catalogueBefore,'saved automatic event block retains its own photo and link through the real mail renderer without modifying its CMS source');
$campaigns->planDigest("calendar");
check(
    $plans->state("calendar")["version"] === 1,
    "versioned calendar plan enabled",
);
denied(
    fn() => $campaigns->planDigest("calendar", false, 0),
    "stale plan cannot overwrite current settings",
);
denied(
    fn() => $campaigns->planDigest("month"),
    "calendar and old explicit plans cannot be active together",
);
$preparation = at($year - 1 . "-12-25 10:00");
$plans->pause("calendar", $key, true, 0);
check(
    Campaigns::runDigests($db, $box, $preparation) === [],
    "single period can be paused before draft creation",
);
$plans->pause("calendar", $key, false, 1);
denied(
    fn() => $plans->pause("calendar", $key, true, 1),
    "stale single-period pause rejected",
);
check(
    Campaigns::runDigests($db, $box, $preparation->modify("-1 second")) === [],
    "worker creates no premature quarter draft",
);
$items = Campaigns::runDigests($db, $box, $preparation);
check(count($items) === 1, "one published quarter draft created");
$work = new Workspace($db, $access, "newsletter");
$item = $work->get($items[0]);
check(
    $item["status"] === "draft" &&
        $item["approved_by"] === null &&
        count($item["blocks"]) === 4,
    "published originals only, no implicit approval",
);
check(
    $item["blocks"][1]["date"] === "18.01." . $year . " 19:00 Uhr" && $item['blocks'][0]['type']==='image' && $item['blocks'][0]['media_ref']===$asset['id'],
    "newsletter date rendered in Berlin time",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_delivery_jobs")
        ->fetchColumn() === 0,
    "preparation never creates delivery jobs",
);
check(
    Campaigns::runDigests($db, $box, $preparation->modify("+1 day")) === [],
    "repeat preparation preserves the same original draft",
);
$edition = Plans::edition($db, $club, $item["id"]);
check(
    count($edition["events"]) === 3 &&
        $edition["events"][0]["id"] === $jan["id"],
    "edition pins actual published source identities",
);
$item["blocks"] = array_slice($item["blocks"], 0, 3);
$item = $work->save($item, $item["version"]);
$item = $work->approve($item["id"], $item["version"]);
$subscribers = new Subscribers($db, $access);
$subscribers->recordWrittenConsent(
    "periods@example.invalid",
    "Testperson",
    ["events"],
    "synthetic written consent",
);
$send = at($year . "-01-01 10:00");
$due = $send->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");
denied(
    fn() => $campaigns->queue(
        $item["id"],
        $send
            ->modify("-1 second")
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d H:i:s"),
    ),
    "approved edition cannot be scheduled before its window",
);
denied(
    fn() => $campaigns->queue(
        $item["id"],
        at($year . "-01-04")
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d H:i:s"),
    ),
    "approved edition cannot be scheduled after its deadline",
);
$jobs = $campaigns->queue($item["id"], $due);
check(
    count($jobs) === 1,
    "approved period edition queues one consented recipient",
);
$makeDue = static function () use ($db): void {
    $db->exec(
        "UPDATE native_delivery_jobs SET due_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE status='pending'",
    );
};
$makeDue();
$calls = 0;
$transport = static function () use (&$calls): string {
    $calls++;
    return "synthetic-confirmed-mail";
};
$worker = new Outbox($db, $box, static fn() => $send);
$plans->pause("calendar", $key, true, 2);
$result = $worker->run($transport);
check(
    $calls === 0 &&
        $result[0]["status"] === "pending" &&
        $result[0]["reason"] === "digest_period_paused",
    "pause stops already queued mail without losing its original job",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_digest_announcements")
        ->fetchColumn() === 0,
    "paused jobs never count as announcements",
);
$plans->pause("calendar", $key, false, 3);
$makeDue();
$result = $worker->run($transport);
check(
    $calls === 1 && $result[0]["status"] === "sent",
    "unpaused job sends the same original once through synthetic transport",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_digest_announcements")
        ->fetchColumn() === 2,
    "only blocks retained in the approved edition count as announced",
);
check(
    $worker->run($transport) === [] && $calls === 1,
    "confirmed delivery cannot be replayed",
);
$new = $event("neuer-februartermin", $year . "-02-22T19:00");
$febItems = Campaigns::runDigests($db, $box, at($year . "-01-25 10:00"));
check(count($febItems) === 1, "next month still prepares a new edition");
$febItem = $work->get($febItems[0]);
check(
    array_column($febItem["blocks"], "title") === ["neuer-februartermin"],
    "actually announced quarter event omitted while new event remains",
);
$febItem = $work->approve($febItem["id"], $febItem["version"]);
$febSend = at($year . "-02-01 10:00");
$campaigns->queue(
    $febItem["id"],
    $febSend->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
);
$makeDue();
$result = (new Outbox($db, $box, static fn() => at($year . "-02-04")))->run(
    $transport,
);
check(
    $result[0]["status"] === "cancelled" &&
        $result[0]["reason"] === "digest_send_window_expired" &&
        $calls === 1,
    "expired queued edition never reaches transport",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_digest_announcements")
        ->fetchColumn() === 2,
    "expired delivery never suppresses later event announcements",
);
$marchItems = Campaigns::runDigests($db, $box, at($year . "-02-23 10:00"));
check(count($marchItems) === 1, "following monthly edition prepares");
$marchItem = $work->get($marchItems[0]);
check(
    array_column($marchItem["blocks"], "title") === ["maerz"],
    "event removed before quarter approval remains eligible",
);
$marchItem = $work->approve($marchItem["id"], $marchItem["version"]);
$marchSend = at($year . "-03-01 10:00");
$campaigns->queue(
    $marchItem["id"],
    $marchSend->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
);
$makeDue();
$result = (new Outbox($db, $box, static fn() => $marchSend))->run(
    static function () {
        throw new RuntimeException("synthetic ambiguous acceptance");
    },
);
check(
    $result[0]["status"] === "uncertain" &&
        (int) $db
            ->query("SELECT COUNT(*) FROM native_digest_announcements")
            ->fetchColumn() === 2,
    "uncertain transport is retained without claiming successful announcements",
);
$callsBefore = $calls;
(new Outbox($db, $box, static fn() => $marchSend))->run($transport);
check(
    $calls === $callsBefore,
    "uncertain period job is not automatically retried",
);
$campaigns->planDigest("calendar", true, 1);
check(
    Campaigns::runDigests($db, $box, at($year . "-03-25 10:00")) === [],
    "global plan pause stops future preparation",
);
$campaigns->planDigest("calendar", false, 2);
$children = [];
for ($i = 0; $i < 2; $i++) {
    $process = proc_open(
        [
            ...TestSupport::phpCommand(),
            __FILE__,
            "--plan-worker",
            $year . "-03-25T10:00:00+01:00",
        ],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
    );
    fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
$results = [];
foreach ($children as [$process, $pipes]) {
    $results[] = (int) trim(stream_get_contents($pipes[1]));
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(
        proc_close($process) === 0 && $errors === "",
        "independent concurrent planner completes",
    );
}
sort($results);
check($results === [0, 1], "concurrent workers create one new quarter edition");
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='newsletter' AND capability='manage'",
);
check(
    Campaigns::runDigests($db, $box, at($year . "-04-24 10:00")) === [],
    "live rights revocation stops later planning",
);
denied(
    fn() => $plans->pause("calendar", "month-" . $year . "-05", true, 0),
    "revoked administrator cannot pause another period",
);
Schema::install($db);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_digest_announcements")
        ->fetchColumn() === 2,
    "idempotent schema upgrade preserves original announcement history",
);
echo "$count native digest checks passed; actual MySQL, published CMS originals, explicit synthetic transport only.\n";
