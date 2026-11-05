<?php
declare(strict_types=1);
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";

use Kulturhus\Native\Cms\{
    Articles,
    Conflict,
    DataModel,
    NewsReview,
    Pages,
    PublicationSchedule,
    Schema,
};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\{Articles as ArticleView, Manager};
use Kulturhus\Native\Web\Website\PageEditor;

$checks = [];
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks[] = $label;
}
function rejects(callable $action, string $label): void
{
    try {
        $action();
    } catch (Conflict | DomainException | InvalidArgumentException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException("FAIL: accepted " . $label);
}
$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$editorAccess = new Access($db, $actors["editor"]);
$pages = new Pages($db, $access);
$articles = new Articles($db, $access);
$editor = new Articles($db, $editorAccess);
$root = dirname((string) getenv("KH_NATIVE_TEST_CONFIG")) . "/review-media";
$view = new ArticleView($db, $access, $root, "test-csrf");
$item = $articles->save([
    "title" => "Originalbeitrag",
    "slug" => "aktuelles/review",
    "news_date" => "2050-02-03",
    "body" => "Unveränderter Originaltext",
]);
$id = $item["id"];
check(
    NewsReview::status($item) === "draft" &&
        !array_key_exists("news_review", $item),
    "old native draft shape remains byte-compatible",
);
$item = $editor->review($id, "review", $item["version"]);
check(
    NewsReview::status($item) === "review" && $item["version"] === 2,
    "editor submits exact stored revision for review",
);
check(
    $pages->publicPage($item["slug"]) === null && $pages->generation() === 0,
    "review has no public page or cache mutation",
);
rejects(
    fn() => $editor->review($id, "approved", $item["version"]),
    "editor cannot approve a revision",
);
rejects(
    fn() => $articles->review($id, "approved", 1),
    "stale review cannot approve a newer revision",
);
rejects(
    fn() => $articles->review($id, "review", $item["version"]),
    "repeated review is a conflict rather than an extra revision",
);
$item = $articles->review($id, "approved", $item["version"]);
$approvalRevision = $item["version"];
check(
    $item["news_review"] === "approved" &&
        $pages->publicPage($item["slug"]) === null,
    "publisher approval remains private",
);
$revision = $articles->revisions($id)[0];
check(
    $revision["action"] === "news-approve" &&
        $revision["actor_id"] === "admin" &&
        $revision["payload"]["news_review"] === "approved",
    "approval has an immutable original revision and actor",
);
check(
    count($articles->list("", "approved")) === 1 &&
        $articles->list("", "review") === [],
    "news lists filter by current editorial decision",
);
check(
    str_contains($view->manager($id), 'data-news-review="approved"') &&
        str_contains(
            $view->manager("", ["status" => "approved"]),
            "Originalbeitrag",
        ),
    "real article editor and list render current approval",
);
check(
    str_contains(
        (new PageEditor($db, $access, $root, "test-csrf"))->manager($id),
        'value="page-review"',
    ),
    "general page editor shares the review panel",
);
$item = $articles->publish($id, $item["version"]);
$published = $pages->publicPage($item["slug"]);
$publicBytes = json_encode($published, JSON_THROW_ON_ERROR);
$generation = $pages->generation();
$item = $editor->save(
    ["id" => $id, "body" => "Privater neuer Entwurf"],
    $item["version"],
);
check(
    $item["news_review"] === "draft" &&
        json_encode($pages->publicPage($item["slug"]), JSON_THROW_ON_ERROR) ===
            $publicBytes,
    "content edit resets review while the public original stays byte-identical",
);
$item = $editor->review($id, "review", $item["version"]);
check(
    $pages->generation() === $generation &&
        json_encode($pages->publicPage($item["slug"]), JSON_THROW_ON_ERROR) ===
            $publicBytes,
    "review of a new draft preserves public cache generation and original",
);
check(
    str_contains($view->manager("", ["status" => "review"]), "Zur Prüfung"),
    "review list also includes changes to a published article",
);
$item = $articles->review($id, "approved", $item["version"]);
$item = $editor->restore($id, $approvalRevision, $item["version"]);
check(
    $item["news_review"] === "draft" &&
        $item["body"] === "Unveränderter Originaltext",
    "restoring an approved historical original resets its current approval",
);
$item = $articles->review($id, "approved", $item["version"]);
$submitted = $item;
unset($submitted["news_review"]);
$submitted["body"] = "Altes vollständiges DTO ohne Redaktionsfeld";
$submitted["body_html"] = "";
$item = $pages->save($submitted, $item["version"]);
check(
    $item["news_review"] === "draft",
    "old full-page callers cannot omit the review reset",
);
$item = $editor->save(
    ["id" => $id, "news_review" => "approved"],
    $item["version"],
);
check(
    $item["news_review"] === "draft",
    "submitted approval field cannot manufacture a decision",
);
$item = $articles->review($id, "approved", $item["version"]);
$item = $articles->archive($id, $item["version"]);
rejects(
    fn() => $articles->review($id, "review", $item["version"]),
    "archived article cannot receive review decisions",
);
$item = $editor->unarchive($id, $item["version"]);
check(
    $item["news_review"] === "draft" &&
        $pages->publicPage($item["slug"]) === null,
    "returning from archive requires fresh review and publication",
);
$reader = new Articles($db, new Access($db, $actors["reader"]));
$outsider = new Articles($db, new Access($db, $actors["outsider"]));
rejects(
    fn() => $reader->review($id, "review", $item["version"]),
    "reader cannot submit a decision",
);
try {
    $outsider->review($id, "approved", $item["version"]);
    throw new LogicException("Foreign approval accepted.");
} catch (RuntimeException $e) {
    check(
        $e->getMessage() === "Seite nicht gefunden.",
        "foreign club cannot approve or read the original",
    );
}
foreach (["published", "archived", "APPROVED", "", null, [], true] as $bad) {
    rejects(
        fn() => NewsReview::normalize($bad),
        "strict review state rejects " . get_debug_type($bad),
    );
}
rejects(
    fn() => $articles->review($id, "approved", 0),
    "review needs a saved original version",
);
$before = count($articles->revisions($id));
Db::run($db, "UPDATE native_users SET active=0 WHERE id='admin'");
rejects(
    fn() => $articles->review($id, "approved", $item["version"]),
    "disabled publisher loses current approval authority",
);
Db::run($db, "UPDATE native_users SET active=1 WHERE id='admin'");
check(
    count($articles->revisions($id)) === $before,
    "rejected approval leaves no revision",
);
$incomplete = $articles->save([
    "title" => "Nur Titel",
    "slug" => "aktuelles/empty",
    "news_date" => "2050-02-03",
    "body_html" => "<p>&nbsp;</p>",
]);
rejects(
    fn() => $articles->review(
        $incomplete["id"],
        "approved",
        $incomplete["version"],
    ),
    "empty markup cannot be approved as meaningful content",
);
$placeholder = $articles->save([
    "title" => "Neue Website-Neuigkeit",
    "slug" => "aktuelles/placeholder",
    "news_date" => "2050-02-03",
    "excerpt" => "Kurztext",
]);
rejects(
    fn() => $articles->review(
        $placeholder["id"],
        "approved",
        $placeholder["version"],
    ),
    "placeholder cannot receive editorial approval",
);
$standard = $pages->save(["title" => "Inhaltsseite", "slug" => "normal"]);
rejects(
    fn() => $pages->review($standard["id"], "review", $standard["version"]),
    "review cannot cross to a different content type",
);
$clock = time() + 100;
$schedule = new PublicationSchedule($db, $access, static fn() => $clock);
$item = $articles->review($id, "approved", $item["version"]);
$job = $schedule->plan($id, $item["version"], $clock + 60);
$stored = Db::run(
    $db,
    "SELECT snapshot_json FROM khcms_publication_schedule WHERE id=?",
    [$job["id"]],
)->fetchColumn();
check(
    $stored ===
        Db::run($db, "SELECT draft_json FROM khcms_pages WHERE id=?", [
            $id,
        ])->fetchColumn(),
    "approved review preserves the exact scheduled original bytes",
);
PublicationSchedule::runDue($db, fn() => $access, 50, $clock + 61);
check(
    $schedule->forPage($id)["status"] === "published" &&
        $pages->publicPage($item["slug"])["news_review"] === "approved",
    "due job publishes the approved original without a fingerprint mismatch",
);
$item = $articles->get($id);
$job = $schedule->plan($id, $item["version"], $clock + 120);
$item = $editor->review($id, "review", $item["version"]);
PublicationSchedule::runDue($db, fn() => $access, 50, $clock + 121);
check(
    $schedule->forPage($id)["status"] === "conflicted",
    "new review decision invalidates an older scheduled original",
);
$post = [
    "csrf" => "test-csrf",
    "operation" => "article-review",
    "id" => $id,
    "version" => (string) $item["version"],
    "review_status" => "approved",
];
rejects(
    fn() => (new ArticleView($db, $editorAccess, $root, "test-csrf"))->action(
        $post,
    ),
    "HTTP article action cannot elevate an editor to approver",
);
$bad = $post;
$bad["csrf"] = "expired";
rejects(fn() => $view->action($bad), "review HTTP action checks CSRF");
$bad = $post;
$bad["review_status"] = ["approved"];
rejects(fn() => $view->action($bad), "review HTTP action rejects array status");
$view->action($post);
$item = $articles->get($id);
check(
    $item["news_review"] === "approved",
    "actual article action persists approval",
);
$manager = new Manager($db, $access, $root, "test-csrf");
$manager->action(
    "website",
    [
        "csrf" => "test-csrf",
        "operation" => "page-review",
        "id" => $id,
        "version" => (string) $item["version"],
        "review_status" => "review",
    ],
    [],
);
check(
    $articles->get($id)["news_review"] === "review",
    "general page action uses the same club-bound revision",
);
$bad = [
    "csrf" => "test-csrf",
    "operation" => "page-review",
    "id" => $id,
    "version" => ["1"],
    "review_status" => "approved",
];
rejects(
    fn() => $manager->action("website", $bad, []),
    "general page action rejects an array version",
);
check(
    !str_contains(
        (new ArticleView(
            $db,
            new Access($db, $actors["reader"]),
            $root,
            "test-csrf",
        ))->manager($id),
        'value="article-review"',
    ),
    "reader receives no actionable review controls",
);
echo json_encode(
    [
        "passed" => count($checks),
        "checks" => $checks,
        "live_transport" => false,
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . "\n";
