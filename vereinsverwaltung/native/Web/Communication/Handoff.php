<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Html;

use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Communication\StoryHandoff as Handoffs;

/** Focused app handoff UI, separate from the editorial form and transport adapters. */
final class Handoff
{
    public static function render(
        PDO $db,
        Access $access,
        string $mediaRoot,
        string $csrf,
        array $item,
    ): string {
        $service = new Handoffs($db, $access, $mediaRoot);
        $history = $service->history($item["id"]);
        if (!$history && empty($item["story_link_requested"])) {
            return "";
        }
        try {
            $access->require("social", "publish");
            $publisher = true;
        } catch (\DomainException) {
            $publisher = false;
        }
        $form = static fn(string $operation) => '<form method="post">' .
            Html::hidden("csrf", $csrf) .
            Html::hidden("operation", $operation) .
            Html::hidden("id", $item["id"]);
        $h =
            '<section class="panel story-handoff"><h2>Story mit Link veröffentlichen</h2><p>Die freigegebenen Folien und die Zieladresse werden für jeden gewählten Kanal festgehalten. Anschließend veröffentlichst du die Story in der jeweiligen App.</p>';
        if (
            $publisher &&
            $item["status"] === "approved" &&
            !empty($item["story_link_requested"]) &&
            !array_filter(
                $history,
                static fn($row) => $row["item_version"] === $item["version"],
            )
        ) {
            $h .=
                $form("story-handoff-prepare") .
                Html::hidden("version", $item["version"]) .
                '<button class="btn btn-solid theme-primary">App-Übergabe vorbereiten</button></form>';
        } elseif (!$history) {
            $h .=
                "<p>Erzeuge und prüfe zuerst alle Folien. Gib anschließend die aktuelle Fassung frei.</p>";
        }
        foreach ($history as $row) {
            $current =
                $row["item_version"] === $item["version"] &&
                $item["status"] === "approved";
            $status = match ($row["status"]) {
                "confirmed" => "Manuell bestätigt",
                "cancelled" => "Abgebrochen",
                default => $current
                    ? "Für die App vorbereitet"
                    : "Ältere Fassung · bitte abbrechen",
            };
            $h .=
                '<article data-handoff="' .
                Html::e($row["id"]) .
                '"><h3>' .
                Html::e(ucfirst($row["channel"])) .
                " · Fassung " .
                $row["item_version"] .
                '</h3><p role="status">' .
                Html::e($status) .
                "</p>";
            if ($row["status"] === "prepared" && $current) {
                $h .=
                    "<ol><li><strong>Freigegebene Folien herunterladen</strong><ol>";
                foreach ($row["snapshot"]["images"] as $image) {
                    $h .=
                        '<li><a href="/social-image/' .
                        Html::e($image["id"]) .
                        '" download="story-folie-' .
                        ($image["slide"] + 1) .
                        '.png">Folie ' .
                        ($image["slide"] + 1) .
                        " herunterladen</a></li>";
                }
                $h .=
                    '</ol></li><li><strong>In der App den Link-Sticker einsetzen</strong><label>Zieladresse zum Kopieren<input class="form-control" type="url" readonly value="' .
                    Html::e($row["snapshot"]["destination_url"]) .
                    '" aria-label="Zieladresse zum Kopieren"></label><p>Link-Sticker auf jeder vorgesehenen Folie ergänzen und das Ziel prüfen.</p></li><li><strong>Story in ' .
                    Html::e(ucfirst($row["channel"])) .
                    " veröffentlichen</strong><p>Öffne anschließend die veröffentlichte Story und prüfe Folien sowie Link.</p></li></ol>";
                if ($publisher) {
                    $h .=
                        $form("story-handoff-complete") .
                        Html::hidden("handoff_id", $row["id"]) .
                        Html::hidden("handoff_version", $row["version"]) .
                        "<fieldset><legend>Veröffentlichung dokumentieren</legend>";
                    foreach (
                        [
                            "images_checked" =>
                                "Ich habe die freigegebenen Folien geprüft.",
                            "link_added" =>
                                "Ich habe den Link-Sticker mit dieser Zieladresse eingesetzt und geprüft.",
                            "published" =>
                                "Ich habe die Story im gewählten Kanal veröffentlicht und geöffnet.",
                        ]
                        as $name => $label
                    ) {
                        $h .=
                            '<label class="check"><input class="check" type="checkbox" name="' .
                            $name .
                            '" required> ' .
                            Html::e($label) .
                            "</label>";
                    }
                    $h .=
                        Html::input(
                            "reference_url",
                            "Link zur veröffentlichten Story (optional)",
                            "",
                            "url",
                        ) .
                        '<p class="hint">Dieser Eintrag dokumentiert deine manuelle Bestätigung. Ein automatischer Anbieterabgleich findet nicht statt.</p><button class="btn btn-solid theme-primary">Manuelle Veröffentlichung bestätigen</button></fieldset></form>';
                }
            }
            if ($publisher && $row["status"] === "prepared") {
                $h .=
                    "<details><summary>Übergabe abbrechen</summary>" .
                    $form("story-handoff-cancel") .
                    Html::hidden("handoff_id", $row["id"]) .
                    Html::hidden("handoff_version", $row["version"]) .
                    Html::input(
                        "reason",
                        "Grund",
                        "",
                        "text",
                        'required minlength="10" maxlength="1000"',
                    ) .
                    '<button class="btn btn-outline theme-secondary secondary">Übergabe abbrechen</button></form></details>';
            }
            if ($row["reference_url"]) {
                $h .=
                    '<a href="' .
                    Html::e($row["reference_url"]) .
                    '" target="_blank" rel="noopener noreferrer">Gespeicherten Story-Link öffnen</a>';
            }
            $h .= "<details><summary>Verlauf</summary><ol>";
            foreach ($service->events($row["id"]) as $event) {
                $date = new \DateTimeImmutable(
                    $event["created_at"],
                    new \DateTimeZone("UTC"),
                );
                $label = match ($event["action"]) {
                    "prepared" => "Übergabe vorbereitet",
                    "manually_confirmed" => "Manuell bestätigt",
                    default => "Übergabe abgebrochen",
                };
                $h .=
                    "<li>" .
                    Html::e(
                        $date
                            ->setTimezone(new \DateTimeZone("Europe/Berlin"))
                            ->format("d.m.Y H:i") .
                            " · " .
                            $label,
                    ) .
                    " · " .
                    Html::e($event["actor_id"]);
                if ($event["action"] === "cancelled") {
                    $h .=
                        " · " .
                        Html::e(
                            json_decode(
                                $event["details_json"],
                                true,
                                16,
                                JSON_THROW_ON_ERROR,
                            )["reason"] ?? "",
                        );
                }
                $h .= "</li>";
            }
            $h .= "</ol></details></article>";
        }
        return $h . "</section>";
    }
}
