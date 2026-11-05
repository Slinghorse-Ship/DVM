<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Communication\{Schema, SocialHub};
use Kulturhus\Native\Integration\Accounts;
$db = TestSupport::db();
TestSupport::seed($db);
Schema::install($db);
$a = new Access($db, "admin");
$box = new SecretBox(str_repeat("h", 32));
$accounts = new Accounts($db, $box);
$accounts->save(
    $a,
    "meta",
    [
        "page_id" => "123",
        "instagram_id" => "456",
        "api_version" => "v25.0",
        "token" => "synthetic-never-send",
    ],
    0,
);
$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
    echo "PASS $label\n";
}
function denied(callable $f, string $label): void
{
    try {
        $f();
    } catch (DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
$phase = "good";
$calls = [];
$http = function ($method, $path, $headers, $body) use (&$phase, &$calls, $db) {
    $calls[] = [$method, $path];
    $parts = parse_url($path);
    parse_str($parts["query"] ?? "", $q);
    $p = $parts["path"];
    $ok = static fn($body) => ["status" => 200, "body" => $body];
    if ($p === "/v25.0/123") {
        return $ok([
            "id" => $phase === "foreign-page" ? "999" : "123",
            "instagram_business_account" => ["id" => "456"],
        ]);
    }
    if ($p === "/v25.0/456") {
        return $ok([
            "id" => $phase === "foreign-ig" ? "999" : "456",
            "username" => "verein_demo",
        ]);
    }
    if ($p === "/v25.0/123/published_posts") {
        $start = isset($q["after"]) ? 25 : 0;
        $end = isset($q["after"]) ? 40 : 25;
        $rows = [];
        for ($i = $start; $i < $end; $i++) {
            $rows[] = [
                "id" => "123_" . (1000 + $i),
                "message" => "Kulturabend " . $i,
                "created_time" =>
                    $phase === "bad-date" && $i === 26
                        ? "2026-02-31T12:00:00+0000"
                        : "2026-10-08T12:00:00+0000",
                "permalink_url" =>
                    "https://www.facebook.com/123/posts/" . (1000 + $i),
                "full_picture" =>
                    "https://scontent.example.fbcdn.net/source.jpg",
            ];
        }
        if ($phase === "overlap" && isset($q["after"])) {
            $rows[0]["id"] = "123_1000";
        }
        $r = ["data" => $rows];
        if (!isset($q["after"])) {
            $r["paging"] = [
                "next" =>
                    "https://graph.facebook.com/unsafe?access_token=NEVER_FOLLOW",
                "cursors" => ["after" => "page2"],
            ];
        }
        return $ok($r);
    }
    if ($p === "/v25.0/123/video_reels") {
        return $ok([
            "data" => [
                [
                    "id" => "800",
                    "title" => "Facebook Reel",
                    "created_time" => "2026-10-08T13:00:00Z",
                    "permalink_url" => "https://www.facebook.com/reel/800",
                    "picture" => "https://scontent.example.fbcdn.net/reel.jpg",
                ],
            ],
        ]);
    }
    if ($p === "/v25.0/456/media") {
        return $ok([
            "data" => [
                [
                    "id" => "700",
                    "caption" => "Instagram Reel",
                    "media_type" => "VIDEO",
                    "media_product_type" => "REELS",
                    "thumbnail_url" =>
                        "https://scontent.example.cdninstagram.com/reel.jpg",
                    "permalink" => "https://www.instagram.com/reel/700/",
                    "timestamp" => "2026-10-08T12:00:00+0000",
                    "like_count" => 7,
                ],
                [
                    "id" => "701",
                    "caption" => "Karussell",
                    "media_type" => "CAROUSEL_ALBUM",
                    "media_url" =>
                        "https://scontent.example.cdninstagram.com/carousel.jpg",
                    "timestamp" => "2026-10-08T12:00:00Z",
                ],
            ],
        ]);
    }
    if ($p === "/v25.0/456/stories") {
        return $ok([
            "data" => [
                [
                    "id" => "702",
                    "media_type" => "IMAGE",
                    "media_url" =>
                        "https://scontent.example.cdninstagram.com/story.jpg",
                    "timestamp" => "2026-10-08T12:00:00Z",
                ],
            ],
        ]);
    }
    if (str_ends_with($p, "/insights")) {
        return $q["metric"] === "reach"
            ? $ok([
                "data" => [["name" => "reach", "values" => [["value" => 42]]]],
            ])
            : [
                "status" => 400,
                "body" => ["error" => ["message" => "Unsupported metric"]],
            ];
    }
    if (
        in_array(
            $phase,
            [
                "ig-threads",
                "bad-child",
                "duplicate-child",
                "foreign-ig",
                "revoked-child",
                "paged-child",
                "bad-child-second",
                "edited-child",
            ],
            true,
        )
    ) {
        if ($p === "/v25.0/700/comments") {
            return $ok([
                "data" => [
                    [
                        "id" => "root_owned",
                        "text" => "Eigener Kommentar",
                        "username" => "VEREIN_DEMO",
                        "timestamp" => "2026-10-08T12:00:00Z",
                    ],
                    [
                        "id" => "root_visitor",
                        "text" => "Erste Frage",
                        "username" => "gast",
                        "timestamp" => "2026-10-08T12:00:00Z",
                    ],
                ],
            ]);
        }
        if ($p === "/v25.0/root_owned/replies") {
            if (in_array($phase, ["paged-child", "bad-child-second"], true)) {
                if (isset($q["after"])) {
                    if ($phase === "bad-child-second") {
                        return [
                            "status" => 503,
                            "body" => [
                                "error" => [
                                    "message" => "Incomplete second reply page",
                                ],
                            ],
                        ];
                    }
                    return $ok([
                        "data" => [
                            [
                                "id" => "followup_visitor",
                                "text" => "Rückfrage nach eurer Antwort",
                                "username" => "gast",
                                "timestamp" => "2026-10-08T12:10:00Z",
                            ],
                        ],
                    ]);
                }
                return $ok([
                    "data" => [
                        [
                            "id" => "answer_owned",
                            "text" => "Eigene Antwort",
                            "username" => "verein_demo",
                            "timestamp" => "2026-10-08T12:05:00Z",
                        ],
                    ],
                    "paging" => [
                        "next" =>
                            "https://graph.facebook.com/unsafe?access_token=NEVER_FOLLOW",
                        "cursors" => ["after" => "thread_second"],
                    ],
                ]);
            }
            if ($phase === "bad-child") {
                return [
                    "status" => 503,
                    "body" => [
                        "error" => ["message" => "Synthetic incomplete thread"],
                    ],
                ];
            }
            if ($phase === "duplicate-child") {
                return $ok([
                    "data" => [
                        [
                            "id" => "root_owned",
                            "text" => "Duplicate",
                            "username" => "gast",
                        ],
                    ],
                ]);
            }
            if ($phase === "revoked-child") {
                Db::run(
                    $db,
                    "DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'",
                );
            }
            return $ok([
                "data" => [
                    [
                        "id" => "answer_owned",
                        "text" => "Eigene Antwort",
                        "username" => "verein_demo",
                        "timestamp" => "2026-10-08T12:05:00Z",
                    ],
                    [
                        "id" => "followup_visitor",
                        "text" =>
                            $phase === "edited-child"
                                ? "Neue bearbeitete Rückfrage"
                                : "Rückfrage nach eurer Antwort",
                        "username" => "gast",
                        "timestamp" => "2026-10-08T12:10:00Z",
                    ],
                ],
            ]);
        }
    }
    if ($phase === "fb-threads") {
        if ($p === "/v25.0/123_1000/comments") {
            return $ok([
                "data" => [
                    [
                        "id" => "fb_root",
                        "message" => "Eigener Seitenkommentar",
                        "from" => ["id" => "123", "name" => "Verein"],
                        "created_time" => "2026-10-08T12:00:00Z",
                    ],
                ],
            ]);
        }
        if ($p === "/v25.0/fb_root/comments") {
            return $ok([
                "data" => [
                    [
                        "id" => "fb_known_answer",
                        "message" => "Eigene bestätigte Antwort",
                        "created_time" => "2026-10-08T12:05:00Z",
                    ],
                    [
                        "id" => "fb_question",
                        "message" => "Neue Frage im Strang",
                        "from" => ["id" => "987", "name" => "Besucher"],
                        "created_time" => "2026-10-08T12:10:00Z",
                    ],
                ],
            ]);
        }
    }
    if (str_ends_with($p, "/replies") || $p === "/v25.0/comment_1/comments") {
        return $ok(["data" => []]);
    }
    if (str_ends_with($p, "/comments")) {
        return $ok([
            "data" => [
                [
                    "id" => "comment_1",
                    "text" => "Private Testkommentare",
                    "timestamp" => "2026-10-08T12:00:00+0000",
                    "username" => "Synthetischer Absender",
                    "hidden" => false,
                ],
            ],
        ]);
    }
    if ($p === "/v25.0/123/conversations") {
        return $ok([
            "data" => [
                [
                    "id" => "conversation_1",
                    "participants" => [
                        "data" => [["id" => "987"], ["id" => "456"]],
                    ],
                ],
            ],
        ]);
    }
    if ($p === "/v25.0/conversation_1/messages") {
        return $ok([
            "data" => [
                [
                    "id" => "message_1",
                    "message" => "Private synthetische Anfrage",
                    "created_time" => gmdate("Y-m-d\TH:i:s\Z", time() - 3600),
                    "from" => ["id" => "987", "name" => "Synthetische Person"],
                ],
                [
                    "id" => "message_2",
                    "message" => "Frühere Antwort",
                    "created_time" => gmdate("Y-m-d\TH:i:s\Z", time() - 1800),
                    "from" => ["id" => "456", "name" => "Euer Instagram"],
                ],
                [
                    "id" => "old_message",
                    "message" => "Nicht mehr speichern",
                    "created_time" => gmdate(
                        "Y-m-d\TH:i:s\Z",
                        time() - 31 * 86400,
                    ),
                    "from" => ["id" => "987"],
                ],
            ],
        ]);
    }
    return [
        "status" => 400,
        "body" => ["error" => ["message" => "Unexpected contract endpoint"]],
    ];
};
$im = imagecreatetruecolor(900, 600);
imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 130));
ob_start();
imagejpeg($im);
$jpeg = ob_get_clean();
unset($im);
$fetches = 0;
$fetch = function ($url) use ($jpeg, &$fetches) {
    $fetches++;
    return $jpeg;
};
$hub = new SocialHub($db, $box, $http, $fetch);
$result = $hub->sync($a);
check(
    count($result) === 4 &&
        count(array_filter($result, fn($r) => $r["status"] === "available")) ===
            4,
    "four real provider edges independently confirmed by contract transport",
);
$list = $hub->listing($a);
check(
    $list["total"] === 44 &&
        count($list["rows"]) === 24 &&
        $list["pages"] === 2,
    "complete remote archive paginated in private club library",
);
check(
    count($hub->listing($a, "", "", "", 2)["rows"]) === 20,
    "second persisted library page contains remaining objects",
);
check(
    $hub->listing($a, "Instagram Reel", "reel", "instagram")["total"] === 1,
    "search and kind/channel filters select an actual stored Reel",
);
check(
    $hub->listing($a, "Kulturabend %")["total"] === 0,
    "literal percent search does not broaden results",
);
$reel = $hub->listing($a, "Instagram Reel")["rows"][0];
$story = $hub->listing($a, "", "story")["rows"][0];
check(
    $story["payload"]["thumbnail"] !== "",
    "image Story uses its actual media_url",
);
check(
    $reel["payload"]["like_count"] === 7 &&
        $reel["payload"]["comments_count"] === null,
    "missing metrics stay unknown rather than fabricated zero",
);
$png = $hub->thumbnail($a, $reel["id"]);
$size = getimagesizefromstring($png);
check(
    $size[0] === 480 && $size[1] === 320 && $size["mime"] === "image/png",
    "provider thumbnail is real reencoded PNG pixels",
);
check(
    $hub->thumbnail($a, $reel["id"]) === $png && $fetches === 1,
    "private thumbnail cache preserves exact pixels without second fetch",
);
denied(
    fn() => $hub->thumbnail(new Access($db, "outsider"), $reel["id"]),
    "thumbnail does not cross club boundary",
);
check(
    SocialHub::url("https://evil.example/source.jpg", true) === "",
    "arbitrary image origin excluded",
);
check(
    SocialHub::url("https://evil.fbcdn.net.evil.example/source.jpg", true) ===
        "",
    "suffix-confusion image origin excluded",
);
check(
    SocialHub::url(
        "https://scontent.example.fbcdn.net:443/source.jpg",
        true,
    ) === "",
    "provider thumbnail explicit port excluded",
);
denied(
    fn() => SocialHub::url(
        "https://user:password@scontent.example.fbcdn.net/source.jpg",
        true,
    ),
    "credential-bearing provider URL rejected",
);
$metrics = $hub->insights($a, $reel["id"]);
check(
    $metrics["reach"]["available"] &&
        $metrics["reach"]["data"]["values"][0]["value"] === 42,
    "independent provider metric preserved",
);
check(
    !$metrics["views"]["available"],
    "unsupported insight remains unavailable",
);
$comments = $hub->comments($a, $reel["id"]);
check(
    $comments[0]["text"] === "Private Testkommentare",
    "comment sync returns actual provider content",
);
$stored = $hub->object($a, $reel["id"]);
check(
    !str_contains($stored["comments_cipher"], "Private Testkommentare"),
    "comments encrypted at rest",
);
denied(
    fn() => $hub->privateComments(new Access($db, "reader"), $reel["id"]),
    "plain social read is insufficient for private comment data",
);
check(
    $hub->syncInbox($a, "instagram") === 1,
    "private conversation and complete messages synced",
);
$inbox = $hub->inbox($a);
check(
    count($inbox[0]["messages"]) === 2 &&
        $inbox[0]["messages"][0]["incoming"] &&
        !$inbox[0]["messages"][1]["incoming"],
    "incoming/outgoing sender pinned to configured account and old messages excluded",
);
check(
    !str_contains(
        Db::run(
            $db,
            "SELECT ciphertext FROM native_social_inbox WHERE club_id=?",
            [$a->club()],
        )->fetchColumn(),
        "Private synthetische Anfrage",
    ),
    "inbox messages encrypted at rest",
);
denied(
    fn() => $hub->inbox(new Access($db, "reader")),
    "private inbox requires social management",
);
$phase = "ig-threads";
$threadComments = $hub->comments($a, $reel["id"]);
check(
    count($threadComments) === 4 &&
        count(array_filter($threadComments, fn($c) => !$c["incoming"])) === 2,
    "Instagram root/reply edges include original questions and our own answers with verified account identity",
);
$attention = new \Kulturhus\Native\Communication\Attention($db, $box);
$incoming = array_values(
    array_filter(
        $attention->listing($a, "all"),
        fn($r) => $r["kind"] === "comment" && $r["parent_id"] === "700",
    ),
);
check(
    count($incoming) === 2 &&
        in_array(
            "followup_visitor",
            array_column(array_column($incoming, "payload"), "remote_id"),
            true,
        ),
    "follow-up inside our comment thread creates actual human reply review without our own answers",
);
check(
    array_column($threadComments, "reply_to") === [
        "",
        "root_owned",
        "root_owned",
        "",
    ],
    "private conversation preserves root/reply relationships",
);
$phase = "paged-child";
$retainedThreadBytes=$hub->object($a,$reel['id'])['comments_cipher'];
$originalArchive=new \Kulturhus\Native\Communication\OriginalArchive($db,$box);
$objectHistoryCount=$originalArchive->history($a,'object',$reel['id'])['total'];
$pagedComments = $hub->comments($a, $reel["id"]);
check($retainedThreadBytes===$hub->object($a,$reel['id'])['comments_cipher']&&$objectHistoryCount===$originalArchive->history($a,'object',$reel['id'])['total'],'unchanged complete Meta reread retains exact encrypted thread and creates no false historical object');
check(
    $pagedComments === $threadComments,
    "complete paginated child reads preserve both answers without following provider paging URLs",
);
$followup = array_values(
    array_filter(
        $attention->listing($a, "all"),
        fn($row) => $row["payload"]["remote_id"] === "followup_visitor",
    ),
)[0];
$attention->setState($a, $followup["id"], "done", $followup["version"]);
$hub->comments($a, $reel["id"]);
$followupAfter = array_values(
    array_filter(
        $attention->listing($a, "all"),
        fn($row) => $row["payload"]["remote_id"] === "followup_visitor",
    ),
)[0];
check(
    $followupAfter["state"] === "done",
    "unchanged fully read follow-up retains the human completed decision",
);
$phase = "edited-child";
$originalObjectBefore=$hub->object($a,$reel['id']);
$hub->comments($a, $reel["id"]);
$originalHistory=$originalArchive->history($a,'object',$reel['id']);$retainedObject=$originalArchive->get($a,$originalHistory['rows'][0]['id'])['original'];ksort($originalObjectBefore);ksort($retainedObject);
check($retainedObject===$originalObjectBefore,'actual edited Meta child retains complete prior object, original question, own replies and exact ciphertext');
$followupAfter = array_values(
    array_filter(
        $attention->listing($a, "all"),
        fn($row) => $row["payload"]["remote_id"] === "followup_visitor",
    ),
)[0];
check(
    $followupAfter["state"] === "new" &&
        $followupAfter["payload"]["text"] === "Neue bearbeitete Rückfrage",
    "edited actual follow-up reopens human review after a completed answer",
);
$unchanged = $hub->object($a, $reel["id"])["comments_cipher"];
foreach (
    [
        "bad-child" => "incomplete child read",
        "bad-child-second" => "failed second reply page",
        "duplicate-child" => "overlapping root/reply IDs",
        "foreign-ig" => "foreign Instagram identity",
    ]
    as $broken => $label
) {
    $phase = $broken;
    denied(
        fn() => $hub->comments($a, $reel["id"]),
        $label . " cannot replace a complete private snapshot",
    );
    check(
        $hub->object($a, $reel["id"])["comments_cipher"] === $unchanged,
        $label . " leaves previous encrypted conversation untouched",
    );
}
$phase = "revoked-child";
denied(
    fn() => $hub->comments($a, $reel["id"]),
    "current management revocation after reply read prevents committing private content",
);
check(
    $hub->object($a, $reel["id"])["comments_cipher"] === $unchanged,
    "mid-read rights revocation preserves original conversation bytes",
);
Db::run($db, "INSERT INTO native_grants VALUES('admin','social','manage')");
$phase = "fb-threads";
$facebook = $hub->listing($a, "Kulturabend 0", "post", "facebook")["rows"][0];
$binding = Accounts::binding($accounts->get($a->club(), "meta"));
Db::run(
    $db,
    "INSERT INTO native_social_replies(club_id,id,attention_id,channel,parent_id,connection_id,source_fingerprint,status,version,ciphertext,created_by,updated_by,remote_id,created_at,updated_at) VALUES(?,?,?,'facebook','123_1000',?,?,'sent',1,?,'admin','admin','fb_known_answer',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
    [
        $a->club(),
        str_repeat("d", 32),
        str_repeat("c", 32),
        $binding,
        str_repeat("f", 64),
        $box->seal("social-reply:" . $a->club() . ":" . str_repeat("d", 32), [
            "text" => "Own confirmed answer",
            "original" => [],
        ]),
    ],
);
$facebookComments = $hub->comments($a, $facebook["id"]);
check(
    count($facebookComments) === 3 &&
        count(array_filter($facebookComments, fn($r) => !$r["incoming"])) === 2,
    "Facebook page authorship and bound native answer IDs exclude our own content from reply demand",
);
$incoming = array_values(
    array_filter(
        $attention->listing($a, "all"),
        fn($r) => $r["kind"] === "comment" && $r["parent_id"] === "123_1000",
    ),
);
check(
    count($incoming) === 1 &&
        $incoming[0]["payload"]["remote_id"] === "fb_question",
    "Facebook visitor follow-up remains visible through complete child reads",
);
$phase = "good";
$phase = "overlap";
$r = (new SocialHub($db, $box, $http))->sync($a);
check(
    $r["facebook-posts"]["status"] === "not-confirmed" &&
        $hub->listing($a)["total"] === 44,
    "overlapping provider pages do not partially mutate prior archive",
);
$phase = "bad-date";
$r = (new SocialHub($db, $box, $http))->sync($a);
check(
    $r["facebook-posts"]["status"] === "not-confirmed",
    "nonexistent provider date rejected before snapshot mutation",
);
$phase = "foreign-page";
denied(
    fn() => (new SocialHub($db, $box, $http))->sync($a),
    "provider page identity mismatch rejected",
);
check(
    !array_filter($calls, fn($c) => $c[0] !== "GET"),
    "archive, insights, comments and inbox perform no provider writes",
);
check(
    !array_filter(
        $calls,
        fn($c) => str_contains($c[1], "NEVER_FOLLOW") ||
            str_contains($c[1], "access_token="),
    ),
    "pagination never follows credential-bearing provider URL",
);
$phase = "good";
$accounts->disconnect($a, "meta", 1);
$accounts->save(
    $a,
    "meta",
    [
        "page_id" => "123",
        "instagram_id" => "456",
        "api_version" => "v25.0",
        "token" => "synthetic-new-connection",
    ],
    2,
);
$beforeCalls = count($calls);
denied(
    fn() => $hub->comments($a, $reel["id"]),
    "old object cannot read comments through reconnected account",
);
denied(
    fn() => $hub->insights($a, $reel["id"]),
    "old object cannot read insights through reconnected account",
);
check(
    count($calls) === $beforeCalls,
    "stale object binding rejected before any provider request",
);
$hub->sync($a);
check(
    $hub->comments($a, $reel["id"])[0]["text"] === "Private Testkommentare",
    "explicit current-account sync confirms object binding for new reads",
);
echo "$n native social hub checks passed; isolated synthetic contract transport, actual thumbnail bytes, no live Meta requests.\n";
