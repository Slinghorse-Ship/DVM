<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

/** Shared, readable source labels for agenda, list, grid and legend. */
final class Presentation
{
    public static function today(): string
    {
        return (new \DateTimeImmutable(
            "now",
            new \DateTimeZone("Europe/Berlin"),
        ))->format("Y-m-d");
    }
    private const LABELS = [
        'inspections' => 'Gefährdungsprüfung',
        "events" => "Veröffentlichte Veranstaltung",
        "external" => "Externe Veranstaltung",
        "internal" => "Vereinstermin",
        "board" => "Vorstand & Versammlung",
        "birthdays" => "Geburtstag",
        "social" => "Social Media",
        "newsletter" => "Newsletter",
        "press" => "Presse",
        "loans" => "Ausleihe",
        "deadlines" => "Nachweisfrist",
        "workflows" => "Dokumenten-/HSE-Frist",
    ];
    public static function label(array $entry): string
    {
        return self::LABELS[$entry["source_kind"] ?? ""] ?? "Vereinstermin";
    }
    public static function css(array $entry): string
    {
        $kind = $entry["source_kind"] ?? "internal";
        return "source-" . (isset(self::LABELS[$kind]) ? $kind : "internal");
    }
    public static function legend(array $entries): array
    {
        $kinds = array_fill_keys(array_column($entries, "source_kind"), true);
        $labels = [];
        foreach (self::LABELS as $kind => $label) {
            if (isset($kinds[$kind])) {
                $labels[$kind] = $label;
            }
        }
        return $labels;
    }
}
