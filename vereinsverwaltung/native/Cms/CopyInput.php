<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Closed copy request, shared by single-page and subtree operations. */
final class CopyInput
{
    public static function validate(
        string $sourceId,
        int $version,
        array $options,
    ): array {
        if (
            $version < 1 ||
            $version > 4294967294 ||
            !preg_match("/\A[a-f0-9]{32}\z/D", $sourceId) ||
            array_diff(array_keys($options), ["title", "slug", "basis"]) ||
            !is_string($options["title"] ?? null) ||
            !is_string($options["slug"] ?? null) ||
            !in_array(
                $options["basis"] ?? "draft",
                ["draft", "published"],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Gespeicherte Quelle, Titel, neue Adresse und Kopiergrundlage prüfen.",
            );
        }
        $options["basis"] = $options["basis"] ?? "draft";
        return $options;
    }
}
