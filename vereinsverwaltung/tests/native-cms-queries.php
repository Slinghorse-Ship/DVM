<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema, DataModel, Pages, Selectors, QueryOrder};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\{WebsiteWorkspace};
use Kulturhus\Native\Web\Website\PageSearch;

$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$n = 0;
function queryCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function queryReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $e) {
        queryCheck($e instanceof $class, $label . " (" . get_class($e) . ")");
        return;
    }
    throw new RuntimeException($label);
}
function slugs(array $rows): array
{
    return array_column($rows, "slug");
}
$fields = [];
foreach (
    [
        "number" => "integer",
        "price" => "decimal",
        "flag" => "boolean",
        "notes" => "richtext",
        "word" => "text",
        "day" => "date",
    ]
    as $key => $type
) {
    $fields[$key] = $model->saveField([
        "key" => $key,
        "label" => "Query " . $key,
        "type" => $type,
    ]);
}
$model->saveTemplate([
    "key" => "query_page",
    "label" => "Typed query page",
    "renderer" => "standard",
    "fields" => array_keys($fields),
]);
$source = [];
foreach (
    [
        [
            "a",
            10,
            "9007199254740993.000002",
            true,
            "<p>Zebra <strong>Treffen</strong></p>",
            "null",
            "2050-12-31",
        ],
        [
            "b",
            2,
            "9007199254740993.000001",
            false,
            "<p>Äpfel <em>&amp; Begegnung</em></p>",
            "Ärger",
            "2050-01-01",
        ],
        ["c", -2, "-0.100001", null, "<p>Alpha</p>", "zebra", "2049-01-01"],
        ["d", null, null, null, null, null, null],
    ]
    as [$suffix, $number, $price, $flag, $notes, $word, $day]
) {
    $source[$suffix] = $pages->save([
        "title" => "Same title",
        "slug" => "query-" . $suffix,
        "template" => "query_page",
        "custom_fields" => compact(
            "number",
            "price",
            "flag",
            "notes",
            "word",
            "day",
        ),
    ]);
}
$base = [["field" => "template", "op" => "eq", "value" => "query_page"]];
$order = static fn(
    $field,
    $direction = "asc",
    $nulls = "last",
    $case = false,
) => [
    [
        "field" => $field,
        "direction" => $direction,
        "nulls" => $nulls,
        "case_fold" => $case,
    ],
];
queryCheck(
    slugs($pages->search($base)) === [
        "query-a",
        "query-b",
        "query-c",
        "query-d",
    ],
    "previous default slug ordering remains exact",
);
foreach (
    [
        [
            "custom.number",
            "asc",
            "last",
            ["query-c", "query-b", "query-a", "query-d"],
        ],
        [
            "custom.number",
            "desc",
            "last",
            ["query-a", "query-b", "query-c", "query-d"],
        ],
        [
            "custom.number",
            "asc",
            "first",
            ["query-d", "query-c", "query-b", "query-a"],
        ],
        [
            "custom.price",
            "asc",
            "last",
            ["query-c", "query-b", "query-a", "query-d"],
        ],
        [
            "custom.price",
            "desc",
            "last",
            ["query-a", "query-b", "query-c", "query-d"],
        ],
        [
            "custom.flag",
            "asc",
            "last",
            ["query-b", "query-a", "query-c", "query-d"],
        ],
        [
            "custom.day",
            "asc",
            "last",
            ["query-c", "query-b", "query-a", "query-d"],
        ],
    ]
    as [$field, $direction, $nulls, $expected]
) {
    $got = slugs(
        $pages->search($base, 50, 0, $order($field, $direction, $nulls)),
    );
    if ($field === "custom.flag") {
        $tail = array_slice($got, 2);
        sort($tail);
        queryCheck(
            array_slice($got, 0, 2) === array_slice($expected, 0, 2) &&
                $tail === ["query-c", "query-d"],
            "false, true and null sort as distinct typed values",
        );
    } else {
        queryCheck(
            $got === $expected,
            "actual MySQL typed " .
                $field .
                " " .
                $direction .
                " nulls " .
                $nulls,
        );
    }
}
queryCheck(
    slugs(
        $pages->search(
            $base,
            50,
            0,
            $order("custom.notes", "asc", "last", true),
        ),
    ) === ["query-c", "query-a", "query-b", "query-d"],
    "rich text sort uses visible Unicode text, never opening markup",
);
queryCheck(
    slugs(
        $pages->search([
            ...$base,
            ["field" => "custom.word", "op" => "eq", "value" => "null"],
        ]),
    ) === ["query-a"],
    "literal null string remains distinct from missing data",
);
queryCheck(
    slugs(
        $pages->search([
            ...$base,
            ["field" => "custom.word", "op" => "eq", "value" => null],
        ]),
    ) === ["query-d"],
    "JSON null becomes actual SQL null",
);
queryCheck(
    slugs(
        $pages->search([
            ...$base,
            [
                "field" => "custom.price",
                "op" => "gt",
                "value" => "9007199254740993.000001",
            ],
        ]),
    ) === ["query-a"],
    "exact decimal comparison survives values beyond float integer precision",
);
queryCheck(
    slugs(
        $pages->search([
            ...$base,
            [
                "field" => "custom.notes",
                "op" => "contains_ci",
                "value" => "ÄPFEL & BEGEGNUNG",
            ],
        ]),
    ) === ["query-b"],
    "case folded rich text crosses formatting and entity boundaries",
);
queryCheck(
    $pages->search([
        ...$base,
        ["field" => "custom.notes", "op" => "contains", "value" => "ÄPFEL"],
    ]) === [],
    "existing case sensitive text contract remains intact",
);
queryCheck(
    slugs(
        $pages->search([
            ...$base,
            [
                "field" => "custom.word",
                "op" => "starts_with_ci",
                "value" => "ÄR",
            ],
        ]),
    ) === ["query-b"],
    "case folding preserves actual umlaut characters",
);
$group = [
    ...$base,
    [
        "any" => [
            [
                "all" => [
                    ["field" => "custom.number", "op" => "gte", "value" => 2],
                    ["field" => "custom.flag", "op" => "eq", "value" => false],
                ],
            ],
            ["field" => "custom.price", "op" => "lt", "value" => 0],
        ],
    ],
];
queryCheck(
    slugs($pages->search($group)) === ["query-b", "query-c"] &&
        $pages->count($group) === 2,
    "nested OR and AND share exact filter/count semantics",
);
queryCheck(
    $pages->count([]) === 4 &&
        $pages->count([
            [
                "any" => [
                    ["field" => "slug", "value" => "query-a"],
                    ["field" => "slug", "value" => "absent"],
                ],
            ],
        ]) === 1,
    "empty filter and default eq remain supported",
);
$tieOrder = [["field" => "title", "case_fold" => true]];
$ties = $pages->search($base, 50, 0, $tieOrder);
$ids = array_column($ties, "id");
$sorted = $ids;
sort($sorted);
queryCheck(
    $ids === $sorted,
    "stable page identity resolves all equal sort values",
);
queryCheck(
    array_merge(
        array_column($pages->search($base, 2, 0, $tieOrder), "id"),
        array_column($pages->search($base, 2, 2, $tieOrder), "id"),
    ) === $ids,
    "adjacent SQL pages have no duplicate or missing ties",
);
queryCheck(
    slugs(
        $pages->search($base, 50, 0, [
            ["field" => "title"],
            ["field" => "custom.number", "direction" => "desc"],
        ]),
    ) === ["query-a", "query-b", "query-c", "query-d"],
    "second sort key uses its own typed order and null placement",
);
foreach ($source as $suffix => $row) {
    Db::run(
        $db,
        "UPDATE khcms_pages SET updated_at=?,created_at=? WHERE id=? AND club_id=?",
        [
            "2050-01-0" . (ord($suffix) - 96) . " 12:00:00",
            "2049-01-0" . (ord($suffix) - 96) . " 12:00:00",
            $row["id"],
            $access->club(),
        ],
    );
}
foreach (["updated_at", "created_at"] as $field) {
    queryCheck(
        slugs($pages->search($base, 50, 0, $order($field, "desc"))) === [
            "query-d",
            "query-c",
            "query-b",
            "query-a",
        ],
        "closed metadata " . $field . " sorts actual UTC columns",
    );
}
$published = $pages->publish($source["a"]["id"], $source["a"]["version"]);
$original = json_encode($pages->publicPage("query-a"));
$generation = $pages->generation();
$published["body_html"] = "<p>Private query draft</p>";
$source["a"] = $pages->save($published, $published["version"]);
queryCheck(
    $pages->count([...$base, ["field" => "published", "value" => true]]) ===
        1 &&
        $pages->count([
            ...$base,
            ["field" => "status", "value" => "unpublished_changes"],
        ]) === 1,
    "published flag includes edited published originals",
);
queryCheck(
    $pages->count([...$base, ["field" => "published", "value" => false]]) === 3,
    "unpublished and changed publication states stay distinct",
);
queryCheck(
    $pages->search($base, 50, 0, $order("published_at", "desc"))[0]["id"] ===
        $source["a"]["id"],
    "published timestamp sort keeps unpublished nulls last",
);

$outsiderPages = new Pages($db, new Access($db, $actors["outsider"]));
$outside = $outsiderPages->save([
    "title" => "Same title",
    "slug" => "query-outside",
]);
queryCheck(
    $pages->count([
        [
            "any" => [
                ["field" => "title", "value" => "Same title"],
                ["field" => "slug", "value" => "query-outside"],
            ],
        ],
    ]) === 4,
    "club predicate stays outside every OR group",
);
queryCheck(
    slugs(
        $outsiderPages->search([
            [
                "any" => [
                    ["field" => "title", "value" => "Same title"],
                    ["field" => "slug", "value" => "query-a"],
                ],
            ],
        ]),
    ) === ["query-outside"],
    "outsider OR cannot read another club",
);
$wild = $pages->save([
    "title" => "Literal 100%_! Offer",
    "slug" => "query-literal",
    "excerpt" => 'SQL-looking value %\' OR 1=1 --',
    "body_html" => "<p>Readable body</p>",
]);
foreach (["100%_!", '%\' OR 1=1 --'] as $needle) {
    queryCheck(
        slugs(
            $pages->search([
                [
                    "field" => "search_text",
                    "op" => "contains_ci",
                    "value" => $needle,
                ],
            ]),
        ) === ["query-literal"],
        "LIKE metacharacters and SQL-looking text remain literal: " . $needle,
    );
}
queryCheck(
    $pages->search([
        [
            "field" => "title",
            "op" => "contains_ci",
            "value" => "100%_! missing",
        ],
    ]) === [],
    "escaped LIKE cannot widen a query",
);
foreach (
    [
        [["any" => []]],
        [["all" => "bad"]],
        [
            [
                "any" => [["field" => "title", "value" => "Same title"]],
                "op" => "OR",
            ],
        ],
        ["named" => ["field" => "title", "value" => "Same title"]],
        [
            [
                "any" => [
                    [
                        "any" => [
                            [
                                "any" => [
                                    [
                                        "any" => [
                                            [
                                                "field" => "title",
                                                "value" => "Same title",
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            [
                "any" => array_fill(0, 11, [
                    "field" => "title",
                    "value" => "Same title",
                ]),
            ],
            [
                "all" => array_fill(0, 10, [
                    "field" => "title",
                    "value" => "Same title",
                ]),
            ],
        ],
        [["field" => "title", "op" => "contains_ci", "value" => []]],
        [["field" => "custom.number", "op" => "contains_ci", "value" => "2"]],
        [["field" => "title) OR 1=1 --", "value" => "Same title"]],
        [["field" => "title", "op" => "eq OR 1=1", "value" => "Same title"]],
    ]
    as $i => $bad
) {
    queryReject(
        fn() => $pages->search($bad),
        "malformed/injected/deep/oversize filter " . $i,
    );
}
foreach (
    [
        [["field" => "title) DESC; --"]],
        [["field" => "title", "direction" => "desc; DROP TABLE khcms_pages"]],
        [["field" => "title", "direction" => []]],
        [["field" => "title", "nulls" => "random"]],
        [["field" => "title", "case_fold" => "yes"]],
        [["field" => "custom.number", "case_fold" => true]],
        [["field" => "title"], ["field" => "title"]],
        array_fill(0, 4, ["field" => "title"]),
        ["named" => ["field" => "title"]],
        [["field" => "title", "sql" => "1"]],
        [["field" => "title", "direction" => null]],
        [["field" => "title", "nulls" => null]],
        [["field" => "title", "case_fold" => null]],
    ]
    as $i => $bad
) {
    queryReject(
        fn() => $pages->search([], 50, 0, $bad),
        "malformed/injected/oversize ordering " . $i,
    );
}
foreach ([[0, 0], [501, 0], [1, -1], [1, 1000001]] as [$limit, $offset]) {
    queryReject(
        fn() => $pages->search([], $limit, $offset),
        "bounded SQL slice " . $limit . "/" . $offset,
    );
}
foreach ([$base, $group] as $selectors) {
    [$sql, $params] = (new Selectors($model))->compile($selectors);
    queryCheck(
        substr_count($sql, "?") === count($params),
        "all nested/filter projection bindings match actual placeholders",
    );
}
[$sql, $params] = (new QueryOrder($model))->compile([
    ["field" => "custom.number"],
    ["field" => "custom.notes", "case_fold" => true],
    ["field" => "updated_at"],
]);
queryCheck(
    substr_count($sql, "?") === count($params),
    "null and value ranking bind every projection independently",
);

for ($i = 0; $i < 57; ++$i) {
    $row = $pages->save([
        "title" => "Own bounded listing " . sprintf("%02d", $i),
        "slug" => "bounded-" . sprintf("%02d", $i),
        "template" => "query_page",
        "excerpt" => "Own search text",
        "custom_fields" => [
            "number" => 56 - $i,
            "notes" => "<p>Own visible " . sprintf("%02d", $i) . "</p>",
        ],
    ]);
    if ($i < 20) {
        $row = $pages->publish($row["id"], $row["version"]);
        if ($i >= 10) {
            $row["body_html"] = "<p>Own changed draft</p>";
            $pages->save($row, $row["version"]);
        }
    }
}
foreach (["news", "gallery", "event"] as $template) {
    $pages->save([
        "title" => "Own bounded listing excluded " . $template,
        "slug" => "bounded-excluded-" . $template,
        "template" => $template,
        "news_date" => $template === "news" ? "2050-01-01" : "",
        "event_start" => $template === "event" ? "2050-01-01T12:00" : "",
    ]);
}
$lister = new PageSearch($db, $access);
$filter = [
    "q" => "Own bounded listing",
    "status" => "all",
    "sort" => "title_asc",
    "page" => 1,
];
$first = $lister->listing($filter);
queryCheck(
    $first["total"] === 57 &&
        count($first["rows"]) === 25 &&
        $first["last"] === 3,
    "actual 57 matching pages return only one SQL-bounded 25 row slice",
);
queryCheck(
    slugs($first["rows"]) ===
        array_map(
            static fn($i) => "bounded-" . sprintf("%02d", $i),
            range(0, 24),
        ),
    "title sort preserves original alphabetical list",
);
$last = $lister->listing(array_replace($filter, ["page" => 999999]));
queryCheck(
    $last["page"] === 3 &&
        count($last["rows"]) === 7 &&
        $last["rows"][0]["slug"] === "bounded-50",
    "out of range list clamps to last SQL page",
);
$reverse = $lister->listing(
    array_replace($filter, ["sort" => "custom.number:asc"]),
);
queryCheck(
    slugs($reverse["rows"]) ===
        array_map(
            static fn($i) => "bounded-" . sprintf("%02d", $i),
            range(56, 32),
        ),
    "real editor can sort arbitrary declared integer field numerically",
);
foreach (
    ["published" => 20, "draft" => 37, "changed" => 10]
    as $status => $count
) {
    $slice = $lister->listing(array_replace($filter, ["status" => $status]));
    queryCheck(
        $slice["total"] === $count && count($slice["rows"]) === min(25, $count),
        "bounded editorial " . $status . " state retains prior behavior",
    );
}
$none = $lister->listing(
    array_replace($filter, ["q" => "Not in any own page", "page" => 90]),
);
queryCheck(
    $none["total"] === 0 &&
        $none["rows"] === [] &&
        $none["page"] === 1 &&
        $none["last"] === 1,
    "empty result has coherent pagination",
);
queryCheck(
    $lister->listing(array_replace($filter, ["q" => "OFFer query-literal"]))[
        "total"
    ] === 1,
    "original search spanning title and address remains available",
);
queryCheck(
    $lister->listing(array_replace($filter, ["q" => "READABLE BODY"]))[
        "rows"
    ][0]["id"] === $wild["id"],
    "case folded body query uses actual derived plain text",
);
$choices = $lister->choices();
queryCheck(
    isset($choices["custom.number:asc"], $choices["custom.notes:desc"]) &&
        !isset($choices["custom.unknown:asc"]),
    "UI choices come from current club declarations only",
);
$ui = (new WebsiteWorkspace($db, $access, "own-csrf"))->pages([
    "q" => $filter["q"],
    "sort" => "custom.number:asc",
    "page" => "2",
]);
queryCheck(
    substr_count($ui, "data-website-row=") === 25 &&
        str_contains($ui, "57 Seiten gefunden · Seite 2 von 3"),
    "actual UI renders total count and one slice",
);
queryCheck(
    str_contains($ui, "custom.number%3Aasc") &&
        str_contains($ui, "q=Own+bounded+listing") &&
        str_contains($ui, "page=3"),
    "next/previous links preserve original filter and custom order",
);
queryCheck(
    str_contains($ui, 'class="btn btn-solid theme-primary"') &&
        str_contains($ui, "custom.notes:desc"),
    "Bootstrap filter controls expose rich and numeric choices",
);
foreach (
    [
        ["q" => []],
        ["q" => "\xff"],
        ["q" => "bad\nquery"],
        ["q" => str_repeat("ä", 121)],
        ["page" => 0],
        ["page" => "2"],
        ["status" => "other"],
        ["sort" => "custom.number:desc; DROP TABLE khcms_pages"],
    ]
    as $i => $bad
) {
    queryReject(
        fn() => $lister->listing(array_replace($filter, $bad)),
        "typed lister refuses invalid request " . $i,
    );
}
queryReject(
    fn() => $lister->listing($filter, 101),
    "lister enforces maximum slice",
);
queryCheck(
    json_encode($pages->publicPage("query-a")) === $original &&
        $pages->generation() >= $generation,
    "list, sort and count preserve original published snapshot",
);
$generation = $pages->generation();
$pages->search($group, 2, 0, $order("custom.price"));
$pages->count($group);
queryCheck(
    $pages->generation() === $generation,
    "query paths do not invalidate publication cache",
);

$model->saveField(
    array_replace($fields["number"], ["type" => "text"]),
    $fields["number"]["version"],
);
queryCheck(
    $pages->count([
        ...$base,
        ["field" => "custom.number", "op" => "ne", "value" => null],
    ]) === 0,
    "changed field type quarantines old snapshots instead of reinterpreting numeric values",
);
$new = $pages->save([
    "title" => "Current text definition",
    "slug" => "query-fresh",
    "template" => "query_page",
    "custom_fields" => ["number" => "Fresh"],
]);
$changed = $pages->search($base, 500, 0, $order("custom.number"));
queryCheck(
    $changed[0]["id"] === $new["id"],
    "current text values sort before quarantined historic null projections",
);
Db::run($db, "DELETE FROM native_grants WHERE actor_id=? AND module=?", [
    $actors["reader"],
    "website",
]);
$denied = new Pages($db, new Access($db, $actors["reader"]));
queryReject(
    fn() => $denied->count([]),
    "count checks current access",
    DomainException::class,
);
queryReject(
    fn() => $denied->search([]),
    "search checks current access",
    DomainException::class,
);
echo json_encode(
    [
        "suite" => "native-cms-queries",
        "checks" => $n,
        "storage" => "fresh synthetic MySQL",
        "external_requests" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
