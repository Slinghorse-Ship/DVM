<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Web\Html;

/** One copy form shared by page, article and album editors. Caller checks edit rights. */
final class PageCopyPanel
{
    public static function render(array $page, string $csrf,?array $allowed=null,?callable $interfaceText=null): string
    {
        $interfaceText??=static fn(string $source):string=>$source;
        if (empty($page["id"])) {
            return "";
        }
        $basis = ["draft" => "Zuletzt gespeicherter Entwurf"];
        if (!empty($page["published_revision"])) {
            $basis["published"] = "Veröffentlichte Fassung";
        }
        if($allowed!==null)$basis=array_intersect_key($basis,array_fill_keys($allowed,true));if(!$basis)return '';
        $slug = rtrim(substr($page["slug"], 0, 173), "-/") . "-kopie";
        if ($page["template"] === "gallery" && $page["slug"] === "galerie") {
            $slug = "galerie/kopie";
        }
        $hint = match ($page["template"]) {
            "news"
                => ('<p class="hint">'.Html::e($interfaceText('Beitragsadresse: aktuelles/eigener-name')).'</p>'),
            "gallery"
                => ('<p class="hint">'.Html::e($interfaceText('Albumadresse: galerie/eigener-name')).'</p>'),
            default => "",
        };
        $title = mb_substr($page["title"], 0, 192) . " – Kopie";
        $selectedBasis = array_key_first($basis);
        $copyTree = false;
        $copyJob = false;
        $submitted =
            ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
            ($_POST["operation"] ?? "") === "page-copy" &&
            ($_POST["id"] ?? "") === $page["id"] && http_response_code()>=400 && $csrf!=="" && is_string($_POST["csrf"]??null) && hash_equals($csrf,$_POST["csrf"]);
        if ($submitted) {
            $copyTree = ($_POST["copy_tree"] ?? "") === "1";
            $copyJob = ($_POST['copy_job'] ?? '') === '1';
            foreach (
                ["copy_title", "copy_slug"] as $key
            ) {
                $value = $_POST[$key] ?? null;
                if (!is_string($value) || !preg_match("//u", $value)) {
                    continue;
                }
                if ($key === "copy_title") {
                    $title = $value;
                } else {
                    $slug = $value;
                }
            }
            if (
                is_string($_POST["basis"] ?? null) &&
                isset($basis[$_POST["basis"]])
            ) {
                $selectedBasis = $_POST["basis"];
            }
        }
        return '<details class="panel page-copy-panel"' .
            ($submitted ? " open" : "") .
            ('><summary>'.Html::e($interfaceText('Als neuen Entwurf kopieren')).'</summary>') .
            ('<p>'.Html::e($interfaceText('Wähle die gespeicherte Grundlage und eine eigene Adresse. Kopien bleiben unveröffentlicht und außerhalb des Menüs. Bilder bleiben mit dem vorhandenen Bestand verbunden. Ungespeicherte Änderungen vorher sichern.')).'</p>') .
            '<form method="post">' .
            Html::hidden("csrf", $csrf) .
            Html::hidden("operation", "page-copy") .
            Html::hidden("id", $page["id"]) .
            Html::hidden("version", $submitted&&is_string($_POST["csrf"]??null)&&hash_equals($csrf,$_POST["csrf"])&&is_string($_POST["version"]??null)?$_POST["version"]:$page["version"]) .
            Html::select("basis", $interfaceText("Kopiergrundlage"), array_map($interfaceText,$basis), $selectedBasis) .
            Html::input(
                "copy_title",
                $interfaceText("Titel der Kopie"),
                $title,
                "text",
                'required maxlength="200"',
            ) .
            Html::input(
                "copy_slug",
                $interfaceText("Neue Adresse der Kopie"),
                $slug,
                "text",
                'required maxlength="180"',
            ) .
            $hint .
            '<label class="check"><input class="check" type="checkbox" name="copy_tree" value="1"' .
            ($copyTree ? " checked" : "") .
            ('>'.Html::e($interfaceText('Unterseiten mitkopieren')).'</label><p class="hint">'.Html::e($interfaceText('Interne Seitenverweise zeigen auf die neuen Kopien; äußere Verweise bleiben erhalten. Für Teilbäume die Wurzeladresse auf höchstens 120 Zeichen begrenzen.')).'</p>') .
            '<label class="check"><input class="check" type="checkbox" name="copy_job" value="1"'.($copyJob?' checked':'').('>'.Html::e($interfaceText('Teilbaum schrittweise vorbereiten (bis 5000 Seiten)')).'</label><p class="hint">'.Html::e($interfaceText('Fortschritt bleibt erhalten. Erst nach der Vorbereitung werden alle Kopien gemeinsam als private Entwürfe angelegt. Ohne diese Auswahl werden bis zu 500 Seiten sofort kopiert.')).'</p>') .
            ('<button class="btn btn-solid theme-primary">'.Html::e($interfaceText('Unveröffentlichten Entwurf anlegen')).'</button></form></details>');
    }
}
