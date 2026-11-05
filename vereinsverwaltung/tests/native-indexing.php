<?php
declare(strict_types=1);
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/bootstrap.php";

use Kulturhus\Native\Cms\{Conflict, Pages, WebsiteSettings};
use Kulturhus\Native\Integration\{ManagerBridge, PublicSite};
use Kulturhus\Native\Shared\{Access, Db, Identity};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\{Indexing, Manager, PublicLayout};
use Kulturhus\Verein\Database;

if (PHP_SAPI === "cli-server") {
    $stateFile = getenv("DVM_INDEXING_HTTP_STATE");
    $port = getenv("DVM_INDEXING_HTTP_PORT");
    if (
        getenv("DVM_INDEXING_HTTP") !== "synthetic" ||
        !is_string($stateFile) ||
        !is_file($stateFile) ||
        ($_SERVER["REMOTE_ADDR"] ?? "") !== "127.0.0.1" ||
        ($_SERVER["HTTP_HOST"] ?? "") !== "127.0.0.1:" . $port
    ) {
        http_response_code(403);
        exit();
    }
    $state = json_decode(
        file_get_contents($stateFile),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    $db = TestSupport::db();
    if (
        !PublicSite::serve(
            $db,
            new Access($db, $state["reader"]),
            $state["root"] . "/media",
            $state["root"],
            $state["club"],
            $state["origin"],
        )
    ) {
        http_response_code(404);
    }
    return;
}

$db = TestSupport::db();
Database::migrate($db);
$seed = TestSupport::seed($db);
\Kulturhus\Native\Cms\Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
$club = "indexing-" . bin2hex(random_bytes(5));
$nativeClub = ManagerBridge::clubKey($club);
foreach (["admin", "editor", "reader"] as $role) {
    Db::run($db, "INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)", [
        $club . "-" . $role,
        $nativeClub,
        "Indexing " . $role,
    ]);
    Db::run(
        $db,
        'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=? AND module=\'website\'',
        [$club . "-" . $role, $seed[$role]],
    );
}
$admin = new Access($db, $club . "-admin");
$reader = new Access($db, $club . "-reader");
$identity = new Identity($db, $admin);
$settings = new WebsiteSettings($db, $admin);
$currentIdentity = static fn(): array => array_replace($identity->get(), [
    "website_indexing" => $settings->get()["enabled"],
]);
$origin = "https://indexing.example.invalid";
$identity->save(
    [
        "name" => "Synthetischer Indexverein",
        "origin" => $origin,
        "address" => "",
    ],
    0,
);
$root =
    dirname(getenv("KH_NATIVE_TEST_CONFIG")) .
    "/indexing-runtime-" .
    bin2hex(random_bytes(6));
mkdir($root, 0700);
$pages = new Pages($db, $admin);
foreach (
    [
        ["slug" => "start", "title" => "Öffentliche Startfassung"],
        ["slug" => "indexierbar", "title" => "Öffentliche Indexfassung"],
        [
            "slug" => "versteckt",
            "title" => "Nicht indexieren",
            "noindex" => true,
        ],
        ["slug" => "privat", "title" => "PRIVATE-INDEX-DRAFT"],
        [
            "slug" => "aktuelles/rss-original",
            "title" => "RSS-ONLY-INDEXING",
            "template" => "news",
            "news_date" => "2026-10-09",
            "body" => "Synthetischer Beitrag ausschließlich für den Feed.",
            "news_channels" => ["rss"],
        ],
    ]
    as $data
) {
    $page = $pages->save($data);
    $ids[$data["slug"]] = $page["id"];
    if ($data["slug"] !== "privat") {
        $pages->publish($page["id"], $page["version"]);
    }
}
$original = $pages->publicPage("indexierbar");
for ($number = 0; $number < 102; ++$number) {
    $extra = $pages->save([
        "slug" => "umfang-" . $number,
        "title" => "Synthetischer Umfang " . $number,
    ]);
    $pages->publish($extra["id"], $extra["version"]);
}
$draft = $pages->get($ids["indexierbar"]);
$draft["title"] = "PRIVATE-INDEX-CHANGE";
$draft["noindex"] = true;
$draft = $pages->save($draft, $draft["version"]);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    ++$checks;
};
$deny = static function (callable $operation, string $label) use (
    $check,
): void {
    try {
        $operation();
    } catch (DomainException | InvalidArgumentException | Conflict $e) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
};
$production = static function (): void {
    putenv("VEREIN_MODE=production");
    putenv("VEREIN_AUTH=native");
    putenv("KH_NATIVE_MANAGER=1");
    putenv("KULTURHUS_LOCAL_DEMO=0");
    putenv("KH_NATIVE_LOCAL_DEMO");
};
$production();
$check(
    !Indexing::enabled($currentIdentity(), $origin),
    "historical settings stay blocked until an explicit choice",
);
$manager = new Manager($db, $admin, $root . "/media", "indexing-csrf");
$post = [
    "operation" => "website-indexing",
    "csrf" => "indexing-csrf",
    "version" => "0",
    "name" => "Synthetischer Indexverein",
    "address" => "",
    "origin" => $origin,
    "website_indexing_present" => "1",
    "website_indexing" => "1",
];
$manager->action("settings", $post, []);
$check(
    $settings->get()["enabled"] === true &&
        $settings->get()["version"] === 1 &&
        $identity->get()["version"] === 1,
    "actual settings controller stores explicit indexing choice",
);
$check(
    Indexing::enabled($currentIdentity(), $origin),
    "configured native HTTPS website permits public indexing",
);
$deny(
    static fn() => $manager->action("settings", $post, []),
    "stale settings form cannot overwrite current indexing choice",
);
$deny(
    static fn() => (new Manager(
        $db,
        new Access($db, $club . "-editor"),
        $root . "/media",
        "indexing-csrf",
    ))->action("settings", array_replace($post, ["version" => "1"]), []),
    "editor has no technical website settings permission",
);
$deny(
    static fn() => (new Manager(
        $db,
        $reader,
        $root . "/media",
        "indexing-csrf",
    ))->action("settings", array_replace($post, ["version" => "1"]), []),
    "reader cannot change indexing",
);
foreach (
    [
        ["website_indexing_present" => ["1"]],
        ["website_indexing" => ["1"]],
        ["website_indexing" => "yes"],
        ["website_indexing_present" => "yes"],
    ]
    as $malformed
) {
    $deny(
        static fn() => $manager->action(
            "settings",
            array_replace($post, ["version" => "1"], $malformed),
            [],
        ),
        "malformed checkbox submission rejected",
    );
}
$deny(
    static fn() => $settings->save("1", 1),
    "service requires an actual boolean choice",
);
foreach ([-1, "01", "1x", [], "4294967295"] as $invalidVersion) {
    $deny(
        static fn() => $manager->action(
            "settings",
            array_replace($post, ["version" => $invalidVersion]),
            [],
        ),
        "malformed or out-of-range version cannot change website policy",
    );
}
$check(
    count($settings->history()) === 1 &&
        $settings->history()[0]["actor_id"] === $club . "-admin",
    "history records the actual own administration without failed attempts",
);
$deny(
    static fn() => (new WebsiteSettings($db, $reader))->history(),
    "public reader cannot retrieve administration history",
);
$identity->save(
    ["name" => "Synthetischer Indexverein", "origin" => $origin],
    1,
);
$check(
    $settings->get()["enabled"] === true && $settings->get()["version"] === 1,
    "older service callers retain saved indexing choice",
);
$oldClient = array_replace($post, [
    "operation" => "club-settings",
    "version" => "2",
]);
unset($oldClient["website_indexing_present"], $oldClient["website_indexing"]);
$manager->action("settings", $oldClient, []);
$check(
    $settings->get()["enabled"] === true && $settings->get()["version"] === 1,
    "older HTTP clients retain saved indexing choice",
);
foreach (
    [
        "VEREIN_MODE=synthetic",
        "VEREIN_AUTH=processwire",
        "KH_NATIVE_MANAGER=0",
        "KULTURHUS_LOCAL_DEMO=1",
        "KH_NATIVE_LOCAL_DEMO=synthetic",
    ]
    as $disabled
) {
    $production();
    putenv($disabled);
    $check(
        !Indexing::enabled($currentIdentity(), $origin),
        "runtime guard prevents indexing: " . explode("=", $disabled)[0],
    );
}
$production();
foreach (
    [
        "http://indexing.example.invalid",
        "https://foreign.example.invalid",
        $origin . "/path",
        $origin . "?x=1",
        $origin . "#x",
        "https://user@indexing.example.invalid",
        "https://indexing.example.invalid:443",
    ]
    as $bad
) {
    $check(
        !Indexing::enabled($currentIdentity(), $bad),
        "unconfigured or malformed origin cannot enable indexing",
    );
}
foreach (["1", 1, null, ["enabled"], false] as $bad) {
    $check(
        !Indexing::enabled(
            array_replace($currentIdentity(), ["website_indexing" => $bad]),
            $origin,
        ),
        "malformed stored policy remains blocked",
    );
}
foreach (["search", "suche", "mitglied-werden", "newsletter"] as $private) {
    $check(
        Indexing::directive($currentIdentity(), $origin, false, $private) ===
            "noindex,nofollow",
        "sensitive public endpoint remains blocked: " . $private,
    );
}
$check(
    Indexing::directive($currentIdentity(), $origin, true, "indexierbar") ===
        "noindex,nofollow",
    "page-level exclusion overrides site choice",
);
$layout = PublicLayout::render(
    "Original",
    "",
    "<main>Original</main>",
    [],
    "indexierbar",
    $origin,
    "/vereinsverwaltung/",
    $currentIdentity(),
);
$check(
    str_contains($layout, 'content="index,follow"'),
    "shared public layout has matching production robots metadata",
);
$layout = PublicLayout::render(
    "Original",
    "",
    "<main>Original</main>",
    [],
    "indexierbar",
    $origin,
    "/vereinsverwaltung/",
    $currentIdentity(),
    true,
);
$check(
    str_contains($layout, 'content="noindex,nofollow"'),
    "shared layout retains per-page noindex",
);
$stateFile = $root . "/http-state.json";
file_put_contents(
    $stateFile,
    json_encode(
        [
            "reader" => $club . "-reader",
            "root" => $root,
            "club" => $club,
            "origin" => $origin,
        ],
        JSON_THROW_ON_ERROR,
    ),
);
chmod($stateFile, 0600);
mkdir($root . "/web", 0700);
$socket = stream_socket_server("tcp://127.0.0.1:0", $errorNumber, $errorText);
if (!$socket) {
    throw new RuntimeException("Loopback socket unavailable");
}
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
fclose($socket);
putenv("DVM_INDEXING_HTTP=synthetic");
putenv("DVM_INDEXING_HTTP_PORT=" . $port);
putenv("DVM_INDEXING_HTTP_STATE=" . $stateFile);
$process = proc_open(
    [
        ...TestSupport::phpCommand(),
        "-t",
        $root . "/web",
        "-S",
        "127.0.0.1:" . $port,
        __FILE__,
    ],
    [
        0 => ["pipe", "r"],
        1 => ["file", $root . "/http.log", "a"],
        2 => ["file", $root . "/http.log", "a"],
    ],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException("Isolated HTTP process unavailable");
}
fclose($pipes[0]);
$request = static function (
    string $path,
    string $method = "GET",
    array $headers = [],
) use ($port): array {
    $context = stream_context_create([
        "http" => [
            "method" => $method,
            "ignore_errors" => true,
            "follow_location" => 0,
            "timeout" => 5,
            "header" => implode("\r\n", $headers),
        ],
    ]);
    $body = file_get_contents(
        "http://127.0.0.1:" . $port . $path,
        false,
        $context,
    );
    if (!is_string($body)) {
        throw new RuntimeException("Isolated HTTP response unavailable");
    }
    $lines = $http_response_header ?? [];
    preg_match("~HTTP/\S+ (\d+)~", $lines[0] ?? "", $status);
    $map = [];
    foreach (array_slice($lines, 1) as $line) {
        if (($position = strpos($line, ":")) !== false) {
            $map[strtolower(substr($line, 0, $position))] = trim(
                substr($line, $position + 1),
            );
        }
    }
    return [(int) ($status[1] ?? 0), $map, $body];
};
try {
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $connection = @stream_socket_client(
            "tcp://127.0.0.1:" . $port,
            $n,
            $text,
            0.05,
        );
        if ($connection) {
            fclose($connection);
            break;
        }
        usleep(20000);
    }
    [$status, $headers, $html] = $request("/indexierbar/");
    $check(
        $status === 200 &&
            ($headers["x-robots-tag"] ?? "") === "index, follow" &&
            str_contains($html, 'content="index,follow"'),
        "actual HTTP header and HTML metadata agree on published page",
    );
    $check(
        str_contains($html, "Öffentliche Indexfassung") ||
            str_contains($html, "&Ouml;ffentliche Indexfassung"),
        "HTTP page uses the published title",
    );
    $check(
        !str_contains($html, "PRIVATE-INDEX-CHANGE"),
        "draft title and noindex change remain private",
    );
    $etag = $headers["etag"];
    [$status, $headers, $body] = $request("/indexierbar/", "HEAD");
    $check(
        $status === 200 &&
            $body === "" &&
            $headers["etag"] === $etag &&
            ($headers["x-robots-tag"] ?? "") === "index, follow",
        "indexed HEAD carries headers without body",
    );
    [$status, $headers, $body] = $request("/indexierbar/", "GET", [
        "If-None-Match: " . $etag,
    ]);
    $check(
        $status === 304 &&
            $body === "" &&
            ($headers["x-robots-tag"] ?? "") === "index, follow",
        "indexed conditional response preserves robots choice",
    );
    [$status, $headers, $body] = $request("/versteckt/");
    $check(
        $status === 200 &&
            ($headers["x-robots-tag"] ?? "") === "noindex, nofollow" &&
            str_contains($body, 'content="noindex,nofollow"'),
        "published per-page exclusion applies in actual HTTP",
    );
    [$status, $headers, $body] = $request("/search?q=Original");
    $check(
        $status === 200 &&
            ($headers["x-robots-tag"] ?? "") === "noindex, nofollow" &&
            str_contains($body, 'content="noindex,nofollow"'),
        "actual search stays excluded",
    );
    [$status, $headers, $robots] = $request("/robots.txt");
    $check(
        $status === 200 &&
            str_contains($robots, "Allow: /\n") &&
            str_contains($robots, "Disallow: /vereinsverwaltung") &&
            str_contains($robots, "Disallow: /reset") &&
            str_contains($robots, "Disallow: /page-photo-status") &&
            str_contains($robots, "Disallow: /album-photo-status") &&
            str_contains($robots, "Disallow: /newsletter/bestaetigen") &&
            str_contains($robots, "Sitemap: " . $origin . "/sitemap/"),
        "actual production robots preserves public access and protects internal/token paths",
    );
    foreach (["/sitemap.xml", "/sitemap", "/sitemap/"] as $path) {
        [$status, $headers, $xml] = $request($path);
        $document = new DOMDocument();
        $check(
            $status === 200 &&
                str_starts_with(
                    $headers["content-type"] ?? "",
                    "application/xml",
                ) &&
                $document->loadXML($xml, LIBXML_NONET),
            "actual original sitemap route returns valid XML: " . $path,
        );
        $check(
            str_contains($xml, $origin . "/indexierbar/") &&
                !str_contains($xml, "/site/") &&
                !str_contains($xml, "/versteckt/") &&
                !str_contains($xml, "/privat/") &&
                !str_contains($xml, "rss-original"),
            "sitemap excludes draft, page exclusion and RSS-only originals",
        );
        $check(
            str_contains(
                $xml,
                "<lastmod>" .
                    (new DateTimeImmutable(
                        $original["published_at"],
                        new DateTimeZone("UTC"),
                    ))->format(DATE_ATOM) .
                    "</lastmod>",
            ),
            "sitemap reports publication time rather than private draft modification",
        );
        $check(
            substr_count($xml, "<url>") === 104 &&
                str_contains($xml, "/umfang-0/") &&
                str_contains($xml, "/umfang-101/"),
            "sitemap retains every published page across multiple SQL batches",
        );
    }
    [$status, , $body] = $request("/sitemap/", "POST");
    $check($status === 405, "sitemap rejects mutations");
    [$status, , $body] = $request("/sitemap/", "HEAD");
    $check(
        $status === 200 && $body === "",
        "original sitemap HEAD is body-free",
    );
    $pages->publish($draft["id"], $draft["version"]);
    [$status, $headers, $body] = $request("/indexierbar/", "GET", [
        "If-None-Match: " . $etag,
    ]);
    $check(
        $status === 200 &&
            ($headers["x-robots-tag"] ?? "") === "noindex, nofollow" &&
            str_contains($body, 'content="noindex,nofollow"'),
        "actual publication changes indexing and invalidates the old cached response",
    );
    $post["version"] = "1";
    unset($post["website_indexing"]);
    $manager->action("settings", $post, []);
    $check(
        $settings->get()["enabled"] === false,
        "unchecked current HTTP form withdraws global permission",
    );
    [$status, , $body] = $request("/robots.txt");
    $check(
        $status === 200 &&
            str_contains($body, "\n\nUser-agent: *\nDisallow: /\n") &&
            !str_contains($body, "Allow: /\n") &&
            !str_contains($body, "Sitemap:"),
        "global withdrawal still blocks general indexing and sitemap discovery",
    );
    $check(
        str_starts_with($body, "User-agent: facebookexternalhit\n") &&
            str_contains($body, "Allow: /publication-video/\n") &&
            str_contains($body, "Allow: /publication-image/\n"),
        "only Meta's named crawler can fetch bounded publication file paths",
    );
    $metaRules = explode("\n\n", $body)[0];
    $check(
        substr_count($metaRules, "Allow:") === 2 &&
            str_ends_with($metaRules, "Disallow: /"),
        "Meta's exception never opens content pages, accounts or other private paths",
    );
    [$status, $headers, $body] = $request("/");
    $check(
        $status === 200 &&
            ($headers["x-robots-tag"] ?? "") === "noindex, nofollow" &&
            str_contains($body, 'content="noindex,nofollow"'),
        "global withdrawal invalidates public cached metadata",
    );
    $other = new WebsiteSettings($db, new Access($db, $seed["outsider"]));
    $other->save(true, 0);
    $check(
        $settings->get()["enabled"] === false,
        "another club cannot enable the own website",
    );
    $history = $settings->history();
    $check(
        count($history) === 2 &&
            (int) $history[0]["indexing_enabled"] === 0 &&
            (int) $history[1]["indexing_enabled"] === 1,
        "withdrawal retains both exact earlier website decisions",
    );
    Db::run($db, "UPDATE native_users SET active=0 WHERE id=?", [
        $club . "-admin",
    ]);
    $deny(
        static fn() => $settings->save(true, 2),
        "suspended administration immediately loses policy-changing rights",
    );
    Db::run($db, "UPDATE native_users SET active=1 WHERE id=?", [
        $club . "-admin",
    ]);
    $check(
        $settings->get()["version"] === 2 && count($settings->history()) === 2,
        "denied suspended submission creates no policy or history revision",
    );
} finally {
    proc_terminate($process);
    proc_close($process);
    putenv("DVM_INDEXING_HTTP");
    putenv("DVM_INDEXING_HTTP_PORT");
    putenv("DVM_INDEXING_HTTP_STATE");
}
$check(
    !class_exists("ProcessWire\\ProcessWire", false),
    "complete public indexing path needs no ProcessWire runtime",
);
echo json_encode(
    [
        "suite" => "native-indexing",
        "passed" => $checks,
        "transport" => "isolated loopback HTTP and MySQL",
        "external_transport" => false,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;
