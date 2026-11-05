<?php declare(strict_types=1);
require dirname(__DIR__, 2) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/src/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Media};
use Kulturhus\Native\Integration\ManagerBridge;
if (
    PHP_SAPI !== "cli" ||
    (TestSupport::config()["label"] ?? "") !== "integrated-manager"
) {
    throw new RuntimeException("Own synthetic manager fixture required.");
}
$db = TestSupport::db();
$access = (new ManagerBridge(
    $db,
    "manager-admin",
    "native",
    static fn() => null,
))->access();
$root =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/integrated-manager-runtime";
$media = new Media($db, $access, $root . "/media");
$photo = null;
foreach ($media->list() as $entry) {
    if ($entry["alt"] === "Own synthetic page copy fixture pixels") {
        $photo = $entry;
        break;
    }
}
if (!$photo) {
    $image = imagecreatetruecolor(160, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 104, 75));
    imagepng($image, $root . "/page-copy-pixels.png");
    imagedestroy($image);
    $photo = $media->upload(
        $root . "/page-copy-pixels.png",
        "synthetic-page-copy.png",
        "Own synthetic page copy fixture pixels",
    );
}
$pages = new Pages($db, $access);
$fixture = [];
foreach (
    [
        ["website", "standard", "synthetic-copy-source", "pages"],
        ["news", "news", "aktuelles/synthetic-copy-source", "news"],
        ["events", "event", "veranstaltungen/synthetic-copy-source", "events"],
        ["media", "gallery", "galerie/synthetic-copy-source", "gallery"],
    ]
    as [$view, $template, $slug, $tab]
) {
    $existing = $pages->search(
        [["field" => "slug", "op" => "eq", "value" => $slug]],
        1,
    );
    $old = $existing[0] ?? null;
    $title = "Own synthetic copy source " . $view;
    if ($old && $old["title"] !== $title) {
        throw new RuntimeException(
            "Synthetic original address is already in use.",
        );
    }
    if ($old && $old["archived"]) {
        $old = $pages->unarchive($old["id"], $old["version"]);
    }
    $input = [
        "title" => $title,
        "slug" => $slug,
        "template" => $template,
        "body_html" =>
            "<p>Published <strong>original " . $view . " 🎶</strong></p>",
        "news_date" => $template === "news" ? "2050-01-12" : "",
        "news_channels" => ["rss"],
        "nav_visible" => false,
        "event_start" => $template === "event" ? "2050-01-12T20:00" : "",
        "event_location" =>
            $template === "event" ? "Own synthetic location" : "",
        "media_ids" => [$photo["id"]],
        "gallery_items" => [
            [
                "id" => $photo["id"],
                "caption" => "Own original caption",
                "tags" => ["Own original tag"],
            ],
        ],
    ];
    if ($old) {
        $input["id"] = $old["id"];
    }
    $page = $pages->save($input, $old["version"] ?? 0);
    $page = $pages->publish($page["id"], $page["version"]);
    $published = $page["body_html"];
    $page["body_html"] =
        "<p>Current <strong>private draft " . $view . " 🎶</strong></p>";
    $page = $pages->save($page, $page["version"]);
    $fixture[] = [
        "id" => $page["id"],
        "view" => $view,
        "tab" => $tab,
        "slug" => $slug,
        "version" => $page["version"],
        "draft" => $page["body_html"],
        "published" => $published,
        "photo" => $photo["id"],
    ];
}
$path = $root . "/page-copies-browser.json";
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Own synthetic page/article/event/album copy originals prepared; no external transport.\n";
