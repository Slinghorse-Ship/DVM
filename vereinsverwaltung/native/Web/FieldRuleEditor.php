<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use InvalidArgumentException;
use Kulturhus\Native\Cms\FieldConditions;
use Kulturhus\Native\Shared\Access;
use PDO;

/** Human-readable conditions; HTML form input becomes the same closed server rule. */
final class FieldRuleEditor
{
    use ContentInterfaceText;
    public const LABELS = [
        "eq" => "Ist gleich",
        "ne" => "Ist ungleich",
        "empty" => "Ist leer",
        "filled" => "Ist ausgefüllt",
        "contains" => "Enthält Text",
        "gt" => "Ist größer / später",
        "gte" => "Ist mindestens / ab",
        "lt" => "Ist kleiner / früher",
        "lte" => "Ist höchstens / bis",
    ];
    public function __construct(private PDO $db, private Access $access) {}

    public static function submitted(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (
            !is_array($input) ||
            array_diff(array_keys($input), [
                "enabled",
                "match",
                "conditions",
            ]) ||
            !in_array($input["enabled"] ?? "0", ["0", "1"], true)
        ) {
            throw new InvalidArgumentException("Feldregel prüfen.");
        }
        if (($input["enabled"] ?? "0") === "0") {
            return null;
        }
        $rows = $input["conditions"] ?? null;
        if (!is_array($rows) || count($rows) > 10) {
            throw new InvalidArgumentException(
                "Höchstens zehn Feldbedingungen angeben.",
            );
        }
        $conditions = [];
        foreach ($rows as $index => $row) {
            if (
                !is_int($index) ||
                $index < 0 ||
                $index > 9 ||
                !is_array($row) ||
                array_diff(array_keys($row), ["field", "operator", "value"])
            ) {
                throw new InvalidArgumentException("Bedingungszeile prüfen.");
            }
            if (
                !is_string($row["field"] ?? null) ||
                !is_string($row["operator"] ?? null) ||
                !is_string($row["value"] ?? "")
            ) {
                throw new InvalidArgumentException(
                    "Bedingungsangaben müssen einzelne Werte sein.",
                );
            }
            if ($row["field"] === "") {
                continue;
            }
            $condition = [
                "field" => $row["field"],
                "operator" => $row["operator"],
            ];
            if (!in_array($row["operator"], ["empty", "filled"], true)) {
                $condition["value"] = $row["value"] ?? "";
            }
            $conditions[] = $condition;
        }
        return FieldConditions::rule([
            "match" => $input["match"] ?? null,
            "conditions" => $conditions,
        ]);
    }

    public function render(array $field, array $sources): string
    {
        $choices = ["" => $this->contentText("Angabe wählen")];
        foreach ($sources as $source) {
            if ($source["key"] !== $field["key"]) {
                $choices[$source["key"]] = $source["label"];
            }
        }
        $definitions = array_column($sources, null, "key");
        $html =
            ('<details class="field-rules"><summary>' . Html::e($this->contentText('Anzeige und Pflicht nach anderen Angaben')) . '</summary><p class="hint">' . Html::e($this->contentText('Bedingungsangaben müssen zur selben Seitenvorlage gehören. Ausgeblendete Angaben bleiben gespeichert. Ohne JavaScript bleiben alle Felder erreichbar.')) . '</p>');
        foreach (
            [
                "show_when" => $this->contentText("Nur anzeigen, wenn"),
                "required_when" => $this->contentText("Zusätzlich erforderlich, wenn"),
            ]
            as $kind => $label
        ) {
            $rule = $field[$kind] ?? null;
            $html .=
                '<fieldset data-condition-editor data-condition-kind="' .
                $kind .
                '"><legend>' .
                Html::e($this->contentText($label)) .
                '</legend><label class="check"><input class="check" type="checkbox" name="' .
                $kind .
                '[enabled]" value="1" data-condition-enabled' .
                ($rule ? " checked" : "") .
                ('> ' . Html::e($this->contentText('Bedingung verwenden')) . '</label><div data-condition-body>') .
                Html::select(
                    $kind . "[match]",
                    $this->contentText("Bedingungen verbinden"),
                    [
                        "all" => $this->contentText("Alle Bedingungen müssen passen"),
                        "any" => $this->contentText("Mindestens eine muss passen"),
                    ],
                    $rule["match"] ?? "all",
                ) .
                "<div data-condition-rows>";
            foreach (
                $rule["conditions"] ?? [
                    ["field" => "", "operator" => "eq", "value" => ""],
                ]
                as $index => $condition
            ) {
                $html .= $this->row(
                    $kind,
                    (string) $index,
                    $condition,
                    $choices,
                    $definitions,
                    $field["key"] ?: "new",
                );
            }
            $html .=
                '</div><button type="button" class="btn btn-solid theme-secondary" data-condition-add>Weitere Bedingung</button><template data-condition-row-template>' .
                $this->row(
                    $kind,
                    "__INDEX__",
                    ["field" => "", "operator" => "eq", "value" => ""],
                    $choices,
                    $definitions,
                    $field["key"] ?: "new",
                ) .
                "</template></div></fieldset>";
        }
        return $html . "</details>";
    }

    public function referenceTemplates(): string
    {
        $html = "";
        $picker = new ReferencePicker($this->db, $this->access);
        foreach (["page", "media"] as $kind) {
            $html .=
                '<template data-condition-reference-template="' .
                $kind .
                '">' .
                $picker->render($kind, "", "condition-reference-" . $kind, "") .
                "</template>";
        }
        return $html;
    }

    private function row(
        string $kind,
        string $index,
        array $condition,
        array $choices,
        array $definitions,
        string $target,
    ): string {
        $name = $kind . "[conditions][" . $index . "]";
        $source = $definitions[$condition["field"]] ?? null;
        $value = $condition["value"] ?? "";
        $value = is_bool($value) ? ($value ? "1" : "0") : (string) $value;
        $html =
            '<div class="condition-row" data-condition-row>' .
            Html::select(
                $name . "[field]",
                $this->contentText("Andere Angabe"),
                $choices,
                $condition["field"],
            );
        $operators = $this->contentOptions(self::LABELS);
        $html .=
            Html::select(
                $name . "[operator]",
                $this->contentText("Vergleich"),
                $operators,
                $condition["operator"],
            ) . "<div data-condition-value>";
        $id = "condition-" . $target . "-" . $kind . "-" . $index;
        if (
            $source &&
            in_array(
                $source["type"],
                ["page-reference", "media-reference"],
                true,
            )
        ) {
            $html .=
                '<label for="' .
                $id .
                ('">' . Html::e($this->contentText('Vergleichswert')) . '</label>') .
                (new ReferencePicker($this->db, $this->access))->render(
                    $source["type"] === "page-reference" ? "page" : "media",
                    $name . "[value]",
                    $id,
                    $value,
                );
        } elseif (
            $source &&
            $condition["operator"] !== "contains" &&
            in_array($source["type"], ["boolean", "select"], true)
        ) {
            $html .= Html::select(
                $name . "[value]",
                $this->contentText("Vergleichswert"),
                ["" => $this->contentText("Leer")] +
                    ($source["type"] === "boolean"
                        ? ["1" => $this->contentText("Ja"), "0" => $this->contentText("Nein")]
                        : array_combine(
                            $source["options"],
                            $source["options"],
                        )),
                $value,
            );
        } else {
            $html .= Html::input(
                $name . "[value]",
                $this->contentText("Vergleichswert"),
                $value,
                $source && $source["type"] === "date" ? "date" : "text",
                'maxlength="2000"',
            );
        }
        return $html .
            ('</div><button type="button" class="btn btn-solid theme-secondary" data-condition-remove>' . Html::e($this->contentText('Bedingung entfernen')) . '</button></div>');
    }
}
