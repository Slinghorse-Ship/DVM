<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;

/** Editorial decisions are part of the page revision, separate from its public original. */
final class NewsReview
{
    public const LABELS = [
        "draft" => "Entwurf",
        "review" => "Zur Prüfung",
        "approved" => "Freigegeben",
    ];

    public static function normalize(mixed $status): string
    {
        if (!is_string($status) || !isset(self::LABELS[$status])) {
            throw new InvalidArgumentException(
                "Redaktionsstatus Entwurf, Zur Prüfung oder Freigegeben wählen.",
            );
        }
        return $status;
    }

    public static function status(array $page): string
    {
        return self::normalize($page["news_review"] ?? "draft");
    }

    /** No content edit, restore or submitted DTO can preserve or manufacture an approval. */
    public static function afterEdit(
        array $payload,
        array $previous = [],
    ): array {
        if (
            NewsChannels::isNews($payload) &&
            (array_key_exists("news_review", $payload) ||
                array_key_exists("news_review", $previous))
        ) {
            $payload["news_review"] = "draft";
        }
        return $payload;
    }

    public static function assertReady(array $page): void
    {
        $text = html_entity_decode(
            strip_tags(
                ($page["excerpt"] ?? "") .
                    " " .
                    ($page["body"] ?? "") .
                    " " .
                    ($page["body_html"] ?? ""),
            ),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8",
        );
        if (
            trim($page["title"] ?? "") === "" ||
            strcasecmp(trim($page["title"]), "Neue Website-Neuigkeit") === 0 ||
            trim(str_replace("\xc2\xa0", " ", $text)) === ""
        ) {
            throw new DomainException(
                "Vor der Freigabe einen Beitragstitel und Kurztext oder Inhalt ausfüllen.",
            );
        }
    }
}
