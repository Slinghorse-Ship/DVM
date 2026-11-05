<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    Pages,
    Media,
    DataModel,
    CustomFields,
    FieldConditions,
    Conflict,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\{ContentModel, FieldRuleEditor, Html};
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Integration\EmbeddedManager;
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$checks = 0;
function conditionCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS " . $label . "\n";
}
function conditionReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $error) {
        conditionCheck($error instanceof $class, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$rule = static fn(
    string $field,
    string $operator,
    mixed $value = null,
): array => [
    "match" => "all",
    "conditions" => [
        in_array($operator, ["empty", "filled"], true)
            ? ["field" => $field, "operator" => $operator]
            : ["field" => $field, "operator" => $operator, "value" => $value],
    ],
];
$fields = [];
foreach (
    [
        "active" => ["type" => "boolean", "required" => true],
        "amount" => ["type" => "decimal"],
        "count" => ["type" => "integer"],
        "day" => ["type" => "date"],
        "category" => [
            "type" => "select",
            "options" => ["Musik", "Theater", '100%_! " <marker>'],
        ],
        "short" => ["type" => "text"],
        "long" => ["type" => "textarea"],
        "formatted" => ["type" => "richtext"],
        "url" => ["type" => "url"],
        "email" => ["type" => "email"],
        "page" => ["type" => "page-reference"],
        "image" => ["type" => "media-reference"],
    ]
    as $key => $definition
) {
    $fields[$key] = $model->saveField(
        ["key" => $key, "label" => "Own " . $key] + $definition,
    );
}
$fields["details"] = $model->saveField([
    "key" => "details",
    "label" => "Own details",
    "type" => "text",
    "required" => true,
    "min" => 5,
    "show_when" => [
        "match" => "all",
        "conditions" => [
            ["field" => "active", "operator" => "eq", "value" => true],
            ["field" => "count", "operator" => "gte", "value" => "10"],
        ],
    ],
]);
$fields["reply"] = $model->saveField([
    "key" => "reply",
    "label" => "Own reply",
    "type" => "text",
    "required_when" => [
        "match" => "any",
        "conditions" => [
            ["field" => "category", "operator" => "eq", "value" => "Musik"],
            [
                "field" => "amount",
                "operator" => "gt",
                "value" => "999999999999999999.999998",
            ],
        ],
    ],
]);
$fields["rich_note"] = $model->saveField([
    "key" => "rich_note",
    "label" => "Own rich note",
    "type" => "richtext",
    "show_when" => $rule("active", "eq", true),
    "required_when" => $rule("count", "gt", 10),
]);
conditionCheck(
    $fields["details"]["show_when"]["conditions"][1]["value"] === 10,
    "rule constants bind to the actual own integer type",
);
conditionCheck(
    !array_key_exists("show_when", $fields["active"]) &&
        !array_key_exists("required_when", $fields["active"]),
    "unconditional historical definition shape remains exact",
);
$template = $model->saveTemplate([
    "key" => "conditions",
    "label" => "Own conditional content",
    "fields" => array_keys($fields),
]);
$source = $pages->save([
    "title" => "Own related original",
    "slug" => "own-conditions-source",
]);
$pixels = imagecreatetruecolor(12, 9);
$root =
    dirname(__DIR__, 2) .
    "/.local/native-conditions-" .
    bin2hex(random_bytes(5));
mkdir($root, 0700, true);
imagepng($pixels, $root . "/own.png");
unset($pixels);
$media = new Media($db, $access, $root . "/media");
$image = $media->upload(
    $root . "/own.png",
    "own-conditions.png",
    "Own condition picture",
);
$values = [
    "active" => false,
    "amount" => "0",
    "count" => 8,
    "day" => "2026-10-10",
    "category" => "Theater",
    "short" => "Own literal",
    "long" => "Own first\nsecond",
    "formatted" => "<p>Own <strong>formatted</strong> &lt;marker&gt;</p>",
    "url" => "https://example.invalid/own",
    "email" => "own@example.invalid",
    "page" => $source["id"],
    "image" => $image["id"],
    "details" => "Original hidden details",
    "reply" => null,
    "rich_note" => "<p>Original hidden rich note</p>",
];
$page = $pages->save([
    "title" => "Own conditional original",
    "slug" => "own-conditional-original",
    "template" => "conditions",
    "custom_fields" => $values,
]);
$page = $pages->publish($page["id"], $page["version"]);
$original = $pages->publicPage($page["slug"]);
$generation = $pages->generation();
conditionCheck(
    $page["custom_fields"]["details"] === "Original hidden details" &&
        $page["custom_fields"]["rich_note"] === $values["rich_note"],
    "hidden text and rich original values are preserved in their actual saved revision",
);
$states = FieldConditions::states($fields, $page["custom_fields"]);
conditionCheck(
    !$states["details"]["visible"] &&
        !$states["details"]["required"] &&
        !$states["rich_note"]["visible"],
    "hidden unconditional and conditional required fields are not demanded",
);
$empty = $values;
$empty["details"] = null;
$empty["rich_note"] = null;
conditionCheck(
    $model->normalizePage([
        "title" => "Own empty hidden",
        "slug" => "own-empty-hidden",
        "template" => "conditions",
        "custom_fields" => $empty,
    ])["custom_fields"]["details"] === null,
    "server allows a genuinely empty hidden required field",
);
$visible = array_replace($values, ["active" => true, "count" => 10]);
conditionReject(
    fn() => $model->normalizePage([
        "title" => "Missing visible",
        "slug" => "missing-visible",
        "template" => "conditions",
        "custom_fields" => array_replace($visible, ["details" => ""]),
    ]),
    "actual server rejects missing visible required value",
);
$ui = new ContentModel($db, $access, "own-csrf");
conditionReject(
    fn() => $ui->values(
        "conditions",
        array_replace($visible, ["details" => ""]),
    ),
    "HTTP field normalization enforces the same conditional requirement",
);
conditionReject(
    fn() => $ui->values(
        "conditions",
        array_replace($values, ["category" => "Musik", "reply" => ""]),
    ),
    "OR requirement is enforced by the category original",
);
conditionReject(
    fn() => $model->normalizePage([
        "title" => "Exact amount",
        "slug" => "exact-amount",
        "template" => "conditions",
        "custom_fields" => array_replace($values, [
            "amount" => "999999999999999999.999999",
        ]),
    ]),
    "OR requirement distinguishes exact 24 digit decimal values",
);
conditionCheck(
    FieldConditions::states(
        $fields,
        array_replace($values, ["amount" => "999999999999999999.999998"]),
    )["reply"]["required"] === false,
    "adjacent exact decimal does not round into a required value",
);
conditionReject(
    fn() => $ui->values(
        "conditions",
        array_replace($visible, ["count" => 11, "rich_note" => "<p><br></p>"]),
    ),
    "formatted conditional required uses visible original text",
);
conditionReject(
    fn() => $ui->values(
        "conditions",
        array_replace($values, ["details" => "x"]),
    ),
    "hidden present values still respect original typed length limits",
);
conditionReject(
    fn() => $ui->values(
        "conditions",
        array_replace($values, ["count" => "not a number"]),
    ),
    "invalid source values cannot bypass conditional server rules",
);
$http = $ui->values(
    "conditions",
    array_replace($values, [
        "count" => "0008",
        "amount" => "0012,5000",
        "active" => "0",
    ]),
);
conditionCheck(
    $http["count"] === 8 &&
        $http["amount"] === "12.5" &&
        $http["active"] === false,
    "existing HTTP decimal comma integer and false normalization remains exact",
);
$comparisons = [
    ["active", "eq", false, true],
    ["active", "ne", true, true],
    ["active", "filled", null, true],
    ["active", "empty", null, false],
    ["count", "gt", 7, true],
    ["count", "gte", 8, true],
    ["count", "lt", 8, false],
    ["count", "lte", 8, true],
    ["amount", "eq", "-0.000000", true],
    ["amount", "gt", "-0.000001", true],
    ["amount", "lt", "0.000001", true],
    ["amount", "eq", "0,0", true],
    ["day", "gt", "2026-10-09", true],
    ["day", "lte", "2026-10-10", true],
    ["day", "gte", "2026-10-11", false],
    ["category", "eq", "Theater", true],
    ["short", "contains", "literal", true],
    ["short", "contains", "Literal", false],
    ["long", "contains", "first\nsecond", true],
    ["formatted", "contains", "formatted <marker>", true],
    ["formatted", "eq", "Own formatted <marker>", true],
    ["url", "contains", "example.invalid", true],
    ["email", "eq", "own@example.invalid", true],
    ["page", "eq", $source["id"], true],
    ["image", "eq", $image["id"], true],
    ["reply", "empty", null, true],
    ["reply", "eq", null, true],
    ["reply", "ne", null, false],
];
foreach ($comparisons as $index => [$key, $operator, $expected, $matches]) {
    $target = FieldConditions::bind(
        CustomFields::definition([
            "key" => "comparison",
            "label" => "Own comparison",
            "type" => "text",
            "show_when" => $rule($key, $operator, $expected),
        ]),
        $fields,
    );
    conditionCheck(
        FieldConditions::states($fields + ["comparison" => $target], $values)[
            "comparison"
        ]["visible"] === $matches,
        "typed source comparison " . $index . " " . $key . " " . $operator,
    );
}
conditionReject(
    fn() => $model->saveTemplate([
        "key" => "incomplete",
        "label" => "Missing dependency",
        "fields" => ["details"],
    ]),
    "template cannot omit required dependency sources",
);
$definitionCount = (int) Db::run(
    $db,
    "SELECT COUNT(*) FROM khcms_definitions",
)->fetchColumn();
$historyCount = (int) Db::run(
    $db,
    "SELECT COUNT(*) FROM khcms_definition_revisions",
)->fetchColumn();
foreach (
    [
        [],
        ["match" => "any", "conditions" => []],
        [
            "match" => "sql",
            "conditions" => [["field" => "active", "operator" => "filled"]],
        ],
        [
            "match" => "all",
            "conditions" => array_fill(0, 11, [
                "field" => "active",
                "operator" => "filled",
            ]),
        ],
        [
            "match" => "all",
            "conditions" => [
                ["field" => "active", "operator" => "eq", "value" => []],
            ],
        ],
        [
            "match" => "all",
            "conditions" => [
                [
                    "field" => "active",
                    "operator" => "filled",
                    "value" => "unused",
                ],
            ],
        ],
        [
            "match" => "all",
            "conditions" => [
                ["field" => "active", "operator" => "PHP", "value" => "x"],
            ],
        ],
        [
            "match" => "all",
            "conditions" => [
                [
                    "field" => "active",
                    "operator" => "eq",
                    "value" => true,
                    "code" => "exec",
                ],
            ],
        ],
        $rule("missing", "filled"),
        $rule("bad_condition", "filled"),
        $rule("category", "gt", "Theater"),
        $rule("active", "contains", "x"),
        $rule("amount", "gt", null),
        $rule("short", "contains", ""),
        $rule("day", "eq", "2026-02-30"),
        $rule("image", "eq", "bad"),
    ]
    as $index => $bad
) {
    conditionReject(
        fn() => $model->saveField([
            "key" => "bad_condition",
            "label" => "Own bad rule",
            "type" => "text",
            "show_when" => $bad,
        ]),
        "malformed incompatible or absent field rule " . $index . " rolls back",
    );
}
conditionCheck(
    (int) Db::run(
        $db,
        "SELECT COUNT(*) FROM khcms_definitions",
    )->fetchColumn() === $definitionCount &&
        (int) Db::run(
            $db,
            "SELECT COUNT(*) FROM khcms_definition_revisions",
        )->fetchColumn() === $historyCount,
    "rejected rules create neither definition nor revision",
);
$outside = new DataModel($db, new Access($db, "outsider"));
$outside->saveField([
    "key" => "foreign_source",
    "label" => "Foreign source",
    "type" => "text",
]);
conditionReject(
    fn() => $model->saveField([
        "key" => "bad_foreign",
        "label" => "Own target",
        "type" => "text",
        "show_when" => $rule("foreign_source", "filled"),
    ]),
    "rule cannot bind another club field",
);
$foreign = (new Pages($db, new Access($db, "outsider")))->save([
    "title" => "Foreign reference",
    "slug" => "foreign-rule-reference",
]);
conditionReject(
    fn() => $model->saveField([
        "key" => "bad_reference",
        "label" => "Own target",
        "type" => "text",
        "show_when" => $rule("page", "eq", $foreign["id"]),
    ]),
    "rule reference literal cannot bind another club page",
);
$first = $model->saveField([
    "key" => "cycle_first",
    "label" => "Own cycle first",
    "type" => "text",
]);
$second = $model->saveField([
    "key" => "cycle_second",
    "label" => "Own cycle second",
    "type" => "text",
    "show_when" => $rule("cycle_first", "filled"),
]);
conditionReject(
    fn() => $model->saveField(
        $first + ["required_when" => $rule("cycle_second", "filled")],
        $first["version"],
    ),
    "mixed visibility requirement dependency cycle is rejected atomically",
);
conditionCheck(
    $model->field("cycle_first") === $first &&
        count($model->fieldRevisions("cycle_first")) === 1,
    "cycle rejection retains exact original definition and history",
);
conditionReject(
    fn() => $model->saveField(
        array_replace($fields["count"], ["type" => "text"]),
        $fields["count"]["version"],
    ),
    "source type cannot invalidate existing numeric comparison",
);
conditionCheck(
    $model->field("count") === $fields["count"],
    "rejected source type retains the original field",
);
conditionReject(
    fn() => $model->saveField(
        array_replace($fields["reply"], [
            "show_when" => $rule("cycle_first", "filled"),
        ]),
        $fields["reply"]["version"],
    ),
    "rule edit cannot silently break an existing assigned template",
);
conditionCheck(
    FieldConditions::states(
        array_replace($fields, [
            "count" => array_replace($fields["count"], ["type" => "decimal"]),
        ]),
        $values,
    )["details"]["visible"] === false,
    "older integer original remains editable after a compatible decimal type change",
);
$html = $ui->fields("conditions", $values);
conditionCheck(
    str_contains($html, "data-field-conditions=") &&
        str_contains($html, 'data-model-field="details"') &&
        !str_contains($html, 'data-model-field="details" hidden'),
    "server renders all original fields reachable without JavaScript",
);
conditionCheck(
    !preg_match('/name="custom_fields\[details\]"[^>]* required/', $html),
    "initial hidden requirement is not attached to native input",
);
conditionCheck(
    str_contains(
        Html::select(
            "own",
            "Own",
            ["" => "Leer", "1" => "Ja", "0" => "Nein"],
            "0",
        ),
        'value="0" selected',
    ),
    "native Bootstrap select retains actual numeric string false option",
);
$form = [
    "enabled" => "1",
    "match" => "any",
    "conditions" => [
        0 => [
            "field" => "active",
            "operator" => "filled",
            "value" => "ignored",
        ],
        4 => ["field" => "amount", "operator" => "gt", "value" => "12,5"],
    ],
];
conditionCheck(
    FieldRuleEditor::submitted($form) === [
        "match" => "any",
        "conditions" => [
            ["field" => "active", "operator" => "filled"],
            ["field" => "amount", "operator" => "gt", "value" => "12,5"],
        ],
    ],
    "human rule form retains row order after removal and omits unused comparison values",
);
conditionReject(
    fn() => FieldRuleEditor::submitted([
        "enabled" => "1",
        "match" => "all",
        "conditions" => [["field" => [], "operator" => "eq", "value" => "x"]],
    ]),
    "forged rule form array scalar is rejected",
);
conditionCheck(
    FieldRuleEditor::submitted(["enabled" => "0"]) === null,
    "explicit disabled rule removes the dependency",
);
$previousModelQuery=$_GET;$_GET=['model_kind'=>'field','model_key'=>'reply'];
$embedded = (new EmbeddedManager(
    new Manager($db, $access, $root . "/media", "own-csrf", false, true),
    $access,
))->main("model");
$_GET=$previousModelQuery;
$dom = new DOMDocument();
@$dom->loadHTML(
    '<?xml encoding="UTF-8">' . $embedded,
    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
);
$forms = (new DOMXPath($dom))->query("//form[@data-model-form]");
$correct = true;
foreach ($forms as $form) {
    parse_str(parse_url($form->getAttribute("action"),PHP_URL_QUERY)??'', $target);
    if (parse_url($form->getAttribute("action"),PHP_URL_PATH)!=='/vereinsverwaltung/'||($target['view']??null)!=='website'||($target['tab']??null)!=='technical'||($target['model_key']??null)!=='reply') {
        $correct = false;
    }
}
conditionCheck(
    $forms->length === 1 && $correct,
    "every actual embedded field/template POST targets its native manager route",
);
$edited = $model->saveField(
    array_replace($fields["reply"], [
        "required_when" => $rule("category", "eq", "Theater"),
    ]),
    $fields["reply"]["version"],
);
conditionCheck(
    $edited["version"] === 2 && count($model->fieldRevisions("reply")) === 2,
    "rule changes have their own model version and immutable history",
);
conditionCheck(
    $pages->publicPage($page["slug"]) === $original &&
        $pages->generation() === $generation,
    "changed form rules retain exact approved public original and cache generation",
);
conditionReject(
    fn() => $pages->publish($page["id"], $page["version"]),
    "changed required rule cannot republish an unchecked original",
);
$resaved = $pages->save(
    array_replace($page, [
        "custom_fields" => array_replace($values, [
            "reply" => "Own explicit required reply",
        ]),
    ]),
    $page["version"],
);
conditionCheck(
    $resaved["field_definitions"]["reply"]["version"] === 2 &&
        $resaved["custom_fields"]["details"] === $values["details"],
    "deliberate save captures new rule version without discarding hidden original",
);
conditionReject(
    fn() => $pages->publish($page["id"], $page["version"]),
    "stale original version cannot overwrite deliberate new conditional revision",
    Conflict::class,
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='manage'",
);
conditionReject(
    fn() => $model->saveField(
        array_replace($edited, ["label" => "Denied"]),
        $edited["version"],
    ),
    "rule mutation checks current manage revocation",
    DomainException::class,
);
conditionCheck(
    $pages->publicPage($page["slug"]) === $original,
    "all local conditional workflows retain original published bytes",
);
echo json_encode([
    "suite" => "native-field-conditions",
    "checks" => $checks,
    "external_actions" => 0,
]) . "\n";
