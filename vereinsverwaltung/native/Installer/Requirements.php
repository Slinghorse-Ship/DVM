<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Hosting requirements for the PHP application, independent of optional native binaries. */
final class Requirements
{
    public static function inspect(?string $app = null): array
    {
        $checks = [
            ["name" => "PHP ab 8.3", "available" => PHP_VERSION_ID >= 80300],
        ];
        foreach (
            [
                "pdo_mysql",
                "curl",
                "mbstring",
                "openssl",
                "sodium",
                "gd",
                "intl",
                "dom",
                "xmlreader",
                "xmlwriter",
                "zip",
                "zlib",
                "fileinfo",
            ]
            as $extension
        ) {
            $checks[] = [
                "name" => "PHP-Erweiterung " . $extension,
                "available" => extension_loaded($extension),
            ];
        }
        foreach (
            ["random_bytes", "putenv", "session_start", "flock", "hash_file"]
            as $function
        ) {
            $checks[] = [
                "name" => "PHP-Funktion " . $function,
                "available" => function_exists($function),
            ];
        }
        return [
            "ready" => !in_array(
                false,
                array_column($checks, "available"),
                true,
            ),
            "checks" => $checks,
            "optional" => [
                "photo" => \Kulturhus\Native\Cms\PhotoRuntime::inspect($app),
                "private_processes" => function_exists("proc_open"),
                "imap" => function_exists('imap_open'),
                "scheduled_jobs" =>
                    "PHP-CLI auswählen; konkrete Hosting-Zeitpläne nach der Einrichtung übernehmen und tatsächliche Läufe prüfen",
            ],
            "integrations" => [
                "fints" => $app !== null && FinTsSetup::inspect($app),
                "ocr" => OcrSetup::inspect($app ?? ""),
            ],
        ];
    }

    public static function assertReady(): void
    {
        $result = self::inspect();
        if (!$result["ready"]) {
            throw new \DomainException(
                "Hosting-Voraussetzungen fehlen: " .
                    implode(
                        ", ",
                        array_column(
                            array_filter(
                                $result["checks"],
                                static fn($r) => !$r["available"],
                            ),
                            "name",
                        ),
                    ),
            );
        }
    }
}
