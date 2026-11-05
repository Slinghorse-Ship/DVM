<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Versioned form structure; each assigned original field appears once, with at most three levels. */
final class FieldGroups
{
    public const MAX_GROUPS = 20;
    public const MAX_DEPTH = 3;
    public static function normalize(mixed $input, array $fields): array
    {
        if (
            !is_array($input) ||
            !array_is_list($input) ||
            count($input) > self::MAX_GROUPS
        ) {
            throw new InvalidArgumentException(
                "Formulargruppen müssen eine Liste mit höchstens 20 Gruppen sein.",
            );
        }
        $groups = [];
        $assigned = [];
        foreach ($input as $row) {
            if (
                !is_array($row) ||
                array_is_list($row) ||
                array_diff(array_keys($row), [
                    "key",
                    "label",
                    "description",
                    "parent",
                    "collapsed",
                    "columns",
                    "fields",
                ])
            ) {
                throw new InvalidArgumentException(
                    "Unbekannte Formulargruppeneinstellung.",
                );
            }
            $key = CustomFields::key($row["key"] ?? null);
            $label = CustomFields::text($row["label"] ?? "", 160);
            $description = CustomFields::text($row["description"] ?? "", 500);
            $parent = array_key_exists("parent", $row) ? $row["parent"] : "";
            if (!is_string($parent)) {
                throw new InvalidArgumentException(
                    "Übergeordnete Gruppe wählen.",
                );
            }
            if ($parent !== "") {
                CustomFields::key($parent);
            }
            $collapsed = array_key_exists("collapsed", $row)
                ? $row["collapsed"]
                : false;
            $columns = array_key_exists("columns", $row) ? $row["columns"] : 1;
            if (
                isset($groups[$key]) ||
                $label === "" ||
                !in_array($collapsed, [true, false, 1, 0, "1", "0"], true) ||
                !in_array($columns, [1, 2, "1", "2"], true)
            ) {
                throw new InvalidArgumentException(
                    "Eindeutige Gruppe mit Bezeichnung, Aufklappzustand und einer oder zwei Spalten angeben.",
                );
            }
            $members = array_key_exists("fields", $row) ? $row["fields"] : [];
            if (
                !is_array($members) ||
                !array_is_list($members) ||
                count($members) > 100
            ) {
                throw new InvalidArgumentException(
                    "Gruppenangaben müssen eine Liste sein.",
                );
            }
            foreach ($members as $field) {
                CustomFields::key($field);
                if (
                    !in_array($field, $fields, true) ||
                    isset($assigned[$field])
                ) {
                    throw new InvalidArgumentException(
                        "Jede Angabe einer Gruppe muss einmalig zur selben Seitenvorlage gehören.",
                    );
                }
                $assigned[$field] = true;
            }
            $groups[$key] = [
                "key" => $key,
                "label" => $label,
                "description" => $description,
                "parent" => $parent,
                "collapsed" => in_array($collapsed, [true, 1, "1"], true),
                "columns" => (int) $columns,
                "fields" => $members,
            ];
        }
        $depths = [];
        $active = [];
        $visit = function (string $key) use (
            &$visit,
            $groups,
            &$depths,
            &$active,
        ): int {
            if (isset($active[$key])) {
                throw new InvalidArgumentException(
                    "Formulargruppen dürfen keinen Kreis bilden.",
                );
            }
            if (isset($depths[$key])) {
                return $depths[$key];
            }
            $parent = $groups[$key]["parent"];
            if ($parent !== "" && !isset($groups[$parent])) {
                throw new InvalidArgumentException(
                    "Die übergeordnete Formulargruppe gehört nicht zu dieser Vorlage.",
                );
            }
            $active[$key] = true;
            $depth = $parent === "" ? 1 : $visit($parent) + 1;
            unset($active[$key]);
            if ($depth > self::MAX_DEPTH) {
                throw new InvalidArgumentException(
                    "Formulargruppen dürfen höchstens drei Ebenen haben.",
                );
            }
            return $depths[$key] = $depth;
        };
        foreach (array_keys($groups) as $key) {
            $visit($key);
        }
        return array_values($groups);
    }

    public static function paths(array $groups): array
    {
        $byKey = array_column($groups, null, "key");
        $paths = [];
        $path = function (string $key) use (&$path, $byKey, &$paths): string {
            return $paths[$key] ??=
                $byKey[$key]["parent"] === ""
                    ? $byKey[$key]["label"]
                    : $path($byKey[$key]["parent"]) .
                        " / " .
                        $byKey[$key]["label"];
        };
        foreach (array_keys($byKey) as $key) {
            $path($key);
        }
        return $paths;
    }
}
