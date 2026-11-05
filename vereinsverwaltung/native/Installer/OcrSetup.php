<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Activate only after the actual package, interpreter, sandbox and raster proof pass. */
final class OcrSetup
{
    public static function inspect(string $app): array
    {
        $packaged =
            $app !== "" &&
            is_file($app . "/runtime/distribution/manifest.json");
        $host =
            PHP_OS_FAMILY === "Linux" &&
            in_array(php_uname("m"), ["x86_64", "amd64"], true);
        $processes = !in_array(
            false,
            array_map("function_exists", [
                "proc_open",
                "proc_get_status",
                "proc_close",
                "proc_terminate",
            ]),
            true,
        );
        return [
            "packaged" => $packaged,
            "host" => $host,
            "processes" => $processes,
            "can_check" => $packaged && $host && $processes,
        ];
    }

    public static function prepare(string $target, bool $enabled): array
    {
        if (!$enabled) {
            return [];
        }
        $available = self::inspect($target . "/app");
        if (!$available["packaged"]) {
            throw new \DomainException(
                "OCR-Paket fehlt. Upload-Paket mit --with-ocr erstellen oder OCR vorerst abwählen.",
            );
        }
        if (!$available["host"]) {
            throw new \DomainException(
                "Dieses OCR-Paket benötigt Linux x86_64. OCR vorerst abwählen oder passendes Hosting verwenden.",
            );
        }
        if (!$available["processes"]) {
            throw new \DomainException(
                "OCR benötigt freigegebene PHP-Prozessfunktionen einschließlich proc_open. OCR vorerst abwählen oder Hosting-Freigabe einrichten.",
            );
        }
        require_once $target . "/app/distribution/src/RuntimeInstaller.php";
        try {
            $environment = \RuntimeInstaller::configureOcr(
                $target . "/app/runtime",
                $target . "/app",
                $target . "/private",
            );
        } catch (\RuntimeException $error) {
            throw new \DomainException($error->getMessage());
        }
        self::prove($target . "/app", $environment);
        return $environment;
    }

    /** Also reusable by the existing CLI/runtime installer; never touches manager originals. */
    public static function prove(string $app, array $environment): void
    {
        require_once $app . "/vereinsverwaltung/src/autoload.php";
        $before = getenv("VEREIN_OCR_RUNTIME");
        putenv("VEREIN_OCR_RUNTIME=" . $environment["VEREIN_OCR_RUNTIME"]);
        try {
            $image = imagecreatetruecolor(1600, 800);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            $black = imagecolorallocate($image, 0, 0, 0);
            $font =
                $app .
                "/vereinsverwaltung/resources/fonts/liberation-serif/LiberationSerif-Regular.ttf";
            foreach (
                [
                    "DVM synthetische Einrichtungsrechnung",
                    "Rechnungsnummer: DVM-OCR-001",
                    "Rechnungsdatum: 10.10.2026",
                    "Gesamtbetrag brutto: 123,45 EUR",
                ]
                as $i => $line
            ) {
                imagettftext(
                    $image,
                    32,
                    0,
                    60,
                    140 + $i * 110,
                    $black,
                    $font,
                    $line,
                );
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $result = \Kulturhus\Verein\InvoiceRecognition::extract(
                $bytes,
                "image/png",
            );
            if (
                ($result["state"] ?? "") !== "ready" ||
                ($result["method"] ?? "") !== "ocr" ||
                ($result["fields"]["Rechnungsnummer"] ?? "") !==
                    "DVM-OCR-001" ||
                ($result["fields"]["Rechnungsdatum"] ?? "") !== "2026-10-10" ||
                ($result["fields"]["Rechnungsbetrag brutto"] ?? "") !== "123.45"
            ) {
                throw new \DomainException(
                    "OCR-Funktionsprüfung fehlgeschlagen. Ausführungsrechte, private Pfade, Landlock ABI 6 und seccomp im Hosting prüfen. Der Schutz wird nicht abgeschaltet.",
                );
            }
        } finally {
            $before === false
                ? putenv("VEREIN_OCR_RUNTIME")
                : putenv("VEREIN_OCR_RUNTIME=" . $before);
        }
    }
}
