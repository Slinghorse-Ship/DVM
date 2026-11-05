<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, SecretBox, RuntimeSchema};
use Kulturhus\Native\Communication\{
    Attention,
    PersonalActivity,
    SocialHub,
    CheckPlans,
};
use Kulturhus\Native\Integration\StadtLandFunk\{Connection, Login, Http};
// An explicitly disabled legacy demo flag must reach input validation without opening a socket.
$oldDemo=getenv('KULTURHUS_LOCAL_DEMO');$oldMail=getenv('KULTURHUS_DISABLE_MAIL');putenv('KULTURHUS_DISABLE_MAIL=0');putenv('KULTURHUS_LOCAL_DEMO=0');
try{(new Http())->request('GET',Http::API.'/grapevine/post/own',["Rejected\r\nheader"]);throw new RuntimeException('Invalid header accepted.');}catch(DomainException $error){if($error->getMessage()!=='Ungültiger HTTP-Header.')throw $error;}
putenv('KULTURHUS_LOCAL_DEMO=1');try{(new Http())->request('GET',Http::API.'/grapevine/post/own');throw new RuntimeException('Demo network allowed.');}catch(DomainException $error){if(!str_contains($error->getMessage(),'gesperrt'))throw $error;}
putenv($oldDemo===false?'KULTURHUS_LOCAL_DEMO':'KULTURHUS_LOCAL_DEMO='.$oldDemo);putenv($oldMail===false?'KULTURHUS_DISABLE_MAIL':'KULTURHUS_DISABLE_MAIL='.$oldMail);
$db = TestSupport::db();
TestSupport::seed($db);
RuntimeSchema::install($db);
$a = new Access($db, "admin");
$box = new SecretBox(str_repeat("p", 32));
$club = $a->club();
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
$state = "";
$mode = "good";
$calls = [];
$refreshes = 0;
$transport = function ($method, $url, $headers, $body) use (
    &$state,
    &$mode,
    &$calls,
    &$refreshes,
    $db,
    $box,
    $club,
) {
    $path = parse_url($url, PHP_URL_PATH);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? "", $q);
    $calls[] = [$method, $path];
    $reply = static fn($data, $status = 200, $headers = []) => [
        "status" => $status,
        "body" => is_array($data) ? json_encode($data) : $data,
        "headers" => $headers,
    ];
    if (str_ends_with($path, "/openid-connect/auth")) {
        $state = $q["state"];
        return $reply(
            '<form id="kc-form-login" action="' .
                Http::ISSUER .
                '/login-actions/authenticate?session_code=synthetic"></form>',
        );
    }
    if (str_ends_with($path, "/login-actions/authenticate")) {
        return $reply("", 302, [
            "location" =>
                Login::CALLBACK .
                "?" .
                http_build_query([
                    "state" => $state,
                    "code" => "synthetic-code",
                    "iss" => Http::ISSUER,
                ]),
        ]);
    }
    if (str_ends_with($path, "/openid-connect/token")) {
        parse_str($body, $f);
        if ($f["grant_type"] === "refresh_token") {
            $refreshes++;
        }
        return $reply([
            "access_token" => "synthetic-access",
            "refresh_token" => "synthetic-refresh",
            "token_type" => "Bearer",
            "expires_in" => 7200,
            "refresh_expires_in" => 86400,
        ]);
    }
    if ($path === "/app/onboardingStatus") {
        return $reply([
            "ownPerson" => ["id" => "person_1"],
            "homeGeoArea" => ["id" => "area_1", "name" => "Testort"],
            "homeGeoAreaSelectionStatus" => "VALID",
            "onboardingRequired" => false,
        ]);
    }
    if (str_starts_with($path, "/grapevine/")) {
        check(
            in_array("Authorization: Bearer synthetic-access", $headers, true),
            "private provider read uses current synthetic token",
        );
        if ($mode === "cooldown") {
            return $reply(["error" => "synthetic throttling"], 429, [
                "retry-after" => "3600",
            ]);
        }
    }
    if ($path === "/grapevine/post/own") {
        if ($mode === "large") {
            return $reply(["content" => [], "totalPages" => 5]);
        }
        $index = (int) $q["page"];
        $id = $mode === "overlap" ? "event_0" : "event_" . $index;
        $event = [
            "creator" => [
                "id" => $mode === "foreign" ? "foreign_person" : "person_1",
            ],
            "title" => "Eigener Termin " . $index,
            "text" => "Originale Vereinsbeschreibung",
            "created" => 1791453600000,
            "startTime" => 1794663600000,
            "endTime" => 1794670800000,
            "likeCount" => $index === 0 ? 3 : 0,
            "commentCount" => 3,
        ];
        if (str_starts_with($mode, 'paged-')) {
            $rows=[];
            for($i=$index*50;$i<min(51,($index+1)*50);$i++)$rows[]=['id'=>'paged_event_'.$i,'happening'=>$event];
            $frame=['content'=>$rows,'page'=>['number'=>$index,'size'=>50,'totalElements'=>51,'totalPages'=>2]];
            if($mode==='paged-conflict')$frame['totalPages']=1;
            if($mode==='paged-short')array_pop($frame['content']);
            if($mode==='paged-wrong-number')$frame['page']['number']=1-$index;
            if($mode==='paged-null')$frame['page']=null;
            if($mode==='paged-string')$frame['page']['totalPages']='2';
            if($mode==='paged-large'){$frame['page']['totalElements']=201;$frame['page']['totalPages']=5;}
            if($index===1&&$mode==='paged-changing-total'){$frame['page']['totalElements']=52;$frame['content'][]=['id'=>'paged_event_51','happening'=>$event];}
            if($index===1&&$mode==='paged-changing-format')$frame=['content'=>$rows,'totalPages'=>2];
            if($index===1&&$mode==='paged-duplicate')$frame['content'][0]['id']='paged_event_0';
            return $reply($frame);
        }
        return $reply([
            "content" => [["id" => $id, "happening" => $event]],
            "totalPages" => 2,
        ]);
    }
    if ($path === "/grapevine/comment") {
        if ($mode === "rights") {
            Db::run(
                $db,
                "DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'",
            );
        }
        if ($mode === "reconnect") {
            $row = Db::run(
                $db,
                "SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",
                [$club],
            )->fetch();
            $data = $box->open(
                "api:" . $club . ":stadtlandfunk",
                $row["ciphertext"],
            );
            $data["connection_id"] = "new-synthetic-connection";
            Db::run(
                $db,
                "UPDATE native_private_settings SET ciphertext=?,version=version+1 WHERE club_id=? AND purpose='api-stadtlandfunk'",
                [$box->seal("api:" . $club . ":stadtlandfunk", $data), $club],
            );
        }
        if ($mode === "deleted") {
            return $reply([]);
        }
        $parent = $mode === "foreign-comment" ? "foreign_event" : $q["postId"];
        return $reply([
            [
                "id" => "comment_" . $q["postId"],
                "postId" => $parent,
                "text" =>
                    $mode === "edited"
                        ? "Private Frage ergänzt"
                        : "Private Frage zum Termin",
                "created" => 1791453600000,
                "creator" => [
                    "id" => "guest_1",
                    "firstName" => "Synthetische",
                    "lastName" => "Person",
                ],
            ],
            [
                "id" => "own_reply_" . $q["postId"],
                "postId" => $parent,
                "text" => "Unsere frühere Antwort",
                "created" => 1791453700000,
                "creator" => ["id" => "person_1"],
            ],
            [
                "id" => "deleted_" . $q["postId"],
                "postId" => $parent,
                "text" => "Gelöscht",
                "deleted" => true,
                "creator" => ["id" => "guest_2"],
            ],
        ]);
    }
    throw new RuntimeException("Unexpected synthetic personal endpoint");
};
$login = new Login($transport);
$connection = new Connection($db, $box, $login);
$connection->connect(
    $a,
    "synthetic@example.invalid",
    "Synthetic-personal-password",
    0,
    true,
);
$activity = new PersonalActivity($db, $box, $connection);
$calls = [];
$result = $activity->sync($a);
check(
    $result["events"] === 2 && $result["changed_activity"] === 3,
    "complete own event pages import two incoming comments and one actual reaction",
);
check(
    !array_filter($calls, fn($c) => $c[0] !== "GET"),
    "personal event and activity sync never publishes or replies",
);
$hub = new SocialHub($db, $box, static function () {
    throw new RuntimeException("Meta must not be called for personal data");
});
$list = $hub->listing($a, "", "event", "stadtlandfunk");
check(
    $list["total"] === 2 &&
        $list["rows"][0]["payload"]["permalink"] ===
            "https://www.stadtlandfunk.app/post-detail?id=event_1",
    "shared library retains native personal event source and original link",
);
$id = $list["rows"][0]["id"];
$comments = $hub->privateComments($a, $id);
check(
    count($comments) === 1 && $comments[0]["name"] === "Synthetische Person",
    "own replies and deleted comments are excluded from incoming review",
);
check(
    !str_contains(
        $hub->object($a, $id)["comments_cipher"],
        "Private Frage zum Termin",
    ),
    "personal comments encrypted with the club and object context",
);
denied(
    fn() => $hub->privateComments(new Access($db, "reader"), $id),
    "personal private comments require manage rights",
);
denied(
    fn() => $hub->comments($a, $id),
    "personal comments cannot accidentally route to Meta Graph",
);
denied(
    fn() => $hub->insights($a, $id),
    "personal insights cannot accidentally route to Meta Graph",
);
$attention = new Attention($db, $box);
$unchangedPersonalBytes=$hub->object($a,$id)['comments_cipher'];
$originalArchive=new \Kulturhus\Native\Communication\OriginalArchive($db,$box);
$historyCount=$originalArchive->history($a,'object',$id)['total'];
foreach ($attention->listing($a) as $row) {
    $attention->setState($a, $row["id"], "done", (int) $row["version"]);
}
check(
    $activity->sync($a)["changed_activity"] === 0 &&
        $attention->listing($a) === [],
    "repeated actual activity keeps prior human decisions",
);
check($unchangedPersonalBytes===$hub->object($a,$id)['comments_cipher']&&$historyCount===$originalArchive->history($a,'object',$id)['total'],'unchanged personal read retains exact encrypted comment bytes and no false object revision');
$mode = "edited";
$originalPersonalObject=$hub->object($a,$id);
$activity->sync($a);
$history=$originalArchive->history($a,'object',$id);$retainedPersonalObject=$originalArchive->get($a,$history['rows'][0]['id'])['original'];ksort($originalPersonalObject);ksort($retainedPersonalObject);
check($retainedPersonalObject===$originalPersonalObject,'actual edited personal comments retain complete prior event and every exact original ciphertext byte');
check(
    count($attention->listing($a)) === 2,
    "provider edits reopen incoming comments without reopening unchanged reactions",
);
$before = Db::run(
    $db,
    "SELECT id,payload_json,comments_cipher FROM native_social_objects ORDER BY id",
)->fetchAll();
foreach (["foreign", "overlap", "large", "foreign-comment", 'paged-conflict', 'paged-short', 'paged-wrong-number', 'paged-null', 'paged-string', 'paged-large', 'paged-changing-total', 'paged-changing-format', 'paged-duplicate'] as $bad) {
    $mode = $bad;
    denied(
        fn() => $activity->sync($a),
        "reject incomplete or foreign personal data " . $bad,
    );
    check(
        Db::run(
            $db,
            "SELECT id,payload_json,comments_cipher FROM native_social_objects ORDER BY id",
        )->fetchAll() === $before,
        "prior complete snapshot survives " . $bad,
    );
}
$mode='paged-good';$start=count($calls);$db->beginTransaction();
try {
    check($activity->sync($a)['events']===51,'current PagedModel reads all 51 original events across two complete pages');
    check(count($calls)===$start+53&&!array_filter(array_slice($calls,$start),fn($c)=>$c[0]!=='GET'),'nested pagination and all comments use only bounded original GET requests');
} finally {$db->rollBack();}
check(Db::run($db,"SELECT id,payload_json,comments_cipher FROM native_social_objects ORDER BY id")->fetchAll()===$before,'current-format test leaves the prior legacy originals byte-identical');
$mode = "rights";
denied(
    fn() => $activity->sync($a),
    "revoked live rights stop personal import after provider read",
);
Db::run(
    $db,
    "INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','social','manage')",
);
check(
    Db::run(
        $db,
        "SELECT id,payload_json,comments_cipher FROM native_social_objects ORDER BY id",
    )->fetchAll() === $before,
    "revocation leaves all prior snapshots intact",
);
$mode = "reconnect";
denied(
    fn() => $activity->sync($a),
    "account reconnect during read prevents old response being stored",
);
$mode = "good";
$row = Db::run(
    $db,
    "SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",
    [$club],
)->fetch();
$data = $box->open("api:" . $club . ":stadtlandfunk", $row["ciphertext"]);
$data["expires_at"] = time() - 1;
Db::run(
    $db,
    "UPDATE native_private_settings SET ciphertext=? WHERE club_id=? AND purpose='api-stadtlandfunk'",
    [$box->seal("api:" . $club . ":stadtlandfunk", $data), $club],
);
$connection->read($a, "/grapevine/post/own?page=0&type=HAPPENING");
check(
    $refreshes === 1,
    "read renews expired access token with the actual rotating refresh flow",
);
denied(
    fn() => $connection->read($a, "/grapevine/post/create"),
    "read adapter rejects personal publishing endpoints",
);
$mode = "cooldown";
try {
    $activity->sync($a);
    throw new RuntimeException("cooldown missing");
} catch (\Kulturhus\Native\Integration\Cooldown $wait) {
    check(
        $wait->seconds === 3600,
        "personal provider cooldown escapes without further probing",
    );
}
$mode = "deleted";
$activity->sync($a);
check(
    !array_filter(
        $attention->listing($a, "all"),
        fn($r) => $r["kind"] === "comment",
    ),
    "complete removed-comment snapshot clears private review entries",
);
$plans = new CheckPlans($db);
$plans->save($a, "stadtlandfunk", [], true, 0);
$connection->disconnect($a, $connection->status($a)["version"]);
check(
    !$plans->status($a, "stadtlandfunk")["enabled"] &&
        $attention->listing($a, "all") === [] &&
        $hub->privateComments($a, $id) === [],
    "disconnect pauses personal plan and clears all cached private activity",
);
check(
    $hub->listing($a, "", "event", "stadtlandfunk")["total"] === 2,
    "disconnect retains public original history without deleting provider events",
);
echo "$n native personal activity checks passed; synthetic PHP HTTP contract and MySQL only.\n";
