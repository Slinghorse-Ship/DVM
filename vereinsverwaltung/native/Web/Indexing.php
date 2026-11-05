<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

/** Search-engine choices apply only to the configured native production website. */
final class Indexing
{
    public static function enabled(array $identity, string $origin): bool
    {
        if (
            ($identity["website_indexing"] ?? false) !== true ||
            getenv("VEREIN_MODE") !== "production" ||
            getenv("VEREIN_AUTH") !== "native" ||
            getenv("KH_NATIVE_MANAGER") !== "1" ||
            !in_array(getenv("KULTURHUS_LOCAL_DEMO"), [false, "", "0"], true) ||
            !in_array(getenv("KH_NATIVE_LOCAL_DEMO"), [false, "", "0"], true)
        ) {
            return false;
        }
        $origin = rtrim($origin, "/");
        $configured = $identity["origin"] ?? "";
        $parts = parse_url($origin);
        return is_string($configured) &&
            $configured !== "" &&
            hash_equals(rtrim($configured, "/"), $origin) &&
            is_array($parts) &&
            ($parts["scheme"] ?? "") === "https" &&
            isset($parts["host"]) &&
            $parts["host"] !== "" &&
            count($parts) === 2;
    }

    public static function directive(
        array $identity,
        string $origin,
        bool $pageNoindex = false,
        string $current = "",
    ): string {
        return !$pageNoindex &&
            !in_array(
                $current,
                ["search", "suche", "mitglied-werden", "newsletter"],
                true,
            ) &&
            self::enabled($identity, $origin)
            ? "index,follow"
            : "noindex,nofollow";
    }

    public static function robots(array $identity, string $origin): string
    {
        if (!self::enabled($identity, $origin)) {
            return "User-agent: facebookexternalhit\nAllow: /publication-video/\nAllow: /publication-image/\nDisallow: /\n\nUser-agent: *\nDisallow: /\n";
        }
        $text = "User-agent: *\nAllow: /\n";
        foreach (
            [
                "vereinsverwaltung",
                "system",
                "processwire",
                "login",
                "logout",
                "account",
                "accounts",
                "invite",
                "reset",
                "forgot",
                "preview",
                "sections-preview",
                "media-preview",
                "chat-file",
                "chat-image",
                "chat-status",
                "model-fields",
                "reference-options",
                "page-photo-status",
                "album-photo-status",
                "search",
                "suche",
                "newsletter/bestaetigen",
                "newsletter/abmelden",
                "newsletter-confirm",
                "newsletter-unsubscribe",
            ]
            as $path
        ) {
            $text .= "Disallow: /" . $path . "\n";
        }
        return $text . "Sitemap: " . rtrim($origin, "/") . "/sitemap/\n";
    }
}
