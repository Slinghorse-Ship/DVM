<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    Pages,
    Media,
    DataModel,
    AlbumVersions,
    PageVersions,
    Conflict,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\{Manager};
use Kulturhus\Native\Integration\EmbeddedManager;
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$pages = new Pages($db, $access);
$restore = new AlbumVersions($db, new Access($db, "editor"));
$root = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/album-versions";
mkdir($root, 0700, true);
$media = new Media($db, $access, $root . "/media");
$images = [];
$pixels = [];
$checks = 0;
function albumCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS " . $label . "\n";
}
function albumReject(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        albumCheck($e instanceof $class, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
foreach ([12, 140] as $index => $red) {
    $raster = imagecreatetruecolor(40, 30);
    imagefill($raster, 0, 0, imagecolorallocate($raster, $red, 50, 80));
    imagepng($raster, $root . "/own.png");
    $asset = $media->upload(
        $root . "/own.png",
        "own-" . $index . ".png",
        "Own original image " . $index,
    );
    $images[] = $asset["id"];
    foreach (["original", "thumb", "medium", "large"] as $size) {
        $pixels[$asset["id"]][$size] = hash_file(
            "sha256",
            $media->variant($asset["id"], $size)["path"],
        );
    }
}
$album = $pages->save([
    "title" => "Own original album",
    "slug" => "galerie/own-original",
    "template" => "gallery",
    "body_html" => "<p>Own <strong>original</strong> description</p>",
    "nav_visible" => false,
    "media_ids" => $images,
    "gallery_items" => [
        [
            "id" => $images[0],
            "caption" => "Own first <literal>",
            "tags" => ["Original", "First"],
        ],
        ["id" => $images[1], "caption" => "Own second", "tags" => []],
    ],
]);
$album = $pages->publish($album["id"], $album["version"]);
$original = $pages->publicPage($album["slug"]);
$generation = $pages->generation();
$originalJson = Db::run(
    $db,
    "SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=2",
    [$album["id"]],
)->fetchColumn();
$album = $pages->save(
    array_replace($album, [
        "title" => "Own changed album",
        "body_html" => "<p>Own changed description</p>",
        "media_ids" => array_reverse($images),
        "gallery_items" => [
            [
                "id" => $images[1],
                "caption" => "Own changed second",
                "tags" => ["Changed"],
            ],
            [
                "id" => $images[0],
                "caption" => "Own changed first",
                "tags" => [],
            ],
        ],
    ]),
    $album["version"],
);
$restored = $restore->restore($album["id"], 2, $album["version"]);
albumCheck(
    $restored["id"] === $album["id"] &&
        $restored["version"] === 4 &&
        $restored["published_revision"] === 2 &&
        $restored["has_unpublished_changes"],
    "editor without publication rights creates one private restored original",
);
albumCheck(
    $restored["title"] === $original["title"] &&
        $restored["body_html"] === $original["body_html"],
    "original album title and formatted description restored",
);
albumCheck(
    $restored["media_ids"] === $original["media_ids"] &&
        $restored["gallery_items"] === $original["gallery_items"],
    "original image order, literal captions and tags restored exactly",
);
albumCheck(
    (new PageVersions($db, $access))->compare($album["id"], 2, 4)["changes"] ===
        [],
    "restored private payload compares equal to the original publication",
);
albumCheck(
    $pages->publicPage($original["slug"]) === $original &&
        $pages->generation() === $generation,
    "private restoration retains exact public original and cache generation",
);
albumCheck(
    Db::run(
        $db,
        "SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=2",
        [$album["id"]],
    )->fetchColumn() === $originalJson,
    "original historical JSON remains byte-identical",
);
albumCheck(
    Db::run(
        $db,
        "SELECT action FROM khcms_revisions WHERE page_id=? AND revision=4",
        [$album["id"]],
    )->fetchColumn() === "restore",
    "new private revision retains original restore audit action",
);
foreach ($pixels as $id => $sizes) {
    foreach ($sizes as $size => $hash) {
        albumCheck(
            hash_file("sha256", $media->variant($id, $size)["path"]) === $hash,
            "actual original image bytes preserved " . $id . " " . $size,
        );
    }
}
$oldGet = $_GET;
$_GET = [];
$manager = new Manager($db, $access, $root . "/media", "own-csrf");
$html = $manager->main("media", $album["id"]);
albumCheck(
    str_contains($html, 'name="operation" value="album-restore"') &&
        str_contains($html, 'name="csrf" value="own-csrf"') &&
        str_contains($html, 'name="version" value="4"'),
    "actual album UI uses protected current version restore forms",
);
$embedded = (new EmbeddedManager($manager, $access))->main(
    "media",
    $album["id"],
);
albumCheck(
    str_contains($embedded, 'name="action" value="native-module"') &&
        str_contains($embedded, 'name="operation" value="album-restore"'),
    "integrated original manager translates album restore to protected native action",
);
$readOnly = new Manager(
    $db,
    new Access($db, "reader"),
    $root . "/media",
    "reader-csrf",
);
albumCheck(
    !str_contains(
        $readOnly->main("media", $album["id"]),
        'name="operation" value="album-restore"',
    ),
    "read-only original history contains no restoration controls",
);
$post = [
    "operation" => "album-restore",
    "id" => $album["id"],
    "version" => "4",
    "revision" => "2",
    "csrf" => "own-csrf",
];
albumReject(
    fn() => $manager->action("media", array_replace($post, ["id" => []]), []),
    InvalidArgumentException::class,
    "malformed album identity rejected before original access",
);
albumReject(
    fn() => $manager->action(
        "media",
        array_replace($post, ["csrf" => "wrong"]),
        [],
    ),
    DomainException::class,
    "wrong CSRF fails before album restoration",
);
foreach (["0", "-1", "2junk", "01", "+2", true, [], "4294967296"] as $invalid) {
    albumReject(
        fn() => $manager->action(
            "media",
            array_replace($post, ["revision" => $invalid]),
            [],
        ),
        InvalidArgumentException::class,
        "malformed or out-of-range original revision rejected",
    );
}
albumReject(
    fn() => $restore->restore($album["id"], 2, 3),
    Conflict::class,
    "stale original album version cannot write another restore",
);
albumReject(
    fn() => $restore->restore($album["id"], 999, 4),
    RuntimeException::class,
    "absent historical original cannot create a revision",
);
albumReject(
    fn() => (new AlbumVersions($db, new Access($db, "outsider")))->restore(
        $album["id"],
        2,
        4,
    ),
    RuntimeException::class,
    "other club cannot restore original album",
);
albumReject(
    fn() => (new AlbumVersions($db, new Access($db, "reader")))->restore(
        $album["id"],
        2,
        4,
    ),
    DomainException::class,
    "read-only account cannot forge album restoration",
);
albumCheck(
    $pages->get($album["id"])["version"] === 4,
    "all rejected original requests leave private version unchanged",
);
$page = $pages->save([
    "title" => "Own nonalbum original",
    "slug" => "own-nonalbum",
]);
albumReject(
    fn() => $restore->restore($page["id"], 1, 1),
    InvalidArgumentException::class,
    "nonalbum current content cannot enter album restoration",
);
$converted = $pages->save(
    array_replace($page, [
        "template" => "gallery",
        "slug" => "galerie/own-converted",
        "nav_visible" => false,
    ]),
    1,
);
albumReject(
    fn() => $restore->restore($converted["id"], 1, 2),
    InvalidArgumentException::class,
    "older nonalbum revision cannot turn current album into another content type",
);
$container = $pages->save([
    "title" => "Own gallery container",
    "slug" => "galerie",
    "template" => "gallery",
]);
albumReject(
    fn() => $restore->restore($container["id"], 1, 1),
    InvalidArgumentException::class,
    "gallery index is not a restorable individual album",
);
$model = new DataModel($db, $access);
$template = $model->saveTemplate([
    "key" => "own_album",
    "label" => "Own album model",
    "renderer" => "gallery",
    "fields" => [],
]);
$typed = $pages->save([
    "title" => "Own typed album",
    "slug" => "galerie/own-typed",
    "template" => "own_album",
    "nav_visible" => false,
]);
$model->saveTemplate(
    array_replace($template, ["renderer" => "standard"]),
    $template["version"],
);
$before = Db::run($db, "SELECT draft_json FROM khcms_pages WHERE id=?", [
    $typed["id"],
])->fetchColumn();
albumReject(
    fn() => $restore->restore($typed["id"], 1, 1),
    InvalidArgumentException::class,
    "changed current template cannot silently convert restored album",
);
albumCheck(
    $pages->get($typed["id"])["version"] === 1 &&
        Db::run($db, "SELECT draft_json FROM khcms_pages WHERE id=?", [
            $typed["id"],
        ])->fetchColumn() === $before &&
        (int) Db::run(
            $db,
            "SELECT COUNT(*) FROM khcms_revisions WHERE page_id=?",
            [$typed["id"]],
        )->fetchColumn() === 1,
    "late renderer mismatch rolls back private payload and newly inserted revision atomically",
);
$reply = $manager->action("media", $post, []);
albumCheck(
    $reply["view"] === "media" &&
        $reply["item"] === $album["id"] &&
        $pages->get($album["id"])["version"] === 5,
    "actual manager action returns the same restored original album",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='edit'",
);
albumReject(
    fn() => $restore->restore($album["id"], 2, 5),
    DomainException::class,
    "previously constructed service honors current edit revocation",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='read'",
);
albumReject(
    fn() => (new AlbumVersions($db, $access))->restore($album["id"], 2, 5),
    DomainException::class,
    "current read revocation prevents owned original access",
);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','website','read')",
);
albumCheck(
    $pages->get($album["id"])["version"] === 5 &&
        $pages->publicPage($original["slug"]) === $original &&
        $pages->generation() === $generation,
    "final public original, private version and cache remain intact after revocations",
);
$_GET = $oldGet;
echo json_encode(
    [
        "suite" => "native-album-versions",
        "checks" => $checks,
        "external_actions" => 0,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
