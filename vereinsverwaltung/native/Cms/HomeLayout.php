<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\RichText;

/** Finite website sections; stored with the page draft and immutable publication. */
final class HomeLayout
{
    public const SECTIONS = [
        "highlights" => "Vereinsschwerpunkte",
        "events" => "Nächste Veranstaltung",
        "news" => "Aktuelle Beiträge",
        "content" => "Seiteninhalt",
        "newsletter" => "Newsletter-Einstieg",
    ];
    public const LABELS = [
        "hero_title" => "Hero-Überschrift",
        "hero_html" => "Formatierter Hero-Text",
        "poster_top" => "Plakat: obere Zeile",
        "poster_main" => "Plakat: Hauptzeile",
        "poster_sub" => "Plakat: untere Zeile",
        "highlights" => "Vereinsschwerpunkte",
        "events_heading" => "Überschrift Veranstaltungen",
        "news_heading" => "Überschrift Beiträge",
        "newsletter_heading" => "Überschrift Newsletter",
        "newsletter_text" => "Newsletter-Einleitung",
        "sections" => "Bereiche und Reihenfolge",
    ];
    public static function defaults(): array
    {
        return [
            "hero_title" => "",
            "hero_html" => "",
            "poster_top" => "Gemeinsam",
            "poster_main" => "VEREIN",
            "poster_sub" => "Aktiv vor Ort",
            "highlights" => [
                "Gemeinschaft",
                "Aktivitäten",
                "Veranstaltungen",
                "Ehrenamt",
            ],
            "events_heading" => "Programm {jahr}",
            "news_heading" => "Was uns bewegt",
            "newsletter_heading" => "Neues zuerst erfahren.",
            "newsletter_text" =>
                "Termine und Infos, sparsam verschickt und jederzeit abbestellbar.",
            "sections" => array_keys(self::SECTIONS),
        ];
    }
    public static function normalize(mixed $input): array
    {
        if (
            !is_array($input) ||
            array_diff(array_keys($input), array_keys(self::LABELS))
        ) {
            throw new InvalidArgumentException(
                "Ungültige Startseitengestaltung.",
            );
        }
        $values = array_replace(self::defaults(), $input);
        foreach (
            [
                "hero_title" => 200,
                "poster_top" => 80,
                "poster_main" => 80,
                "poster_sub" => 120,
                "events_heading" => 200,
                "news_heading" => 200,
                "newsletter_heading" => 200,
                "newsletter_text" => 1200,
            ]
            as $key => $max
        ) {
            $values[$key] = self::text(
                $values[$key],
                self::LABELS[$key],
                $max,
                in_array(
                    $key,
                    ["events_heading", "news_heading", "newsletter_heading"],
                    true,
                ),
            );
        }
        $values["hero_html"] = RichText::sanitize(
            self::text($values["hero_html"], "Hero-Text", 20000),
        );
        $sections = $values["sections"];
        if (
            !is_array($sections) ||
            !array_is_list($sections) ||
            count($sections) > 5
        ) {
            throw new InvalidArgumentException(
                "Höchstens fünf Startseitenbereiche als Liste angeben.",
            );
        }
        $seen = [];
        foreach ($sections as $key) {
            if (
                !is_string($key) ||
                !isset(self::SECTIONS[$key]) ||
                isset($seen[$key])
            ) {
                throw new InvalidArgumentException(
                    "Startseitenbereiche müssen bekannt und eindeutig sein.",
                );
            }
            $seen[$key] = true;
        }
        $highlights = $values["highlights"];
        if (
            !is_array($highlights) ||
            !array_is_list($highlights) ||
            count($highlights) > 8
        ) {
            throw new InvalidArgumentException(
                "Höchstens acht Vereinsschwerpunkte als Liste angeben.",
            );
        }
        $values["highlights"] = array_map(
            static fn($text) => self::text(
                $text,
                "Vereinsschwerpunkt",
                80,
                true,
            ),
            $highlights,
        );
        return $values;
    }
    private static function text(
        mixed $value,
        string $label,
        int $max,
        bool $required = false,
    ): string {
        if (
            !is_string($value) ||
            !preg_match("//u", $value) ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException(
                $label . ": gültigen UTF-8-Text angeben.",
            );
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (
            preg_match_all("/./us", $value) > $max ||
            ($required && $value === "")
        ) {
            throw new InvalidArgumentException(
                $label . ": Länge außerhalb des erlaubten Bereichs.",
            );
        }
        return $value;
    }
}
