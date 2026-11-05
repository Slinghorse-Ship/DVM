<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, SecretBox, RuntimeSchema};
use Kulturhus\Native\Communication\{Attention, CheckSchedule, CheckPlans};
use Kulturhus\Native\Integration\{Accounts, Cooldown};

$db = TestSupport::db();
TestSupport::seed($db);
RuntimeSchema::install($db);
$a = new Access($db, "admin");
$club = $a->club();
$box = new SecretBox(str_repeat("v", 32));
$attention = new Attention($db, $box);
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
$entry = [
    "id" => "comment_1",
    "text" => "Private synthetische Rückfrage",
    "name" => "Testperson",
];
check(
    $attention->observe($a, "facebook", "comment", "post_1", [$entry], true) ===
        1,
    "new actual activity becomes a private review item",
);
$row = $attention->listing($a)[0];
$id = $row["id"];
$stored = Db::run($db, "SELECT * FROM native_social_attention WHERE id=?", [
    $id,
])->fetch();
check(
    !str_contains(json_encode($stored), $entry["text"]) &&
        !str_contains(json_encode($stored), $entry["name"]),
    "comment body and person encrypted in MySQL",
);
check(
    !hash_equals(
        $stored["fingerprint"],
        hash("sha256", json_encode($row["payload"], JSON_THROW_ON_ERROR)),
    ),
    "change fingerprint is keyed rather than guessable plaintext hash",
);
$attention->setState($a, $id, "reply_needed", 1);
$attention->setState($a, $id, "done", 2);
check(
    $attention->observe($a, "facebook", "comment", "post_1", [$entry], true) ===
        0 && count($attention->listing($a)) === 0,
    "unchanged reread preserves completed human decision",
);
$entry["text"] .= " · ergänzt";
$attention->observe($a, "facebook", "comment", "post_1", [$entry], true);
check(
    $attention->listing($a)[0]["state"] === "new" &&
        (int) $attention->listing($a)[0]["version"] === 4,
    "edited provider comment reopens review with a new version",
);
denied(
    fn() => $attention->setState($a, $id, "done", 3),
    "stale response decision cannot overwrite new provider content",
);
denied(
    fn() => $attention->listing(new Access($db, "reader")),
    "ordinary social read cannot decrypt private activity",
);
denied(
    fn() => $attention->setState(new Access($db, "outsider"), $id, "done", 4),
    "review mutation stays inside the current club",
);
check(
    $attention->listing(new Access($db, "outsider"), "all") === [],
    "another club sees no private review entries",
);
$attention->observe($a, "facebook", "comment", "post_1", [], true);
check(
    $attention->listing($a, "all") === [],
    "complete empty snapshot removes deleted provider comments",
);
$reaction = [
    "id" => "reaction-summary",
    "count" => 0,
    "text" => "Reaktionen: 0",
];
check(
    $attention->observe($a, "instagram", "reaction", "post_2", [$reaction]) ===
        0,
    "zero initial reactions create no fictitious incoming activity",
);
$reaction["count"] = 2;
$reaction["text"] = "Reaktionen: 2";
$attention->observe($a, "instagram", "reaction", "post_2", [$reaction]);
$row = $attention->listing($a)[0];
$attention->setState($a, $row["id"], "done", 1);
$reaction["count"] = 0;
$reaction["text"] = "Reaktionen: 0";
$attention->observe($a, "instagram", "reaction", "post_2", [$reaction]);
check(
    $attention->listing($a)[0]["payload"]["count"] === 0,
    "actual decrease in reactions updates prior evidence",
);

$hourly=CheckSchedule::config('stadtlandfunk',[]);
check($hourly===['mode'=>'hourly','day_start'=>8,'day_end'=>22],'personal channel defaults to one randomized check per daytime hour');
check(CheckSchedule::config('stadtlandfunk',['day_start'=>' 08 ','day_end'=>'22'])===$hourly,'normal hour notation 08 and its original spaces normalize without changing the daytime range');
denied(fn()=>CheckSchedule::stored('stadtlandfunk',['mode'=>'unknown','windows'=>['morning'=>[8,11]]]),'unknown stored schedule formats are not silently reinterpreted');
denied(fn()=>CheckSchedule::config('stadtlandfunk',['day_start'=>22,'day_end'=>8]),'inverted daytime range rejected');
denied(fn()=>CheckSchedule::config('meta',['interval_seconds'=>5]),'rapid five-second provider polling rejected');
$zone=new DateTimeZone('Europe/Berlin');$utc=new DateTimeZone('UTC');
foreach(['2026-03-29','2026-10-25']as$date){
 $cursor=new DateTimeImmutable($date.' 07:59:59',$zone);$offsets=[];$completed=null;$previousMinute=null;
 for($hour=8;$hour<22;$hour++){
  $next=CheckSchedule::next('stadtlandfunk',$hourly,$cursor,$offsets,$completed);$actual=(new DateTimeImmutable($next['at'],$utc))->setTimezone($zone);
  check($actual->format('Y-m-d')===$date&&(int)$actual->format('G')===$hour&&(int)$actual->format('i')<=58&&$actual->format('s')==='00'&&$actual>$cursor,'exactly the next daytime hour is randomly scheduled across DST '.$date.'/'.$hour);
  if($previousMinute!==null)check((int)$actual->format('i')!==$previousMinute,'consecutive daytime hours do not reuse the chosen minute '.$date.'/'.$hour);
  $previousMinute=(int)$actual->format('i');$cursor=$actual;$offsets=$next['offsets'];$completed=$next['slot'];
 }
 $next=CheckSchedule::next('stadtlandfunk',$hourly,$cursor,$offsets,$completed);$actual=(new DateTimeImmutable($next['at'],$utc))->setTimezone($zone);
 check($actual->format('Y-m-d')===(new DateTimeImmutable($date,$zone))->modify('+1 day')->format('Y-m-d')&&(int)$actual->format('G')===8,'no night check follows the final daytime hour '.$date);
}
foreach(['2026-10-09T00:00:00Z','2026-10-09T22:00:00Z']as$time){$now=new DateTimeImmutable($time);$next=CheckSchedule::next('meta',['interval_seconds'=>300],$now);check(strtotime($next['at'].' UTC')===$now->getTimestamp()+300,'Meta continues its interval at night '.$time);}
$legacy=['windows'=>['morning'=>[8,11],'evening'=>[18,22]]];$copy=serialize($legacy);
check(CheckSchedule::stored('stadtlandfunk',$legacy)===$hourly&&serialize($legacy)===$copy,'earlier daily windows become one hourly daytime span without changing their original data');
$next=CheckSchedule::next('stadtlandfunk',$hourly,new DateTimeImmutable('2026-10-09T07:20:00Z'),[], '2026-10-09:hour-09');
check($next['slot']==='2026-10-09:hour-10','an already completed current hour is skipped without skipping the next daytime hour');

$plans = new CheckPlans($db);
check(
    \Kulturhus\Native\Integration\Routes::result([
        "view" => "social",
        "section" => "review",
    ])["section"] === "review",
    "review save has an allowed integrated return route",
);
check(
    \Kulturhus\Native\Integration\Routes::result([
        "view" => "social",
        "section" => "events",
    ])["section"] === "events",
    "own event selector has an allowed integrated return route",
);
$now = new DateTimeImmutable("2026-10-09T07:00:00Z");
$plans->save($a, "meta", ["interval_seconds" => 300], true, 0, $now);
$plans->save($a, "stadtlandfunk", [], true, 0, $now);
check(
    $plans->status($a, "meta")["next_at"] === "2026-10-09 07:05:00",
    "Meta cadence is independent of personal daily windows",
);
denied(
    fn() => $plans->save($a, "meta", [], false, 0, $now),
    "stale plan edit rejected",
);
denied(
    fn() => $plans->save(new Access($db, "editor"), "meta", [], true, 0, $now),
    "redaction rights cannot activate background reads",
);
$due = static function (string $channel) use ($db, $club): void {
    Db::run(
        $db,
        "UPDATE native_social_check_plans SET next_at='2026-10-08 00:00:00' WHERE club_id=? AND channel=?",
        [$club, $channel],
    );
};
$due("meta");
$due("stadtlandfunk");
$calls = [];
$result = $plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
        return true;
    },
    $now,
);
check(
    $calls === ["meta", "stadtlandfunk"] &&
        $result["stadtlandfunk"] === "checked",
    "both due channels checked through current actor authority",
);
check(
    $plans->status($a, "stadtlandfunk")["last_checked"] ===
        "2026-10-09 07:00:00",
    "successful check time saved",
);
$due("stadtlandfunk");
$calls = [];
$plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
    },
    $now->modify("+1 minute"),
);
check(
    $calls === [],
    "restart does not repeat the personal check in an already completed hour",
);
$due("stadtlandfunk");
$plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
    },
    new DateTimeImmutable("2026-10-09T14:00:00Z"),
);
check(
    in_array("stadtlandfunk", $calls, true),
    "the formerly empty afternoon now has its own hourly check",
);
$due('stadtlandfunk');$nightCalls=[];
$plans->run($club,function($access,$channel)use(&$nightCalls){if($channel==='stadtlandfunk')$nightCalls[]=$channel;},new DateTimeImmutable('2026-10-09T21:30:00Z'));
check($nightCalls===[],'an overdue personal check is rescheduled at night without any provider read');
$due("meta");
$calls = [];
$plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
        return false;
    },
    $now,
);
check(
    $calls === ["meta"] &&
        $plans->status($a, "meta")["state"] === "needs_review",
    "partially supported API read reports review instead of complete success",
);
$due("meta");
$wait = Cooldown::forResponse([
    "status" => 429,
    "headers" => ["retry-after" => "172800"],
]);
check(
    $wait->seconds === 172800,
    "provider pause longer than a day is preserved",
);
$plans->run(
    $club,
    static function () use ($wait) {
        throw $wait;
    },
    $now,
);
check(
    $plans->status($a, "meta")["state"] === "cooldown" &&
        $plans->status($a, "meta")["next_at"] === "2026-10-11 07:00:00",
    "durable next API read respects actual provider cooldown",
);
$due("meta");
$other = TestSupport::db();
$lock = "social-check:" . substr(hash("sha256", $club . ":meta"), 0, 48);
Db::run($other, "SELECT GET_LOCK(?,0)", [$lock]);
$calls = [];
$plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
    },
    $now,
);
Db::run($other, "SELECT RELEASE_LOCK(?)", [$lock]);
check(
    $calls === [],
    "another worker cannot read a channel while its durable semaphore is held",
);
$due("meta");
$plans->run(
    $club,
    function ($access, $channel) use ($plans, $now) {
        $plans->save($access, $channel, [], false, 1, $now);
        throw new RuntimeException("Synthetic delayed failure");
    },
    $now,
);
check(
    !$plans->status($a, "meta")["enabled"] &&
        $plans->status($a, "meta")["state"] === "paused",
    "delayed failure cannot overwrite a concurrently paused plan",
);
$plans->save($a, "meta", [], true, 2, $now);
$due("meta");
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'",
);
$calls = [];
$plans->run(
    $club,
    function ($access, $channel) use (&$calls) {
        $calls[] = $channel;
    },
    $now,
);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','social','manage')",
);
check(
    $calls === [] &&
        !$plans->status($a, "meta")["enabled"] &&
        $plans->status($a, "meta")["state"] === "needs_review",
    "revoked live rights stop and pause a due provider check",
);
$legacyJson=json_encode(['windows'=>['morning'=>[8,11],'evening'=>[18,22]]],JSON_THROW_ON_ERROR);
Db::run($db,"UPDATE native_social_check_plans SET config_json=?,offsets_json='{}',last_slot='2026-10-09:evening',last_checked='2026-10-09 18:00:00',next_at='2026-10-10 17:00:00',state='scheduled' WHERE club_id=? AND channel='stadtlandfunk'",[$legacyJson,$club]);
$legacyRaw=Db::run($db,"SELECT config_json FROM native_social_check_plans WHERE club_id=? AND channel='stadtlandfunk'",[$club])->fetchColumn();$legacyCalls=[];$legacyNow=new DateTimeImmutable('2026-10-10T07:00:00Z');
$plans->run($club,function($access,$channel)use(&$legacyCalls){$legacyCalls[]=$channel;},$legacyNow);
$adjusted=$plans->status($a,'stadtlandfunk');$adjustedAt=new DateTimeImmutable($adjusted['next_at'],$utc);
check($legacyCalls===[]&&$adjustedAt>$legacyNow&&(int)$adjustedAt->setTimezone($zone)->format('G')===9,'first ordinary cron pass replans an old future daily slot within the current hour without an immediate read');
check(Db::run($db,"SELECT config_json FROM native_social_check_plans WHERE club_id=? AND channel='stadtlandfunk'",[$club])->fetchColumn()===$legacyRaw,'hourly adoption preserves the exact earlier saved configuration bytes');
$plans->run($club,function($access,$channel)use(&$legacyCalls){$legacyCalls[]=$channel;},$adjustedAt);
check($legacyCalls===['stadtlandfunk'],'the newly selected random hourly time performs exactly one actual check');
$due('stadtlandfunk');$legacyCalls=[];$plans->run($club,function($access,$channel)use(&$legacyCalls){$legacyCalls[]=$channel;},$adjustedAt->modify('+10 seconds'));
check($legacyCalls===[],'a restart in the same adopted hour cannot perform a second check');
Db::run($db,"UPDATE native_social_check_plans SET offsets_json='{}',next_at='2026-10-12 07:00:00',state='cooldown' WHERE club_id=? AND channel='stadtlandfunk'",[$club]);
$plans->run($club,function($access,$channel)use(&$legacyCalls){$legacyCalls[]=$channel;},$legacyNow);
check($legacyCalls===[]&&$plans->status($a,'stadtlandfunk')['next_at']==='2026-10-12 07:00:00'&&$plans->status($a,'stadtlandfunk')['state']==='cooldown','hourly adoption never shortens an existing provider cooldown');
$dailyStart=new DateTimeImmutable('2026-11-04 07:59:59',$zone);$dailyVersion=$plans->status($a,'stadtlandfunk')['version'];$plans->save($a,'stadtlandfunk',[],true,$dailyVersion,$dailyStart);$dailyCalls=[];
for($hour=8;$hour<22;$hour++){
 $at=new DateTimeImmutable($plans->status($a,'stadtlandfunk')['next_at'],$utc);
 check($at->setTimezone($zone)->format('Y-m-d')==='2026-11-04'&&(int)$at->setTimezone($zone)->format('G')===$hour,'stored scheduler chooses the actual due time in each daytime hour '.$hour);
 $plans->run($club,function($access,$channel)use(&$dailyCalls){if($channel==='stadtlandfunk')$dailyCalls[]=$channel;return true;},$at);
 check(count($dailyCalls)===$hour-7,'actual stored hourly claim invokes its provider callback exactly once in hour '.$hour);
}
$afterDay=new DateTimeImmutable($plans->status($a,'stadtlandfunk')['next_at'],$utc);check($afterDay->setTimezone($zone)->format('Y-m-d H')==='2026-11-05 08','stored scheduler carries its final daytime hour to the next morning');
$restartAt=new DateTimeImmutable('2026-11-05 16:20:00',$zone);$plans->run($club,function($access,$channel)use(&$dailyCalls){if($channel==='stadtlandfunk')$dailyCalls[]=$channel;return true;},$restartAt);$afterRestart=new DateTimeImmutable($plans->status($a,'stadtlandfunk')['next_at'],$utc);
check(count($dailyCalls)===15&&(int)$afterRestart->setTimezone($zone)->format('G')===17,'a late restart performs one current-hour read and schedules the next hour without replaying missed hours');
$accounts = new Accounts($db, $box);
$accounts->save(
    $a,
    "meta",
    [
        "page_id" => "123",
        "instagram_id" => "456",
        "api_version" => "v25.0",
        "token" => "synthetic-only",
    ],
    0,
);
Db::run(
    $db,
    "INSERT INTO native_social_checks(club_id,feature,state,checked_at) VALUES(?,'stadtlandfunk-events','available',UTC_TIMESTAMP())",
    [$club],
);
$attention->observe($a, "stadtlandfunk", "comment", "own_event", [
    ["id" => "personal-comment", "text" => "Privat"],
]);
$accounts->disconnect($a, "meta", 1);
check(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM native_social_checks WHERE feature='stadtlandfunk-events'",
    )->fetchColumn() === 1 && count($attention->listing($a)) === 1,
    "disconnecting Meta preserves independent personal channel activity",
);
echo "$n native social attention and scheduling checks passed; synthetic MySQL and no live requests.\n";
