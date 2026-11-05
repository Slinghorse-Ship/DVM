<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    DataModel,
    CustomFields,
    Pages,
    Media,
    Conflict,
};
use Kulturhus\Native\Shared\{Access, RichText, Db};
use Kulturhus\Native\Web\{ContentModel, RichTextEditor, Site, Cache};
$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$read = new Access($db, $actors["reader"]);
$model = new DataModel($db, $access);
$pages = new Pages($db, $access);
$n = 0;
function richFieldCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function richFieldReject(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        richFieldCheck($e instanceof $class, $label);
        return;
    }
    throw new RuntimeException($label);
}
$legacy = $pages->save([
    "title" => "Preserved plain page",
    "slug" => "preserved-plain",
]);
$legacy = $pages->publish($legacy["id"], $legacy["version"]);
$before = json_encode($pages->publicPage("preserved-plain"));
$definition = $model->saveField([
    "key" => "formatted_notes",
    "label" => "Formatierte Vereinsangaben",
    "type" => "richtext",
    "required" => true,
    "min" => 3,
    "max" => 200,
]);
$optional = $model->saveField([
    "key" => "optional_notes",
    "label" => "Optional format",
    "type" => "richtext",
    "max" => 200,
]);
$template = $model->saveTemplate([
    "key" => "formatted_page",
    "label" => "Formatted page",
    "renderer" => "standard",
    "fields" => ["formatted_notes", "optional_notes"],
]);
richFieldCheck(
    $definition["type"] === "richtext" && $definition["version"] === 1,
    "twelfth declarative type uses actual versioned field/template model",
);
richFieldCheck(
    CustomFields::value($optional, "<p>&nbsp;<br></p>") === null,
    "formatting and nonbreaking whitespace alone remain an empty optional field",
);
richFieldReject(
    fn() => CustomFields::value($definition, "<script>alert(1)</script>"),
    InvalidArgumentException::class,
    "stripped executable content cannot fill a required field",
);
richFieldReject(
    fn() => CustomFields::value($definition, "<p>🎉</p>"),
    InvalidArgumentException::class,
    "minimum counts actual Unicode text rather than HTML markup",
);
richFieldReject(
    fn() => CustomFields::value(
        $definition,
        "<p>" . str_repeat("ä", 201) . "</p>",
    ),
    InvalidArgumentException::class,
    "maximum counts visible Unicode characters",
);
richFieldReject(
    fn() => CustomFields::value($definition, []),
    InvalidArgumentException::class,
    "array cannot become formatted content",
);
richFieldReject(
    fn() => CustomFields::value($definition, "\xff"),
    InvalidArgumentException::class,
    "invalid UTF8 is refused",
);
$raw =
    '<h2>Musik 🎶</h2><p>Kultur <strong>&amp; Begegnung</strong> <a href="javascript:alert(1)" onclick="alert(1)">sicher</a></p><script>alert(1)</script><table><tr><td>Freitag</td><td>Verein</td></tr></table>';
$raw .= '<p><a href="mailto:own%25news@example.invalid">Eigene Mail Ä</a> <a href="tel:04454.12345">Eigenes Telefon</a></p>';
$safe = RichText::sanitize($raw);
richFieldCheck(str_contains($safe, 'href="mailto:own%25news@example.invalid"') && str_contains($safe, 'href="tel:04454.12345"'), 'actual typed richtext input retains exact own mail and telephone targets');
$page = $pages->save([
    "title" => "Formatted original",
    "slug" => "formatted-original",
    "template" => "formatted_page",
    "custom_fields" => ["formatted_notes" => $raw],
    "custom_field_text" => ["formatted_notes" => "forged search data"],
]);
richFieldCheck(
    $page["custom_fields"]["formatted_notes"] === $safe,
    "actual saved field sanitizes links, scripts and handlers while preserving formatting",
);
richFieldCheck(
    $page["custom_field_text"]["formatted_notes"] === RichText::plain($safe),
    "derived search text is authoritative, cannot be forged by input",
);
foreach (
    [
        ["contains", "Kultur & Begegnung"],
        ["contains", "Freitag"],
        ["eq", RichText::plain($safe)],
        ["starts_with", "Musik 🎶"],
    ]
    as [$op, $value]
) {
    richFieldCheck(
        count(
            $pages->search([
                [
                    "field" => "custom.formatted_notes",
                    "op" => $op,
                    "value" => $value,
                ],
            ]),
        ) === 1,
        "visible-text selector " .
            $op .
            " crosses formatting and decodes entities",
    );
}
richFieldCheck(
    $pages->search([
        [
            "field" => "custom.formatted_notes",
            "op" => "contains",
            "value" => "%' OR 1=1 --",
        ],
    ]) === [],
    "SQL-looking search remains escaped bound visible text",
);
richFieldCheck(
    (new Pages($db, new Access($db, $actors["outsider"])))->search([
        ["field" => "slug", "op" => "eq", "value" => "formatted-original"],
    ]) === [],
    "other club cannot read or search this original",
);
$ui = new ContentModel($db, $access, "synthetic-csrf");
$html = $ui->fields("formatted_page", $page["custom_fields"]);
richFieldCheck(
    str_contains($html, 'name="custom_fields[formatted_notes]"') &&
        str_contains($html, 'data-richtext-required="1"') &&
        str_contains($html, 'aria-required="true"'),
    "typed UI uses original nested field name and accessible required editor",
);
richFieldCheck(
    str_contains($html, 'data-richtext-min="3"') &&
        str_contains($html, 'data-richtext-max="200"') &&
        !str_contains($html, "javascript:"),
    "UI carries visible-text bounds with safe persisted HTML",
);
richFieldReject(
    fn() => RichTextEditor::render("custom_fields[x][y]", "test"),
    DomainException::class,
    "arbitrary nested editor names cannot create unexpected form structure",
);
richFieldReject(
    fn() => (new DataModel($db, new Access($db, $actors["editor"])))->saveField(
        ["key" => "denied", "label" => "Denied", "type" => "richtext"],
    ),
    DomainException::class,
    "editor may fill fields but cannot change definitions",
);
$page = $pages->publish($page["id"], $page["version"]);
$root = sys_get_temp_dir() . "/dvm-rich-fields-" . bin2hex(random_bytes(8));
try {
    $site = new Site(
        new Pages($db, $read),
        new Media($db, $read, $root . "/media"),
        new Cache($root . "/cache"),
        "https://synthetic.example",
        $access->club(),
    );
    $public = $site->page("formatted-original");
    richFieldCheck(
        str_contains($public, $safe) &&
            str_contains($public, 'class="kh-richtext"') &&
            !str_contains($public, "javascript:"),
        "actual public renderer safely renders formatted original snapshot",
    );
    $draft = $pages->save(
        array_replace($page, [
            "custom_fields" => [
                "formatted_notes" => "<p>Geänderte Fassung</p>",
            ],
        ]),
        $page["version"],
    );
    richFieldCheck(
        $site->page("formatted-original") === $public,
        "editing formatted draft leaves published content and cache unchanged",
    );
    $changed = $model->saveField(
        array_replace($definition, ["max" => 199]),
        $definition["version"],
    );
    richFieldReject(
        fn() => $pages->publish($draft["id"], $draft["version"]),
        Conflict::class,
        "changed field definition requires deliberate resave before publication",
    );
    $draft = $pages->save($draft, $draft["version"]);
    $draft = $pages->publish($draft["id"], $draft["version"]);
    richFieldCheck(
        str_contains(
            $site->page("formatted-original"),
            "<p>Geänderte Fassung</p>",
        ),
        "deliberate resave and publish replace actual cached public formatted text",
    );
    $restored = $pages->restore($draft["id"], 1, $draft["version"]);
    richFieldCheck(
        $restored["custom_fields"]["formatted_notes"] === $safe,
        "original historical formatted HTML survives revision restoration",
    );
    richFieldCheck(
        json_encode($pages->publicPage("preserved-plain")) === $before,
        "existing plain publication keeps exact original snapshot shape",
    );
    Db::run(
        $db,
        "DELETE FROM native_grants WHERE actor_id=? AND module='website' AND capability='edit'",
        [$actors["editor"]],
    );
    richFieldReject(
        fn() => (new Pages($db, new Access($db, $actors["editor"])))->save(
            $restored,
            $restored["version"],
        ),
        DomainException::class,
        "current revoked edit grant stops formatted writes",
    );
} finally {
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }
}
echo json_encode(
    ["checks" => $n, "driver" => "mysql", "external_actions" => 0],
    JSON_THROW_ON_ERROR,
) . "\n";
