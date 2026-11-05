<?php declare(strict_types=1);
require __DIR__ . "/../src/bootstrap.php";
require __DIR__ . "/../native/bootstrap.php";
use Kulturhus\Verein\{CalendarFeed, Database, Registry};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Communication\Digests\Plans;

$db = \Kulturhus\Verein\Tests\MySqlFixture::connect(":memory:");
Database::migrate($db);
\Kulturhus\Native\Shared\Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
$db->exec(
    "INSERT INTO clubs VALUES('a','Calendar test'),('b','Other club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('foreign','b','members',1),('member','a','member',0)",
);
$club = \Kulturhus\Native\Integration\ManagerBridge::clubKey("a");
Db::run(
    $db,
    "INSERT INTO native_users VALUES('calendar-admin',?,'Periodentest',1)",
    [$club],
);
foreach (["read", "edit", "manage"] as $cap) {
    Db::run(
        $db,
        "INSERT INTO native_grants VALUES('calendar-admin','newsletter',?)",
        [$cap],
    );
}
Db::run(
    $db,
    "INSERT INTO native_grants VALUES('calendar-admin','website','read')",
);
$access = new Access($db, "calendar-admin");
$plans = new Plans($db, $access);
$plans->save("calendar", false, 0);
$calendar = new CalendarFeed($db, "admin", new Registry($db, "admin"), $access);
$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
    echo "PASS " . $label . PHP_EOL;
}
function denied(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
$october = $calendar->between("2026-10-01", "2026-11-01", "newsletter");
check(
    count($october) === 3,
    "calendar combines quarter sending window and next month preparation",
);
check(
    count(
        array_filter(
            $october,
            fn($row) => str_contains($row["title"], "quarter-2026-Q4"),
        ),
    ) === 2,
    "quarter has one start and one deadline",
);
$prepare = array_values(
    array_filter(
        $october,
        fn($row) => str_contains($row["title"], "Ausgabe vorbereiten"),
    ),
)[0];
check(
    $prepare["starts_at"] === "2026-10-25T09:00:00Z" && !$prepare["all_day"],
    "Berlin DST preparation keeps its exact timestamp",
);
check(
    $prepare["route"] === ["view" => "newsletter", "section" => "planning"],
    "unprepared period opens the original planning view",
);
check(
    count($calendar->between("2026-09-24", "2026-09-25", "newsletter")) === 1,
    "half-open day range includes next-quarter preparation",
);
check(
    $calendar->between("2026-09-25", "2026-09-26", "newsletter") === [],
    "next day cannot repeat preparation",
);
$item = (new \Kulturhus\Native\Communication\Workspace(
    $db,
    $access,
    "newsletter",
))->save(["title" => "Originale Quartalsausgabe", "body" => "Originalentwurf"]);
Db::run(
    $db,
    "INSERT INTO native_digest_editions(club_id,period,period_key,item_id) VALUES(?,?,?,?)",
    [$club, "calendar", "quarter-2026-Q4", $item["id"]],
);
$row = $calendar->between("2026-10-01", "2026-10-02", "newsletter")[0];
check(
    $row["route"] === ["view" => "newsletter", "native_item" => $item["id"]],
    "prepared period links to the same original edition",
);
$plans->pause("calendar", "month-2026-11", true, 0);
$rows = $calendar->between("2026-10-01", "2026-11-01", "newsletter");
check(
    count(array_filter($rows, fn($row) => $row["location"] === "Pausiert")) ===
        1,
    "single period pause changes only its calendar dates",
);
$plans->save("calendar", false, 1, 8, 11);
$rows = $calendar->between("2026-10-01", "2026-11-01", "newsletter");
$prepare = array_values(
    array_filter(
        $rows,
        fn($row) => str_contains($row["title"], "Ausgabe vorbereiten"),
    ),
)[0];
check(
    $prepare["starts_at"] === "2026-10-24T08:00:00Z",
    "source settings change immediately moves the same preparation date",
);
check(
    $calendar->between("2026-10-25", "2026-10-26", "newsletter") === [],
    "old preparation date does not leave a copied appointment",
);
$plans->save("calendar", true, 2, 8, 11);
check(
    count(
        array_filter(
            $calendar->between("2026-10-01", "2026-11-01", "newsletter"),
            fn($row) => $row["location"] === "Pausiert",
        ),
    ) === 3,
    "global pause appears on all original plan dates",
);
check(
    (int) $db->query("SELECT COUNT(*) FROM appointments")->fetchColumn() ===
        0 &&
        (int) $db
            ->query("SELECT COUNT(*) FROM native_delivery_jobs")
            ->fetchColumn() === 0,
    "calendar projection never writes appointments or delivery jobs",
);
check(
    !preg_match("/ciphertext|password|subscriber|token/", json_encode($rows)),
    "calendar metadata contains no credential or recipient payloads",
);
denied(
    fn() => (new CalendarFeed(
        $db,
        "foreign",
        new Registry($db, "foreign"),
        $access,
    ))->between("2026-10-01", "2026-11-01", "newsletter"),
    "foreign-club plan projection rejected",
);
denied(
    fn() => (new CalendarFeed(
        $db,
        "member",
        new Registry($db, "member"),
    ))->between("2026-10-01", "2026-11-01", "newsletter"),
    "module filter cannot grant missing communication access",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='calendar-admin' AND module='newsletter' AND capability='read'",
);
denied(
    fn() => $calendar->between("2026-10-01", "2026-11-01", "newsletter"),
    "same feed recognizes current module revocation",
);
echo "$n newsletter calendar checks passed; actual MySQL originals, no mail or copied appointments.\n";
