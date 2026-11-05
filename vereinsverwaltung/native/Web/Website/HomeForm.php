<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use InvalidArgumentException;
use Kulturhus\Native\Cms\HomeLayout;
use Kulturhus\Native\Web\{Html, RichTextEditor};

/** Plain Bootstrap controls also submit successfully without JavaScript. */
final class HomeForm
{
    public static function render(array $page): string
    {
        $values = $page["home_layout"] ?? HomeLayout::defaults();
        $html =
            '<details data-home-layout><summary>Startseite gestalten</summary><p class="hint">Gestaltung und Bereichsfolge werden mit diesem Entwurf gespeichert. Erst „Veröffentlichen“ ändert die Website. Ohne individuelle Gestaltung gelten die bisherigen Vorgaben.</p>' .
            Html::hidden("home_layout_present", "1") .
            '<label class="check"><input class="check" type="checkbox" name="home_layout_enabled" value="1"' .
            (isset($page["home_layout"]) ? " checked" : "") .
            '> Individuelle Startseite verwenden</label><div class="fields">';
        foreach (
            [
                "hero_title" => 200,
                "poster_top" => 80,
                "poster_main" => 80,
                "poster_sub" => 120,
            ]
            as $key => $max
        ) {
            $html .= Html::input(
                "home_layout[" . $key . "]",
                HomeLayout::LABELS[$key],
                $values[$key],
                "text",
                'maxlength="' . $max . '"',
            );
        }
        $html .=
            '</div><p class="hint">Leere Hero-Überschrift bzw. leerer Hero-Text verwenden den Seitentitel bzw. die Einleitung.</p>' .
            RichTextEditor::render(
                "home_layout[hero_html]",
                $values["hero_html"],
                "Formatierter Hero-Text",
            ) .
            '<h3>Bereiche anordnen</h3><p class="hint">Von oben nach unten. Jeden Bereich höchstens einmal auswählen. „Ausblenden“ lässt einen Platz frei. Veranstaltungen und Beiträge verwenden veröffentlichte Fassungen. Seiteninhalt und Newsletter erscheinen nur, wenn Inhalt bzw. der Newsletter-Menüeintrag vorhanden ist.</p><div class="fields">';
        for ($i = 0; $i < 5; ++$i) {
            $html .= Html::select(
                "home_sections[" . $i . "]",
                "Platz " . ($i + 1),
                ["" => "Ausblenden"] + HomeLayout::SECTIONS,
                $values["sections"][$i] ?? "",
            );
        }
        $html .=
            '</div><label>Vereinsschwerpunkte – eine Zeile je Schwerpunkt<textarea class="form-control" name="home_highlights" rows="4">' .
            Html::e(implode("\n", $values["highlights"])) .
            '</textarea></label><p class="hint">Höchstens acht Zeilen mit jeweils 80 Zeichen.</p><div class="fields">';
        foreach (
            [
                "events_heading" => 200,
                "news_heading" => 200,
                "newsletter_heading" => 200,
                "newsletter_text" => 1200,
            ]
            as $key => $max
        ) {
            $html .= Html::input(
                "home_layout[" . $key . "]",
                HomeLayout::LABELS[$key],
                $values[$key],
                "text",
                'maxlength="' . $max . '"',
            );
        }
        return $html .
            '</div><p class="hint">In der Veranstaltungsüberschrift wird {jahr} durch das Jahr der nächsten Veranstaltung ersetzt; ohne Termin gilt das aktuelle Jahr.</p></details>';
    }
    /** null means explicit return to defaults; caller checks the presence marker. */
    public static function fromPost(array $post): ?array
    {
        if (
            ($post["home_layout_present"] ?? null) !== "1" ||
            (isset($post["home_layout_enabled"]) &&
                $post["home_layout_enabled"] !== "1")
        ) {
            throw new InvalidArgumentException("Ungültige Startseitenauswahl.");
        }
        if (!isset($post["home_layout_enabled"])) {
            return null;
        }
        $input = $post["home_layout"] ?? null;
        $slots = $post["home_sections"] ?? null;
        $highlights = $post["home_highlights"] ?? null;
        if (
            !is_array($input) ||
            array_key_exists("sections", $input) ||
            array_key_exists("highlights", $input) ||
            !is_array($slots) ||
            !array_is_list($slots) ||
            count($slots) !== 5 ||
            !is_string($highlights)
        ) {
            throw new InvalidArgumentException(
                "Vollständige Startseitengestaltung erforderlich.",
            );
        }
        foreach ($slots as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(
                    "Ungültiger Startseitenbereich.",
                );
            }
        }
        $input["sections"] = array_values(
            array_filter($slots, static fn($key) => $key !== ""),
        );
        $input["highlights"] = array_values(
            array_filter(
                preg_split('/\r\n|\r|\n/', $highlights),
                static fn($line) => trim($line) !== "",
            ),
        );
        return HomeLayout::normalize($input);
    }
}
