<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

/** Predictable bounded addresses; the full source identity prevents sibling name collisions. */
final class TreeCopyAddresses
{
    public static function child(string $rootSlug, array $source): string
    {
        $payload = $source["payload"];
        $prefix = match ($payload["template"]) {
            "news" => "aktuelles/",
            "gallery" => "galerie/",
            default => $rootSlug . "/",
        };
        $seed =
            (in_array($payload["template"], ["news", "gallery"], true)
                ? basename($rootSlug) . "-"
                : "") . basename($payload["slug"]);
        $maximum = 180 - strlen($prefix) - 33;
        $name = rtrim(substr($seed, 0, $maximum), "-");
        return $prefix . $name . "-" . $source["id"];
    }
}
