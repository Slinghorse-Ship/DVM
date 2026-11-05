<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Optional bank metadata, never bank credentials or a network connection. */
final class FinTsSetup
{
    public static function validate(array $input, string $club): ?array
    {
        if (($input["fints_enable"] ?? "") !== "1") {
            return null;
        }
        if (($input["fints_confirm"] ?? "") !== "1") {
            throw new \DomainException(
                "Bestätigten Bankendpunkt und eigene FinTS-Registrierung bestätigen.",
            );
        }
        $data = ["club_id" => $club];
        foreach (
            ["url", "bank_code", "product_id", "product_version"]
            as $key
        ) {
            $value = $input["fints_" . $key] ?? null;
            if (
                !is_string($value) ||
                trim($value) === "" ||
                strlen($value) > 300 ||
                preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new \DomainException(
                    "FinTS-Bankendpunkt, BLZ, Registrierungs-ID und Version vollständig angeben.",
                );
            }
            $data[$key] = trim($value);
        }
        $url = parse_url($data["url"]);
        if (
            ($url["scheme"] ?? "") !== "https" ||
            empty($url["host"]) ||
            filter_var($url["host"], FILTER_VALIDATE_IP) ||
            isset($url["user"]) ||
            isset($url["pass"]) ||
            isset($url["query"]) ||
            isset($url["fragment"]) ||
            !preg_match("/\A[0-9]{8}\z/D", $data["bank_code"])
        ) {
            throw new \DomainException(
                "Bestätigten HTTPS-Bankendpunkt und achtstellige BLZ verwenden.",
            );
        }
        if (
            !preg_match("/\A[A-Za-z0-9]{25}\z/D", $data["product_id"]) ||
            strlen($data["product_version"]) > 5
        ) {
            throw new \DomainException(
                "FinTS-Registrierungs-ID mit genau 25 Zeichen und Produktversion mit höchstens fünf Zeichen angeben.",
            );
        }
        return $data;
    }

    public static function inspect(string $app): bool
    {
        $file = $app . "/banking/vendor/composer/installed.json";
        if (
            !is_file($app . "/banking/vendor/autoload.php") ||
            !is_file($file)
        ) {
            return false;
        }
        $data = json_decode((string) file_get_contents($file), true);
        foreach ($data["packages"] ?? [] as $package) {
            if (($package["name"] ?? "") === "nemiah/php-fints") {
                return ($package["version"] ?? "") === "4.1.0" &&
                    ($package["source"]["reference"] ?? "") ===
                        "845c8cc973310c11ae2441e88e118c505d1b59f1";
            }
        }
        return false;
    }

    public static function prepare(
        string $target,
        string $club,
        ?array $config,
    ): array {
        $root = $target . "/private/banking";
        \PackageStore::directory($root);
        if (fileperms($root) & 0077) {
            throw new \DomainException(
                "Banking-Verzeichnis muss privat bleiben (0700).",
            );
        }
        if ($config !== null) {
            if (
                ($config["club_id"] ?? "") !== $club ||
                !self::inspect($target . "/app")
            ) {
                throw new \DomainException(
                    "Geprüfte FinTS-Bibliothek und aktuelle Vereinsbindung erforderlich.",
                );
            }
            $config = self::validate(
                [
                    "fints_enable" => "1",
                    "fints_confirm" => "1",
                    "fints_url" => $config["url"] ?? "",
                    "fints_bank_code" => $config["bank_code"] ?? "",
                    "fints_product_id" => $config["product_id"] ?? "",
                    "fints_product_version" => $config["product_version"] ?? "",
                ],
                $club,
            );
            if (
                !class_exists(\Kulturhus\Banking\FinTsReadEngine::class, false)
            ) {
                require_once $target . "/app/banking/FinTsReadEngine.php";
            }
            $before = getenv("VEREIN_FINTS_AUTOLOAD");
            try {
                putenv(
                    "VEREIN_FINTS_AUTOLOAD=" .
                        $target .
                        "/app/banking/vendor/autoload.php",
                );
                // Construction verifies the actual locked SDK. No dialogue or bank request is opened.
                new \Kulturhus\Banking\FinTsReadEngine($config);
            } finally {
                $before === false
                    ? putenv("VEREIN_FINTS_AUTOLOAD")
                    : putenv("VEREIN_FINTS_AUTOLOAD=" . $before);
            }
            $key = $root . "/fints-state.key";
            if (!file_exists($key)) {
                Deployment::file($key, random_bytes(32));
            }
            if (
                is_link($key) ||
                !is_file($key) ||
                strlen((string) file_get_contents($key)) !== 32 ||
                fileperms($key) & 0077
            ) {
                throw new \DomainException(
                    "Privater FinTS-Sitzungsschlüssel ist ungültig.",
                );
            }
            Deployment::file(
                $root . "/fints.json",
                json_encode(
                    $config,
                    JSON_THROW_ON_ERROR |
                        JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_SLASHES,
                ) . "\n",
            );
        }
        return ["VEREIN_BANKING_ROOT" => $root, "VEREIN_FINTS_ROOT" => $root];
    }
}
