<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{
    Schema,
    Pages,
    Media,
    HomeLayout,
    Conflict,
    PageVersions,
    PageCopies,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\{Site, Cache, Manager, Articles};
use Kulturhus\Native\Web\Website\{
    HomeForm,
    HomePage,
    HomePreview,
    VersionValues,
};
use Kulturhus\Native\Integration\EmbeddedManager;
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, "admin");
$pages = new Pages($db, $access);
$root = dirname(getenv("KH_NATIVE_TEST_CONFIG")) . "/home-layout";
mkdir($root, 0700, true);
$media = new Media($db, $access, $root . "/media");
$site = new Site(
    $pages,
    $media,
    new Cache($root . "/cache"),
    "https://website.invalid",
    $access->club(),
    null,
    new Articles($db, $access, $root . "/media"),
    "/?view=website",
    ["name" => "Own club"],
);
$checks = 0;
function homeCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS " . $label . "\n";
}
function homeReject(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        homeCheck($e instanceof $class, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$input = [
    "title" => "Own original home",
    "slug" => "start",
    "excerpt" => "Own original intro",
    "body_html" => "<p>Own original content</p>",
];
$home = $pages->save($input);
$home = $pages->publish($home["id"], 1);
foreach (["veranstaltungen", "verein"] as $slug) {
    $p = $pages->save(["title" => "Own " . $slug, "slug" => $slug]);
    $pages->publish($p["id"], 1);
}
$baseline = $site->page("start");
$generation = $pages->generation();
homeCheck(
    $pages->publicPage('newsletter') === null &&
        substr_count($baseline, 'href="/newsletter/"') === 3 &&
        str_contains($baseline, 'class="newsletter-callout"') &&
        str_contains($baseline, 'Neues zuerst erfahren.') &&
        !str_contains($baseline, 'href="/site/newsletter"'),
    "built-in newsletter remains reachable from hero, introduction and footer without a CMS page or menu entry",
);
homeCheck(
    !isset($home["home_layout"]) &&
        str_contains($baseline, "<h1>Own original home</h1>") &&
        str_contains($baseline, "Gemeinschaft"),
    "older publication retains title, intro and default sections without new payload keys",
);
$zeroHome = array_replace($pages->publicPage("start"), [
    "home_layout" => HomeLayout::normalize(["hero_title" => "0"]),
]);
homeCheck(
    str_contains(
        (new HomePage($pages, $media, null, []))->render($zeroHome, ""),
        "<h1>0</h1>",
    ),
    "valid literal hero title zero survives the shared public renderer",
);
$normal = HomeLayout::normalize([
    "hero_html" =>
        '<p>Own <strong>hero</strong><script>alert(1)</script><a href="javascript:alert(1)">link</a></p>',
    "sections" => ["newsletter", "content", "news", "events", "highlights"],
]);
homeCheck(
    !str_contains($normal["hero_html"], "<script") &&
        !str_contains($normal["hero_html"], "javascript:") &&
        str_contains($normal["hero_html"], "<strong>hero</strong>"),
    "hero rich text sanitized while retaining format",
);
homeCheck(
    HomeLayout::normalize($normal) === $normal,
    "canonical home layout normalizes idempotently for publication checks",
);
foreach (
    [
        "string" => "bad",
        "unknown-key" => ["unknown" => true],
        "wrong-section-type" => ["sections" => false],
        "duplicate" => ["sections" => ["events", "events"]],
        "unknown-section" => ["sections" => ["html"]],
        "section-object" => ["sections" => [1 => "events"]],
        "section-scalar" => ["sections" => [1]],
        "too-many-sections" => ["sections" => array_fill(0, 6, "events")],
        "highlight-type" => ["highlights" => "x"],
        "highlight-object" => ["highlights" => [2 => "Own"]],
        "empty-highlight" => ["highlights" => [""]],
        "many-highlights" => ["highlights" => array_fill(0, 9, "Own")],
        "long-highlight" => ["highlights" => [str_repeat("ä", 81)]],
        "title-type" => ["hero_title" => []],
        "long-title" => ["hero_title" => str_repeat("😀", 201)],
        "control" => ["poster_main" => "bad\0text"],
        "invalid-utf8" => ["poster_top" => "\xff"],
        "empty-heading" => ["news_heading" => ""],
        "long-richtext" => ["hero_html" => str_repeat("x", 20001)],
    ]
    as $label => $bad
) {
    homeReject(
        fn() => HomeLayout::normalize($bad),
        InvalidArgumentException::class,
        "reject malformed bounded layout " . $label,
    );
}
$editor = new Manager(
    $db,
    new Access($db, "editor"),
    $root . "/media",
    "own-csrf",
);
$form = $editor->main("website", $home["id"]);
homeCheck(
    str_contains($form, "data-home-layout") &&
        substr_count($form, 'name="home_sections[') === 5 &&
        str_contains($form, "home_layout_present"),
    "actual home editor exposes five accessible section slots with presence marker",
);
homeCheck(
    str_contains(
        (new EmbeddedManager($editor, new Access($db, "editor")))->main(
            "website",
            $home["id"],
        ),
        'name="action" value="native-module"',
    ),
    "integrated manager retains protected home form routing",
);
homeCheck(
    !str_contains(
        (new Manager(
            $db,
            new Access($db, "reader"),
            $root . "/media",
            "own-csrf",
        ))->main("website", $home["id"]),
        "data-home-layout",
    ),
    "read-only user cannot edit home layout",
);
$post = $home + [
    "operation" => "page-save",
    "csrf" => "own-csrf",
    "home_layout_present" => "1",
    "home_layout_enabled" => "1",
    "home_sections" => [
        "newsletter",
        "content",
        "news",
        "events",
        "highlights",
    ],
    "home_highlights" => "Own music\r\nOwn volunteering",
];
$post["home_layout"] = array_diff_key($normal, [
    "sections" => true,
    "highlights" => true,
]);
$post["home_layout"]["hero_title"] = "Own new hero <literal>";
$post["home_layout"]["news_heading"] = "Own news <literal>";
$post["home_layout"]["events_heading"] = "Own programme {jahr}";
$editor->action("website", $post, []);
$draft = $pages->get($home["id"]);
homeCheck(
    $draft["home_layout"]["sections"] === [
        "newsletter",
        "content",
        "news",
        "events",
        "highlights",
    ] &&
        $draft["home_layout"]["highlights"] === [
            "Own music",
            "Own volunteering",
        ],
    "actual no-JavaScript form compiler stores ordered sections and CRLF highlights",
);
homeCheck(
    $site->page("start") === $baseline && $pages->generation() === $generation,
    "private custom home draft preserves exact public bytes and cached generation",
);
$preview = (new HomePreview(
    $db,
    new Access($db, "editor"),
    $root . "/media",
))->render($home["id"]);
homeCheck(
    str_contains($preview, "<h1>Own new hero &lt;literal&gt;</h1>") &&
        str_contains($preview, "Private Startseitenvorschau") &&
        str_contains($preview, "noindex"),
    "private uncached preview shows current saved hero and explicit noindex status",
);
homeCheck(
    $site->page("start") === $baseline &&
        $pages->get($home["id"])["version"] === $draft["version"],
    "private preview leaves current revision and public bytes unchanged",
);
$embeddedPreview = (new EmbeddedManager(
    $editor,
    new Access($db, "editor"),
))->main("website", $home["id"]);
homeCheck(
    str_contains($embeddedPreview, "native_endpoint=home-preview") &&
        str_contains($embeddedPreview, "item=" . $home["id"]),
    "integrated private preview link preserves current own page identity",
);
homeReject(
    fn() => (new HomePreview(
        $db,
        new Access($db, "outsider"),
        $root . "/media",
    ))->render($home["id"]),
    DomainException::class,
    "private preview denies foreign club home",
);
homeReject(
    fn() => (new HomePreview($db, $access, $root . "/media"))->render("bad"),
    DomainException::class,
    "private preview checks canonical home identity",
);
homeReject(
    fn() => $pages->save(
        array_replace($draft, [
            "home_layout" => ["sections" => ["events", "events"]],
        ]),
        $draft["version"],
    ),
    InvalidArgumentException::class,
    "invalid layout cannot enter a page revision",
);
homeReject(
    fn() => $pages->save($draft, $home["version"]),
    Conflict::class,
    "stale home save rejected",
);
homeReject(
    fn() => (new Pages($db, new Access($db, "reader")))->save(
        $draft,
        $draft["version"],
    ),
    DomainException::class,
    "current edit rights protect home original",
);
homeReject(
    fn() => (new Pages($db, new Access($db, "outsider")))->save(
        $draft,
        $draft["version"],
    ),
    RuntimeException::class,
    "foreign club home inaccessible",
);
homeReject(
    fn() => $editor->action(
        "website",
        array_replace($post, ["csrf" => "wrong"]),
        [],
    ),
    DomainException::class,
    "home form retains CSRF protection",
);
homeReject(
    fn() => HomeForm::fromPost(
        array_replace($post, ["home_sections" => ["news", "news", "", "", ""]]),
    ),
    InvalidArgumentException::class,
    "HTTP duplicate section slots rejected",
);
homeReject(
    fn() => HomeForm::fromPost(
        array_replace($post, ["home_layout_enabled" => []]),
    ),
    InvalidArgumentException::class,
    "HTTP checkbox types checked",
);
homeReject(
    fn() => HomeForm::fromPost(
        array_replace($post, ["home_sections" => ["news"]]),
    ),
    InvalidArgumentException::class,
    "HTTP compiler rejects incomplete section form",
);
homeReject(
    fn() => $editor->action(
        "website",
        array_replace($post, [
            "operation" => "page-publish",
            "version" => $draft["version"],
        ]),
        [],
    ),
    DomainException::class,
    "editing alone cannot publish a custom home",
);
$before = $pages->get($home["id"]);
$plainPost = $before + ["operation" => "page-save", "csrf" => "own-csrf"];
unset($plainPost["home_layout"]);
$editor->action("website", $plainPost, []);
$draft = $pages->get($home["id"]);
homeCheck(
    $draft["home_layout"] === $before["home_layout"],
    "older editors without presence marker preserve current configured home",
);
$published = $pages->publish($home["id"], $draft["version"]);
$html = $site->page("start");
homeCheck(
    str_contains($html, "<h1>Own new hero &lt;literal&gt;</h1>") &&
        str_contains($html, "<strong>hero</strong>") &&
        !str_contains($html, "javascript:"),
    "explicit publication renders exact configured sanitized hero",
);
homeCheck(
    strpos($html, "newsletter-callout") < strpos($html, "native-home-intro") &&
        strpos($html, "native-home-intro") < strpos($html, "section--news") &&
        strpos($html, "section--news") < strpos($html, 'id="termine"') &&
        strpos($html, 'id="termine"') < strpos($html, "culture-strip"),
    "published body sections follow saved original order",
);
homeCheck(
    str_contains($html, "Own news &lt;literal&gt;") &&
        str_contains($html, "Own music") &&
        str_contains($html, "Own programme "),
    "configured section headings and highlights escaped and rendered",
);
$comparison = (new PageVersions($db, $access))->compare(
    $home["id"],
    2,
    $published["version"],
);
$byKey = array_column($comparison["changes"], null, "key");
homeCheck(
    isset($byKey["home.sections"], $byKey["home.hero_html"]) &&
        $byKey["home.sections"]["before"] === null,
    "original version comparison exposes home changes with missing historic fields preserved",
);
$valueHtml = (new VersionValues($db, $access))->render(
    $byKey["home.sections"]["after"],
);
homeCheck(
    str_contains($valueHtml, "Newsletter-Einstieg") &&
        !str_contains($valueHtml, '["newsletter"'),
    "version comparison names sections instead of raw technical data",
);
$copy = (new PageCopies($db, $access))->create(
    $home["id"],
    $published["version"],
    [
        "title" => "Own copied home",
        "slug" => "own-home-copy",
        "basis" => "published",
    ],
);
homeCheck(
    $copy["home_layout"] === $published["home_layout"] &&
        $copy["published_revision"] === null,
    "private copy retains exact home settings without publishing",
);
$restored = $pages->restore($home["id"], 2, $published["version"]);
homeCheck(
    !isset($restored["home_layout"]) && $site->page("start") === $html,
    "restoring older home creates default draft and retains exact published custom home",
);
$restored = $pages->restore(
    $home["id"],
    $published["version"],
    $restored["version"],
);
homeCheck(
    $restored["home_layout"] === $published["home_layout"],
    "restoring configured original preserves precise stored settings",
);
$reset = $restored + [
    "operation" => "page-save",
    "csrf" => "own-csrf",
    "home_layout_present" => "1",
];
$editor->action("website", $reset, []);
$resetDraft = $pages->get($home["id"]);
homeCheck(
    !isset($resetDraft["home_layout"]) && $site->page("start") === $html,
    "explicit defaults checkbox removal affects draft only",
);
$pages->publish($home["id"], $resetDraft["version"]);
homeCheck(
    $site->page("start") === $baseline,
    "explicit default publication restores exact original website bytes",
);
$raster = imagecreatetruecolor(48, 32);
imagepng($raster, $root . "/own.png");
$asset = $media->upload(
    $root . "/own.png",
    "own-home-image.png",
    "Own published event picture",
);
$event = $pages->save([
    "title" => "Own next event",
    "slug" => "own-next-event",
    "template" => "event",
    "event_start" => "2099-04-08T19:00",
    "media_ids" => [$asset["id"]],
]);
$pages->publish($event["id"], 1);
$eventHtml = $site->page("start");
homeCheck(
    str_contains($eventHtml, "event-feature__media") &&
        str_contains($eventHtml, 'alt="Own published event picture"') &&
        str_contains($eventHtml, "Programme 2099") === false &&
        str_contains($eventHtml, "Programm 2099"),
    "next published event restores original website feature image and year",
);
$private = $pages->save([
    "title" => "Own unpublished event",
    "slug" => "own-private-event",
    "template" => "event",
    "event_start" => "2098-04-08T19:00",
]);
homeCheck(
    $site->page("start") === $eventHtml,
    "earlier private event never leaks into home",
);
$current = $pages->get($home["id"]);
$hidden = $pages->save(
    array_replace($current, ["home_layout" => ["sections" => []]]),
    $current["version"],
);
$pages->publish($hidden["id"], $hidden["version"]);
$hiddenHtml = $site->page("start");
homeCheck(
    !str_contains($hiddenHtml, 'id="termine"') &&
        !str_contains($hiddenHtml, 'href="#termine"') &&
        !str_contains($hiddenHtml, "culture-strip") &&
        !str_contains($hiddenHtml, "section--news"),
    "all optional body sections can be hidden without broken event anchor",
);
$current = $pages->get($home["id"]);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='edit'",
);
homeReject(
    fn() => $editor->action(
        "website",
        array_replace($post, ["version" => $current["version"]]),
        [],
    ),
    DomainException::class,
    "existing editor object detects immediate rights revocation",
);
echo json_encode(
    ["checks" => $checks, "external_actions" => 0],
    JSON_THROW_ON_ERROR,
) . "\n";
