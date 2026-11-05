<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** HTTPS/CSRF/key-gated controller. Saved passwords are encrypted and removed on completion. */
final class WebSetup
{
    public static function serve(string $root): void
    {
        ini_set("display_errors", "0");
        ini_set("zend.exception_ignore_args", "1");
        header("Cache-Control: no-store");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: no-referrer");
        header(
            "Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        );
        if (is_file($root . "/installation.json")) {
            http_response_code(410);
            echo "DVM ist eingerichtet. Der Assistent ist gesperrt. Bitte setup/ entfernen und /login/ öffnen.";
            return;
        }
        if (!in_array($_SERVER["HTTPS"] ?? "", ["on", "1"], true)) {
            http_response_code(403);
            echo "Einrichtung ausschließlich über HTTPS aufrufen.";
            return;
        }
        $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
        if (!in_array($method, ["GET", "POST"], true)) {
            http_response_code(405);
            header("Allow: GET, POST");
            return;
        }
        $host = (string) ($_SERVER["HTTP_HOST"] ?? "");
        if (
            $method === "POST" &&
            isset($_SERVER["HTTP_ORIGIN"]) &&
            $_SERVER["HTTP_ORIGIN"] !== "https://" . $host
        ) {
            http_response_code(403);
            return;
        }
        try {
            $webroot = realpath((string) ($_SERVER["DOCUMENT_ROOT"] ?? ""));
            if (
                !$webroot ||
                dirname($webroot) !== realpath($root) ||
                in_array(basename($webroot), ["app", "private"], true)
            ) {
                throw new \DomainException(
                    "Öffentlicher Domain-Webroot muss neben app/ und private/ liegen; diese bleiben außerhalb.",
                );
            }
            if (
                !is_dir($root . "/private") ||
                is_link($root . "/private") ||
                fileperms($root . "/private") & 0077
            ) {
                throw new \DomainException(
                    "app/ und private/ müssen privat außerhalb des Domain-Webroots liegen.",
                );
            }
            \PackageStore::directory($root . "/private/setup-sessions");
            session_name("DVM_SETUP");
            session_save_path($root . "/private/setup-sessions");
            session_start([
                "use_strict_mode" => 1,
                "use_only_cookies" => 1,
                "cookie_secure" => true,
                "cookie_httponly" => true,
                "cookie_samesite" => "Strict",
                "cookie_path" => "/setup/",
            ]);
            $_SESSION["csrf"] ??= bin2hex(random_bytes(32));
            if ($method === "GET") {
                header("Content-Type: text/html; charset=utf-8");
                echo SetupView::render(
                    $_SESSION["csrf"],
                    Requirements::inspect($root . "/app"),
                );
                return;
            }
            header("Content-Type: application/json; charset=utf-8");
            if (
                !is_string($_POST["csrf"] ?? null) ||
                !hash_equals($_SESSION["csrf"], $_POST["csrf"])
            ) {
                http_response_code(403);
                echo json_encode([
                    "error" => "Formular abgelaufen. Assistent neu öffnen.",
                ]);
                return;
            }
            $permit = SetupPermit::authenticate(
                $root,
                is_string($_POST["token"] ?? null) ? $_POST["token"] : "",
            );
            $old = umask(0077);
            try {
                $lock = fopen($root . "/private/setup.lock", "c+");
            } finally {
                umask($old);
            }
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \DomainException(
                    "Ein Einrichtungsschritt läuft bereits. Bitte erneut versuchen.",
                );
            }
            try {
                $installer = new PreparedInstall($root);
                $action = $_POST["action"] ?? "";
                if ($action === "begin") {
                    if (is_file($root . "/private/setup-state")) {
                        throw new \DomainException(
                            "Begonnene Einrichtung mit dem gleichen Schlüssel wiederaufnehmen.",
                        );
                    }
                    $state = [
                        "phase" => "verify",
                        "cursor" => 0,
                        "package" => $installer->proofHash(),
                        "config" => $installer->validate(
                            $_POST,
                            $host,
                            $webroot,
                        ),
                    ];
                } elseif (
                    in_array($action, ["step", "resume", "addons"], true)
                ) {
                    $state = self::readState($root);
                    if ($state["config"]["hosts"] !== $host) {
                        throw new \DomainException(
                            "Die Einrichtung gehört zu einer anderen Domain.",
                        );
                    }
                    if ($action === "step") {
                        $state = $installer->step($state, $permit);
                    }
                    if ($action === "addons") {
                        if ($state["phase"] !== "integrations") {
                            throw new \DomainException(
                                "OCR-Auswahl kann nur vor der Datenbankeinrichtung im Zusatzfunktionsschritt geändert werden.",
                            );
                        }
                        $state["config"]["ocr_enabled"] =
                            ($_POST["ocr_enable"] ?? "") === "1";
                    }
                } else {
                    throw new \DomainException(
                        "Einrichtungsschritt nicht verfügbar.",
                    );
                }
                if ($state["phase"] === "installed") {
                    foreach (
                        ["setup-state", "setup-token", "setup-state.key"]
                        as $file
                    ) {
                        if (is_file($root . "/private/" . $file)) {
                            unlink($root . "/private/" . $file);
                        }
                    }
                    echo json_encode(
                        [
                            "phase" => "installed",
                            "percent" => 100,
                            "message" =>
                                "DVM ist eingerichtet. Der Assistent ist gesperrt.",
                            "login" => "/login/",
                            "scheduler" => ScheduledJobs::plan($root, $state["config"]["scheduler_php"] ?? "php"),
                            "scheduler_text" => ScheduledJobs::text(ScheduledJobs::plan($root, $state["config"]["scheduler_php"] ?? "php")),
                        ],
                        JSON_THROW_ON_ERROR,
                    );
                    return;
                }
                self::writeState($root, $state);
                $total = count(
                    \PackageStore::json($root . "/private/app-files.json")[
                        "files"
                    ],
                );
                echo json_encode(
                    [
                        "phase" => $state["phase"],
                        "percent" =>
                            $state["phase"] === "verify"
                                ? (int) floor(
                                    (80 * $state["cursor"]) / max(1, $total),
                                )
                                : ($state["phase"] === "database"
                                    ? 85
                                    : 90),
                        "message" => match ($state["phase"]) {
                            "verify" => "Paketdateien prüfen …",
                            "database" => "Leere Datenbank prüfen …",
                            "integrations"
                                => "Gewählte Zusatzfunktionen und OCR prüfen …",
                            "initialize"
                                => "Vereinsdaten, Module und Konto einrichten …",
                            default => "Einrichtung fortsetzen …",
                        },
                    ],
                    JSON_THROW_ON_ERROR,
                );
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\DomainException $error) {
            http_response_code(422);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(
                ["error" => $error->getMessage()],
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $error) {
            error_log(
                "DVM setup " .
                    $error::class .
                    " at " .
                    basename($error->getFile()) .
                    ":" .
                    $error->getLine(),
            );
            http_response_code(422);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode([
                "error" =>
                    "Einrichtung unterbrochen. Hosting-Dateizugriff und Datenbankrechte prüfen; anschließend wiederaufnehmen.",
            ]);
        } finally {
            unset(
                $_POST["db_password"],
                $_POST["admin_password"],
                $_POST["token"],
            );
        }
    }

    private static function readState(string $root): array
    {
        $file = $root . "/private/setup-state";
        $key = $root . "/private/setup-state.key";
        if (
            is_link($file) ||
            is_link($key) ||
            !is_file($file) ||
            !is_file($key) ||
            fileperms($file) & 0077 ||
            strlen((string) file_get_contents($key)) !== 32
        ) {
            throw new \DomainException("Private Einrichtung nicht verfügbar.");
        }
        $bytes = (string) file_get_contents($file);
        if (strlen($bytes) > 32768) {
            throw new \DomainException("Einrichtungszustand beschädigt.");
        }
        $plain = sodium_crypto_secretbox_open(
            substr($bytes, 24),
            substr($bytes, 0, 24),
            (string) file_get_contents($key),
        );
        if (!is_string($plain)) {
            throw new \DomainException("Einrichtungszustand beschädigt.");
        }
        return json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
    }

    private static function writeState(
        string $root,
        #[\SensitiveParameter] array $state,
    ): void {
        $key = (string) file_get_contents($root . "/private/setup-state.key");
        $nonce = random_bytes(24);
        $bytes =
            $nonce .
            sodium_crypto_secretbox(
                json_encode($state, JSON_THROW_ON_ERROR),
                $nonce,
                $key,
            );
        $file = $root . "/private/setup-state";
        if (is_link($file)) {
            throw new \DomainException("Privater Einrichtungszustand prüfen.");
        }
        $temporary = $file . "." . bin2hex(random_bytes(12));
        Deployment::file($temporary, $bytes);
        if (!rename($temporary, $file)) {
            unlink($temporary);
            throw new \DomainException(
                "Einrichtungsfortschritt konnte nicht gespeichert werden.",
            );
        }
    }
}
