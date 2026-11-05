<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{CustomFields, FieldGroups};
use Kulturhus\Native\Web\Html;

/** Human-readable template layout editor and its closed form compiler. */
final class GroupForm
{
    public static function render(array $template, array $fields, ?callable $interfaceText=null): string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $groups = FieldGroups::normalize(
            $template["groups"] ?? [],
            $template["fields"],
        );
        $choices = ["" => $t("Ohne Gruppe")] + FieldGroups::paths($groups);
        $assignments = [];
        foreach ($groups as $group) {
            foreach ($group["fields"] as $field) {
                $assignments[$field] = $group["key"];
            }
        }
        $html =
            ('<div data-group-editor><fieldset><legend>' . Html::e($t('Formulargruppen')) . '</legend><p class="hint">' . Html::e($t('Ordne zusammengehörige Angaben in Gruppen. Bis zu 20 Gruppen mit drei Ebenen sind möglich. Die Inhalte der Felder bleiben erhalten.')) . '</p><div data-group-rows>');
        foreach ($groups as $index => $group) {
            $html .= self::row($index, $group, $choices, interfaceText:$t);
        }
        $html .=
            ('</div><button type="button" class="btn btn-outline theme-secondary" data-group-add>' . Html::e($t('Gruppe hinzufügen')) . '</button><p class="hint" role="status" aria-live="polite" data-group-status></p><template data-group-template>') .
            self::row(
                0,
                [
                    "key" => "",
                    "label" => "",
                    "description" => "",
                    "parent" => "",
                    "collapsed" => false,
                    "columns" => 1,
                ],
                ["" => $t("Ohne Gruppe")], interfaceText:$t) .
            ('</template></fieldset><fieldset><legend>' . Html::e($t('Zusätzliche Felder dieser Vorlage')) . '</legend><p class="hint">' . Html::e($t('Wähle die Angaben aus und ordne sie einer Gruppe zu. Die Pfeile ändern die Reihenfolge innerhalb ihrer Gruppe; Angaben ohne Gruppe stehen zuerst.')) . '</p><div data-group-fields>');
        $byKey = array_column($fields, null, "key");
        $ordered = array_values(
            array_unique([...$template["fields"], ...array_keys($byKey)]),
        );
        foreach ($ordered as $key) {
            if (!isset($byKey[$key])) {
                continue;
            }
            $field = $byKey[$key];
            $html .=
                '<div class="group-field-row" data-group-field="' .
                Html::e($key) .
                '"><label class="check"><input class="check" type="checkbox" name="fields[]" value="' .
                Html::e($key) .
                '"' .
                (in_array($key, $template["fields"], true) ? " checked" : "") .
                "> " .
                Html::e($field["label"]) .
                ' <span class="hint">(' .
                Html::e(Controls::TYPE_LABELS[$field["type"]]) .
                ")</span></label>" .
                Html::select(
                    "field_groups[" . $key . "]",
                    str_replace('{field}',$field['label'],$t('Gruppe für {field}')),
                    $choices,
                    $assignments[$key] ?? "",
                ) .
                self::orderButtons("field", $field["label"], interfaceText:$t) .
                "</div>";
        }
        if (!$fields) {
            $html .=
                ('<p class="hint">' . Html::e($t('Lege zuerst ein Feld an. Die vorhandenen Standardangaben stehen unabhängig davon zur Verfügung.')) . '</p>');
        }
        return $html . "</div></fieldset></div>";
    }

    public static function submitted(
        mixed $rows,
        mixed $assignments,
        array $selected,
        array $ownFields,
    ): array {
        if (
            !is_array($rows) ||
            count($rows) > FieldGroups::MAX_GROUPS ||
            !is_array($assignments)
        ) {
            throw new InvalidArgumentException(
                "Gültige Formulargruppen und Feldzuordnungen erforderlich.",
            );
        }
        $groups = [];
        foreach ($rows as $index => $row) {
            if (
                !preg_match("/\A(?:0|[1-9][0-9]?)\z/D", (string) $index) ||
                !is_array($row) ||
                array_diff(array_keys($row), [
                    "key",
                    "label",
                    "description",
                    "parent",
                    "collapsed",
                    "columns",
                ])
            ) {
                throw new InvalidArgumentException(
                    "Unbekannte Formulargruppeneinstellung.",
                );
            }
            $key = CustomFields::key($row["key"] ?? null);
            if (isset($groups[$key])) {
                throw new InvalidArgumentException(
                    "Jede Formulargruppe benötigt eine eigene Kennung.",
                );
            }
            $groups[$key] = $row + ["fields" => []];
        }
        foreach ($assignments as $field => $group) {
            CustomFields::key($field);
            if (
                !in_array($field, $ownFields, true) ||
                !is_string($group) ||
                ($group !== "" && !isset($groups[$group]))
            ) {
                throw new InvalidArgumentException(
                    "Angaben und Gruppen müssen zur eigenen Seitenvorlage gehören.",
                );
            }
        }
        foreach ($selected as $field) {
            CustomFields::key($field);
            $group = $assignments[$field] ?? "";
            if ($group !== "") {
                $groups[$group]["fields"][] = $field;
            }
        }
        return FieldGroups::normalize(array_values($groups), $selected);
    }

    private static function row(
        int $index,
        array $group,
        array $choices, ?callable $interfaceText=null): string {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $name = "groups[" . $index . "]";
        unset($choices[$group["key"]]);
        $choices[""] = $t("Keine übergeordnete Gruppe");
        return '<section class="group-editor-row" data-group-row data-group-key="' .
            Html::e($group["key"]) .
            '">' .
            Html::hidden($name . "[key]", $group["key"]) .
            Html::input(
                $name . "[label]",
                $t("Gruppenname"),
                $group["label"],
                "text",
                'required maxlength="160" data-group-label',
            ) .
            ('<label>' . Html::e($t('Hinweis für diese Gruppe')) . '<textarea class="form-control" name="') .
            $name .
            '[description]" maxlength="500" rows="2">' .
            Html::e($group["description"]) .
            '</textarea></label><div class="fields">' .
            Html::select(
                $name . "[parent]",
                $t("Übergeordnete Gruppe"),
                $choices,
                $group["parent"],
            ) .
            Html::select(
                $name . "[columns]",
                $t("Anordnung der Angaben"),
                [1 => $t("Eine Spalte"), 2 => $t("Zwei Spalten")],
                (string) $group["columns"],
            ) .
            '</div><label class="check"><input class="check" type="checkbox" name="' .
            $name .
            '[collapsed]" value="1"' .
            ($group["collapsed"] ? " checked" : "") .
            ('> ' . Html::e($t('Zu Beginn zugeklappt')) . '</label><div class="group-editor-actions">') .
            self::orderButtons("group", $group["label"], interfaceText:$t) .
            ('<button type="button" class="btn btn-outline theme-secondary" data-group-remove>' . Html::e($t('Gruppe entfernen')) . '</button></div></section>');
    }

    private static function orderButtons(string $kind, string $label, ?callable $interfaceText=null): string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        return '<div class="group-order-actions"><button type="button" class="btn btn-outline theme-secondary" data-' .
            $kind .
            '-move="up" aria-label="' .
            Html::e(str_replace('{name}',$label,$t('{name} nach oben'))) .
            '">↑</button><button type="button" class="btn btn-outline theme-secondary" data-' .
            $kind .
            '-move="down" aria-label="' .
            Html::e(str_replace('{name}',$label,$t('{name} nach unten'))) .
            '">↓</button></div>';
    }
}
