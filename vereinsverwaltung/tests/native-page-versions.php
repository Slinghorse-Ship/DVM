<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    Pages,
    Media,
    DataModel,
    PageVersions,
    VersionChanges,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Website\{PageVersionsPanel, VersionValues};
use Kulturhus\Native\Integration\{EmbeddedManager, Routes};
use Kulturhus\Native\Web\Manager;
if (($argv[1] ?? "") === "metadata-memory") {
    $db = TestSupport::db();
    $access = new Access($db, "admin");
    $overview = (new PageVersions($db, $access))->overview($argv[2]);
    $html = (new PageVersionsPanel($db, $access, "own-csrf"))->render(
        ["id" => $argv[2], "version" => $overview["current"]],
        "website",
        "page-restore",
    );
    echo json_encode(
        [
            "rows" => count($overview["rows"]),
            "total" => $overview["total"],
            "controls" => substr_count($html, "data-saved-version="),
            "memory" => memory_get_peak_usage(true),
        ],
        JSON_THROW_ON_ERROR,
    );
    exit();
}
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$versions = new PageVersions($db, $access);
$checks = 0;
function versionCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS " . $label . "\n";
}
function versionReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $error) {
        versionCheck($error instanceof $class, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$amount = $model->saveField([
    "key" => "amount",
    "label" => "Original amount",
    "type" => "decimal",
]);
$active = $model->saveField([
    "key" => "active",
    "label" => "Original active",
    "type" => "boolean",
]);
$formatted = $model->saveField([
    "key" => "formatted",
    "label" => "Original <formatted>",
    "type" => "richtext",
]);
$related = $model->saveField([
    "key" => "related",
    "label" => "Original related",
    "type" => "page-reference",
]);
$template = $model->saveTemplate([
    "key" => "versions",
    "label" => "Own original model",
    "fields" => ["amount", "active", "formatted", "related"],
    "groups" => [
        [
            "key" => "own",
            "label" => "Original group",
            "fields" => ["formatted"],
        ],
    ],
]);
$reference = $pages->save([
    "title" => "Own first reference",
    "slug" => "own-first-reference",
]);
$otherReference = $pages->save([
    "title" => "Own second reference",
    "slug" => "own-second-reference",
]);
$page = $pages->save([
    "title" => "Own first title",
    "slug" => "own-version-original",
    "template" => "versions",
    "body_html" => "<p>Own <strong>first</strong> body</p>",
    "custom_fields" => [
        "amount" => "999999999999999999.999997",
        "active" => false,
        "formatted" => "<p>Own &lt;marker&gt;</p>",
        "related" => $reference["id"],
    ],
]);
$page = $pages->publish($page["id"], $page["version"]);
$original = $pages->publicPage($page["slug"]);
$generation = $pages->generation();
versionCheck(
    $versions->compare($page["id"], 1, 2)["changes"] === [],
    "publication action does not invent content changes",
);
versionCheck(
    $versions->compare($page["id"], 2, 2)["changes"] === [],
    "same original compares equal without rewriting it",
);
$formatted = $model->saveField(
    array_replace($formatted, ["label" => "New formatted label"]),
    $formatted["version"],
);
$template = $model->saveTemplate(
    array_replace($template, [
        "groups" => [
            [
                "key" => "own",
                "label" => "Changed <group>",
                "fields" => ["formatted"],
                "columns" => 2,
            ],
        ],
    ]),
    $template["version"],
);
$page = $pages->save(
    array_replace($page, [
        "title" => "Own second title",
        "body_html" => "<p>Own <em>second</em> body</p>",
        "custom_fields" => [
            "amount" => "999999999999999999.999998",
            "active" => true,
            "formatted" => "<p>Own <strong>new</strong> &lt;marker&gt;</p>",
            "related" => $otherReference["id"],
        ],
    ]),
    $page["version"],
);
$comparison = $versions->compare($page["id"], 2, 3);
$changes = array_column($comparison["changes"], null, "key");
versionCheck(
    $changes["title"]["before"]["value"] === "Own first title" &&
        $changes["title"]["after"]["value"] === "Own second title",
    "comparison reads two exact own historical snapshots",
);
versionCheck(
    $changes["custom.amount"]["before"]["value"] ===
        "999999999999999999.999997" &&
        $changes["custom.amount"]["after"]["value"] ===
            "999999999999999999.999998",
    "adjacent large decimal originals are compared without floating point",
);
versionCheck(
    $changes["custom.active"]["before"]["value"] === false &&
        $changes["custom.active"]["after"]["value"] === true,
    "original false is not mistaken for an absent or empty value",
);
versionCheck(
    $changes["custom.related"]["before"]["value"] === $reference["id"] &&
        $changes["custom.related"]["after"]["value"] === $otherReference["id"],
    "original reference identities remain exact",
);
versionCheck(
    $changes["definition.formatted"]["before"]["value"]["label"] ===
        "Original <formatted>" &&
        $changes["definition.formatted"]["after"]["value"]["label"] ===
            "New formatted label",
    "changed current field cannot reinterpret old labels or definitions",
);
versionCheck(
    $changes["template_definition"]["before"]["value"]["groups"][0]["label"] ===
        "Original group" &&
        $changes["template_definition"]["after"]["value"]["groups"][0][
            "label"
        ] === "Changed <group>",
    "historical group assignments and versioned model compare as originals",
);
versionCheck(
    isset($changes["content"]) &&
        !isset($changes["body"]) &&
        !isset($changes["body_html"]) &&
        !isset($changes["custom_field_text"]),
    "derived text never duplicates a formatted content change",
);
versionCheck(
    $comparison["before_labels"]["formatted"] === "Original <formatted>" &&
        $comparison["after_labels"]["formatted"] === "New formatted label",
    "both sides retain their own complete definition labels",
);
$model->saveField(
    array_replace($formatted, ["type" => "text"]),
    $formatted["version"],
);
versionCheck(
    $versions->compare($page["id"], 2, 3) === $comparison,
    "later current type change cannot coerce or replace either historical comparison",
);
$typed = VersionChanges::between(
    ["custom_fields" => ["own" => null]],
    ["custom_fields" => ["own" => "null"]],
);
versionCheck(
    $typed[0]["before"]["value"] === null &&
        $typed[0]["after"]["value"] === "null",
    "null and literal null are distinct originals",
);
$typed = VersionChanges::between(
    ["custom_fields" => ["own" => 1]],
    ["custom_fields" => ["own" => "1"]],
);
versionCheck(
    count($typed) === 1 &&
        $typed[0]["before"]["value"] === 1 &&
        $typed[0]["after"]["value"] === "1",
    "equal-looking values retain exact original scalar types",
);
$typed = VersionChanges::between([], ["custom_fields" => ["own" => null]]);
versionCheck(
    $typed[0]["before"] === null && $typed[0]["after"]["value"] === null,
    "added empty field differs from an absent original field",
);
$typed = VersionChanges::between(
    ["media_ids" => [$reference["id"], $otherReference["id"]]],
    ["media_ids" => [$otherReference["id"], $reference["id"]]],
);
versionCheck(
    count($typed) === 1,
    "original list ordering is a meaningful change",
);
$values = new VersionValues($db, $access);
versionCheck(
    str_contains($values->render($changes["custom.active"]["before"]), "Nein"),
    "false renders as the original human choice",
);
versionCheck(
    str_contains(
        $values->render($changes["custom.related"]["before"]),
        "Own first reference",
    ) &&
        str_contains(
            $values->render($changes["custom.related"]["before"]),
            $reference["id"],
        ),
    "reference comparison uses owned current metadata and retains original identity",
);
versionCheck(
    str_contains(
        $values->render($changes["custom.formatted"]["before"]),
        "&lt;marker&gt;",
    ) &&
        str_contains(
            $values->render($changes["custom.formatted"]["after"]),
            "<strong>new</strong>",
        ),
    "formatted comparison retains escaped literal characters and safe original formatting",
);
$html = $values->render(
    $changes["template_definition"]["after"],
    $comparison["after_labels"],
);
versionCheck(
    str_contains($html, "Changed &lt;group&gt;") &&
        str_contains($html, "New formatted label") &&
        !str_contains($html, "Changed <group>"),
    "template comparison uses original readable field labels and literal escaped groups",
);
$overview = $versions->overview($page["id"]);
versionCheck(
    $overview["total"] === 3 &&
        $overview["previous"] === 2 &&
        $overview["published"] === 2 &&
        array_column($overview["rows"], "revision") === [3, 2, 1],
    "history has exact count, predecessor, publication and descending original order",
);
versionCheck(
    !isset($overview["rows"][0]["payload"]) &&
        !isset($overview["rows"][0]["payload_json"]) &&
        $overview["rows"][0]["actor_name"] === "Lokale Administration",
    "history reads only original metadata and own author identity",
);
versionCheck(
    count($versions->selected($page["id"], [2, 2])) === 1,
    "identical selected originals retain one metadata record",
);
foreach ([0, -1, 4294967296] as $i => $bad) {
    versionReject(
        fn() => $versions->compare($page["id"], $bad, 3),
        "invalid original version rejected " . $i,
    );
}
foreach ([["3"], [1, 2, 3], ["from" => 2]] as $i => $bad) {
    versionReject(
        fn() => $versions->selected($page["id"], $bad),
        "malformed selected original list rejected " . $i,
    );
}
foreach ([0, -1, 1000001] as $i => $bad) {
    versionReject(
        fn() => $versions->overview($page["id"], $bad),
        "malformed history page rejected " . $i,
    );
}
versionReject(
    fn() => $versions->compare($page["id"], 2, 99),
    "absent requested snapshot is rejected",
    RuntimeException::class,
);
versionReject(
    fn() => $versions->overview("../foreign"),
    "malformed page identity rejected",
);
versionReject(
    fn() => $versions->overview(str_repeat("a", 32)),
    "unknown own page does not expose any history",
    RuntimeException::class,
);
$foreign = (new Pages($db, new Access($db, "outsider")))->save([
    "title" => "Foreign private title",
    "slug" => "foreign-version-page",
]);
versionReject(
    fn() => $versions->compare($foreign["id"], 1, 1),
    "comparison cannot bind foreign page history",
    RuntimeException::class,
);
versionReject(
    fn() => $versions->selected($foreign["id"], [1]),
    "selected metadata cannot bind foreign page",
    RuntimeException::class,
);
versionCheck(
    !str_contains(
        $values->render([
            "type" => "page-reference",
            "value" => $foreign["id"],
        ]),
        "Foreign private title",
    ),
    "historical reference rendering cannot reveal foreign metadata",
);
Db::run($db, "UPDATE native_users SET club_id='synthetic-b' WHERE id='admin'");
versionCheck(
    !str_contains(
        $values->render($changes["custom.related"]["before"]),
        "Own first reference",
    ),
    "cached reference metadata is still bounded to current club identity",
);
Db::run($db, "UPDATE native_users SET club_id='synthetic-a' WHERE id='admin'");
$panel = new PageVersionsPanel($db, $access, "own-csrf");
$oldGet = $_GET;
$_GET = ["compare_from" => "2", "compare_to" => "3"];
$html = $panel->render($page, "website", "page-restore");
versionCheck(
    str_contains($html, "data-version-comparison") &&
        str_contains($html, "Own first title") &&
        str_contains($html, "Own second title"),
    "shared panel exposes concrete original comparison",
);
versionCheck(
    str_contains($html, 'name="item" value="' . $page["id"] . '"') &&
        str_contains($html, 'method="get"') &&
        str_contains($html, 'name="operation" value="page-restore"'),
    "read-only comparison and existing explicit restoration keep separate native form contracts",
);
$embedded = (new EmbeddedManager(
    new Manager($db, $access, __DIR__, "own-csrf", false, true),
    $access,
))->main("website", $page["id"]);
versionCheck(
    str_contains($embedded, 'name="native_item" value="' . $page["id"] . '"') &&
        str_contains($embedded, 'name="compare_from"') &&
        !str_contains($embedded, 'name="item"'),
    "embedded compare GET form retains the selected original identity in its own manager route",
);
versionCheck(
    str_contains(
        Routes::translate(
            "/?view=website&item=" .
                $page["id"] .
                "&versions_page=2&compare_from=1&compare_to=3",
        ),
        "compare_from=1",
    ) &&
        str_contains(
            Routes::translate(
                "/?view=website&item=" .
                    $page["id"] .
                    "&versions_page=2&compare_from=1&compare_to=3",
            ),
            "versions_page=2",
        ),
    "shared route translation retains complete version comparison and history state",
);
$readonly = (new PageVersionsPanel(
    $db,
    new Access($db, "reader"),
    "own-csrf",
))->render($page, "website", "page-restore");
versionCheck(
    str_contains($readonly, "data-version-comparison") &&
        !str_contains($readonly, 'method="post"'),
    "read-only member can compare originals without restoration controls",
);
foreach (
    [
        ["compare_from" => ["2"]],
        ["compare_to" => "0"],
        ["versions_page" => "-1"],
    ]
    as $i => $bad
) {
    $_GET = $bad;
    versionReject(
        fn() => $panel->render($page, "website", "page-restore"),
        "malformed closed comparison query rejected " . $i,
    );
}
$_GET = $oldGet;
versionCheck(
    $pages->publicPage($page["slug"]) === $original &&
        $pages->generation() === $generation &&
        $pages->get($page["id"])["version"] === 3,
    "all comparison and metadata reads preserve exact original public cache and private version",
);
// A real memory-limited subprocess must not load twenty megabytes of unrelated old bodies.
$stress = $pages->save([
    "title" => "Own large version history",
    "slug" => "own-large-version-history",
]);
$snapshot = json_decode(
    Db::run($db, "SELECT draft_json FROM khcms_pages WHERE id=?", [
        $stress["id"],
    ])->fetchColumn(),
    true,
);
$snapshot["body"] = str_repeat("own historical ", 44000);
$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
for ($i = 2; $i <= 36; $i++) {
    Db::run(
        $db,
        "INSERT INTO khcms_revisions(page_id,revision,club_id,payload_json,actor_id,action,created_at) VALUES(?,?,?,?,?,'save',UTC_TIMESTAMP(6))",
        [$stress["id"], $i, $access->club(), $json, "admin"],
    );
}
Db::run($db, "UPDATE khcms_pages SET version=36 WHERE id=?", [$stress["id"]]);
$first = $versions->overview($stress["id"]);
$second = $versions->overview($stress["id"], 2);
versionCheck(
    count($first["rows"]) === 25 &&
        count($second["rows"]) === 11 &&
        array_column($second["rows"], "revision") === range(11, 1),
    "history slices never skip or duplicate original revisions",
);
versionCheck(
    $versions->overview($stress["id"], 999)["page"] === 2,
    "history clamps to the actual complete last metadata slice",
);
$process = proc_open(
    [
        ...TestSupport::phpCommand(),
        "-d",
        "memory_limit=16M",
        __FILE__,
        "metadata-memory",
        $stress["id"],
    ],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException("Metadata process unavailable.");
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
versionCheck(
    $status === 0 && $errors === "",
    "actual sixteen-megabyte PHP subprocess renders metadata history over twenty megabytes of original bodies",
);
$proof = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
versionCheck(
    $proof["rows"] === 25 &&
        $proof["total"] === 36 &&
        $proof["controls"] === 25 &&
        $proof["memory"] < 16 * 1024 * 1024,
    "memory-limited history has full count and exact bounded controls",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='read'",
);
foreach (
    [
        "overview" => fn() => $versions->overview($page["id"]),
        "selected" => fn() => $versions->selected($page["id"], [2]),
        "compare" => fn() => $versions->compare($page["id"], 2, 3),
        "render" => fn() => $values->render($changes["title"]["before"]),
        "panel" => fn() => $panel->render($page, "website"),
    ]
    as $label => $fn
) {
    versionReject(
        $fn,
        "current read revocation blocks original " . $label,
        DomainException::class,
    );
}
echo json_encode(
    [
        "suite" => "native-page-versions",
        "checks" => $checks,
        "memory_proof" => $proof,
        "external_actions" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
