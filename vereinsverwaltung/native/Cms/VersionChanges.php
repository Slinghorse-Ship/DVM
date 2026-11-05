<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

/** Compare original domain values, including exact scalar types, order and historical definitions. */
final class VersionChanges
{
    private const LABELS = [
        "title" => "Titel",
        "slug" => "Adresse",
        "template" => "Seitenvorlage",
        "excerpt" => "Einleitung",
        "seo_title" => "SEO-Titel",
        "seo_description" => "SEO-Beschreibung",
        "noindex" => "Suchmaschinen ausschließen",
        "nav_visible" => "Im Menü anzeigen",
        "nav_label" => "Menütitel",
        "nav_order" => "Menüreihenfolge",
        "parent_id" => "Übergeordnete Seite",
        "media_ids" => "Bilder und Reihenfolge",
        "document_links" => "PDF-Dokumente und Reihenfolge",
        "media_metadata" => "Bildquellen und Credits",
        "gallery_items" => "Bildbeschreibungen und Schlagwörter",
        "news_date" => "Beitragsdatum",
        "news_channels" => "Veröffentlichungskanäle",
        "news_review" => "Redaktionelle Freigabe",
        "event_start" => "Veranstaltungsbeginn vor Ort",
        "event_timezone" => "Zeitzone",
        "event_start_utc" => "Veranstaltungsbeginn (UTC)",
        "event_end" => "Veranstaltungsende",
        "event_location" => "Veranstaltungsort",
        "event_address" => "Anschrift",
        "event_status" => "Veranstaltungsstatus",
        "event_status_mode" => "Automatischer oder manueller Status",
        "event_ticket_url" => "Ticketlink",
        "event_reference_url" => "Weiterführender Link",
        "event_price" => "Eintritt",
        "template_definition" => "Aufbau der Seitenvorlage",
        "content_sections" => "Inhaltsabschnitte und Reihenfolge",
    ];

    public static function between(array $before, array $after): array
    {
        $left = self::values($before);
        $right = self::values($after);
        $changes = [];
        foreach (
            array_unique([...array_keys($left), ...array_keys($right)])
            as $key
        ) {
            $from = $left[$key] ?? null;
            $to = $right[$key] ?? null;
            if (
                $from !== null &&
                $to !== null &&
                $from["value"] === $to["value"] &&
                $from["type"] === $to["type"]
            ) {
                continue;
            }
            $changes[] = [
                "key" => $key,
                "label" => ($to ?? $from)["label"],
                "before" => $from,
                "after" => $to,
            ];
        }
        return $changes;
    }

    public static function labels(array $payload): array
    {
        $labels = [];
        foreach ($payload["field_definitions"] ?? [] as $key => $definition) {
            $labels[$key] = self::label($definition, $key);
        }
        return $labels;
    }

    private static function values(array $payload): array
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if (
                in_array(
                    $key,
                    [
                        "body",
                        "body_html",
                        "custom_fields",
                        "field_definitions",
                        "custom_field_text",
                        "custom_media_ids",
                        "custom_file_metadata",
                        "home_layout",
                        "content_sections_text",
                    ],
                    true,
                )
            ) {
                continue;
            }
            $type = match ($key) {
                "parent_id" => "page-reference",
                "media_ids" => "media-list",
                "document_links" => "document-links",
                "media_metadata" => "media-metadata",
                "gallery_items" => "gallery",
                "content_sections" => "content-sections",
                "template_definition" => "template",
                "news_channels" => "channels",
                "news_review" => "review",
                "event_status" => "event-status",
                "event_status_mode" => "event-status",
                default => is_array($value)
                    ? "list"
                    : (is_bool($value)
                        ? "boolean"
                        : "text"),
            };
            $values[$key] = [
                "label" => self::LABELS[$key] ?? "Weitere Angabe",
                "type" => $type,
                "value" => $value,
            ];
        }
        if (
            array_key_exists("body", $payload) ||
            array_key_exists("body_html", $payload)
        ) {
            $html = ($payload["body_html"] ?? "") !== "";
            $values["content"] = [
                "label" => "Inhalt",
                "type" => $html ? "richtext" : "textarea",
                "value" => $html
                    ? $payload["body_html"]
                    : $payload["body"] ?? "",
            ];
        }
        foreach ($payload["custom_fields"] ?? [] as $key => $value) {
            $definition = $payload["field_definitions"][$key] ?? [];
            $values["custom." . $key] = [
                "label" => self::label($definition, $key),
                "type" => $definition["type"] ?? "text",
                "value" => $value,
            ];
            if(isset($definition['type'])&&FieldValues::collection($definition))$values['custom.'.$key]['definition']=$definition;
        }
        foreach ($payload["home_layout"] ?? [] as $key => $value) {
            $values["home." . $key] = [
                "label" => "Startseite: " . (HomeLayout::LABELS[$key] ?? $key),
                "type" =>
                    $key === "hero_html"
                        ? "richtext"
                        : ($key === "sections"
                            ? "home-sections"
                            : (is_array($value)
                                ? "list"
                                : "text")),
                "value" => $value,
            ];
        }
        foreach ($payload["field_definitions"] ?? [] as $key => $definition) {
            $values["definition." . $key] = [
                "label" => "Felddefinition: " . self::label($definition, $key),
                "type" => "definition",
                "value" => $definition,
            ];
        }
        return $values;
    }
    private static function label(array $definition, string $fallback): string
    {
        return is_string($definition["label"] ?? null)
            ? $definition["label"]
            : $fallback;
    }
}
