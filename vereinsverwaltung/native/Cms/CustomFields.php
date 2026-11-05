<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;

/** Closed set of declarative field types. No executable code or free SQL is accepted. */
final class CustomFields
{
    public const TYPES = [
        "text",
        "textarea",
        "richtext",
        "integer",
        "decimal",
        "boolean",
        "date",
        "url",
        "email",
        "select",
        "page-reference",
        "media-reference",
        "file-reference",
        "datetime",
        "password",
        "repeater",
        "pagetable",
        "comments",
    ];

    public static function key(mixed $value): string
    {
        if (
            !is_string($value) ||
            !preg_match("/\A[a-z][a-z0-9_]{0,63}\z/D", $value)
        ) {
            throw new InvalidArgumentException(
                "Feld-/Template-Schlüssel: Kleinbuchstaben, Zahlen und Unterstriche; maximal 64 Zeichen.",
            );
        }
        return $value;
    }

    public static function definition(array $input): array
    {
        if (
            array_diff(array_keys($input), [
                "key",
                "label",
                "type",
                "required",
                "min",
                "max",
                "options",
                "version",
                "show_when",
                "required_when",
                "multiple",
                "max_items",
                "fields",
                "access",
                "fieldtype",
                "inputfield",
            ])
        ) {
            throw new InvalidArgumentException(
                "Unbekannte Felddefinitions-Eigenschaft.",
            );
        }
        $key = self::key($input["key"] ?? null);
        $label = self::text($input["label"] ?? "", 160);
        if ($label === "") {
            throw new InvalidArgumentException("Feldbezeichnung erforderlich.");
        }
        $type = $input["type"] ?? "";
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unbekannter Feldtyp.");
        }
        $required = self::boolean($input["required"] ?? false);
        if ($type==='comments' && ($required || !empty($input['multiple']) || !empty($input['fields']) || !empty($input['options']) || ($input['min']??null)!==null && ($input['min']??'')!=='' || ($input['max']??null)!==null && ($input['max']??'')!=='' || !empty($input['show_when']) || !empty($input['required_when']))) {
            throw new InvalidArgumentException('Kommentarfelder werden als eigener Gesprächsbereich ohne Pflichtwert, Grenzen, Bedingungen oder Unterfelder geführt.');
        }
        $options = $input["options"] ?? [];
        if (
            !is_array($options) ||
            !array_is_list($options) ||
            count($options) > 200
        ) {
            throw new InvalidArgumentException(
                "Auswahloptionen müssen eine Liste mit maximal 200 Einträgen sein.",
            );
        }
        $options = array_map(
            static fn($option): string => self::text($option, 200),
            $options,
        );
        if (
            ($type === "select" &&
                (!$options || in_array("", $options, true))) ||
            ($type !== "select" && $options) ||
            count(array_unique($options)) !== count($options)
        ) {
            throw new InvalidArgumentException(
                "Eindeutige, nicht leere Optionen sind ausschließlich für Auswahlfelder erforderlich.",
            );
        }
        $min = $input["min"] ?? null;
        $max = $input["max"] ?? null;
        $min = $min === "" ? null : $min;
        $max = $max === "" ? null : $max;
        foreach (["min" => &$min, "max" => &$max] as &$bound) {
            if ($bound === null) {
                continue;
            }
            $bound = match ($type) {
                "text",
                "textarea",
                "richtext",
                "url",
                "email",
                "select"
                    => self::integer(
                    $bound,
                    0,
                    in_array($type, ["textarea", "richtext"], true)
                        ? 100000
                        : 2000,
                ),
                "integer" => self::integer($bound),
                "decimal" => self::decimal($bound),
                "date" => self::date($bound),
                default => throw new InvalidArgumentException(
                    "Dieser Feldtyp unterstützt keine min/max-Grenzen.",
                ),
            };
        }
        unset($bound);
        if (
            $min !== null &&
            $max !== null &&
            ($type === "decimal"
                ? self::compareDecimal($min, $max) > 0
                : $min > $max)
        ) {
            throw new InvalidArgumentException(
                "Die Untergrenze darf die Obergrenze nicht überschreiten.",
            );
        }
        $definition = [
            "key" => $key,
            "label" => $label,
            "type" => $type,
            "required" => $required,
            "min" => $min,
            "max" => $max,
            "options" => $options,
        ];
        $multiple = self::boolean($input['multiple'] ?? false);
        if ($multiple && in_array($type, ['repeater', 'pagetable', 'password'], true)) {
            throw new InvalidArgumentException('Dieser Feldtyp verwaltet seine Einträge selbst.');
        }
        if ($multiple) $definition['multiple'] = true;
        if ($multiple || in_array($type, ['repeater', 'pagetable'], true)) {
            $definition['max_items'] = self::integer($input['max_items'] ?? 50, 1, 50);
        } elseif (isset($input['max_items']) && $input['max_items'] !== '' && $input['max_items'] !== null) {
            throw new InvalidArgumentException('Eine Eintragsgrenze benötigt ein Mehrfach- oder Datensatzfeld.');
        }
        $children = $input['fields'] ?? [];
        if (!is_array($children) || !array_is_list($children) || count($children) > 20) {
            throw new InvalidArgumentException('Datensatzfelder: höchstens 20 eindeutige Feldkennungen auswählen.');
        }
        $children=array_map(self::key(...),$children);
        if(count(array_unique($children))!==count($children))throw new InvalidArgumentException('Datensatzfelder dürfen nicht doppelt zugeordnet werden.');
        if ($type === 'repeater') {
            if (!$children) throw new InvalidArgumentException('Mindestens ein Feld für den Datensatz auswählen.');
            $definition['fields'] = array_map(self::key(...), $children);
        } elseif ($children) throw new InvalidArgumentException('Unterfelder sind nur bei Datensatzfeldern möglich.');
        foreach (["show_when", "required_when"] as $kind) {
            if (array_key_exists($kind, $input)) {
                $rule = FieldConditions::rule($input[$kind]);
                if ($rule !== null) {
                    $definition[$kind] = $rule;
                }
            }
        }
        if(array_key_exists('access',$input))$definition['access']=FieldPolicy::normalize($input['access']);
        foreach(['fieldtype','inputfield']as$provider)if(array_key_exists($provider,$input)){
            if($input[$provider]!==null&&!in_array($type,\Kulturhus\Native\Fieldtypes\Definition::STORAGE_TYPES,true))throw new InvalidArgumentException('Dieser native Feldtyp erhält keine ausführbare Erweiterungsbindung.');
            $definition[$provider]=$input[$provider]===null?null:\Kulturhus\Native\Fieldtypes\Binding::normalize($input[$provider]);
        }
        return $definition;
    }

    public static function value(
        array $definition,
        mixed $value,
        bool $forSearch = false,
    ): mixed {
        if (($definition['type']??null)==='comments') {
            if ($value!==null) throw new InvalidArgumentException('Kommentare ausschließlich im zugeordneten Gesprächsbereich bearbeiten.');
            return null;
        }
        if (!empty($definition['multiple']) || in_array($definition['type'], ['repeater', 'pagetable'], true)) {
            return FieldValues::normalize($definition, $value, $forSearch);
        }
        if ($value === null || $value === "") {
            if ($definition["required"] && !$forSearch) {
                throw new InvalidArgumentException(
                    $definition["label"] . ": Pflichtfeld fehlt.",
                );
            }
            return null;
        }
        $type = $definition["type"];
        $value = match ($type) {
            "text" => self::text($value, 2000),
            "textarea" => self::text($value, 100000),
            "richtext" => RichTextField::value($value),
            "integer" => self::integer($value),
            "decimal" => self::decimal($value),
            "boolean" => self::boolean($value),
            "date" => self::date($value),
            "datetime" => FieldValues::dateTime($value),
            "password" => FieldValues::password($value),
            "url" => self::url($value),
            "email" => self::email($value),
            "select" => self::option($value, $definition["options"]),
            "page-reference", "media-reference", "file-reference" => self::reference($value),
            default => throw new InvalidArgumentException(
                "Unbekannter Feldtyp.",
            ),
        };
        if ($value === "") {
            if ($definition["required"] && !$forSearch) {
                throw new InvalidArgumentException(
                    $definition["label"] . ": Pflichtfeld fehlt.",
                );
            }
            return null;
        }
        // Selectors may intentionally search beyond current validation bounds.
        if (!$forSearch) {
            $lengthType = in_array(
                $type,
                ["text", "textarea", "richtext", "url", "email", "select"],
                true,
            );
            $compared = $lengthType
                ? mb_strlen(
                    $type === "richtext"
                        ? RichTextField::plain($value)
                        : $value,
                    "UTF-8",
                )
                : $value;
            foreach (["min", "max"] as $bound) {
                if ($definition[$bound] === null) {
                    continue;
                }
                $comparison =
                    $type === "decimal"
                        ? self::compareDecimal($value, $definition[$bound])
                        : $compared <=> $definition[$bound];
                if (
                    ($bound === "min" && $comparison < 0) ||
                    ($bound === "max" && $comparison > 0)
                ) {
                    throw new InvalidArgumentException(
                        $definition["label"] .
                            ": Wert außerhalb der Feldgrenzen.",
                    );
                }
            }
        }
        return $forSearch && $type === "richtext"
            ? RichTextField::plain($value)
            : $value;
    }

    public static function text(mixed $value, int $limit): string
    {
        if (
            !is_string($value) ||
            !preg_match("//u", $value) ||
            mb_strlen($value, "UTF-8") > $limit ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException(
                "Ungültiger UTF-8-Text oder Textlänge.",
            );
        }
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function integer(
        mixed $value,
        int $min = -2147483648,
        int $max = 2147483647,
    ): int {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match("/\A-?(?:0|[1-9][0-9]*)\z/D", (string) $value)
        ) {
            throw new InvalidArgumentException(
                "Ganzzahl ohne Bruch oder Exponent erforderlich.",
            );
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => $min, "max_range" => $max],
        ]);
        if ($result === false) {
            throw new InvalidArgumentException(
                "Ganzzahl außerhalb des zulässigen Bereichs.",
            );
        }
        return $result;
    }

    /** Exact fixed-point decimal: 18 integer and 6 fractional digits, returned as string. */
    private static function decimal(mixed $value): string
    {
        if (
            (!is_string($value) && !is_int($value)) ||
            !preg_match(
                "/\A(-?)([0-9]{1,18})(?:\.([0-9]{1,6}))?\z/D",
                (string) $value,
                $match,
            )
        ) {
            throw new InvalidArgumentException(
                "Dezimalzahl als Zeichenfolge mit höchstens 18 Vor- und 6 Nachkommastellen erforderlich.",
            );
        }
        $whole = ltrim($match[2], "0") ?: "0";
        $fraction = rtrim($match[3] ?? "", "0");
        $sign = $whole === "0" && $fraction === "" ? "" : $match[1];
        return $sign . $whole . ($fraction !== "" ? "." . $fraction : "");
    }

    public static function compareDecimal(string $left, string $right): int
    {
        $negativeLeft = str_starts_with($left, "-");
        $negativeRight = str_starts_with($right, "-");
        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }
        $parts = static function (string $value): string {
            [$whole, $fraction] = array_pad(
                explode(".", ltrim($value, "-"), 2),
                2,
                "",
            );
            return str_pad($whole, 18, "0", STR_PAD_LEFT) .
                str_pad($fraction, 6, "0");
        };
        return strcmp($parts($left), $parts($right)) * ($negativeLeft ? -1 : 1);
    }

    private static function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 1, 0, "1", "0"], true)) {
            throw new InvalidArgumentException(
                "Boolescher Wert muss true/false oder 1/0 sein.",
            );
        }
        return $value === true || $value === 1 || $value === "1";
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Datum muss Text sein.");
        }
        $date = \DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $value,
            new \DateTimeZone("UTC"),
        );
        if (!$date || $date->format("Y-m-d") !== $value) {
            throw new InvalidArgumentException(
                "Gültiges Datum im Format JJJJ-MM-TT erforderlich.",
            );
        }
        return $value;
    }

    private static function url(mixed $value): string
    {
        $value = self::text($value, 2000);
        $parsed = parse_url($value);
        if (
            !$parsed ||
            !in_array($parsed["scheme"] ?? "", ["http", "https"], true) ||
            empty($parsed["host"]) ||
            isset($parsed["user"]) ||
            isset($parsed["pass"]) ||
            preg_match("/[\s\\\\]/", $value) ||
            !filter_var($value, FILTER_VALIDATE_URL)
        ) {
            throw new InvalidArgumentException(
                "URL muss eine HTTP(S)-Adresse ohne Zugangsdaten sein.",
            );
        }
        return $value;
    }

    private static function email(mixed $value): string
    {
        $value = self::text($value, 254);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Ungültige E-Mail-Adresse.");
        }
        return $value;
    }

    private static function option(mixed $value, array $options): string
    {
        if (!is_string($value) || !in_array($value, $options, true)) {
            throw new InvalidArgumentException(
                "Wert ist keine erlaubte Auswahloption.",
            );
        }
        return $value;
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || !preg_match("/\A[a-f0-9]{32}\z/D", $value)) {
            throw new InvalidArgumentException(
                "Ungültige Seiten-/Medienreferenz.",
            );
        }
        return $value;
    }
}
