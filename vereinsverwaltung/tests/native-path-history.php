<?php
declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";

use Kulturhus\Native\Cms\{Pages, PathHistory, Schema};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\{Manager, Website\PageEditor};

$db = TestSupport::db();
$seed = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $seed["admin"]);
$reader = new Access($db, $seed["reader"]);
$editor = new Access($db, $seed["editor"]);
$outsider = new Access($db, $seed["outsider"]);
$pages = new Pages($db, $access);
$history = new PathHistory($db, $access);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    ++$checks;
};
$deny = static function (callable $work, string $label) use ($check): void {
    try {
        $work();
    } catch (DomainException | InvalidArgumentException | Kulturhus\Native\Cms\Conflict) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
};
$page = $pages->save(["slug" => "verein/frueher", "title" => "Originalseite"]);
$page = $pages->publish($page["id"], $page["version"]);
$original = $pages->revisions($page["id"]);
$draft = $pages->save(
    array_replace($page, [
        "slug" => "verein/heute",
        "title" => "Unveröffentlichter neuer Titel",
    ]),
    $page["version"],
);
$check(
    $history->resolve("verein/frueher") === null &&
        $pages->publicPage("verein/frueher")["title"] === "Originalseite",
    "draft rename does not change old public URL",
);
$deny(
    fn() => $pages->publish($page["id"], $page["version"]),
    "stale publisher cannot create history",
);
$check(
    $history->listing($page["id"]) === [],
    "failed publication creates no link metadata",
);
$page = $pages->publish($draft["id"], $draft["version"]);
$check(
    $history->resolve("verein/frueher") === "verein/heute",
    "published rename resolves old path by original page",
);
$check(
    $pages->publicPage("verein/frueher") === null &&
        $pages->publicPage("verein/heute")["id"] === $page["id"],
    "old path is an alias, not a duplicate page",
);
$revisions = $pages->revisions($page["id"]);
$check(
    array_slice($revisions, -count($original)) === $original,
    "initial revisions stay byte-for-byte unchanged",
);
$draft = $pages->save(
    array_replace($page, [
        "slug" => "verein/morgen",
        "title" => "Geheimer dritter Titel",
    ]),
    $page["version"],
);
$check(
    $history->resolve("verein/frueher") === "verein/heute",
    "history uses published path rather than newer draft",
);
$page = $pages->publish($draft["id"], $draft["version"]);
$check(
    $history->resolve("verein/frueher") === "verein/morgen" &&
        $history->resolve("verein/heute") === "verein/morgen",
    "multiple renames resolve directly without redirect chains",
);
$deny(
    fn() => $pages->save([
        "slug" => "verein/frueher",
        "title" => "Andere Originalseite",
    ]),
    "another page cannot steal an old path",
);
$deny(
    fn() => (new Pages($db, $editor))->save([
        "slug" => "verein/heute",
        "title" => "Andere Seite",
    ]),
    "draft writer cannot steal history either",
);
$originals = $pages->revisions($page["id"]);
$history->remember($page["id"], "historischer-import", $page["version"]);
$row = array_values(
    array_filter(
        $history->listing($page["id"]),
        static fn($r) => $r["slug"] === "historischer-import",
    ),
)[0];
$history->remember($page["id"], "historischer-import", $page["version"]);
$check(
    count($history->listing($page["id"])) === 3 &&
        $history->resolve("historischer-import") === "verein/morgen",
    "explicit old link import is idempotent",
);
$deny(
    fn() => $history->remember($page["id"], "noch-ein-link", 0),
    "old link import requires current original version",
);
$deny(
    fn() => (new PathHistory($db, $editor))->remember(
        $page["id"],
        "fremder-link",
        $page["version"],
    ),
    "ordinary draft writer cannot change public routing",
);
$deny(
    fn() => (new PathHistory($db, $reader))->forget(
        $page["id"],
        $row["id"],
        $page["version"],
        true,
    ),
    "reader cannot remove a redirect",
);
$check(
    (new PathHistory($db, $outsider))->resolve("verein/frueher") === null,
    "old links cannot cross club boundaries",
);
$deny(
    fn() => (new PathHistory($db, $outsider))->listing($page["id"]),
    "foreign private history is hidden",
);
foreach (
    [
        "https://evil.example/path",
        "//evil.example/path",
        "path?token=private",
        "path#fragment",
        "../secret",
        "login",
        "status",
        "status/alter-pfad",
        "page-photo-status",
        "album-photo-status",
        "vereinsverwaltung/settings",
        "newsletter-confirm",
        "media/private",
        "site/old",
        "old\r\nLocation: injected",
    ]
    as $slug
) {
    $deny(
        fn() => $history->remember($page["id"], $slug, $page["version"]),
        "unsafe/system path rejected",
    );
}
foreach (['status','status/unterseite'] as $reserved) {
    $deny(fn() => $pages->save(['slug'=>$reserved,'title'=>'Own reserved status']), 'system status cannot be shadowed by a new CMS page');
}
$manager = new Manager($db, $access, sys_get_temp_dir(), "history-csrf");
$deny(
    fn() => $manager->action(
        "website",
        [
            "operation" => "page-path-add",
            "id" => $page["id"],
            "version" => $page["version"],
            "slug" => "csrf-path",
            "csrf" => "wrong",
        ],
        [],
    ),
    "path action requires CSRF",
);
$manager->action(
    "website",
    [
        "operation" => "page-path-add",
        "id" => $page["id"],
        "version" => $page["version"],
        "slug" => "manager-import",
        "csrf" => "history-csrf",
    ],
    [],
);
$check(
    $history->resolve("manager-import") === "verein/morgen",
    "manager action imports old URL",
);
$html = (new PageEditor(
    $db,
    $access,
    sys_get_temp_dir(),
    "history-csrf",
))->manager($page["id"]);
$check(
    str_contains($html, "Alte Links &amp; Weiterleitungen") &&
        str_contains($html, "historischer-import") &&
        str_contains($html, 'name="confirmed" value="1" required'),
    "native page UI shows history and blank explicit removal confirmation",
);
$html = (new PageEditor(
    $db,
    $reader,
    sys_get_temp_dir(),
    "history-csrf",
))->manager($page["id"]);
$check(
    str_contains($html, "historischer-import") &&
        !str_contains($html, 'value="page-path-remove"') &&
        !str_contains($html, 'value="page-path-add"'),
    "reader sees history without write actions",
);
$deny(
    fn() => $history->forget($page["id"], $row["id"], $page["version"], false),
    "removal requires explicit confirmation",
);
$history->forget($page["id"], $row["id"], $page["version"], true);
$check(
    $history->resolve("historischer-import") === null,
    "removed history stops resolving",
);
$history->remember($page["id"], "historischer-import", $page["version"]);
$deny(
    fn() => $history->forget($page["id"], $row["id"], $page["version"], true),
    "stale removal cannot erase a newly imported mapping",
);
$check(
    $originals === $pages->revisions($page["id"]),
    "link administration does not rewrite page originals",
);
$page = $pages->unpublish($page["id"], $page["version"]);
$check(
    $history->resolve("verein/frueher") === null &&
        $history->resolve("historischer-import") === null,
    "offline content cannot be reached through old links",
);
$page = $pages->publish($page["id"], $page["version"]);
$check(
    $history->resolve("verein/frueher") === "verein/morgen",
    "deliberate republication restores original mappings",
);
$page = $pages->save(
    array_replace($page, ["slug" => "verein/frueher"]),
    $page["version"],
);
$page = $pages->publish($page["id"], $page["version"]);
$check(
    $history->resolve("verein/frueher") === null &&
        $history->resolve("verein/morgen") === "verein/frueher",
    "rename back has no self redirect or chain",
);
Schema::install($db);
$check(
    $history->resolve("verein/morgen") === "verein/frueher",
    "idempotent schema upgrade preserves old links",
);
$check(count($pages->list()) === 1, "history never creates duplicate content");
$check(
    \Kulturhus\Native\Web\PathRedirect::target(
        "verein/heute",
        "/verein/frueher/?page=2&tag=a%20b",
    ) === "/verein/heute/?page=2&tag=a%20b",
    "original query stays attached to fixed same-site target",
);
$check(
    \Kulturhus\Native\Web\PathRedirect::target("start", "/old") === "/",
    "homepage redirect uses original root URL",
);
$check(
    \Kulturhus\Native\Web\PathRedirect::target(
        "verein/heute",
        "/old?page=2",
        true,
    ) === "/site/verein/heute?page=2",
    "standalone demo uses the same checked redirect target",
);
$deny(
    fn() => \Kulturhus\Native\Web\PathRedirect::target(
        "//external.example",
        "/old",
    ),
    "redirect target cannot be an external origin",
);
$paused = $pages->save([
    "slug" => "pause-original",
    "title" => "Zeitweise offline",
]);
$paused = $pages->publish($paused["id"], $paused["version"]);
$paused = $pages->unpublish($paused["id"], $paused["version"]);
$check(
    $history->resolve("pause-original") === null &&
        count($history->listing($paused["id"])) === 1,
    "first public address stays reserved while offline",
);
$paused = $pages->save(
    array_replace($paused, ["slug" => "pause-neu"]),
    $paused["version"],
);
$deny(
    fn() => $pages->save([
        "slug" => "pause-original",
        "title" => "Andere Seite",
    ]),
    "offline draft rename cannot release the original address to another page",
);
$paused = $pages->publish($paused["id"], $paused["version"]);
$check(
    $history->resolve("pause-original") === "pause-neu",
    "rename while offline retains the first published original link",
);
$paused = $pages->archive($paused["id"], $paused["version"]);
$check(
    $history->resolve("pause-original") === null &&
        $history->resolve("pause-neu") === null,
    "archived original and its current URL are not public",
);
$paused = $pages->unarchive($paused["id"], $paused["version"]);
$paused = $pages->save(
    array_replace($paused, ["slug" => "pause-ende"]),
    $paused["version"],
);
$paused = $pages->publish($paused["id"], $paused["version"]);
$check(
    $history->resolve("pause-original") === "pause-ende" &&
        $history->resolve("pause-neu") === "pause-ende",
    "archive and republication still resolve directly by original ID",
);
$check(
    count($pages->list()) === 2,
    "offline lifecycle creates no duplicate pages",
);
echo "$checks native path-history checks passed; original versions, public routing, permissions, conflicts and manager forms.\n";
