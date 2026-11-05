<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Declarative form dependencies. Stored values remain typed and are never discarded when hidden. */
final class FieldConditions
{
    public const OPERATORS = [
        "eq",
        "ne",
        "empty",
        "filled",
        "contains",
        "gt",
        "gte",
        "lt",
        "lte",
    ];
    public static function rule(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (
            !is_array($input) ||
            array_is_list($input) ||
            array_diff(array_keys($input), ["match", "conditions"]) ||
            !in_array($input["match"] ?? null, ["all", "any"], true) ||
            !is_array($input["conditions"] ?? null) ||
            !array_is_list($input["conditions"]) ||
            !$input["conditions"] ||
            count($input["conditions"]) > 10
        ) {
            throw new InvalidArgumentException(
                "Feldregel: Alle oder mindestens eine von 1–10 Bedingungen wählen.",
            );
        }
        $rows = [];
        foreach ($input["conditions"] as $row) {
            if (
                !is_array($row) ||
                array_is_list($row) ||
                array_diff(array_keys($row), ["field", "operator", "value"]) ||
                !in_array($row["operator"] ?? null, self::OPERATORS, true)
            ) {
                throw new InvalidArgumentException(
                    "Feld und gültigen Vergleich für die Bedingung wählen.",
                );
            }
            $condition = [
                "field" => CustomFields::key($row["field"] ?? null),
                "operator" => $row["operator"],
            ];
            if (in_array($row["operator"], ["empty", "filled"], true)) {
                if (array_key_exists("value", $row)) {
                    throw new InvalidArgumentException(
                        "Leer oder ausgefüllt benötigt keinen Vergleichswert.",
                    );
                }
            } else {
                if (
                    !array_key_exists("value", $row) ||
                    (!is_string($row["value"]) &&
                        !is_int($row["value"]) &&
                        !is_bool($row["value"]) &&
                        $row["value"] !== null)
                ) {
                    throw new InvalidArgumentException(
                        "Einen einzelnen Vergleichswert angeben.",
                    );
                }
                $condition["value"] = is_string($row["value"])
                    ? CustomFields::text($row["value"], 2000)
                    : $row["value"];
            }
            $rows[] = $condition;
        }
        return ["match" => $input["match"], "conditions" => $rows];
    }

    /** Bind comparison literals to current own source types without mutating historical definitions. */
    public static function bind(array $field, array $definitions): array
    {
        foreach (["show_when", "required_when"] as $kind) {
            if (!isset($field[$kind])) {
                continue;
            }
            foreach ($field[$kind]["conditions"] as &$condition) {
                $source = $definitions[$condition["field"]] ?? null;
                if (!$source || $condition["field"] === $field["key"]) {
                    throw new InvalidArgumentException(
                        $field["label"] .
                            ": Eine andere vorhandene Vereinsangabe als Bedingung wählen.",
                    );
                }
                self::checkOperator($source, $condition["operator"]);
                if (array_key_exists("value", $condition)) {
                    $condition["value"] = self::constant(
                        $source,
                        $condition["value"],
                        $condition["operator"],
                    );
                    if (
                        $condition["value"] === null &&
                        !in_array($condition["operator"], ["eq", "ne"], true)
                    ) {
                        throw new InvalidArgumentException(
                            $source["label"] .
                                ": Für diesen Vergleich einen Wert angeben.",
                        );
                    }
                }
            }
            unset($condition);
        }
        return $field;
    }

    public static function validate(array $definitions, array $templates): void
    {
        $edges = [];
        foreach ($definitions as $key => $field) {
            self::bind($field, $definitions);
            $edges[$key] = [];
            foreach (["show_when", "required_when"] as $kind) {
                foreach ($field[$kind]["conditions"] ?? [] as $condition) {
                    $edges[$key][] = $condition["field"];
                }
            }
        }
        $active = [];
        $done = [];
        $visit = function (string $key) use (
            &$visit,
            &$active,
            &$done,
            $edges,
        ): void {
            if (isset($active[$key])) {
                throw new InvalidArgumentException(
                    "Feldbedingungen dürfen keinen Kreis bilden.",
                );
            }
            if (isset($done[$key])) {
                return;
            }
            $active[$key] = true;
            foreach ($edges[$key] as $source) {
                $visit($source);
            }
            unset($active[$key]);
            $done[$key] = true;
        };
        foreach (array_keys($edges) as $key) {
            $visit($key);
        }
        foreach ($templates as $template) {
            self::template($template, $definitions);
        }
    }

    public static function template(array $template, array $definitions): void
    {
        foreach ($template["fields"] as $key) {
            foreach (["show_when", "required_when"] as $kind) {
                foreach (
                    $definitions[$key][$kind]["conditions"] ?? []
                    as $condition
                ) {
                    if (
                        !in_array(
                            $condition["field"],
                            $template["fields"],
                            true,
                        )
                    ) {
                        throw new InvalidArgumentException(
                            $template["label"] .
                                ": Auch das Bedingungsfeld " .
                                ($definitions[$condition["field"]]["label"] ??
                                    $condition["field"]) .
                                " zur Vorlage hinzufügen.",
                        );
                    }
                }
            }
        }
    }

    public static function values(array $definitions, array $input): array
    {
        $values = [];
        foreach ($definitions as $key => $field) {
            // Keep the original contracts and error order for fields without dependency rules.
            $conditional =
                isset($field["show_when"]) || isset($field["required_when"]);
            $values[$key] = CustomFields::value(
                $conditional
                    ? array_replace($field, ["required" => false])
                    : $field,
                $input[$key] ?? null,
            );
        }
        foreach (self::states($definitions, $values) as $key => $state) {
            if ($state["required"] && ($values[$key] === null || $values[$key] === [])) {
                throw new InvalidArgumentException(
                    $definitions[$key]["label"] . ": Pflichtfeld fehlt.",
                );
            }
        }
        return $values;
    }

    public static function states(array $definitions, array $values): array
    {
        $typed = [];
        try {
            foreach ($definitions as $key => $field) {
                $typed[$key] = CustomFields::value(
                    $field['type']==='richtext'?array_replace($field,['required'=>false,'min'=>null,'max'=>null]):$field,
                    $values[$key] ?? null,
                    $field['type']!=='richtext',
                );
            }
        } catch (InvalidArgumentException) {
            // An older value may no longer fit a changed type: show it so the editor can correct it.
            return array_map(
                static fn($field) => [
                    "visible" => true,
                    "required" => $field["required"],
                ],
                $definitions,
            );
        }
        $values = $typed;
        $states = [];
        foreach ($definitions as $key => $field) {
            $visible =
                !isset($field["show_when"]) ||
                self::matches($field["show_when"], $definitions, $values);
            $states[$key] = [
                "visible" => $visible,
                "required" =>
                    $visible &&
                    ($field["required"] ||
                        (isset($field["required_when"]) &&
                            self::matches(
                                $field["required_when"],
                                $definitions,
                                $values,
                            ))),
            ];
        }
        return $states;
    }

    public static function matches(
        array $rule,
        array $definitions,
        array $values,
    ): bool {
        $results = [];
        foreach ($rule["conditions"] as $condition) {
            $source = $definitions[$condition["field"]];
            $value = $values[$condition["field"]] ?? null;
            $op = $condition["operator"];
            if ($source["type"] === "richtext" && $value !== null) {
                $value = RichTextField::plain($value);
            }
            if ($op === "empty" || $op === "filled") {
                $results[] =
                    $op === "empty"
                        ? $value === null || $value === "" || $value === []
                        : $value !== null && $value !== "" && $value !== [];
                continue;
            }
            $expected = self::constant($source, $condition["value"],$op);
            if ($op === "contains") {
                $results[] = $value !== null && str_contains($value, $expected);
                continue;
            }
            if ($value === null || $expected === null) {
                $results[] = match ($op) {
                    "eq" => $value === $expected,
                    "ne" => $value !== $expected,
                    default => false,
                };
                continue;
            }
            $compare =
                $source["type"] === "decimal"
                    ? CustomFields::compareDecimal($value, $expected)
                    : ($source["type"] === "integer"
                        ? $value <=> $expected
                        : strcmp(
                            is_bool($value) ? ($value ? "1" : "0") : $value,
                            is_bool($expected)
                                ? ($expected
                                    ? "1"
                                    : "0")
                                : $expected,
                        ));
            $results[] = match ($op) {
                "eq" => $compare === 0,
                "ne" => $compare !== 0,
                "gt" => $compare > 0,
                "gte" => $compare >= 0,
                "lt" => $compare < 0,
                "lte" => $compare <= 0,
            };
        }
        return $rule["match"] === "all"
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    private static function checkOperator(array $source, string $op): void
    {
        if($source['type']==='comments')throw new InvalidArgumentException('Kommentare sind kein Eingabewert für Formularbedingungen.');
        if ((FieldValues::collection($source) || $source['type']==='password') && !in_array($op,['empty','filled'],true)) {
            throw new InvalidArgumentException($source['label'].': Datensatz-, Mehrfach- und Passwortfelder unterstützen nur leer/ausgefüllt als Formularbedingung.');
        }
        if (
            in_array($op, ["gt", "gte", "lt", "lte"], true) &&
            !in_array($source["type"], ["integer", "decimal", "date"], true)
        ) {
            throw new InvalidArgumentException(
                $source["label"] .
                    ": Größer/kleiner nur für Zahlen oder Datum verwenden.",
            );
        }
        if (
            $op === "contains" &&
            !in_array(
                $source["type"],
                ["text", "textarea", "richtext", "url", "email", "select"],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                $source["label"] . ": Enthält benötigt Text.",
            );
        }
    }

    private static function constant(array $source, mixed $value,string $operator='eq'): mixed
    {
        if ($source["type"] === "decimal" && is_string($value)) {
            $value = str_replace(",", ".", $value);
        }
        if ($source["type"] === "richtext"||$operator==='contains') {
            $text = $value === null ? null : CustomFields::text($value, 2000);
            return $text === "" ? null : $text;
        }
        return CustomFields::value($source, $value, true);
    }
}
