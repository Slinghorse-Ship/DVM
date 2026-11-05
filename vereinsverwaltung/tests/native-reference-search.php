<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema, Pages, Media, DataModel, ReferenceCatalog};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\{ReferencePicker, ContentModel};
use Kulturhus\Native\Web\Website\PageSidebar;
$db = TestSupport::db();
if (($argv[1] ?? "") === "bounded-memory") {
    $access = new Access($db, "admin");
    $catalog = new ReferenceCatalog($db, $access);
    $html = (new ContentModel($db, $access, "own-csrf"))->fields(
        "own_reference_template",
        [
            "own_page_link" => getenv("DVM_REFERENCE_SELECTED"),
            "own_image_link" => getenv("DVM_REFERENCE_IMAGE"),
        ],
    );
    $result = $catalog->listing(
        "page",
        "Own reference",
        2,
        getenv("DVM_REFERENCE_SELECTED"),
    );
    $sidebar = (new PageSidebar($db, $access))->render(
        getenv("DVM_REFERENCE_SELECTED"),
    );
    echo json_encode([
        "rows" => count($result["rows"]),
        "total" => $result["total"],
        "options" => substr_count($html, "<option"),
        "sidebar_rows" => substr_count($sidebar, "data-page-directory="),
        "memory" => memory_get_peak_usage(true),
    ]);
    exit();
}
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$pages = new Pages($db, $access);
$catalog = new ReferenceCatalog($db, $access);
$n = 0;
function referenceCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
    echo "PASS " . $label . "\n";
}
function referenceReject(
    callable $fn,
    string $label,
    string $class = InvalidArgumentException::class,
): void {
    try {
        $fn();
    } catch (Throwable $error) {
        referenceCheck($error instanceof $class, $label);
        return;
    }
    throw new RuntimeException($label);
}
$rows = [];
foreach (range(0, 68) as $i) {
    $rows[] = $pages->save([
        "title" => "Own reference " . sprintf("%02d", $i),
        "slug" => "reference-" . sprintf("%02d", $i),
        "body_html" => "<p>Private detail not for reference search</p>",
        "nav_visible" => false,
    ]);
}
$rows[0] = $pages->publish($rows[0]["id"], $rows[0]["version"]);
$original = $pages->publicPage($rows[0]["slug"]);
$generation = $pages->generation();
$root =
    dirname(__DIR__, 2) .
    "/.local/native-reference-tests/" .
    bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$pixels = imagecreatetruecolor(20, 12);
imagefill($pixels, 0, 0, imagecolorallocate($pixels, 10, 80, 120));
imagepng($pixels, $root . "/original.png");
unset($pixels);
$media = new Media($db, $access, $root . "/media");
$images = [];
foreach (range(0, 57) as $i) {
    $images[] = $media->upload(
        $root . "/original.png",
        "own-reference-" . sprintf("%02d", $i) . ".png",
        "Own reference image " . sprintf("%02d", $i),
    );
}
$imageBytes = file_get_contents(
    $media->variant($images[57]["id"], "original")["path"],
);
$foreignPages = new Pages($db, new Access($db, "outsider"));
$foreign = $foreignPages->save([
    "title" => "Foreign private reference",
    "slug" => "foreign-reference",
]);
$foreignImage = (new Media(
    $db,
    new Access($db, "outsider"),
    $root . "/other-media",
))->upload(
    $root . "/original.png",
    "foreign-private.png",
    "Foreign private picture",
);
$filter = "Own reference";
$ids = static fn(array $result): array => array_column($result["rows"], "id");
$first = $catalog->listing("page", $filter);
referenceCheck(
    $first["total"] === 69 &&
        $first["page"] === 1 &&
        $first["last"] === 3 &&
        count($first["rows"]) === 25,
    "SQL first reference page is bounded and has exact count",
);
referenceCheck(
    $ids($first) === array_column(array_slice($rows, 0, 25), "id"),
    "reference ordering preserves original titles",
);
$second = $catalog->listing("page", $filter, 2, $rows[68]["id"]);
referenceCheck(
    $ids($second) === array_column(array_slice($rows, 25, 25), "id"),
    "reference second page has no duplicate or skipped original",
);
referenceCheck(
    $second["selected"]["id"] === $rows[68]["id"] &&
        !in_array($rows[68]["id"], $ids($second), true),
    "selected reference outside the result slice is retained separately",
);
$last = $catalog->listing("page", $filter, 999999);
referenceCheck(
    $last["page"] === 3 &&
        count($last["rows"]) === 19 &&
        $ids($last) === array_column(array_slice($rows, 50), "id"),
    "large reference page clamps to correct last slice",
);
referenceCheck(
    array_keys($first["rows"][0]) === ["id", "label"] &&
        !str_contains(json_encode($first), "Private detail"),
    "reference response contains metadata only",
);
referenceCheck(
    $catalog->listing("page", "REFERENCE-68")["rows"][0]["id"] ===
        $rows[68]["id"],
    "literal private slug search is case insensitive",
);
referenceCheck(
    $catalog->listing("page", "REFERENCE 68")["rows"][0]["id"] ===
        $rows[68]["id"],
    "literal private title search is case insensitive",
);
referenceCheck(
    $catalog->listing("page", "Private detail")["total"] === 0,
    "reference search does not expose private article body",
);
referenceCheck(
    $catalog->listing("page", $filter, 1, "", $rows[0]["id"])["total"] === 68,
    "parent candidates exclude current source identity",
);
referenceCheck(
    $catalog->listing("page", $filter, 1, $rows[0]["id"], $rows[0]["id"])[
        "selected"
    ] === null,
    "a self-parent cannot reappear as selected metadata",
);
referenceCheck(
    $catalog->listing("page", "Foreign private")["total"] === 0 &&
        $catalog->listing("page", "", 1, $foreign["id"])["selected"] === null,
    "foreign page search and selected metadata stay private",
);
$picture = $catalog->listing(
    "media",
    "Own reference image",
    3,
    $images[0]["id"],
);
referenceCheck(
    $picture["total"] === 58 &&
        count($picture["rows"]) === 8 &&
        $ids($picture) === array_column(array_slice($images, 50), "id"),
    "picture choices use bounded stable SQL pages",
);
referenceCheck(
    $picture["selected"]["id"] === $images[0]["id"],
    "selected picture outside current result slice is retained",
);
referenceCheck(
    $catalog->listing("media", "OWN-REFERENCE-57.PNG")["rows"][0]["id"] ===
        $images[57]["id"],
    "literal private filename search works",
);
referenceCheck(
    $catalog->listing("media", "Foreign private")["total"] === 0 &&
        $catalog->listing("media", "", 1, $foreignImage["id"])["selected"] ===
            null,
    "foreign image names never appear in search or selection",
);
referenceCheck(
    array_keys($picture["rows"][0]) === ["id", "label"] &&
        !str_contains(json_encode($picture), "variants"),
    "image response exposes neither file paths nor pixel content",
);
$literal = $pages->save([
    "title" => 'Own escaped 100%_! " <marker> Ä',
    "slug" => "escaped-reference",
]);
$literalImage = $media->upload(
    $root . "/original.png",
    "literal.png",
    'Own escaped picture 100%_! " <marker> Ä',
);
foreach (["100%_!", "<marker> ä"] as $q) {
    referenceCheck(
        $catalog->listing("page", $q)["rows"][0]["id"] === $literal["id"],
        "page literal punctuation and Unicode " . $q,
    );
    referenceCheck(
        $catalog->listing("media", $q)["rows"][0]["id"] === $literalImage["id"],
        "picture literal punctuation and Unicode " . $q,
    );
}
referenceCheck(
    $catalog->listing("page", "' OR 1=1 --")["total"] === 0 &&
        $catalog->listing("media", "' OR 1=1 --")["total"] === 0,
    "SQL-looking reference queries remain ordinary literal text",
);
$empty = $catalog->listing("page", "nothing present", 999999, $rows[68]["id"]);
referenceCheck(
    $empty["rows"] === [] &&
        $empty["page"] === 1 &&
        $empty["last"] === 1 &&
        $empty["selected"]["id"] === $rows[68]["id"],
    "empty reference results preserve current selection coherently",
);
foreach (
    [
        ["kind" => "other"],
        ["kind" => []],
        ["kind" => null],
        ["q" => []],
        ["q" => null],
        ["q" => "bad\ntext"],
        ["q" => "\xff"],
        ["q" => str_repeat("a", 121)],
        ["page" => "0"],
        ["page" => "-1"],
        ["page" => "1.2"],
        ["page" => []],
        ["page" => "1000000"],
        ["selected" => "bad"],
        ["selected" => []],
        ["selected" => null],
        ["exclude" => "bad"],
        ["exclude" => []],
        ["native_endpoint" => "reference-options", "SQL" => "bad"],
    ]
    as $i => $bad
) {
    referenceReject(
        fn() => $catalog->query(array_replace(["kind" => "page"], $bad)),
        "malformed reference query " . $i . " rejected",
    );
}
referenceReject(
    fn() => $catalog->listing("media", "", 1, "", $rows[0]["id"]),
    "picture search rejects page-only exclusions",
);
$picker = new ReferencePicker($db, $access);
$html = $picker->render(
    "page",
    "own-link",
    "own-link",
    $rows[68]["id"],
    true,
    "own-hint",
);
referenceCheck(
    substr_count($html, "<option") === 27 &&
        str_contains($html, 'value="' . $rows[68]["id"] . '" selected'),
    "reference control renders at most 25 choices plus empty and retained original",
);
referenceCheck(
    str_contains($html, 'name="own-link"') &&
        str_contains($html, " required") &&
        str_contains($html, 'aria-describedby="own-hint"'),
    "native reference control preserves submitted name, requirement and described hint",
);
referenceCheck(
    str_contains($html, "data-dirty-ignore") &&
        str_contains($html, 'type="button"') &&
        str_contains($html, "btn btn-solid theme-secondary"),
    "reference search has non-submitting Bootstrap actions and separate unsaved state",
);
referenceCheck(
    str_contains(
        $picker->render("page", "literal", "literal", $literal["id"]),
        "&lt;marker&gt;",
    ),
    "stored reference labels are escaped as literal HTML text",
);
referenceCheck(
    !str_contains(
        $picker->render("page", "foreign", "foreign", $foreign["id"]),
        "Foreign private",
    ) &&
        str_contains(
            $picker->render("page", "foreign", "foreign", $foreign["id"]),
            "Auswahl nicht mehr verfügbar",
        ),
    "unavailable selected reference keeps its identity without foreign metadata",
);
$model = new DataModel($db, $access);
$model->saveField([
    "key" => "own_page_link",
    "label" => "Own related page",
    "type" => "page-reference",
    "required" => true,
]);
$model->saveField([
    "key" => "own_image_link",
    "label" => "Own related picture",
    "type" => "media-reference",
]);
$model->saveTemplate([
    "key" => "own_reference_template",
    "label" => "Own reference template",
    "fields" => ["own_page_link", "own_image_link"],
]);
$fields = (new ContentModel($db, $access, "own-csrf"))->fields(
    "own_reference_template",
    ["own_page_link" => $rows[68]["id"], "own_image_link" => $images[57]["id"]],
);
referenceCheck(
    substr_count($fields, "data-reference-picker") === 2 &&
        substr_count($fields, "<option") === 54,
    "all free page/image reference fields use shared bounded picker",
);
$saved = $pages->save([
    "title" => "Saved own reference",
    "slug" => "saved-own-reference",
    "template" => "own_reference_template",
    "custom_fields" => [
        "own_page_link" => $rows[68]["id"],
        "own_image_link" => $images[57]["id"],
    ],
]);
referenceCheck(
    $saved["custom_fields"]["own_page_link"] === $rows[68]["id"] &&
        $saved["custom_fields"]["own_image_link"] === $images[57]["id"],
    "existing typed save and original reference identities are unchanged",
);
referenceReject(
    fn() => $pages->save(
        array_replace($saved, [
            "custom_fields" => [
                "own_page_link" => $foreign["id"],
                "own_image_link" => $images[57]["id"],
            ],
        ]),
        $saved["version"],
    ),
    "selected foreign id cannot bypass existing save rights",
);
referenceCheck(
    $pages->publicPage($rows[0]["slug"]) === $original &&
        $pages->generation() === $generation,
    "reference read and unrelated draft saves retain exact public originals",
);
referenceCheck(
    file_get_contents(
        $media->variant($images[57]["id"], "original")["path"],
    ) === $imageBytes,
    "reference search retains actual original image bytes",
);
$sidebar = new PageSidebar($db, $access);
$oldQuery = $_GET;
$directoryIds = static function (string $html): array {
    preg_match_all('/data-page-directory="([a-f0-9]{32})"/', $html, $matches);
    return $matches[1];
};
$expectedDirectory = Db::run(
    $db,
    "SELECT id FROM khcms_pages WHERE club_id=? ORDER BY updated_at DESC,id",
    ["synthetic-a"],
)->fetchAll(PDO::FETCH_COLUMN);
$_GET = [];
$directory = $sidebar->render($rows[68]["id"]);
referenceCheck(
    count($directoryIds($directory)) === 25 &&
        $directoryIds($directory) === array_slice($expectedDirectory, 0, 25),
    "standalone directory uses exact first metadata slice",
);
referenceCheck(
    str_contains(
        $directory,
        count($expectedDirectory) . " Inhalte · Seite 1 von 3",
    ) &&
        !str_contains($directory, "Private detail") &&
        !str_contains($directory, "Foreign private"),
    "standalone directory counts all own pages without content bodies or other clubs",
);
referenceCheck(
    str_contains($directory, "item=" . $rows[68]["id"] . "&amp;sidebar_page=2"),
    "standalone next directory page retains current editor identity",
);
$_GET = ["sidebar_page" => "2"];
$directory = $sidebar->render($rows[68]["id"]);
referenceCheck(
    $directoryIds($directory) === array_slice($expectedDirectory, 25, 25),
    "standalone second directory slice has no duplicate or skipped original",
);
$_GET = ["sidebar_page" => "999999"];
$directory = $sidebar->render(
    $expectedDirectory[count($expectedDirectory) - 1],
);
referenceCheck(
    $directoryIds($directory) === array_slice($expectedDirectory, 50) &&
        str_contains($directory, "Seite 3 von 3"),
    "standalone directory clamps to complete last slice",
);
referenceCheck(
    str_contains($directory, 'aria-current="page"') &&
        str_contains($directory, "&amp;sidebar_page=3"),
    "standalone directory labels the current original and retains its page when opened",
);
foreach (["0", "-1", "1.5", "1000000", [], null] as $i => $bad) {
    $_GET = ["sidebar_page" => $bad];
    referenceReject(
        fn() => $sidebar->render(),
        "malformed standalone directory page " . $i . " rejected",
    );
}
$_GET = $oldQuery;
for ($i = 0; $i < 240; $i++) {
    $pages->save([
        "title" => "Own large unrelated " . sprintf("%03d", $i),
        "slug" => "large-reference-" . $i,
        "body" => str_repeat("Own private unrelated body. ", 3200),
        "nav_visible" => false,
    ]);
}
putenv("DVM_REFERENCE_SELECTED=" . $rows[68]["id"]);
putenv("DVM_REFERENCE_IMAGE=" . $images[57]["id"]);
$process = proc_open(
    [
        ...TestSupport::phpCommand(),
        "-d",
        "memory_limit=16M",
        "-d",
        "zend.exception_ignore_args=1",
        __FILE__,
        "bounded-memory",
    ],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException("Reference memory process unavailable.");
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
$memory = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
referenceCheck(
    $code === 0 &&
        $errors === "" &&
        ($memory["rows"] ?? 0) === 25 &&
        ($memory["total"] ?? 0) === 70 &&
        ($memory["options"] ?? 0) === 54 &&
        ($memory["sidebar_rows"] ?? 0) === 25 &&
        ($memory["memory"] ?? PHP_INT_MAX) < 16 * 1024 * 1024,
    "bounded reference fields and query work under 16 MiB with over 20 MB unrelated drafts",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id=? AND module='website' AND capability='read'",
    ["admin"],
);
referenceReject(
    fn() => $catalog->listing("page"),
    "reference search checks immediate read revocation",
    DomainException::class,
);
referenceReject(
    fn() => $catalog->listing("media"),
    "picture search checks immediate read revocation",
    DomainException::class,
);
referenceReject(
    fn() => $picker->render("page", "revoked", "revoked", ""),
    "rendering checks immediate read revocation",
    DomainException::class,
);
referenceReject(
    fn() => $sidebar->render(),
    "standalone directory checks immediate read revocation",
    DomainException::class,
);
echo json_encode([
    "suite" => "native-reference-search",
    "checks" => $n,
    "memory_proof" => $memory,
    "external_requests" => 0,
]) . "\n";
