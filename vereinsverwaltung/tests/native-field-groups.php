<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema, Pages, DataModel, FieldGroups, Conflict};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\ContentModel;
use Kulturhus\Native\Web\Fields\{GroupForm, Layout};
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$ui = new ContentModel($db, $access, "own-csrf");
$checks = 0;
function groupCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS " . $label . "\n";
}
function groupReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $e) {
        groupCheck($e instanceof $class, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$fields = [];
foreach (
    [
        "active" => ["type" => "boolean", "required" => true],
        "amount" => ["type" => "decimal"],
        "note" => [
            "type" => "text",
            "required_when" => [
                "match" => "all",
                "conditions" => [
                    ["field" => "active", "operator" => "eq", "value" => true],
                ],
            ],
        ],
        "formatted" => ["type" => "richtext"],
        "day" => ["type" => "date"],
    ]
    as $key => $input
) {
    $fields[$key] = $model->saveField(
        ["key" => $key, "label" => "Own " . $key] + $input,
    );
}
$template = $model->saveTemplate([
    "key" => "groups",
    "label" => "Own structured template",
    "fields" => array_keys($fields),
]);
groupCheck(
    !array_key_exists("groups", $template),
    "existing ungrouped definition shape stays exact",
);
$values = [
    "active" => false,
    "amount" => "999999999999999999.999998",
    "note" => "Original conditional note",
    "formatted" => "<p>Own <strong>formatted</strong> &lt;marker&gt;</p>",
    "day" => "2026-10-10",
];
$page = $pages->save([
    "title" => "Own structured original",
    "slug" => "own-structured-original",
    "template" => "groups",
    "custom_fields" => $values,
]);
$page = $pages->publish($page["id"], $page["version"]);
$original = $pages->publicPage($page["slug"]);
$generation = $pages->generation();
$groups = [
    [
        "key" => "root",
        "label" => "Own <root>",
        "description" => "Literal <script>\nOwn hint",
        "fields" => ["amount"],
        "columns" => 2,
    ],
    [
        "key" => "child",
        "label" => "Own child",
        "parent" => "root",
        "fields" => ["formatted"],
        "collapsed" => "1",
    ],
    [
        "key" => "leaf",
        "label" => "Own leaf",
        "parent" => "child",
        "fields" => ["note", "day"],
    ],
    ["key" => "empty", "label" => "Own empty", "fields" => []],
];
$normalized = FieldGroups::normalize($groups, array_keys($fields));
groupCheck(
    $normalized[0]["columns"] === 2 &&
        $normalized[1]["collapsed"] === true &&
        $normalized[0]["parent"] === "",
    "typed layout defaults and native form scalar flags canonicalize",
);
groupCheck(
    FieldGroups::paths($normalized)["leaf"] ===
        "Own <root> / Own child / Own leaf",
    "group paths describe actual three-level hierarchy",
);
$template = $model->saveTemplate(
    array_replace($template, ["groups" => $groups]),
    $template["version"],
);
groupCheck(
    $template["groups"] === $normalized &&
        $template["version"] === 2 &&
        count($model->templateRevisions("groups")) === 2,
    "versioned own nested layout persists with one complete revision",
);
groupCheck(
    $pages->publicPage($page["slug"]) === $original &&
        $pages->generation() === $generation,
    "layout edit preserves exact published original and public cache generation",
);
groupReject(
    fn() => $pages->publish($page["id"], $page["version"]),
    "changed layout requires deliberate draft re-save",
    Conflict::class,
);
$page = $pages->save($page, $page["version"]);
groupCheck(
    $page["custom_fields"] === $values &&
        $page["template_definition"]["groups"] === $normalized,
    "private re-save captures layout without renaming or duplicating typed values",
);
groupCheck(
    $pages->publicPage($page["slug"]) === $original,
    "private regrouping leaves approved original unchanged",
);
$html = $ui->fields("groups", $values);
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($document);
groupCheck(
    $xpath->query(
        '//*[@data-field-group="root"]//*[@data-field-group="child"]//*[@data-field-group="leaf"]',
    )->length === 1,
    "actual three-level controls are nested once",
);
groupCheck(
    $xpath->query('//*[@data-field-group="empty"]')->length === 0,
    "empty definition never adds an empty content group",
);
groupCheck(
    $xpath->query('//*[@data-field-group="child" and not(@open)]')->length ===
        1 &&
        $xpath->query('//*[@data-field-group="root" and @open]')->length === 1,
    "stored collapse state controls actual details semantics",
);
groupCheck(
    !str_contains($html, "<script>") &&
        str_contains($html, "Own &lt;root&gt;") &&
        str_contains($html, "Literal &lt;script&gt;"),
    "group labels and multiline hints remain literal escaped text",
);
foreach (array_keys($fields) as $key) {
    groupCheck(
        $xpath->query('//*[@name="custom_fields[' . $key . ']"]')->length === 1,
        "exact original input identity retained once: " . $key,
    );
}
groupCheck(
    $xpath->query(
        '//*[@data-model-field="active" and not(ancestor::*[@data-field-group])]',
    )->length === 1,
    "unassigned original remains reachable before groups",
);
groupCheck(
    $ui->values("groups", [
        "active" => "0",
        "amount" => "999999999999999999,999998",
        "note" => $values["note"],
        "formatted" => $values["formatted"],
        "day" => $values["day"],
    ]) === $values,
    "grouped form uses original exact decimal, false and formatted save contract",
);
groupReject(
    fn() => $pages->save(array_replace($page,['custom_fields'=>$ui->values("groups", ["active" => "1"])]),$page['version']),
    "group collapse cannot bypass conditional required server validation",
);
$revisionCount = (int) Db::run(
    $db,
    "SELECT COUNT(*) FROM khcms_definition_revisions",
)->fetchColumn();
$badLists = [
    null,
    "group",
    ["a" => $groups[0]],
    array_fill(0, 21, $groups[0]),
    [array_replace($groups[0], ["key" => "../bad"])],
    [array_replace($groups[0], ["label" => ""])],
    [array_replace($groups[0], ["label" => str_repeat("x", 161)])],
    [array_replace($groups[0], ["description" => str_repeat("x", 501)])],
    [array_replace($groups[0], ["parent" => null])],
    [array_replace($groups[0], ["parent" => "absent"])],
    [array_replace($groups[0], ["parent" => "root"])],
    [array_replace($groups[0], ["columns" => 3])],
    [array_replace($groups[0], ["columns" => null])],
    [array_replace($groups[0], ["collapsed" => "false"])],
    [array_replace($groups[0], ["collapsed" => null])],
    [array_replace($groups[0], ["fields" => null])],
    [array_replace($groups[0], ["fields" => ["amount", "amount"]])],
    [array_replace($groups[0], ["fields" => ["unknown"]])],
    [$groups[0], $groups[0]],
    [$groups[0], array_replace($groups[1], ["fields" => ["amount"]])],
    [array_replace($groups[0], ["php" => "code"])],
    [
        ["key" => "a", "label" => "A", "parent" => "b"],
        ["key" => "b", "label" => "B", "parent" => "a"],
    ],
    [
        ["key" => "a", "label" => "A"],
        ["key" => "b", "label" => "B", "parent" => "a"],
        ["key" => "c", "label" => "C", "parent" => "b"],
        ["key" => "d", "label" => "D", "parent" => "c"],
    ],
];
foreach ($badLists as $i => $bad) {
    groupReject(
        fn() => $model->saveTemplate(
            array_replace($template, ["groups" => $bad]),
            $template["version"],
        ),
        "malformed or cyclic nested layout rolls back " . $i,
    );
}
groupCheck(
    $model->template("groups") === $template &&
        (int) Db::run(
            $db,
            "SELECT COUNT(*) FROM khcms_definition_revisions",
        )->fetchColumn() === $revisionCount,
    "all rejected layouts retain exact current definition and history",
);
$twenty = [];
for ($i = 0; $i < 20; $i++) {
    $twenty[] = ["key" => "g" . $i, "label" => "Own " . $i];
}
groupCheck(
    count(FieldGroups::normalize($twenty, array_keys($fields))) === 20,
    "exact twenty-group boundary accepted",
);
$formRows = [
    4 => array_diff_key($normalized[1], ["fields" => true]),
    1 => array_diff_key($normalized[0], ["fields" => true]),
    7 => array_diff_key($normalized[2], ["fields" => true]),
];
$assign = [
    "amount" => "root",
    "formatted" => "child",
    "note" => "leaf",
    "day" => "leaf",
    "active" => "",
];
$compiled = GroupForm::submitted(
    $formRows,
    $assign,
    ["day", "note", "formatted", "amount", "active"],
    array_keys($fields),
);
groupCheck(
    array_column($compiled, "key") === ["child", "root", "leaf"] &&
        $compiled[2]["fields"] === ["day", "note"],
    "form compilation retains deliberate group and member order after removal and reordering",
);
$compiled = GroupForm::submitted(
    $formRows,
    $assign,
    ["amount", "active"],
    array_keys($fields),
);
groupCheck(
    $compiled[0]["fields"] === [] && $compiled[2]["fields"] === [],
    "unchecked fields remain defined but leave active group membership",
);
foreach (
    [
        [null, $assign],
        [$formRows, null],
        [$formRows, ["foreign" => "root"]],
        [$formRows, ["amount" => "unknown"]],
        [$formRows, ["amount" => []]],
        [["bad" => $formRows[1]], $assign],
        [[0 => $formRows[1] + ["fields" => ["amount"]]], $assign],
        [[0 => $formRows[1], 1 => $formRows[1]], $assign],
    ]
    as $i => [$rows, $assignments]
) {
    groupReject(
        fn() => GroupForm::submitted(
            $rows,
            $assignments,
            array_keys($fields),
            array_keys($fields),
        ),
        "malformed closed group form rejected " . $i,
    );
}
$manager = $ui->manager(['model_kind'=>'template','model_key'=>'groups']);
groupCheck(
    str_contains($manager, "data-group-editor") &&
        str_contains($manager, 'name="field_groups[amount]"') &&
        str_contains($manager, "Own &lt;root&gt; / Own child"),
    "friendly manager form preserves original grouped assignments",
);
$ui->action([
    "csrf" => "own-csrf",
    "operation" => "model-template",
    "version" => (string) $template["version"],
    "key" => "groups",
    "label" => "Own renamed structured template",
    "renderer" => "standard",
    "fields" => array_keys($fields),
    "groups" => array_map(
        static fn($g) => array_diff_key($g, ["fields" => true]),
        $normalized,
    ),
    "field_groups" => $assign,
]);
$template = $model->template("groups");
groupCheck(
    $template["version"] === 3 && $template["groups"] === $normalized,
    "actual model form action persists one layout revision",
);
$ui->action([
    "csrf" => "own-csrf",
    "operation" => "model-template",
    "version" => (string) $template["version"],
    "key" => "groups",
    "label" => $template["label"],
    "fields" => array_keys($fields),
]);
groupCheck(
    !isset($model->template("groups")["groups"]) &&
        $pages->get($page["id"])["custom_fields"] === $values,
    "removing all groups preserves every stored field value",
);
$outside = new DataModel($db, new Access($db, "outsider"));
$outside->saveField([
    "key" => "foreign",
    "label" => "Foreign field",
    "type" => "text",
]);
groupReject(
    fn() => $model->saveTemplate([
        "key" => "foreign_group",
        "label" => "Own",
        "fields" => ["foreign"],
        "groups" => [
            ["key" => "own", "label" => "Own", "fields" => ["foreign"]],
        ],
    ]),
    "group cannot import another club field",
);
groupReject(
    fn() => (new ContentModel(
        $db,
        new Access($db, "reader"),
        "own-csrf",
    ))->action(["csrf" => "own-csrf", "operation" => "model-template"]),
    "reader cannot manage grouped template",
    DomainException::class,
);
groupReject(
    fn() => $ui->action(["csrf" => "bad", "operation" => "model-template"]),
    "grouped model form checks current CSRF",
    DomainException::class,
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='read'",
);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES('editor','website','manage')",
);
(new ContentModel($db, new Access($db, "editor"), "own-csrf"))->action([
    "csrf" => "own-csrf",
    "operation" => "model-template",
    "key" => "manage_only",
    "label" => "Own manage-only layout",
    "fields" => ["active"],
    "groups" => [["key" => "own", "label" => "Own group"]],
    "field_groups" => ["active" => "own"],
]);
groupCheck(
    $model->template("manage_only")["groups"][0]["fields"] === ["active"],
    "group form retains existing independent manage-only write contract",
);
groupReject(
    fn() => (new DataModel($db, new Access($db, "editor")))->fields(),
    "manage-only identity lookup does not grant content read",
    DomainException::class,
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='manage'",
);
groupReject(
    fn() => (new DataModel($db, new Access($db, "editor")))->managedFieldKeys(),
    "group form identity lookup checks current manage revocation",
    DomainException::class,
);
groupCheck(
    $pages->publicPage($page["slug"]) === $original &&
        $pages->generation() === $generation,
    "all group operations preserve exact published original",
);
echo json_encode(
    [
        "suite" => "native-field-groups",
        "checks" => $checks,
        "external_requests" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
