<?php declare(strict_types=1);
require __DIR__ . "/../src/bootstrap.php";

use Kulturhus\Verein\{CalendarFeed, Database, Inventory, Registry};
use Kulturhus\Verein\Tests\MySqlFixture;

$db = MySqlFixture::connect(":memory:");
Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic loan club'),('b','Synthetic other club');
INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('foreign','b','members',1),('member','a','member',0),('reader','a','member',0);
INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,inventory_access,loans_access,version) VALUES('a','loans','Loan reader','','read','none',0,'none','read',1);
INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('reader','a','loans',1)");
$checks = 0;
function check(bool $value, string $why): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($why);
    }
    ++$checks;
    echo "PASS $why\n";
}
function denied(callable $operation, string $why): void
{
    try {
        $operation();
    } catch (DomainException) {
        check(true, $why);
        return;
    }
    throw new RuntimeException($why);
}
$key = static fn(): string => bin2hex(random_bytes(16));
$today = new DateTimeImmutable(Inventory::today());
$from = $today->modify("first day of this month")->format("Y-m-d");
$to = $today->modify("first day of next month")->format("Y-m-d");
$days = static fn(int $n): string => (new DateTimeImmutable($from))
    ->modify(($n >= 0 ? "+" : "") . $n . " days")
    ->format("Y-m-d");
$inventory = new Inventory($db, "admin");
$registry = new Registry($db, "admin");
$person = $registry->saveContact(null, 0, "person", "Private borrower", "", "");
$location = $inventory->saveLocation(
    "",
    0,
    ["name" => "Synthetic warehouse"],
    $key(),
);
$item = $inventory->saveItem(
    "",
    0,
    ["code" => "CAL-1", "name" => "Synthetic tables", "unit" => "Stück"],
    $key(),
);
$inventory->move(
    $item,
    0,
    [
        "kind" => "receive",
        "quantity" => "10",
        "location" => $location,
        "reason" => "Synthetic existing stock",
    ],
    $key(),
);
$reserve = static function (string $start, string $due, string $purpose) use (
    $inventory,
    $person,
    $location,
    $item,
    $key,
): string {
    return $inventory->reserve(
        [
            "contact" => $person,
            "purpose" => $purpose,
            "notes" => "Private loan note excluded from calendar",
            "starts_on" => $start,
            "due_on" => $due,
            "lines" => [
                [
                    "item" => $item,
                    "location" => $location,
                    "quantity" => "1",
                    "stock_version" => (string) $inventory->item($item)[
                        "stock_version"
                    ],
                ],
            ],
        ],
        $key(),
    );
};
$loan = $reserve($from, $days(5), "Synthetic stage tables");
$same = $reserve($days(4), $days(4), "Same-day loan");
$boundary = $reserve($days(-1), $from, "Boundary loan");
$outside = $reserve($to, $to, "Outside month");
$foreignInventory = new Inventory($db, "foreign");
$foreignRegistry = new Registry($db, "foreign");
$foreignPerson = $foreignRegistry->saveContact(
    null,
    0,
    "person",
    "Other private borrower",
    "",
    "",
);
$foreignLocation = $foreignInventory->saveLocation(
    "",
    0,
    ["name" => "Other warehouse"],
    $key(),
);
$foreignItem = $foreignInventory->saveItem(
    "",
    0,
    ["code" => "OTHER-1", "name" => "Other tables"],
    $key(),
);
$foreignInventory->move(
    $foreignItem,
    0,
    [
        "kind" => "receive",
        "quantity" => "1",
        "location" => $foreignLocation,
        "reason" => "Other synthetic stock",
    ],
    $key(),
);
$foreignInventory->reserve(
    [
        "contact" => $foreignPerson,
        "purpose" => "Foreign loan",
        "starts_on" => $from,
        "due_on" => $days(5),
        "lines" => [
            [
                "item" => $foreignItem,
                "location" => $foreignLocation,
                "quantity" => "1",
                "stock_version" => "1",
            ],
        ],
    ],
    $key(),
);
$calendar = new CalendarFeed($db, "admin", $registry);
$reader = new CalendarFeed($db, "reader", new Registry($db, "reader"));
$member = new CalendarFeed($db, "member", new Registry($db, "member"));
$before = [];
foreach (
    [
        "inventory_loans",
        "inventory_events",
        "inventory_stock",
        "appointments",
        "audit_events",
    ]
    as $table
) {
    $before[$table] = MySqlFixture::rows($db, $table);
}
$rows = $calendar->between($from, $to, "loans");
check(
    $calendar->between($from, $to, "all") === $rows,
    "all calendar view includes the same canonical loan dates",
);
check(
    $member->between($from, $to, "all") === [],
    "all calendar view cannot bypass loan read access",
);
check(
    count($rows) === 4,
    "open canonical loans provide pickup and return planning dates",
);
check(
    $reader->between($from, $to, "loans") === $rows,
    "assigned loan read access uses the same source",
);
check(
    !isset($member->filters()["loans"]),
    "ordinary member sees no private loan filter",
);
denied(
    fn() => $member->between($from, $to, "loans"),
    "query parameter cannot grant loan access",
);
check(
    count(array_filter($rows, fn($r) => str_contains($r["id"], $same))) === 1,
    "same-day pickup and return share one dated entry",
);
check(
    str_contains(
        array_values(
            array_filter($rows, fn($r) => str_contains($r["id"], $same)),
        )[0]["title"],
        "Abholung / Rückgabe",
    ),
    "combined entry retains both planning meanings",
);
check(
    count(array_filter($rows, fn($r) => str_contains($r["id"], $boundary))) ===
        1 && !array_filter($rows, fn($r) => str_contains($r["id"], $outside)),
    "date range includes first day and excludes next-month boundary",
);
check(
    !str_contains(json_encode($rows), "Foreign loan") &&
        !str_contains(json_encode($rows), "Private borrower") &&
        !str_contains(json_encode($rows), "Private loan note"),
    "projection excludes foreign club, borrower identity and private notes",
);
foreach ($rows as $row) {
    check(
        $row["all_day"] &&
            $row["source_kind"] === "loans" &&
            $row["route"] === [
                "view" => "inventory",
                "tab" => "loans",
                "loan" => explode(":", $row["id"])[1],
            ],
        "all-day entry opens the original protected loan",
    );
    $local = (new DateTimeImmutable($row["starts_at"]))
        ->setTimezone(new DateTimeZone("Europe/Berlin"))
        ->format("Y-m-d");
    check(
        str_ends_with($row["id"], ":" . $local),
        "Berlin calendar date survives UTC storage",
    );
}
foreach ($before as $table => $records) {
    check(
        MySqlFixture::rows($db, $table) === $records,
        "calendar projection never writes " . $table,
    );
}
$inventory->transition(
    $loan,
    (int) $inventory->loan($loan)["version"],
    "extend",
    ["due_on" => $to, "reason" => "Synthetic revised return deadline"],
    $key(),
);
check(
    count(
        array_filter(
            $calendar->between($from, $to, "loans"),
            fn($r) => str_contains($r["id"], $loan),
        ),
    ) === 1,
    "changed return deadline leaves no stale copied date",
);
check(
    count(
        array_filter(
            $calendar->between($to, $days(40), "loans"),
            fn($r) => str_contains($r["id"], $loan),
        ),
    ) === 1,
    "new deadline appears immediately in its original range",
);
$inventory->transition(
    $loan,
    (int) $inventory->loan($loan)["version"],
    "issue",
    [
        "date" => $from,
        "reason" => "Synthetic actual handover",
        "current_place" => "Synthetic stage",
    ],
    $key(),
);
check(
    !array_filter(
        $calendar->between($from, $to, "loans"),
        fn($r) => str_contains($r["id"], $loan),
    ),
    "issued loan does not repeat its planned pickup",
);
$detail = $inventory->loan($loan);
$inventory->returnLoan(
    $loan,
    (int) $detail["version"],
    [
        "date" => $today->format("Y-m-d"),
        "reason" => "Synthetic actual full return",
        "lines" => [
            [
                "line" => $detail["lines"][0]["id"],
                "good" => "1",
                "damaged" => "0",
                "lost" => "0",
                "location" => $location,
            ],
        ],
    ],
    $key(),
);
check(
    !array_filter(
        $calendar->between($to, $days(40), "loans"),
        fn($r) => str_contains($r["id"], $loan),
    ),
    "completed return removes its open deadline",
);
$inventory->transition(
    $same,
    (int) $inventory->loan($same)["version"],
    "cancel",
    ["date" => $today->format("Y-m-d"), "reason" => "Synthetic cancellation"],
    $key(),
);
check(
    !array_filter(
        $calendar->between($from, $to, "loans"),
        fn($r) => str_contains($r["id"], $same),
    ),
    "cancelled reservation removes pickup and return",
);
$db->exec(
    "UPDATE settings_roles SET loans_access='none' WHERE id='loans' AND club_id='a'",
);
check(
    !isset($reader->filters()["loans"]),
    "role revocation removes existing feed filter immediately",
);
denied(
    fn() => $reader->between($from, $to, "loans"),
    "existing calendar cannot retain revoked loan rights",
);
check(
    MySqlFixture::foreignKeyErrors($db) === [],
    "calendar and original loan foreign keys remain consistent",
);
echo "$checks loan calendar checks passed; real synthetic stock and loan transitions, no provider actions.\n";
