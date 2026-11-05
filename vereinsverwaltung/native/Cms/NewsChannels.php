<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;

/** One channel rule for original public revisions, lists, links and RSS. */
final class NewsChannels
{
    public const ALL = ["website", "rss"];

    public static function normalize(mixed $channels): array
    {
        if (
            !is_array($channels) ||
            !array_is_list($channels) ||
            count($channels) > 2
        ) {
            throw new InvalidArgumentException(
                "Beitragskanäle als Liste mit Website und/oder RSS angeben.",
            );
        }
        foreach ($channels as $channel) {
            if (!is_string($channel) || !in_array($channel, self::ALL, true)) {
                throw new InvalidArgumentException(
                    "Unbekannter Beitragskanal.",
                );
            }
        }
        return array_values(array_intersect(self::ALL, $channels));
    }

    public static function isNews(array $page): bool
    {
        return ($page["template_definition"]["renderer"] ??
            ($page["template"] ?? "")) ===
            "news";
    }

    public static function visible(
        array $page,
        string $channel = "website",
    ): bool {
        self::channel($channel);
        if (!self::isNews($page)) {
            return true;
        }
        $title = trim((string) ($page["title"] ?? ""));
        if (
            $title === "" ||
            strcasecmp($title, "Neue Website-Neuigkeit") === 0
        ) {
            return false;
        }
        try {
            $channels = self::normalize(
                array_key_exists("news_channels", $page)
                    ? $page["news_channels"]
                    : self::ALL,
            );
        } catch (InvalidArgumentException) {
            return false;
        }
        return in_array($channel, $channels, true);
    }

    /** Fixed SQL over revision alias r; existing revisions without the new field keep both channels. */
    public static function where(string $channel = "website"): string
    {
        self::channel($channel);
        $renderer =
            "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template')))";
        $channels = "JSON_EXTRACT(r.payload_json,'$.news_channels')";
        return "($renderer<>'news' OR (TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')))<>'' AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title'))))<>'neue website-neuigkeit' AND (JSON_CONTAINS_PATH(r.payload_json,'one','$.news_channels')=0 OR (JSON_TYPE($channels)='ARRAY' AND JSON_LENGTH($channels)<=2 AND JSON_CONTAINS('[\"website\",\"rss\"]',$channels)=1 AND JSON_CONTAINS($channels,'\"$channel\"')=1))))";
    }

    private static function channel(string $channel): void
    {
        if (!in_array($channel, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Unbekannter Veröffentlichungskanal.",
            );
        }
    }
}
