<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;

/** Bounded browser installation of an already unpacked, verified upload bundle. */
final class PreparedInstall
{
    public function __construct(private string $root) {}

    public function validate(array $input, string $host, string $webroot): array
    {
        foreach (
            [
                "club_id",
                "club_name",
                "db_host",
                "db_name",
                "db_user",
                "db_password",
                "admin_email",
                "admin_password",
            ]
            as $key
        ) {
            if (
                !is_string($input[$key] ?? null) ||
                $input[$key] === "" ||
                strlen($input[$key]) > 1024 ||
                preg_match('/[\x00-\x1f\x7f]/', $input[$key])
            ) {
                throw new \DomainException(
                    "Einrichtungsangabe prüfen: " . $key,
                );
            }
        }
        if (
            !preg_match("/\A[a-z][a-z0-9-]{0,62}\z/D", $input["club_id"]) ||
            mb_strlen($input["club_name"]) > 160 ||
            !filter_var($input["admin_email"], FILTER_VALIDATE_EMAIL) ||
            strlen($input["admin_password"]) < 16
        ) {
            throw new \DomainException(
                "Vereinsangaben, E-Mail und Passwort mit mindestens 16 Zeichen prüfen.",
            );
        }
        require_once $this->root .
            "/app/vereinsverwaltung/src/Infrastructure/Runtime/ProductionRuntime.php";
        if (
            !\Kulturhus\Verein\ProductionRuntime::allowedHosts($host) ||
            str_contains($host, ",")
        ) {
            throw new \DomainException(
                "Einrichtung auf der eigenen HTTPS-Domain aufrufen.",
            );
        }
        if (
            !preg_match("/\A[a-zA-Z0-9_.:-]+\z/D", $input["db_host"]) ||
            !preg_match("/\A[a-zA-Z0-9_]+\z/D", $input["db_name"])
        ) {
            throw new \DomainException(
                "Datenbankserver und Datenbankname prüfen.",
            );
        }
        $port = $input["db_port"] ?? "3306";
        if (
            !is_string($port) ||
            !ctype_digit($port) ||
            (int) $port < 1 ||
            (int) $port > 65535
        ) {
            throw new \DomainException("Datenbankport prüfen.");
        }
        $schedulerPhp = ScheduledJobs::executable($input["scheduler_php"] ?? null);
        ScheduledJobs::plan($this->root, $schedulerPhp);
        return [
            "target" => $this->root,
            "webroot" => $webroot,
            "dsn" =>
                "mysql:host=" .
                $input["db_host"] .
                ";port=" .
                (int) $port .
                ";dbname=" .
                $input["db_name"] .
                ";charset=utf8mb4",
            "username" => $input["db_user"],
            "password" => $input["db_password"],
            "club_id" => $input["club_id"],
            "club_name" => $input["club_name"],
            "hosts" => $host,
            "admin_email" => $input["admin_email"],
            "admin_password" => $input["admin_password"],
            "scheduler_php" => $schedulerPhp,
            "fints" => FinTsSetup::validate($input, $input["club_id"]),
            "ocr_enabled" => ($input["ocr_enable"] ?? "") === "1",
        ];
    }

    public function step(
        #[\SensitiveParameter] array $state,
        SetupPermit $permit,
    ): array {
        $permit->assertActive($this->root);
        Requirements::assertReady();
        $proof = \PackageStore::json($this->root . "/private/app-files.json");
        if (!hash_equals($state["package"], $this->proofHash())) {
            throw new \DomainException(
                "Paketfassung während der Einrichtung geändert.",
            );
        }
        if ($state["phase"] === "verify") {
            $end = min(count($proof["files"]), $state["cursor"] + 200);
            for ($i = $state["cursor"]; $i < $end; ++$i) {
                \PackageStore::check(
                    $this->root .
                        "/app/" .
                        \PackageStore::relative($proof["files"][$i]["path"]),
                    $proof["files"][$i],
                );
            }
            $state["cursor"] = $end;
            if ($end === count($proof["files"])) {
                $state["phase"] = "integrations";
            }
            return $state;
        }
        if ($state["phase"] === "integrations") {
            $state["config"]["runtime_environment"] = OcrSetup::prepare(
                $this->root,
                $state["config"]["ocr_enabled"] ?? false,
            );
            $state["phase"] = "database";
            return $state;
        }
        require_once $this->root .
            "/app/vereinsverwaltung/src/Infrastructure/Database/Database.php";
        try {
            $db = \Kulturhus\Verein\Database::connect(
                $state["config"]["dsn"],
                $state["config"]["username"],
                $state["config"]["password"],
            );
        } catch (\Throwable) {
            throw new \DomainException(
                "Datenbankverbindung fehlgeschlagen. Server, Port, Datenbank und Zugang im Hosting prüfen.",
            );
        }
        $server = (string) $db->query("SELECT VERSION()")->fetchColumn();
        if (
            stripos($server, "MariaDB") !== false ||
            !preg_match("/\A([0-9]+)\./", $server, $version) ||
            version_compare(
                preg_replace('/[^0-9.].*$/', "", $server),
                "8.0.16",
                "<",
            )
        ) {
            throw new \DomainException(
                "DVM benötigt MySQL ab Version 8.0.16. Bitte einen passenden MySQL-Server im Hosting auswählen.",
            );
        }
        if ($state["phase"] === "database") {
            if (
                (int) $db
                    ->query(
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()",
                    )
                    ->fetchColumn() !== 0
            ) {
                throw new \DomainException(
                    "Eine leere eigene Datenbank ist erforderlich. Bestehende Tabellen bleiben unverändert.",
                );
            }
            $state["phase"] = "initialize";
            return $state;
        }
        if ($state["phase"] !== "initialize") {
            throw new \DomainException("Unbekannter Einrichtungsschritt.");
        }
        $modules = Initialize::run(
            $this->root . "/app",
            $this->root,
            $state["config"],
            $db,
            $permit,
        );
        Deployment::file(
            $this->root . "/installation.json",
            json_encode(
                [
                    "format" => 1,
                    "provider" => "native",
                    "package_mode" => "webspace",
                    "target" => $this->root,
                    "source" => $proof["source"],
                    "app_files_sha256" => $state["package"],
                    "package_generation" => $proof["generation"]??0,
                    "scheduler_php" => $state["config"]["scheduler_php"] ?? "php",
                    "club_id" => $state["config"]["club_id"],
                    "modules" => $modules,
                    "optional_integrations" =>
                        "disabled-until-configured-and-validated",
                    "integration_setup" => [
                        "fints" => empty($state["config"]["fints"])
                            ? "unconfigured"
                            : "prepared-without-bank-dialogue",
                        "ocr" => empty($state["config"]["runtime_environment"])
                            ? "inactive"
                            : "verified-synthetic-raster",
                    ],
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ) . "\n",
        );
        $state["phase"] = "installed";
        return $state;
    }

    public function proofHash(): string
    {
        return hash_file("sha256", $this->root . "/private/app-files.json");
    }

    public static function check(string $root, PDO $db): void
    {
        $receipt = \PackageStore::json($root . "/installation.json");
        if (
            ($receipt["package_mode"] ?? "") !== "webspace" ||
            !hash_equals(
                $receipt["app_files_sha256"] ?? "",
                hash_file("sha256", $root . "/private/app-files.json"),
            )
        ) {
            throw new \DomainException("Webspace-Paketnachweis passt nicht.");
        }
        foreach (
            \PackageStore::json($root . "/private/app-files.json")["files"]
            as $entry
        ) {
            \PackageStore::check(
                $root . "/app/" . \PackageStore::relative($entry["path"]),
                $entry,
            );
        }
        SchemaRegistry::assertCurrent($db);
    }
}
