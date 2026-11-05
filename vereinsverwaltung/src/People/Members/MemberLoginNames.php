<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Personal login names are independent of an address shared by a family. */
final class MemberLoginNames
{
    public static function fromName(string $name): string
    {
        $name = strtr(trim($name), [
            "Ä" => "ae",
            "Ö" => "oe",
            "Ü" => "ue",
            "ä" => "ae",
            "ö" => "oe",
            "ü" => "ue",
            "ß" => "ss",
        ]);
        $name = strtolower(
            iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name) ?: $name,
        );
        $parts = preg_split("/\s+/", $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name =
            count($parts) > 1
                ? $parts[0] . "." . $parts[count($parts) - 1]
                : $parts[0] ?? "mitglied";
        return substr(
            trim(preg_replace("/[^a-z0-9.-]+/", "", $name) ?? "", ". -") ?:
            "mitglied",
            0,
            80,
        );
    }
}
