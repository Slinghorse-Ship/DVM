<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** Fixed HTTPS origins, ephemeral in-memory cookies and bounded responses. */
final class Http
{
    public const ISSUER = "https://login.digitale-doerfer.de/realms/DD";
    public const API = "https://prod.digitale-doerfer.de";
    private ?\CurlHandle $handle = null;

    public static function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (
            !$p ||
            ($p["scheme"] ?? "") !== "https" ||
            isset($p["user"]) ||
            isset($p["pass"]) ||
            isset($p["port"]) ||
            isset($p["fragment"]) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        ) {
            return false;
        }
        return (($p["host"] ?? "") === "prod.digitale-doerfer.de" &&
            str_starts_with($p["path"] ?? "", "/")) ||
            (($p["host"] ?? "") === "login.digitale-doerfer.de" &&
                str_starts_with($p["path"] ?? "", "/realms/DD/"));
    }

    public function request(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null,
    ): array {
        \Kulturhus\Native\Demo\Network::assertAllowed();
        if (!self::allowed($url) || !in_array($method, ["GET", "POST"], true)) {
            throw new \DomainException("Ungültiger StadtLand.Funk-Aufruf.");
        }
        if (
            getenv("KULTURHUS_DISABLE_MAIL") === "1" ||
            !in_array(getenv("KULTURHUS_LOCAL_DEMO"), [false, "", "0"], true)
        ) {
            throw new \DomainException(
                "Externe Verbindungen in der Testumgebung gesperrt.",
            );
        }
        if (!function_exists("curl_init")) {
            throw new \DomainException(
                "Für die Verbindung benötigt der Webspace PHP-cURL.",
            );
        }
        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/[\r\n\x00]/', $header)) {
                throw new \DomainException("Ungültiger HTTP-Header.");
            }
        }
        $this->handle ??= curl_init();
        $ch = $this->handle;
        curl_reset($ch);
        $buffer = "";
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEFILE => "",
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (
                &$buffer,
            ) {
                if (strlen($buffer) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (
                &$responseHeaders,
            ) {
                if (strlen($line) > 8192) {
                    return 0;
                }
                $p = explode(":", $line, 2);
                if (count($p) === 2) {
                    $responseHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (curl_exec($ch) === false) {
            throw new \DomainException(
                "StadtLand.Funk konnte nicht erreicht werden. Verbindung später erneut prüfen.",
            );
        }
        return [
            "status" => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            "headers" => $responseHeaders,
            "body" => $buffer,
        ];
    }
}
