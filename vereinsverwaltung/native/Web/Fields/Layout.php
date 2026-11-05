<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;
use Kulturhus\Native\Cms\FieldGroups;
use Kulturhus\Native\Web\Html;

/** Structured editor layout around the original controls, without renaming or copying any value. */
final class Layout
{
    public static function render(array $template, array $controls): string
    {
        if (empty($template["groups"])) {
            return implode("", $controls);
        }
        $groups = FieldGroups::normalize(
            $template["groups"],
            $template["fields"],
        );
        $assigned = [];
        $children = [];
        $byKey = [];
        foreach ($groups as $group) {
            $byKey[$group["key"]] = $group;
            $children[$group["parent"]][] = $group["key"];
            foreach ($group["fields"] as $field) {
                $assigned[$field] = true;
            }
        }
        $html = "";
        foreach ($controls as $field => $control) {
            if (!isset($assigned[$field])) {
                $html .= $control;
            }
        }
        $render = function (string $key) use (
            &$render,
            $byKey,
            $children,
            $controls,
            $template,
        ): string {
            $group = $byKey[$key];
            $body = "";
            foreach ($group["fields"] as $field) {
                $body .= $controls[$field];
            }
            $nested = "";
            foreach ($children[$key] ?? [] as $child) {
                $nested .= $render($child);
            }
            if ($body === "" && $nested === "") {
                return "";
            }
            $id = "native-group-" . $template["key"] . "-" . $key;
            return '<details class="field-group" data-field-group="' .
                Html::e($key) .
                '"' .
                (!$group["collapsed"] ? " open" : "") .
                '><summary id="' .
                Html::e($id) .
                '"><span>' .
                Html::e($group["label"]) .
                "</span></summary>" .
                ($group["description"] !== ""
                    ? '<p class="hint field-group-description">' .
                        Html::text($group["description"]) .
                        "</p>"
                    : "") .
                '<div class="field-group-controls" data-group-columns="' .
                $group["columns"] .
                '">' .
                $body .
                "</div>" .
                ($nested !== ""
                    ? '<div class="field-group-nested">' . $nested . "</div>"
                    : "") .
                "</details>";
        };
        foreach ($children[""] ?? [] as $key) {
            $html .= $render($key);
        }
        return $html;
    }
}
