<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use InvalidArgumentException;

final class Values
{
    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/D', $value)) {
            throw new InvalidArgumentException('Kommentar oder Originalseite auswählen.');
        }
        return $value;
    }

    public static function stream(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $value)) {
            throw new InvalidArgumentException('Kommentarfeld auswählen.');
        }
        return $value;
    }

    public static function text(mixed $value, int $maximum, bool $required = true): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Gültigen Kommentartext angeben.');
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (($required && $value === '') || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException('Textlänge prüfen.');
        }
        return $value;
    }

    public static function settings(array $input): array
    {
        if (array_diff(array_keys($input), ['enabled', 'moderation', 'anonymous', 'max_length'])) {
            throw new InvalidArgumentException('Kommentareinstellungen prüfen.');
        }
        $result = array_replace(['enabled'=>false, 'moderation'=>true, 'anonymous'=>false, 'max_length'=>5000], $input);
        foreach (['enabled','moderation','anonymous'] as $key) {
            if (!is_bool($result[$key])) throw new InvalidArgumentException('Kommentarfreigaben als Ja oder Nein angeben.');
        }
        if (!is_int($result['max_length']) || $result['max_length'] < 100 || $result['max_length'] > 10000) {
            throw new InvalidArgumentException('Kommentarlänge zwischen 100 und 10.000 Zeichen wählen.');
        }
        return $result;
    }

    /** Public DTOs never contain private addresses, hashes, client identity or moderator IDs. */
    public static function publicRow(array $row): array
    {
        return array_intersect_key($row, array_flip(['id','parent_id','depth','author_name','body','format','created_at']));
    }
}
