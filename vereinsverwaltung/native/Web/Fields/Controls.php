<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{CustomFields,FieldValues,FieldPolicy};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{Html, ReferencePicker, RichTextEditor};
use PDO;

/** One canonical typed control used by every native page/template editor. */
final class Controls
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public const TYPE_LABELS = [
        "text" => "Kurzer Text",
        "textarea" => "Langer Text",
        "richtext" => "Formatierter Text",
        "integer" => "Ganzzahl",
        "decimal" => "Dezimalzahl",
        "boolean" => "Ja / Nein",
        "date" => "Datum",
        "url" => "Internetadresse",
        "email" => "E-Mail-Adresse",
        "select" => "Auswahl",
        "page-reference" => "Verknüpfte Seite",
        "media-reference" => "Verknüpftes Bild",
        "file-reference" => "Verknüpfte Datei",
        "datetime" => "Datum und Uhrzeit",
        "password" => "Geschütztes Passwort",
        "repeater" => "Wiederholbare Datensätze",
        "pagetable" => "Seitentabelle",
        "comments" => "Kommentare",
    ];
    private ReferencePicker $references;
    public function __construct(private PDO $db, private Access $access,private bool $prepared=false)
    {
        $this->references = new ReferencePicker($db, $access);
    }
    public function render(array $field, mixed $value, ?string $inputName = null, ?string $inputId = null): string
    {
        $key = CustomFields::key($field["key"]);
        if(!$this->prepared){$definitions=FieldPolicy::editorDefinitions($this->db,$this->access,[$key=>$field],[$key=>$field]);if(!isset($definitions[$key]))return '';$field=$definitions[$key];}
        $field=\Kulturhus\Native\Fieldtypes\Runtime::editorDefinitions($this->db,$this->access,[$key=>$field])[$key];
        $custom=null;
        if(isset($field['inputfield'])&&!FieldValues::collection($field)){$rendered=\Kulturhus\Native\Fieldtypes\Runtime::control($this->db,$this->access,$field,$inputName??'custom_fields['.$key.']',$value);$field=$rendered['field'];$custom=$rendered['html'];}
        if(($field['display']??'visible')==='hidden')return '';
        if(($field['display']??'visible')==='readonly'&&$field['type']==='richtext')return '<section class="model-field field-readonly" data-model-field="'.Html::e($key).'" aria-readonly="true"><h4>'.Html::e($field['label']).'</h4><div>'.\Kulturhus\Native\Shared\RichText::sanitize(is_string($value)?$value:'').('</div><span class="badge">' . Html::e($this->contentText('Nur lesbar')) . '</span></section>');
        $html=$custom===null?$this->control($field,$value,$inputName,$inputId):'<div class="model-field registered-field-control" data-model-field="'.Html::e($key).'">'.$custom.'</div>';
        return ($field['display']??'visible')==='readonly'?('<fieldset disabled class="field-readonly"><legend class="fs-6">' . Html::e($this->contentText('Nur lesbar')) . '</legend>').$html.'</fieldset>':$html;
    }
    private function control(array $field,mixed $value,?string $inputName,?string $inputId):string
    {
        $key=$field['key'];
        if($field['type']==='comments')return '<section class="model-field" data-model-field="'.Html::e($key).'"><h4>'.Html::e($field['label']).('</h4><span class="badge">' . Html::e($this->contentText('Kommentare')) . '</span></section>');
        if(FieldValues::collection($field))return (new CollectionControls($this->db,$this->access))->render($field,$value,$inputName??'custom_fields['.$key.']',$inputId??'native-custom-'.$key);
        if (!in_array($field["type"], CustomFields::TYPES, true)) {
            throw new InvalidArgumentException("Unbekannter Feldtyp.");
        }
        $html = "";
        $type = $field["type"];
        $passwordCanClear=$type==='password'&&!$field['required']&&$value!==null&&$value!=='';
        if($type==='password' && $value!==null && $value!=='')$field['required']=false;
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException(
                "Feldwerte müssen einzelne Werte sein.",
            );
        }
        $value = is_bool($value)
            ? ($value
                ? "1"
                : "0")
            : (string) ($value ?? "");
        $name = $inputName ?? "custom_fields[" . $key . "]";
        $id = $inputId ?? "native-custom-" . $key;
        $attributes =
            ' class="form-control" id="' .
            Html::e($id) .
            '" name="' .
            Html::e($name) .
            '" data-field-type="' .
            Html::e($type) .
            '" aria-describedby="' .
            Html::e($id . "-hint") .
            '"' .
            ($field["required"] ? " required" : "");
        if($type==='password'&&$value!=='')$attributes.=' data-password-stored="1"';
        if($type==='password')$attributes=str_replace('name="'.Html::e($name).'"','name="'.Html::e($name.'[value]').'"',$attributes);
        $html .=
            '<div class="model-field" data-model-field="' .
            Html::e($key) .
            '" data-field-label="' .
            Html::e($field["label"]) .
            '">' .
            ($type === "richtext"
                ? ""
                : '<label for="' .
                    Html::e($id) .
                    '">' .
                    Html::e($field["label"]) .
                    "</label>");
        if ($type === "richtext") {
            $html .= RichTextEditor::render($name, $value, $field["label"], [
                "required" => $field["required"],
                "min" => $field["min"],
                "max" => $field["max"],
                "hint_id" => $id . "-hint",
            ]);
        } elseif ($type === "boolean") {
            $html .= $this->optionsControl(
                $attributes,
                ["1" => $this->contentText("Ja"), "0" => $this->contentText("Nein")],
                $value,
                $field["required"],
            );
        } elseif ($type === "select") {
            $choices = [];
            foreach ($field["options"] as $option) {
                $choices[$option] = $option;
            }
            $html .= $this->optionsControl(
                $attributes,
                $choices,
                $value,
                $field["required"],
            );
        } elseif (
            in_array($type, ["page-reference", "media-reference", "file-reference"], true)
        ) {
            $html .= $this->references->render(
                match($type){'page-reference'=>'page','file-reference'=>'file',default=>'media'},
                $name,
                $id,
                $value,
                $field["required"],
                $id . "-hint",
            );
        } elseif ($type === "textarea") {
            $html .=
                "<textarea" .
                $attributes .
                ' rows="5"' .
                $this->lengthAttributes($field, 100000) .
                ">" .
                Html::e($value) .
                "</textarea>";
        } else {
            $inputType = match ($type) {
                "integer" => "number",
                "date" => "date",
                "datetime" => "datetime-local",
                "password" => "password",
                "url" => "url",
                "email" => "email",
                default => "text",
            };
            $extra = match ($type) {
                "integer" => ' step="1"' .
                    $this->rangeAttributes($field, -2147483648, 2147483647),
                "date" => $this->rangeAttributes($field),
                "decimal"
                    => ' inputmode="decimal" pattern="-?[0-9]{1,18}(?:[.,][0-9]{1,6})?"',
                "email" => $this->lengthAttributes($field, 254),
                "password" => ' autocomplete="new-password" minlength="8" maxlength="1024"',
                "file-reference" => ' pattern="[a-f0-9]{32}"',
                default => $this->lengthAttributes($field, 2000),
            };
            $html .=
                "<input" .
                $attributes .
                ' type="' .
                $inputType .
                '" value="' .
                Html::e($type==='password'?'':$value) .
                '"' .
                $extra .
                ">";
            if($passwordCanClear)$html.='<label class="check"><input type="checkbox" class="check" name="'.Html::e($name.'[clear]').('" value="1" data-password-clear> ' . Html::e($this->contentText('Gespeichertes Passwort entfernen')) . '</label>');
        }
        $hint =
            self::TYPE_LABELS[$type] .
            ($field["required"] ? " · Pflichtfeld" : " · optional");
        if ($type === "decimal") {
            $hint .= " · zum Beispiel 12,50; maximal 6 Nachkommastellen";
        }
        if ($field["min"] !== null || $field["max"] !== null) {
            $hint .=
                " · " .
                ($field["min"] !== null ? "mindestens " . $field["min"] : "") .
                ($field["min"] !== null && $field["max"] !== null ? ", " : "") .
                ($field["max"] !== null ? "höchstens " . $field["max"] : "");
            if (
                in_array(
                    $type,
                    ["text", "textarea", "richtext", "url", "email", "select"],
                    true,
                )
            ) {
                $hint .= " Zeichen";
            }
        }
        if ($type === "richtext") {
            $hint .= " · sichtbare Textzeichen, ohne Formatierungszeichen";
        }
        $html .=
            '<p id="' .
            Html::e($id . "-hint") .
            '" class="hint">' .
            Html::e($hint) .
            "</p></div>";
        return $html;
    }

    private function optionsControl(
        string $attributes,
        array $choices,
        string $selected,
        bool $required,
    ): string {
        $html =
            "<select" .
            $attributes .
            '><option value=""' .
            ($selected === "" ? " selected" : "") .
            ">" .
            Html::e($required ? $this->contentText("Bitte auswählen") : $this->contentText("Keine Angabe")) .
            "</option>";
        $known = false;
        foreach ($choices as $value => $label) {
            $value = (string) $value;
            $known = $known || $value === $selected;
            $html .=
                '<option value="' .
                Html::e($value) .
                '"' .
                ($value === $selected ? " selected" : "") .
                ">" .
                Html::e($label) .
                "</option>";
        }
        if ($selected !== "" && !$known) {
            $html .=
                '<option value="' .
                Html::e($selected) .
                ('" selected>' . Html::e($this->contentText('Auswahl nicht mehr verfügbar – bitte neu wählen')) . '</option>');
        }
        return $html . "</select>";
    }

    private function rangeAttributes(
        array $field,
        mixed $defaultMinimum = null,
        mixed $defaultMaximum = null,
    ): string {
        $html = "";
        foreach (
            ["min" => $defaultMinimum, "max" => $defaultMaximum]
            as $key => $default
        ) {
            $value = $field[$key] ?? $default;
            if ($value !== null) {
                $html .= " " . $key . '="' . Html::e($value) . '"';
            }
        }
        return $html;
    }

    private function lengthAttributes(array $field, int $maximum): string
    {
        $html =
            ' maxlength="' .
            Html::e(min($maximum, $field["max"] ?? $maximum)) .
            '"';
        if ($field["min"] !== null) {
            $html .= ' minlength="' . Html::e($field["min"]) . '"';
        }
        return $html;
    }
}
