<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use DomainException;
use Kulturhus\Native\Shared\RichText;

final class RichTextEditor
{
    public static function render(
        string $name,
        string $html,
        string $label = "Inhalt",
        array $field = [],
        ?callable $interfaceText=null,
    ): string {
        $interfaceText??=static fn(string $source):string=>$source;
        if (
            !preg_match(
                "/\A(?:[a-zA-Z][a-zA-Z0-9_]*|custom_fields\[[a-z][a-z0-9_]{0,63}\](?:\[(?:[0-9]{1,2}|__ITEM_[a-f0-9]{12}__)\]\[values\]\[[a-z][a-z0-9_]{0,63}\]){0,3}(?:\[(?:[0-9]{1,2}|__ITEM_[a-f0-9]{12}__)\]\[value\])?|home_layout\[hero_html\]|section_rows\[[0-9]{1,2}\]\[body_html\])\z/D",
                $name,
            )
        ) {
            throw new DomainException("Ungültiges Richtextfeld.");
        }
        if (
            array_diff(array_keys($field), [
                "required",
                "min",
                "max",
                "hint_id",
            ])
        ) {
            throw new DomainException("Ungültige Richtext-Feldeigenschaft.");
        }
        $limits = "";
        if (($field["required"] ?? false) === true) {
            $limits .= ' data-richtext-required="1"';
        }
        foreach (["min", "max"] as $bound) {
            if (($field[$bound] ?? null) === null) {
                continue;
            }
            if (
                !is_int($field[$bound]) ||
                $field[$bound] < 0 ||
                $field[$bound] > 100000
            ) {
                throw new DomainException("Ungültige Richtext-Textgrenze.");
            }
            $limits .= " data-richtext-" . $bound . '="' . $field[$bound] . '"';
        }
        $hint = $field["hint_id"] ?? "";
        if (
            !is_string($hint) ||
            ($hint !== "" &&
                !preg_match("/\A[a-zA-Z][a-zA-Z0-9_-]{0,512}\z/D", $hint))
        ) {
            throw new DomainException("Ungültiger Richtext-Hinweis.");
        }
        $safe = RichText::sanitize($html);
        $e = Html::e(...);
        $id = "kh-rte-" . bin2hex(random_bytes(6));
        $button = static fn(
            string $cmd,
            string $title,
            string $text,
        ): string => '<button type="button" class="btn btn-solid theme-secondary secondary kh-rte-' .
            $cmd .
            '" data-rt-command="' .
            $cmd .
            '" aria-label="' .
            $e($interfaceText($title)) .
            '" title="' .
            $e($interfaceText($title)) .
            '"' .
            (in_array($cmd, ["bold", "italic", "underline", "strikeThrough"])
                ? ' aria-pressed="false"'
                : "") .
            ">" .
            $e($text===$title?$interfaceText($text):$text) .
            "</button>";
        $out =
            '<section class="kh-rte" id="' .
            $id .
            '" data-kh-richtext' .
            $limits .
            '><label class="kh-rte-label" id="' .
            $id .
            '-label">' .
            $e($label) .
            '</label><input type="hidden" data-richtext-value name="' .
            $e($name) .
            '" value="' .
            $e($safe) .
            '">';
        $out .=
            '<div class="kh-rte-tools"><div class="kh-rte-toolbar" data-richtext-toolbar role="toolbar" aria-label="' .
            $e(strtr($interfaceText('Text formatieren: {label}'),['{label}'=>$label])) .
            '">';
        $out .=
            ('<select class="form-control" data-rt-format aria-label="'.Html::e($interfaceText('Absatzformat')).'"><option value="p">'.Html::e($interfaceText('Absatz')).'</option><option value="h2">'.Html::e($interfaceText('Überschrift 2')).'</option><option value="h3">'.Html::e($interfaceText('Überschrift 3')).'</option><option value="h4">'.Html::e($interfaceText('Überschrift 4')).'</option><option value="blockquote">'.Html::e($interfaceText('Zitat')).'</option></select><select class="form-control" data-rt-size aria-label="'.Html::e($interfaceText('Textgröße')).'"><option value="3">'.Html::e($interfaceText('Normal')).'</option><option value="2">'.Html::e($interfaceText('Kleiner')).'</option><option value="4">'.Html::e($interfaceText('Größer')).'</option><option value="5">'.Html::e($interfaceText('Sehr groß')).'</option></select><span class="kh-rte-separator"></span>');
        foreach (
            [
                "bold" => ["Fett", "B"],
                "italic" => ["Kursiv", "I"],
                "underline" => ["Unterstrichen", "U"],
            ]
            as $cmd => [$title, $text]
        ) {
            $out .= $button($cmd, $title, $text);
        }
        $out .=
            '<span class="kh-rte-separator"></span>' .
            $button("insertUnorderedList", "Aufzählung", "• ≡") .
            $button("insertOrderedList", "Nummerierte Liste", "1. ≡") .
            ('<button type="button" class="btn btn-solid theme-secondary secondary" data-rt-link title="'.Html::e($interfaceText('Link einfügen')).'">'.Html::e($interfaceText('Link ↗')).'</button><span class="kh-rte-separator"></span>') .
            $button("undo", "Rückgängig", "↶") .
            $button("redo", "Wiederholen", "↷") .
            "</div>";
        $out .=
            ('<div class="kh-rte-insert"><details class="kh-rte-menu"><summary><span class="kh-color-icon">A</span>'.' '.Html::e($interfaceText('Farben')).'</summary><div class="kh-rte-popover"><strong>'.Html::e($interfaceText('Farben gestalten')).'</strong><div class="kh-rte-palette" role="group" aria-label="'.Html::e($interfaceText('Textfarben')).'">');
        foreach (
            [
                "#173b2e" => "Dunkelgrün",
                "#245640" => "Kulturhus-Grün",
                "#d4a44c" => "Ocker",
                "#8d2b27" => "Rot",
                "#164c86" => "Blau",
                "#7b3a80" => "Violett",
                "#000000" => "Schwarz",
                "#ffffff" => "Weiß",
            ]
            as $color => $title
        ) {
            $out .=
                '<button type="button" class="btn btn-solid theme-primary kh-rte-swatch" data-rt-swatch="' .
                $color .
                '" aria-label="'. $e($interfaceText('Textfarbe')).' ' .
                $e($interfaceText($title)) .
                '" title="' .
                $e($interfaceText($title)) .
                '" style="background-color:' .
                $color .
                '"></button>';
        }
        $out .=
            ('</div><label class="kh-rte-color-label">'.Html::e($interfaceText('Schrift')).'<input class="form-control" type="color" value="#245640" data-rt-color="foreColor" aria-label="'.Html::e($interfaceText('Textfarbe wählen')).'"></label><label class="kh-rte-color-label">'.Html::e($interfaceText('Textmarker')).'<input class="form-control" type="color" value="#fff1ad" data-rt-color="hiliteColor" aria-label="'.Html::e($interfaceText('Texthintergrund wählen')).'"></label><label class="kh-rte-color-label">'.Html::e($interfaceText('Tabellenzelle')).'<input class="form-control" type="color" value="#f6f3e9" data-rt-cell-color aria-label="'.Html::e($interfaceText('Tabellenhintergrund wählen')).'"></label><button type="button" class="btn btn-solid theme-secondary secondary" data-rt-clear-colors>'.Html::e($interfaceText('Farben zurücksetzen')).'</button></div></details>');
        $out .=
            ('<details class="kh-rte-menu"><summary>'.Html::e($interfaceText('☺ Emoji')).'</summary><div class="kh-rte-popover"><strong>'.Html::e($interfaceText('Ein Zeichen sagt mehr')).'</strong><div class="kh-rte-emoji" role="group" aria-label="'.Html::e($interfaceText('Emoji einfügen')).'">');
        foreach (
            [
                "😀" => "Lächeln",
                "🎉" => "Feier",
                "🎶" => "Musik",
                "🎭" => "Theater",
                "📅" => "Termin",
                "📍" => "Ort",
                "❤️" => "Herz",
                "👍" => "Daumen hoch",
                "✨" => "Sterne",
                "☕" => "Kaffee",
            ]
            as $emoji => $title
        ) {
            $out .=
                '<button type="button" class="btn btn-solid theme-secondary secondary" data-rt-emoji="' .
                $emoji .
                '" aria-label="' .
                $e(strtr($interfaceText('{emoji} einfügen'),['{emoji}'=>$interfaceText($title)])) .
                '">' .
                $emoji .
                "</button>";
        }
        $out .=
            ('</div><small>'.Html::e($interfaceText('Alle Unicode-Zeichen lassen sich auch direkt eintippen oder einfügen.')).'</small></div></details>');
        $out .=
            ('<details class="kh-rte-menu"><summary>'.Html::e($interfaceText('▦ Tabelle')).'</summary><div class="kh-rte-popover kh-rte-tables" role="group" aria-label="'.Html::e($interfaceText('Tabelle bearbeiten')).'"><strong>'.Html::e($interfaceText('Tabelle einfügen')).'</strong><div class="kh-rte-grid-size"><label>'.Html::e($interfaceText('Zeilen')).'<input class="form-control" type="number" min="1" max="20" value="3" data-rt-rows aria-label="'.Html::e($interfaceText('Neue Tabelle: Zeilen')).'"></label><span>×</span><label>'.Html::e($interfaceText('Spalten')).'<input class="form-control" type="number" min="1" max="10" value="3" data-rt-cols aria-label="'.Html::e($interfaceText('Neue Tabelle: Spalten')).'"></label></div><button type="button" class="btn btn-solid theme-primary" data-rt-table="insert">'.Html::e($interfaceText('Tabelle einfügen')).'</button><hr><small>'.Html::e($interfaceText('Zum Bearbeiten zuerst eine Zelle auswählen.')).'</small>');
        foreach (
            [
                "row-add" => "Zeile hinzufügen",
                "row-remove" => "Zeile entfernen",
                "col-add" => "Spalte hinzufügen",
                "col-remove" => "Spalte entfernen",
                "header" => "Kopfzeile umschalten",
                "remove" => "Tabelle entfernen",
            ]
            as $action => $title
        ) {
            $out .=
                '<button type="button" class="btn btn-solid theme-secondary secondary" data-rt-table="' .
                $action .
                '">' .
                $e($interfaceText($title)) .
                "</button>";
        }
        $out .=
            ('</div></details><details class="kh-rte-menu"><summary>'.Html::e($interfaceText('Weitere Formate')).'</summary><div class="kh-rte-popover kh-rte-formats">');
        foreach (
            [
                "justifyLeft" => "Linksbündig",
                "justifyCenter" => "Zentriert",
                "justifyRight" => "Rechtsbündig",
                "justifyFull" => "Blocksatz",
                "strikeThrough" => "Durchgestrichen",
                "unlink" => "Link entfernen",
                "removeFormat" => "Formatierung entfernen",
            ]
            as $cmd => $title
        ) {
            $out .= $button($cmd, $title, $title);
        }
        $out .=
            ('</div></details><span class="kh-rte-shortcut">'.Html::e($interfaceText('Strg + B · Strg + I')).'</span></div></div>');
        $out .=
            '<div class="kh-richtext kh-rte-editable" data-richtext-surface contenteditable="false" role="textbox" aria-readonly="true" aria-required="' .
            ($field["required"] ?? false ? "true" : "false") .
            '" aria-multiline="true" aria-labelledby="' .
            $id .
            '-label" aria-describedby="' .
            $id .
            "-hint" .
            ($hint !== "" ? " " . $e($hint) : "") .
            '" spellcheck="true" tabindex="0">' .
            $safe .
            '</div><div class="kh-rte-foot"><small id="' .
            $id .
            ('-hint">'.Html::e($interfaceText('Text markieren, dann formatieren.')).'</small><span class="kh-rte-status" role="status" aria-live="polite" data-richtext-status></span></div>');
        $out .=
            '<div class="kh-rte-link-panel" data-rt-link-panel hidden><label for="'.$id.('-link-kind">'.Html::e($interfaceText('Linkart')).'</label><select class="form-control" id="').$id.('-link-kind" data-rt-link-kind><option value="web">'.Html::e($interfaceText('Website')).'</option><option value="email">'.Html::e($interfaceText('E-Mail')).'</option><option value="phone">'.Html::e($interfaceText('Telefon')).'</option></select><label data-rt-link-label for="') .
            $id .
            ('-url">'.Html::e($interfaceText('Webadresse oder Seitenpfad')).'</label><input class="form-control" id="') .
            $id .
            ('-url" type="text" data-rt-link-url autocomplete="off" inputmode="url" autocapitalize="none" spellcheck="false"><div class="actions"><button type="button" class="btn btn-solid theme-primary" data-rt-link-apply>'.Html::e($interfaceText('Link übernehmen')).'</button><button type="button" class="btn btn-solid theme-secondary secondary" data-rt-link-cancel>'.Html::e($interfaceText('Abbrechen')).'</button></div></div>');
        return $out .
            ('<noscript><p>'.Html::e($interfaceText('Zum Formatieren bitte JavaScript aktivieren. Der gespeicherte Inhalt bleibt erhalten.')).'</p></noscript></section>');
    }
}
