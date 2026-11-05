<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Shared\{Access, Db, RichText};
use Kulturhus\Native\Cms\FieldGroups;
use Kulturhus\Native\Web\{Html};
use Kulturhus\Native\Web\Fields\Controls;
use PDO;

/** Read-only presentation of original typed values; references resolve only owned metadata. */
final class VersionValues
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private array $references = [];
    public function __construct(private PDO $db, private Access $access, private bool $showReferenceIds=true) {}
    public function render(?array $original, array $labels = []): string
    {
        $this->access->require("website", "read");
        if ($original === null) {
            return ('<p class="hint">'.Html::e($this->contentText('Nicht in dieser Version vorhanden.')).'</p>');
        }
        $value = $original["value"];
        if ($value === null) {
            return ('<p class="hint">'.Html::e($this->contentText('Keine Angabe')).'</p>');
        }
        if ($value === "") {
            return ('<p class="hint">'.Html::e($this->contentText('Leer')).'</p>');
        }
        if($original['type']==='password')return ('<p>'.Html::e($this->contentText('Geschützter Wert · zum Ändern neu eingeben')).'</p>');
        if(isset($original['definition'])&&\Kulturhus\Native\Cms\FieldValues::collection($original['definition'])){
            $field=$original['definition'];$items=[];
            foreach($value as$i=>$item){
                if($field['type']==='repeater'){
                    $body='<p class="hint">'.Html::e($this->contentFormat('Version {version}',['version'=>$item['version']])).' · '.Html::e($this->contentText(($item['public']??true)?'Öffentlich':'Nur intern')).'</p><dl>';
                    foreach($field['item_definitions']as$key=>$child)$body.='<dt>'.Html::e($child['label']).'</dt><dd>'.$this->render(['type'=>$child['type'],'value'=>$item['values'][$key]??null,'definition'=>$child],$labels).'</dd>';
                    $items[]='<details><summary>'.Html::e($this->contentFormat('Datensatz {number}',['number'=>$i+1])).'</summary>'.$body.'</dl></details>';
                }else{$scalar=$field;unset($scalar['multiple']);if($scalar['type']==='pagetable')$scalar['type']='page-reference';$items[]=$this->render(['type'=>$scalar['type'],'value'=>$item],$labels);}
            }
            return $this->list($items);
        }
        return match ($original["type"]) {
            "richtext" => '<div class="kh-richtext version-richtext">' .
                RichText::sanitize($value) .
                "</div>",
            "page-reference" => $this->reference("page", $value),
            "media-reference" => $this->reference("media", $value),
            "file-reference" => $this->reference("file", $value),
            "media-metadata" => $this->mediaMetadata($value),
            "media-list" => $this->list(
                array_map(fn($id) => $this->reference("media", $id), $value),
            ),
            "gallery" => $this->list(
                array_map(
                    fn($item) => $this->reference("media", $item["id"]) .
                        "<p>" .
                        Html::text($item["caption"]) .
                        '</p><p class="hint">'.Html::e($this->contentText('Schlagwörter')).': ' .
                        Html::e(implode(", ", $item["tags"])) .
                        "</p>",
                    $value,
                ),
            ),
            "content-sections" => $this->sections($value),
            "document-links" => DocumentLinks::render(['document_links'=>$value],true),
            "definition" => $this->definition($value, $labels),
            "template" => $this->template($value, $labels),
            "boolean" => "<p>" . Html::e($this->contentText($value ? "Ja" : "Nein")) . "</p>",
            "channels" => $this->list(
                array_map(
                    fn($channel) => Html::e($this->contentText(
                        ["website" => "Website", "rss" => "RSS-Feed"][
                            $channel
                        ] ?? $channel),
                    ),
                    $value,
                ),
            ),
            "home-sections" => $this->list(
                array_map(
                    fn($key) => Html::e($this->contentText(
                        \Kulturhus\Native\Cms\HomeLayout::SECTIONS[$key] ??
                            $key),
                    ),
                    $value,
                ),
            ),
            "review" => "<p>" .
                Html::e($this->contentText(
                    [
                        "draft" => "Entwurf",
                        "review" => "Zur Prüfung",
                        "approved" => "Redaktionell freigegeben",
                    ][$value] ?? $value),
                ) .
                "</p>",
            "event-status" => "<p>" .
                Html::e($this->contentText(
                    [
                        "scheduled" => "Geplant",
                        "cancelled" => "Abgesagt",
                        "postponed" => "Verschoben",
                        "rescheduled" => "Neuer Termin",
                    ][$value] ?? $value),
                ) .
                "</p>",
            default => is_array($value)
                ? $this->list(
                    array_map(
                        static fn($item) => "<span>" .
                            Html::e(
                                is_scalar($item) || $item === null
                                    ? (string) $item
                                    : json_encode(
                                        $item,
                                        JSON_UNESCAPED_UNICODE |
                                            JSON_THROW_ON_ERROR,
                                    ),
                            ) .
                            "</span>",
                        $value,
                    ),
                )
                : '<p class="version-literal">' .
                    Html::text((string) $value) .
                    "</p>",
        };
    }
    private function reference(string $kind, string $id): string
    {
        if (!preg_match("/\A[a-f0-9]{32}\z/D", $id)) {
            return "<span>" . Html::e($id) . "</span>";
        }
        $owner = $this->access->require("website", "read")["club_id"];
        $key = $owner . ":" . $kind . ":" . $id;
        if (!array_key_exists($key, $this->references)) {
            $this->references[$key] = Db::run(
                $this->db,
                $kind==='file'?'SELECT original_name AS label FROM khcms_documents WHERE id=? AND club_id=?':($kind === "page"
                    ? "SELECT JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.title')) AS label FROM khcms_pages WHERE id=? AND club_id=?"
                    : "SELECT CASE WHEN alt<>'' THEN alt ELSE original_name END AS label FROM khcms_media WHERE id=? AND club_id=?"),
                [$id, $owner],
            )->fetch(PDO::FETCH_ASSOC);
        }
        $label = $this->references[$key]["label"] ?? $this->contentText("Nicht mehr verfügbar");
        return '<span class="version-reference">' .
            Html::e($label) .
            ($this->showReferenceIds ? "<small>" .
            Html::e($id) .
            "</small>" : "") . "</span>";
    }
    private function mediaMetadata(array $rows): string
    {
        $html = '<div class="version-media-metadata">';
        foreach ($rows as $metadata) {
            $html .= '<article><h4>'.Html::e($metadata['alt']).'</h4><dl>';
            foreach (['credit'=>'Fotocredit','source_name'=>'Quelle','source_url'=>'Quellenlink','license_name'=>'Lizenz','license_url'=>'Lizenzlink'] as $key=>$label) if (($metadata[$key]??'')!=='') $html .= '<dt>'.Html::e($this->contentText($label)).'</dt><dd>'.Html::e($metadata[$key]).'</dd>';
            $html .= '</dl></article>';
        }
        return $html.'</div>';
    }

    private function sections(array $sections):string
    {
        $items=[];foreach($sections as $row){
            $h='<strong>'.Html::e($row['heading']).'</strong><p class="hint">'.Html::e($this->contentText(\Kulturhus\Native\Cms\ContentSections::LAYOUTS[$row['layout']])).' · '.Html::e($row['id']).'</p><div class="kh-richtext">'.RichText::sanitize($row['body_html']).'</div>';
            if($row['media_id']!==null)$h.=$this->reference('media',$row['media_id']).'<p>'.Html::e($row['alt']).' · '.Html::e($row['caption']).' · '.Html::e($row['credit']).'</p>';
            if(isset($row['aspect']))$h.='<p>'.Html::e($this->contentFormat('Bildzuschnitt: {aspect} · Fokus {x}% / {y}%',['aspect'=>$this->contentText(\Kulturhus\Native\Cms\ContentSections::ASPECTS[$row['aspect']]),'x'=>$row['focal_x'],'y'=>$row['focal_y']])).'</p>';
            if($row['button_url']!=='')$h.='<p>'.Html::e($row['button_label']).' · '.Html::e($row['button_url']).'</p>';
            $items[]=$h;
        }
        return $this->list($items);
    }
    private function definition(array $field, array $labels): string
    {
        $html =
            "<p><strong>" .
            Html::e($field["label"]) .
            "</strong> · " .
            Html::e(
                $this->contentText(Controls::TYPE_LABELS[$field["type"]] ?? "Zusätzlicher Feldtyp"),
            ) .
            " · Version " .
            Html::e($field["version"] ?? "–") .
            ('</p><dl><dt>'.Html::e($this->contentText('Pflichtfeld')).'</dt><dd>') .
            Html::e($this->contentText($field["required"] ? "Ja" : "Nein")) .
            ('</dd><dt>'.Html::e($this->contentText('Untergrenze')).'</dt><dd>') .
            Html::e($field["min"] ?? $this->contentText("Keine")) .
            ('</dd><dt>'.Html::e($this->contentText('Obergrenze')).'</dt><dd>') .
            Html::e($field["max"] ?? $this->contentText("Keine")) .
            "</dd></dl>";
        if ($field["options"]) {
            $html .=
                "<p>" . Html::e($this->contentText("Auswahl")) . ": " .
                Html::e(implode(", ", $field["options"])) .
                "</p>";
        }
        $html .=
            $this->rule(
                "Anzeige",
                $field["show_when"] ?? null,
                $labels,
                "Immer sichtbar",
            ) .
            $this->rule(
                "Zusätzlich erforderlich",
                $field["required_when"] ?? null,
                $labels,
                "Keine zusätzliche Pflichtregel",
            );
        return $html;
    }
    private function rule(
        string $heading,
        ?array $rule,
        array $labels,
        string $empty,
    ): string {
        if (!$rule) {
            return "<p>" . Html::e($this->contentText($heading)) . ": " . Html::e($this->contentText($empty)) . "</p>";
        }
        $operators = [
            "eq" => "entspricht",
            "ne" => "entspricht nicht",
            "empty" => "ist leer",
            "filled" => "ist ausgefüllt",
            "contains" => "enthält",
            "gt" => "ist größer als",
            "gte" => "ist mindestens",
            "lt" => "ist kleiner als",
            "lte" => "ist höchstens",
        ];
        $rows = [];
        foreach ($rule["conditions"] as $condition) {
            $text =
                ($labels[$condition["field"]] ?? $condition["field"]) .
                " " .
                $this->contentText($operators[$condition["operator"]] ?? $condition["operator"]);
            if (array_key_exists("value", $condition)) {
                $text .=
                    " " .
                    (is_bool($condition["value"])
                        ? ($condition["value"]
                            ? $this->contentText("Ja")
                            : $this->contentText("Nein"))
                        : (string) ($condition["value"] ?? $this->contentText("Keine Angabe")));
            }
            $rows[] = "<span>" . Html::e($text) . "</span>";
        }
        return "<p>" .
            Html::e($this->contentText($heading)) .
            " · " .
            Html::e($this->contentText($rule["match"] === "all"
                ? "Alle Bedingungen"
                : "Mindestens eine Bedingung")) .
            "</p>" .
            $this->list($rows);
    }
    private function template(array $template, array $labels): string
    {
        $layouts = [
            "standard" => "Inhaltsseite",
            "news" => "Beitrag",
            "gallery" => "Galerie",
            "event" => "Veranstaltung",
        ];
        $html =
            "<p><strong>" .
            Html::e($template["label"]) .
            "</strong> · " .
            Html::e(isset($layouts[$template["renderer"]])?$this->contentText($layouts[$template["renderer"]]):$template["renderer"]) .
            " · Version " .
            Html::e($template["version"] ?? "–") .
            ('</p><p>'.Html::e($this->contentText('Angaben in Reihenfolge:')).'</p>') .
            $this->list(
                array_map(
                    static fn($key) => "<span>" .
                        Html::e($labels[$key] ?? $key) .
                        "</span>",
                    $template["fields"],
                ),
            );
        $groups = $template["groups"] ?? [];
        $paths = FieldGroups::paths($groups);
        foreach ($groups as $group) {
            $html .=
                '<div class="version-group"><strong>' .
                Html::e($paths[$group["key"]]) .
                "</strong><p>" .
                Html::text($group["description"]) .
                "</p><p>" .
                Html::e($this->contentText($group["collapsed"]
                    ? "Zunächst zugeklappt"
                    : "Zunächst geöffnet")) .
                " · " .
                $group["columns"] .
                " " .
                Html::e($this->contentText($group["columns"] === 1 ? "Spalte" : "Spalten")) .
                "</p>" .
                $this->list(
                    array_map(
                        static fn($key) => "<span>" .
                            Html::e($labels[$key] ?? $key) .
                            "</span>",
                        $group["fields"],
                    ),
                ) .
                "</div>";
        }
        return $html;
    }
    private function list(array $values): string
    {
        return $values
            ? '<ol class="version-values-list">' .
                    implode(
                        "",
                        array_map(
                            static fn($item) => "<li>" . $item . "</li>",
                            $values,
                        ),
                    ) .
                    "</ol>"
            : ('<p class="hint">'.Html::e($this->contentText('Keine Einträge')).'</p>');
    }
}
