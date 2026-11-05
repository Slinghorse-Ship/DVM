<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Cms\ReferenceCatalog;
use Kulturhus\Native\Shared\Access;
use PDO;

/** Native select semantics and a shared, explicit paged search in both manager shells. */
final class ReferencePicker
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private PDO $db, private Access $access) {}
    public function render(
        string $kind,
        string $name,
        string $id,
        string $selected,
        bool $required = false,
        string $hint = "",
        string $exclude = "",
        string $emptyLabel = "Keine Angabe",
    ): string {
        $data = (new ReferenceCatalog($this->db, $this->access))->listing(
            $kind,
            "",
            1,
            $selected,
            $exclude,
        );
        $options = $data["rows"];
        $known = array_column($options, "id");
        if ($selected !== "" && !in_array($selected, $known, true)) {
            $options[] = $data["selected"] ?? [
                "id" => $selected,
                "label" => $this->contentText("Auswahl nicht mehr verfügbar – bitte neu wählen"),
            ];
        }
        $html =
            '<div class="reference-picker" data-reference-picker data-reference-kind="' .
            Html::e($kind) .
            '" data-reference-exclude="' .
            Html::e($exclude) .
            '" data-reference-page="1" data-reference-last="' .
            $data["last"] .
            '"><div class="reference-search" data-dirty-ignore><label for="' .
            Html::e($id . "-search") .
            '">' .
            Html::e($this->contentText($kind === "page"
                ? "Seite oder Adresse suchen"
                : ($kind==='file'?'Dateiname oder Schlagwort suchen':"Bildbeschreibung oder Dateiname suchen"))) .
            '</label><div class="reference-search-actions"><input class="form-control" type="search" id="' .
            Html::e($id . "-search") .
            ('" maxlength="120" data-reference-query><button class="btn btn-solid theme-secondary" type="button" data-reference-action="search">'.Html::e($this->contentText('Suchen')).'</button></div></div><select class="form-control" id="') .
            Html::e($id) .
            '" name="' .
            Html::e($name) .
            '" data-reference-select data-field-type="' .
            $kind .
            '-reference"' .
            ($hint !== "" ? ' aria-describedby="' . Html::e($hint) . '"' : "") .
            ($required ? " required" : "") .
            '><option value=""' .
            ($selected === "" ? " selected" : "") .
            ">" .
            Html::e($required?$this->contentText("Bitte auswählen"):$emptyLabel) .
            "</option>";
        foreach ($options as $option) {
            $html .=
                '<option value="' .
                Html::e($option["id"]) .
                '"' .
                ($option["id"] === $selected ? " selected" : "") .
                ">" .
                Html::e($option["label"]) .
                "</option>";
        }
        return $html .
            ('</select><div class="reference-pagination" data-dirty-ignore><button class="btn btn-solid theme-secondary" type="button" data-reference-action="previous" disabled>'.Html::e($this->contentText('Vorherige Treffer')).'</button><p role="status" aria-live="polite" data-reference-status>') .
            Html::e($this->contentFormat('{count} Treffer · Seite {page} von {pages}',['count'=>$data['total'],'page'=>1,'pages'=>$data['last']])) .
            '</p><button class="btn btn-solid theme-secondary" type="button" data-reference-action="next"' .
            ($data["last"] === 1 ? " disabled" : "") .
            ('>'.Html::e($this->contentText('Weitere Treffer')).'</button></div><p class="hint">'.Html::e($this->contentText('Die gespeicherte Auswahl bleibt auch außerhalb der Trefferliste erhalten. Eine andere Verknüpfung bewusst auswählen.')).'</p></div>');
    }
}
