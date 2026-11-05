<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Build once on a development machine; the uploaded application needs no Composer or shell. */
final class WebBundle
{
    public static function build(
        string $source,
        string $target,
        bool $withOcr = false,
    ): array {
        require_once $source . "/distribution/src/PackageStore.php";
        if (
            PHP_SAPI !== "cli" ||
            !str_starts_with($target, "/") ||
            is_link($target)
        ) {
            throw new \DomainException(
                "Neues absolutes Paketverzeichnis erforderlich.",
            );
        }
        $parent = realpath(dirname($target));
        $source = realpath($source);
        if (!$parent || !$source) {
            throw new \DomainException(
                "Paket-Elternverzeichnis muss bestehen.",
            );
        }
        $target = $parent . "/" . basename($target);
        if (
            $target === $source ||
            str_starts_with($target, $source . "/") ||
            (file_exists($target) &&
                array_diff(scandir($target) ?: [], [".", ".."]))
        ) {
            throw new \DomainException(
                "Neues leeres Paketverzeichnis außerhalb des Checkouts wählen.",
            );
        }
        $verified = (new \PackageStore($source . "/distribution"))->verify();
        foreach (["app", "private", "web", "web/setup"] as $dir) {
            \PackageStore::directory(
                $target . "/" . $dir,
                in_array($dir, ["web", "web/setup"], true) ? 0755 : 0700,
            );
        }
        chmod($target, 0755);
        Deployment::file($target."/web/.user.ini",Deployment::UPLOAD_INI,0644);
        $files = [];
        foreach (
            array_merge(
                $verified["source"]["files"],
                $verified["source"]["installer_files"],
            )
            as $entry
        ) {
            $name = $entry["path"];
            $included =
                str_starts_with($name, "vereinsverwaltung/") ||
                str_starts_with($name, "banking/") ||
                in_array(
                    $name,
                    [
                        "composer.json",
                        "composer.lock",
                        "LICENSE",
                        "distribution/src/PackageStore.php",
                        "distribution/src/RuntimeInstaller.php",
                        "distribution/upgrade-native.php",
                    ],
                    true,
                ) ||
                str_starts_with($name, "distribution/web/");
            if (
                !$included ||
                str_starts_with($name, "vereinsverwaltung/tests/") ||
                $name === "vereinsverwaltung/native/TestSupport.php" ||
                (str_ends_with($name, ".md") &&
                    !in_array($name, [
                        "vereinsverwaltung/resources/raster-photo/README.md",
                        "vereinsverwaltung/resources/raster-photo/REBUILD.md",
                        "vereinsverwaltung/resources/reel-server/README.md",
                    ], true) &&
                    !str_contains($name, "/vendor/") &&
                    !preg_match(
                        "/(?:LICENSE|NOTICE|COPYING)/i",
                        basename($name),
                    )) ||
                (str_starts_with($name, "vereinsverwaltung/tools/") &&
                    !in_array($name, [
                        "vereinsverwaltung/tools/media/camera-photo.mjs",
                        "vereinsverwaltung/tools/media/raster-photo.mjs",
                    ], true) &&
                    !(str_starts_with(basename($name),'ebilanz_')&&str_ends_with($name,'.py')) &&
                    !in_array(
                        basename($name),
                        [
                            // Runtime renderers and their local import/fetch helpers.
                            "ehrenamt-wesermarsch.pdf",
                            "build-hybrid-invoice.py",
                            "render-activity-report.py",
                            "render-balance-report.py",
                            "render-document-workflow.py",
                            "render-document.py",
                            "render-donation-collection.py",
                            "render-donation-form.py",
                            "render-event-vouchers.py",
                            "render-invitations.py",
                            "render-record.py",
                            "render-volunteer-form.py",
                            "render-volunteer-general.py",
                            "datev_bank_corrections.py",
                            "datev_comparison.py",
                            "datev_issuer_corrections.py",
                            "datev_loan_corrections.py",
                            "datev_statements.py",
                            "datev_web.py",
                            "datev_years.py",
                            "taxonomy_purposes.py",
                            "taxonomy_sources.py",
                            "datev_table.py",
                            "import-datev-catalog.py",
                            "check-datev-skr42.py",
                            "maintain-datev-skr42.py",
                            "eric-bridge.py",
                            "eric_feedback.py",
                            "eric-process.py",
                            "compile-eric-forms.py",
                            "recognize-invoice.py",
                            "invoice_ocr_sandbox.py",
                            "invoice_embedded_xml.py",
                            "heic_invoice_image.py",
                            "ocr-container-launcher.py",
                        ],
                        true,
                    )) ||
                (str_starts_with($name, "vereinsverwaltung/bin/") &&
                    !in_array($name, [
                        "vereinsverwaltung/bin/native-delivery-worker.php",
                        "vereinsverwaltung/bin/service-status-worker.php",
                        "vereinsverwaltung/bin/native-system-worker.php",
                    ], true))
            ) {
                continue;
            }
            $bytes = (string) file_get_contents($source . "/" . $name);
            if ($entry["normalized_lf"]) {
                $bytes = str_replace("\r\n", "\n", $bytes);
            }
            Deployment::file($target . "/app/" . $name, $bytes, 0644);
            $files[] = $entry;
        }
        foreach ($verified["php"]["components"] as $component) {
            $prefix = match ($component["id"]) {
                "root-vendor" => "",
                "banking-vendor" => "banking/",
                "push-vendor" => "vereinsverwaltung/push-runtime/",
                default => null,
            };
            if ($prefix === null) {
                continue;
            }
            $stage = $target . "/private/stage-" . bin2hex(random_bytes(12));
            \PackageStore::extract(
                $source .
                    "/distribution/packages/php-runtime/" .
                    $component["archive"],
                $stage,
            );
            foreach (
                \PackageStore::json(
                    $source .
                        "/distribution/packages/php-runtime/" .
                        $component["file_manifest"],
                )
                as $entry
            ) {
                // Package manifests are lists of files, not trusted archive paths.
                \PackageStore::check(
                    $stage . "/" . \PackageStore::relative($entry["path"]),
                    $entry,
                );
                $entry["path"] = $prefix . $entry["path"];
                $entry["normalized_lf"] = false;
                $files[] = $entry;
            }
            \PackageStore::directory($target . "/app/" . rtrim($prefix, "/"));
            if (
                !rename(
                    $stage . "/vendor",
                    $target . "/app/" . $prefix . "vendor",
                )
            ) {
                throw new \DomainException(
                    "Paketabhängigkeit konnte nicht übernommen werden.",
                );
            }
            rmdir($stage);
        }
        if ($withOcr) {
            $files = array_merge(
                $files,
                RuntimeBundle::build($source, $target, $verified),
            );
        }
        $unique = [];
        foreach ($files as $entry) {
            $unique[$entry["path"]] = $entry;
        }
        ksort($unique);
        $files = array_values($unique);
        $proof =
            json_encode(
                [
                    "format" => 1,
                    "generation" => PackageVersion::GENERATION,
                    "modules" => SchemaRegistry::VERSIONS,
                    "source" => hash_file(
                        "sha256",
                        $source . "/distribution/source-files.json",
                    ),
                    "files" => $files,
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ) . "\n";
        Deployment::file($target . "/private/app-files.json", $proof);
        $token = bin2hex(random_bytes(32));
        Deployment::file(
            $target . "/private/setup-token",
            hash("sha256", $token) . "\n",
        );
        Deployment::file(
            $target . "/private/setup-state.key",
            random_bytes(32),
        );
        Deployment::file($target . "/SETUP-KEY.txt", $token . "\n");
        Deployment::file(
            $target . "/web/setup/index.php",
            "<?php declare(strict_types=1);\n\$dvmSetupRoot=dirname(__DIR__,2);\nrequire \$dvmSetupRoot.'/app/distribution/web/index.php';\n",
            0644,
        );
        foreach (["setup.css", "setup.js"] as $asset) {
            Deployment::file(
                $target . "/web/setup/" . $asset,
                (string) file_get_contents(
                    $source . "/distribution/web/" . $asset,
                ),
                0644,
            );
        }
        foreach (
            \Kulturhus\Native\Web\BootstrapAssets::sources()
            as $name => $path
        ) {
            Deployment::file(
                $target . "/web/setup/" . $name,
                (string) file_get_contents($path),
                0644,
            );
        }
        Deployment::file(
            $target . "/README.txt",
            "DVM – DeinVereinsManager\n\napp/ und private/ liegen außerhalb des öffentlich erreichbaren Verzeichnisses.\nDen Inhalt von web/ als Domain-Webroot verwenden. PHP ab 8.3 und eine leere MySQL-Datenbank erforderlich.\nhttps://DEINE-DOMAIN/setup/ öffnen; Einrichtungsschlüssel aus SETUP-KEY.txt eingeben.\nSETUP-KEY.txt nicht öffentlich hochladen. Nach Einrichtung setup/ entfernen.\nFinTS-Bibliothek enthalten; eigene Registrierung und bestätigten Bankendpunkt optional im Assistenten eintragen. Keine PIN/TAN im Installer.\n" .
                ($withOcr
                    ? "OCR/PDF-Laufzeiten und Modelle enthalten. Private Ausführungsrechte erhalten. Linux x86_64, proc_open, Landlock ABI 6 und seccomp erforderlich; Assistent prüft echte synthetische Bildrechnung vor Aktivierung.\n"
                    : "OCR-Skripte enthalten; Binärlaufzeiten über denselben Builder mit --with-ocr ergänzen.\n") .
                "Serverfotos: Beide lokalen Fotoprogramme und Codecs sind enthalten. Für die optionale Servervorbereitung Node.js ab22 im Hosting-PATH oder über den privaten Wert VEREIN_PHOTO_NODE und PHP-Prozessfunktionen bereitstellen. Kleine Fotos und Browservorbereitung bleiben unabhängig davon nutzbar. Voraussetzungen im privaten Systemstatus prüfen.\nAutomatische Entwurfsvideos: lokaler Linux-x86-64-Encoder samt Quellen enthalten; PHP-Prozessfunktionen erforderlich. Steuerlaufzeiten gesondert einrichten.\n",
            0644,
        );
        return [
            "status" => "prepared",
            "product" => "DVM – DeinVereinsManager",
            "files" => count($files),
            "webroot" => $target . "/web",
            "setup_key_file" => $target . "/SETUP-KEY.txt",
            "external_actions" => 0,
            "ocr_runtime_packaged" => $withOcr,
        ];
    }
}
