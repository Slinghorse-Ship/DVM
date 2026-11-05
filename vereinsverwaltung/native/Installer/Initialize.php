<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use Kulturhus\Native\Integration\{ManagerBridge, ProductionSite};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Auth\Runtime;

/** Shared empty-install initialization; web authorization is checked by SchemaRegistry. */
final class Initialize
{
    public static function run(
        string $source,
        string $target,
        array $config,
        PDO $db,
        ?SetupPermit $permit = null,
    ): array {
        require_once $source . "/vereinsverwaltung/src/autoload.php";
        require_once $source .
            "/vereinsverwaltung/src/Infrastructure/Runtime/ProductionRuntime.php";
        // Keys persist across retries; a retry never rotates credentials or rewrites files.
        foreach (
            ["auth.key", "private.key", "member-bank-keys/v1.key"]
            as $name
        ) {
            $path = $target . "/private/" . $name;
            \PackageStore::directory(dirname($path));
            if (!file_exists($path)) {
                Deployment::file($path, random_bytes(32), 0600);
            }
            if (
                is_link($path) ||
                strlen((string) file_get_contents($path)) !== 32 ||
                fileperms($path) & 0077
            ) {
                throw new \DomainException(
                    "Privater Schlüssel nicht sicher gespeichert.",
                );
            }
        }
        $modules = SchemaRegistry::install($db, $permit, $target);
        require_once $source . "/vereinsverwaltung/src/Auth/Permissions.php";
        require_once $source .
            "/vereinsverwaltung/src/Settings/SettingsSchema.php";
        $actor = "native-install-admin";
        if (
            (int) Db::run($db, "SELECT COUNT(*) FROM clubs")->fetchColumn() ===
            0
        ) {
            Db::atomic($db, function () use ($db, $config, $actor): void {
                Db::run($db, "INSERT INTO clubs(id,name) VALUES(?,?)", [
                    $config["club_id"],
                    $config["club_name"],
                ]);
                Db::run(
                    $db,
                    "INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,1)",
                    [$actor, $config["club_id"], "member"],
                );
            });
        }
        $row = Db::run(
            $db,
            "SELECT club_id,is_superuser FROM actors WHERE id=?",
            [$actor],
        )->fetch();
        if (
            !$row ||
            $row["club_id"] !== $config["club_id"] ||
            !$row["is_superuser"]
        ) {
            throw new \DomainException(
                "Begonnene Datenbank gehört zu einer anderen Installation.",
            );
        }
        putenv("KH_NATIVE_MANAGER=1");
        putenv("VEREIN_AUTH=native");
        $bridge = new ManagerBridge($db, $actor, "native", static fn() => null);
        $access = $bridge->access();
        $auth = Runtime::service($db, $target . "/private/auth.key");
        $existing = Db::run(
            $db,
            "SELECT verified,email FROM native_auth_accounts WHERE actor_id=?",
            [$bridge->nativeActorId()],
        )->fetch();
        if (!$existing || !$existing["verified"]) {
            $invitation = $auth->createInvitation(
                $access,
                $bridge->nativeActorId(),
                $config["admin_email"],
            );
            $auth->acceptInvitation(
                $invitation["token"],
                $config["admin_password"],
            );
        } elseif (
            $existing["email"] !== strtolower(trim($config["admin_email"]))
        ) {
            throw new \DomainException(
                "Bestehendes Administratorkonto bleibt unverändert.",
            );
        }
        Db::run(
            $db,
            "INSERT IGNORE INTO native_users(id,club_id,name) VALUES(?,?,?)",
            [
                ProductionSite::publicActor($config["club_id"]),
                ManagerBridge::clubKey($config["club_id"]),
                "Öffentliche Website",
            ],
        );
        Db::run(
            $db,
            "INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES(?,'website','read')",
            [ProductionSite::publicActor($config["club_id"])],
        );
        $clubData = \Kulturhus\Verein\SettingsSchema::defaults("club");
        $clubData["name"] = $config["club_name"];
        $clubData["website"] =
            "https://" .
            \Kulturhus\Verein\ProductionRuntime::allowedHosts(
                $config["hosts"],
            )[0];
        if (
            !Db::run(
                $db,
                'SELECT 1 FROM club_settings WHERE club_id=? AND section=\'club\'',
                [$config["club_id"]],
            )->fetchColumn()
        ) {
            (new \Kulturhus\Verein\Settings($db, $actor))->save(
                "club",
                $clubData,
                0,
            );
        }
        Db::run(
            $db,
            "INSERT IGNORE INTO native_club_settings(club_id,version,payload,updated_by,updated_at) VALUES(?,1,?,?,UTC_TIMESTAMP())",
            [
                $bridge->access()->club(),
                json_encode(
                    [
                        "name" => $config["club_name"],
                        "origin" => $clubData["website"],
                        "logo_id" => "",
                        "address" => "",
                    ],
                    JSON_THROW_ON_ERROR,
                ),
                $bridge->nativeActorId(),
            ],
        );
        // New websites start with a modern template; resumed choices are preserved.
        Db::run($db,"INSERT IGNORE INTO khcms_website_design VALUES(?,1,'klar',?,UTC_TIMESTAMP(6))",[$access->club(),$bridge->nativeActorId()]);
        Db::run($db,"INSERT IGNORE INTO khcms_website_design_history SELECT club_id,version,template_key,actor_id,updated_at FROM khcms_website_design WHERE club_id=? AND version=1",[$access->club()]);
        $environment = [
            "VEREIN_MODE" => "production",
            "VEREIN_AUTH" => "native",
            "KH_NATIVE_MANAGER" => "1",
            "KULTURHUS_LOCAL_DEMO" => "0",
            "VEREIN_DATABASE" => $config["dsn"],
            "VEREIN_DATABASE_USER" => $config["username"],
            "VEREIN_DATABASE_PASSWORD" => $config["password"],
            "VEREIN_WEB_ROOT" => $config["webroot"] ?? $target . "/web",
            "VEREIN_ALLOWED_HOSTS" => $config["hosts"],
            "VEREIN_CLUB" => $config["club_id"],
            "VEREIN_PUBLIC_URL" => $clubData["website"],
            "VEREIN_PRIVATE_ROOT" => $target . "/private",
            "KH_NATIVE_MANAGER_SESSION_ROOT" => $target . "/private/sessions",
            "KH_NATIVE_MANAGER_MEDIA_ROOT" => $target . "/private/media",
            "KH_NATIVE_AUTH_KEY_FILE" => $target . "/private/auth.key",
            "KH_NATIVE_PRIVATE_KEY_FILE" => $target . "/private/private.key",
            "VEREIN_MEMBER_BANK_KEY_PATH" =>
                $target . "/private/member-bank-keys/v1.key",
            "VEREIN_MEMBER_BANK_KEY_VERSION" => "1",
            "VEREIN_FINTS_AUTOLOAD" =>
                $target . "/app/banking/vendor/autoload.php",
        ];
        $environment += FinTsSetup::prepare(
            $target,
            $config["club_id"],
            $config["fints"] ?? null,
        );
        $environment += $config["runtime_environment"] ?? [];
        Deployment::write($target, $environment);
        return $modules;
    }
}
