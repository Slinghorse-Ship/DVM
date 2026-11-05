<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__) . "/src/autoload.php";

use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge, Schema};
use Kulturhus\Native\Communication\{Campaigns, Workspace};
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database, Settings, SettingsSchema};

$db = TestSupport::db();
Database::migrate($db);
$seed = TestSupport::seed($db);
\Kulturhus\Native\Communication\Schema::install($db);
\Kulturhus\Native\Cms\Schema::install($db);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks++;
};
$deny = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (DomainException) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
};
$standalone = new Identity($db, new Access($db, $seed["admin"]));
$standalone->save(
    [
        "name" => "Eigenständiger Verein",
        "address" => "Eigene Straße 1",
        "origin" => "https://standalone.example.invalid",
    ],
    0,
);
$check(
    $standalone->source() === null &&
        $standalone->get()["name"] === "Eigenständiger Verein",
    "standalone needs no manager tables",
);
Schema::install($db);
Db::run(
    $db,
    "INSERT INTO clubs VALUES('settings-a','Prüfverein'),('settings-b','Anderer Verein')",
);
Db::run(
    $db,
    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('settings-admin','settings-a','members',1),('other-admin','settings-b','members',1)",
);
$access = (new ManagerBridge($db, "settings-admin"))->access();
$other = (new ManagerBridge($db, "other-admin"))->access();
$identity = new Identity($db, $access);
Db::run(
    $db,
    "INSERT INTO native_club_settings VALUES(?,1,?,?,UTC_TIMESTAMP())",
    [
        $access->club(),
        json_encode([
            "name" => "Früherer Website-Name",
            "address" => "Frühere Website-Anschrift",
            "origin" => "https://club.example.invalid",
            "logo_id" => "",
        ]),
        $access->user()["id"],
    ],
);
$check(
    !$identity->source()["configured"],
    "unconfigured manager preserves prior website values",
);
$item = (new Workspace($db, $access, "newsletter"))->save([
    "title" => "Originalausgabe",
    "body" => "Gespeicherter Originalinhalt",
    "blocks" => [],
]);
$campaigns = new Campaigns($db, $access, new SecretBox(random_bytes(32)));
$link = $campaigns->viewLink($item["id"], true);
parse_str(parse_url($link, PHP_URL_QUERY), $query);
$original = Campaigns::publicView($db, $access->club(), $query["token"]);
$settings = new Settings($db, "settings-admin");
$club = array_replace(SettingsSchema::defaults("club"), [
    "name" => "Gemeinsamer Verein",
    "street" => "Teststraße 7",
    "address2" => "Haus A",
    "postcode" => "12345",
    "city" => "Teststadt",
]);
$settings->save("club", $club, 0);
$current = $identity->get();
$check(
    $current["name"] === $club["name"] &&
        $current["address"] === "Teststraße 7\nHaus A\n12345 Teststadt",
    "native identity reads actual canonical manager settings",
);
$paper = $settings->paperForDocument();
$check(
    $paper["sender_name"] === $current["name"] &&
        implode("\n", [
            $paper["sender_street"],
            $paper["sender_address2"],
            $paper["sender_city"],
        ]) === $current["address"],
    "new letterhead and native branding share name and address",
);
$check(
    $identity->source()["previous"]["name"] === "Früherer Website-Name",
    "earlier conflict remains available for review",
);
$check(
    Campaigns::publicView($db, $access->club(), $query["token"]) === $original,
    "issued browser view remains byte-equivalent after common settings change",
);
$post = [
    "club_version" => 1,
    "name" => "Forged name",
    "address" => "Forged address",
    "origin" => "https://changed.example.invalid",
];
$identity->save($post, 1);
$check(
    $identity->get()["origin"] === $post["origin"] &&
        $settings->section("club")["data"] ===
            SettingsSchema::normalize("club", $club),
    "website extension cannot overwrite common fields",
);
$check(
    $identity->source()["previous"]["address"] === "Frühere Website-Anschrift",
    "saving extension retains earlier website address",
);
$deny(fn() => $identity->save($post, 1), "stale website version rejected");
$settings->save(
    "club",
    array_replace($club, ["name" => "Geänderter Verein"]),
    1,
);
$deny(fn() => $identity->save($post, 2), "stale canonical version rejected");
$check(
    $identity->get()["version"] === 2,
    "rejected canonical conflict leaves extension unchanged",
);
$post["club_version"] = 2;
$deny(
    fn() => $identity->save($post + ["logo_id" => str_repeat("a", 32)], 2),
    "missing or foreign logo rejected",
);
$deny(
    fn() => $identity->save(
        array_replace($post, [
            "origin" => "https://changed.example.invalid/path",
        ]),
        2,
    ),
    "website origin remains path-free",
);
$check(
    Identity::forClub($db, $other->club())["name"] === "",
    "other mapped club does not inherit canonical profile",
);
$check(
    $standalone->get()["name"] === "Eigenständiger Verein",
    "standalone identity unchanged with manager tables installed",
);
$html = (new \Kulturhus\Native\Web\Settings\Workspace(
    $db,
    $access,
    "/unused",
    "synthetic-csrf",
))->manager();
$check(
    str_contains($html, "Geänderter Verein") &&
        str_contains($html, "section=club") &&
        str_contains($html, "Früherer Website-Name"),
    "settings show canonical profile, original conflict and single edit route",
);
$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
$check(
    $xpath->query(
        '//form[input[@name="operation" and @value="club-settings"]]//input[@name="name" or @name="address"]',
    )->length === 0,
    "integrated settings have no duplicate common edit fields",
);
Db::run($db, "UPDATE actors SET is_superuser=0 WHERE id='settings-admin'");
$deny(
    fn() => $identity->save($post, 2),
    "same access object recognizes revoked administration",
);
$check(
    Campaigns::publicView($db, $access->club(), $query["token"]) === $original,
    "revoked administration does not alter issued originals",
);
echo json_encode(
    [
        "suite" => "native-manager-settings",
        "passed" => $checks,
        "live_transport" => false,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;
