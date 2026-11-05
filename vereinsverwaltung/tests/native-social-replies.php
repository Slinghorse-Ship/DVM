<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, SecretBox, RuntimeSchema};
use Kulturhus\Native\Communication\{Attention, Replies};
use Kulturhus\Native\Integration\Accounts;

$db = TestSupport::db();
TestSupport::seed($db);
RuntimeSchema::install($db);
$access = new Access($db, "admin");
$box = new SecretBox(str_repeat("r", 32));
$accounts = new Accounts($db, $box);
$accounts->save(
    $access,
    "meta",
    [
        "page_id" => "123",
        "instagram_id" => "456",
        "api_version" => "v25.0",
        "token" => "synthetic-only",
    ],
    0,
);
$account = $accounts->get($access->club(), "meta");
$attention = new Attention($db, $box);
$n = 0;
$calls = [];
$provider = [];
$mode = "normal";
function check(bool $ok, string $message): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $n++;
    echo "PASS $message\n";
}
function denied(callable $f, string $message): void
{
    try {
        $f();
    } catch (DomainException) {
        check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
function original(
    string $channel,
    string $remote,
    string $parent = "123_999",
): array {
    global $db, $access, $account, $attention, $provider;
    $provider[$remote] = [
        "id" => $remote,
        $channel === "facebook" ? "message" : "text" =>
            "Private original question " . $remote,
    ];
    Db::run(
        $db,
        "INSERT IGNORE INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())",
        [
            $access->club(),
            substr(hash("sha256", $channel . $parent), 0, 32),
            $channel,
            $parent,
            "post",
            "Own original",
            json_encode(
                ["connection_id" => Accounts::binding($account)],
                JSON_THROW_ON_ERROR,
            ),
        ],
    );
    $attention->observe(
        $access,
        $channel,
        "comment",
        $parent,
        [
            [
                "id" => $remote,
                "text" =>
                    $provider[$remote][
                        $channel === "facebook" ? "message" : "text"
                    ],
                "name" => "Private reader",
            ],
        ],
        true,
    );
    foreach ($attention->listing($access, "all") as $row) {
        if ($row["payload"]["remote_id"] === $remote) {
            return $row;
        }
    }
    throw new RuntimeException("Original fixture unavailable");
}
$http = static function ($method, $path, $headers, $body) use (
    &$calls,
    &$provider,
    &$mode,
    $db,
    $box,
    $access,
) {
    $calls[] = [$method, $path, $body];
    $remote = explode("?", basename($path))[0];
    if ($method === "GET") {
        return ["status" => 200, "body" => $provider[$remote] ?? []];
    }
    if ($mode === "reject") {
        return [
            "status" => 403,
            "body" => [
                "error" => [
                    "code" => 200,
                    "message" => "Synthetic permission rejection",
                ],
            ],
        ];
    }
    if ($mode === "timeout") {
        throw new RuntimeException("Synthetic lost response");
    }
    if ($mode === "server-error") {
        return ["status" => 500, "body" => ["error" => ["code" => 1]]];
    }
    $second = TestSupport::db();
    check(
        $second
            ->query(
                "SELECT COUNT(*) FROM native_social_replies WHERE status='sending'",
            )
            ->fetchColumn() > 0,
        "send intent is committed and independently readable before external write",
    );
    $reply = "reply_" . count($calls);
    $provider[$reply] = [
        "id" => $reply,
        str_ends_with($path, "/replies") ? "text" : "message" => $body[
            "message"
        ],
    ];
    if ($mode === "lost-proof") {
        $provider[$reply]["message"] = "Provider proof not available yet";
    }
    return ["status" => 200, "body" => ["id" => $reply]];
};
$replies = new Replies($db, $box, $http);
$source = original("facebook", "comment_fb");
$draft = $replies->save(
    $access,
    $source["id"],
    $source["version"],
    "Private reply text",
);
check(
    $draft["status"] === "draft" &&
        $draft["payload"]["text"] === "Private reply text" &&
        !$calls,
    "saving encrypted reply draft makes no provider request",
);
$stored = Db::run($db, "SELECT * FROM native_social_replies WHERE id=?", [
    $draft["id"],
])->fetch();
check(
    !str_contains(json_encode($stored), "Private reply text") &&
        !str_contains(json_encode($stored), "Private original") &&
        !str_contains(json_encode($stored), "Private reader"),
    "reply and original private content are encrypted in MySQL",
);
denied(
    fn() => (new Replies($db, new SecretBox(str_repeat("x", 32))))->get(
        $access,
        $draft["id"],
    ),
    "wrong key cannot decrypt private reply",
);
denied(
    fn() => $replies->get(new Access($db, "reader"), $draft["id"]),
    "private reply history needs social management",
);
denied(
    fn() => $replies->get(new Access($db, "outsider"), $draft["id"]),
    "private reply cannot cross club boundaries",
);
denied(
    fn() => $replies->save(
        $access,
        $source["id"],
        $source["version"],
        "Duplicate draft",
    ),
    "one durable reply intent per original and connection prevents duplicate drafts",
);
$draft = $replies->save(
    $access,
    $source["id"],
    $source["version"],
    "Checked private reply",
    $draft["id"],
    $draft["version"],
);
check(
    $draft["version"] === 2 &&
        $draft["payload"]["text"] === "Checked private reply",
    "explicit versioned draft editing stores the checked final text",
);
denied(
    fn() => $replies->save(
        $access,
        $source["id"],
        $source["version"],
        "Stale edit",
        $draft["id"],
        1,
    ),
    "stale team edit cannot overwrite another draft",
);
denied(
    fn() => $replies->send($access, $draft["id"], $draft["version"], false),
    "send requires explicit confirmation",
);
check(!$calls, "unconfirmed send does not even read the provider");
$sent = $replies->send($access, $draft["id"], $draft["version"], true);
check(
    $sent["status"] === "sent" &&
        $sent["remote_id"] &&
        $sent["sent_by"] === "admin",
    "reply is sent only after independent provider proof of its exact text",
);
check(
    $calls[0][0] === "GET" &&
        str_ends_with($calls[1][1], "/comment_fb/comments") &&
        $calls[1][2] === ["message" => "Checked private reply"] &&
        $calls[2][0] === "GET",
    "Facebook reply reads current original, uses comment endpoint and independently reads actual answer",
);
check(
    !$attention->listing($access),
    "only confirmed answer closes unchanged human review item",
);
$before = count($calls);
denied(
    fn() => $replies->send($access, $sent["id"], $sent["version"], true),
    "already sent reply is never replayed",
);
check(
    count($calls) === $before,
    "repeated send is rejected before any provider call",
);
denied(
    fn() => $replies->save(
        $access,
        $source["id"],
        $source["version"],
        "Changed after send",
        $sent["id"],
        $sent["version"],
    ),
    "sent reply snapshot cannot be rewritten",
);
$ig = original("instagram", "comment_ig", "456_900");
$igDraft = $replies->save(
    $access,
    $ig["id"],
    $ig["version"],
    "Instagram checked answer",
);
$igSent = $replies->send($access, $igDraft["id"], $igDraft["version"], true);
check(
    $igSent["status"] === "sent" &&
        (bool) array_filter(
            $calls,
            fn($c) => $c[0] === "POST" &&
                str_ends_with($c[1], "/comment_ig/replies"),
        ),
    "Instagram uses its own replies endpoint and text proof",
);
$hidden = original("instagram", "comment_hidden", "456_901");
$hiddenDraft = $replies->save(
    $access,
    $hidden["id"],
    $hidden["version"],
    "Hidden original answer",
);
$provider["comment_hidden"]["hidden"] = true;
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $hiddenDraft["id"],
        $hiddenDraft["version"],
        true,
    ),
    "hidden Instagram comment cannot be answered through the reply endpoint",
);
check(
    count($calls) === $before + 1,
    "hidden comment performs one current read and no write",
);
$closed = original("facebook", "comment_closed");
$closedDraft = $replies->save(
    $access,
    $closed["id"],
    $closed["version"],
    "Closed original answer",
);
$provider["comment_closed"]["can_comment"] = false;
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $closedDraft["id"],
        $closedDraft["version"],
        true,
    ),
    "Facebook can_comment false stops a reply before publication",
);
check(
    count($calls) === $before + 1,
    "provider comment restriction causes no write",
);
$changed = original("facebook", "comment_changed");
$changedDraft = $replies->save(
    $access,
    $changed["id"],
    $changed["version"],
    "Reply to old original",
);
$provider["comment_changed"]["message"] = "Edited at provider";
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $changedDraft["id"],
        $changedDraft["version"],
        true,
    ),
    "fresh provider original change prevents replying to outdated text",
);
check(
    count($calls) === $before + 1 &&
        $replies->get($access, $changedDraft["id"])["status"] === "draft",
    "original changed at provider causes read only and retains private draft",
);
$attention->observe(
    $access,
    "facebook",
    "comment",
    $changed["parent_id"],
    [
        [
            "id" => "comment_changed",
            "text" => "Edited at provider",
            "name" => "Private reader",
        ],
    ],
    true,
);
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $changedDraft["id"],
        $changedDraft["version"],
        true,
    ),
    "changed local original fingerprint blocks old draft",
);
check(
    count($calls) === $before,
    "changed cached original is rejected before provider access",
);
$ambiguous = original("facebook", "comment_uncertain");
$pending = $replies->save(
    $access,
    $ambiguous["id"],
    $ambiguous["version"],
    "Unknown result answer",
);
$mode = "lost-proof";
$unknown = $replies->send($access, $pending["id"], $pending["version"], true);
check(
    $unknown["status"] === "uncertain" && $unknown["remote_id"],
    "lost independent proof retains encrypted intent and exact provider identifier",
);
$before = count($calls);
denied(
    fn() => $replies->send($access, $unknown["id"], $unknown["version"], true),
    "ambiguous reply is never automatically retried",
);
denied(
    fn() => $replies->save(
        $access,
        $ambiguous["id"],
        $ambiguous["version"],
        "Another reply",
        $unknown["id"],
        $unknown["version"],
    ),
    "ambiguous intent cannot be edited into an accidental second reply",
);
check(
    count($calls) === $before,
    "ambiguous replay prevention performs no write or provider access",
);
$provider[$unknown["remote_id"]]["message"] = "Unknown result answer";
$mode = "normal";
$proof = $replies->reconcile($access, $unknown["id"], $unknown["version"]);
check(
    $proof["confirmed"] &&
        $proof["reply"]["status"] === "sent" &&
        count($calls) === $before + 1 &&
        end($calls)[0] === "GET",
    "operator reconciliation reads only and confirms existing reply without duplicate send",
);
$lost = original("facebook", "comment_lost");
$lostDraft = $replies->save(
    $access,
    $lost["id"],
    $lost["version"],
    "Lost network answer",
);
$mode = "timeout";
$lostResult = $replies->send(
    $access,
    $lostDraft["id"],
    $lostDraft["version"],
    true,
);
check(
    $lostResult["status"] === "uncertain" && $lostResult["remote_id"] === null,
    "lost POST response remains unknown instead of becoming retryable",
);
denied(
    fn() => $replies->reconcile(
        $access,
        $lostResult["id"],
        $lostResult["version"],
    ),
    "no provider identifier is not invented as a confirmed reply",
);
$failed = original("facebook", "comment_rejected");
$failedDraft = $replies->save(
    $access,
    $failed["id"],
    $failed["version"],
    "Rejected answer",
);
$mode = "reject";
$rejected = $replies->send(
    $access,
    $failedDraft["id"],
    $failedDraft["version"],
    true,
);
check(
    $rejected["status"] === "rejected" &&
        $rejected["reason"] === "provider_rejected",
    "complete Graph 4xx error is an explicit rejection instead of fake delivery",
);
$retried = $replies->save(
    $access,
    $failed["id"],
    $failed["version"],
    "Corrected explicit answer",
    $rejected["id"],
    $rejected["version"],
);
check(
    $retried["status"] === "draft" && $retried["remote_id"] === null,
    "explicit edit can recover a confirmed provider rejection without automatic retry",
);
$mode = "server-error";
$error = $replies->send($access, $retried["id"], $retried["version"], true);
check(
    $error["status"] === "uncertain",
    "server-side 5xx after POST stays ambiguous and never becomes retryable",
);
$revoked = original("facebook", "comment_revoked");
$revokedDraft = $replies->save(
    $access,
    $revoked["id"],
    $revoked["version"],
    "No authority answer",
);
Db::run(
    $db,
    "DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'",
);
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $revokedDraft["id"],
        $revokedDraft["version"],
        true,
    ),
    "current publication revocation prevents a prepared reply from being sent",
);
check(
    count($calls) === $before,
    "permission revocation causes zero provider calls",
);
Db::run($db, "INSERT INTO native_grants VALUES('admin','social','publish')");
$parallel = original("facebook", "comment_parallel");
$parallelDraft = $replies->save(
    $access,
    $parallel["id"],
    $parallel["version"],
    "Single concurrent answer",
);
$parallelCalls = 0;
$parallelReplies = new Replies($db, $box, static function (
    $method,
    $path,
    $headers,
    $body,
) use ($parallelDraft, $box, $access, &$parallelCalls) {
    $parallelCalls++;
    if ($method === "POST") {
        $otherDb = TestSupport::db();
        denied(
            fn() => (new Replies($otherDb, $box, static function () {
                throw new LogicException("Concurrent sender called provider");
            }))->send(
                new Access($otherDb, "admin"),
                $parallelDraft["id"],
                $parallelDraft["version"],
                true,
            ),
            "concurrent second sender sees durable intent and cannot duplicate the reply",
        );
        return ["status" => 200, "body" => ["id" => "reply_parallel"]];
    }
    return [
        "status" => 200,
        "body" => str_contains($path, "comment_parallel?")
            ? [
                "id" => "comment_parallel",
                "message" => "Private original question comment_parallel",
            ]
            : [
                "id" => "reply_parallel",
                "message" => "Single concurrent answer",
            ],
    ];
});
check(
    $parallelReplies->send(
        $access,
        $parallelDraft["id"],
        $parallelDraft["version"],
        true,
    )["status"] === "sent" && $parallelCalls === 3,
    "concurrent send performs one actual write and two proof reads",
);
$unicode = original("facebook", "comment_unicode");
$unicodeDraft = $replies->save(
    $access,
    $unicode["id"],
    $unicode["version"],
    str_repeat("ü", 1500),
);
check(
    $unicodeDraft["payload"]["text"] === str_repeat("ü", 1500),
    "German reply length is measured in characters rather than cutting UTF-8 bytes",
);
$crash = original("facebook", "comment_crash");
$crashDraft = $replies->save(
    $access,
    $crash["id"],
    $crash["version"],
    "Interrupted answer",
);
Db::run(
    $db,
    "UPDATE native_social_replies SET status='sending',version=version+1 WHERE id=?",
    [$crashDraft["id"]],
);
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $crashDraft["id"],
        $crashDraft["version"],
        true,
    ),
    "persisted interrupted-send intent cannot be replayed after restart",
);
check(
    count($calls) === $before,
    "restart protection performs zero provider calls",
);
$revocation = original("facebook", "comment_after_intent");
$revocationDraft = $replies->save(
    $access,
    $revocation["id"],
    $revocation["version"],
    "Authority changed after intent",
);
// Revoke at the database commit boundary between durable intent and actual write.
$db->exec(
    "CREATE TRIGGER reply_authority_revoked AFTER UPDATE ON native_social_replies FOR EACH ROW BEGIN IF NEW.status='sending' AND OLD.status='draft' THEN DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'; END IF; END",
);
$blockedHttpCalls = 0;
$blockedReplies = new Replies($db, $box, static function ($method) use (
    &$blockedHttpCalls,
) {
    $blockedHttpCalls++;
    if ($method !== "GET") {
        throw new LogicException("Revoked sender reached write");
    }
    return [
        "status" => 200,
        "body" => [
            "id" => "comment_after_intent",
            "message" => "Private original question comment_after_intent",
        ],
    ];
});
denied(
    fn() => $blockedReplies->send(
        $access,
        $revocationDraft["id"],
        $revocationDraft["version"],
        true,
    ),
    "publication right is checked again after durable intent commits",
);
check(
    $blockedHttpCalls === 1 &&
        $replies->get($access, $revocationDraft["id"])["status"] === "blocked",
    "post-intent authority revocation keeps a stopped intent and causes no provider write",
);
$db->exec("DROP TRIGGER reply_authority_revoked");
Db::run($db, "INSERT INTO native_grants VALUES('admin','social','publish')");
$accounts->save(
    $access,
    "meta",
    [
        "page_id" => "789",
        "instagram_id" => "890",
        "api_version" => "v25.0",
        "token" => "another-synthetic-only",
    ],
    1,
);
$before = count($calls);
denied(
    fn() => $replies->send(
        $access,
        $revokedDraft["id"],
        $revokedDraft["version"],
        true,
    ),
    "changed Meta target cannot receive a previous-account reply",
);
check(
    count($calls) === $before,
    "account change rejected before provider call",
);
check(
    (int) $db
        ->query("SELECT COUNT(*) FROM native_delivery_jobs")
        ->fetchColumn() === 0,
    "reply workflow does not leak into automatic publication or mailing queues",
);
echo "$n native social reply checks passed; encrypted drafts, exact originals, explicit sends and synthetic transport only.\n";
