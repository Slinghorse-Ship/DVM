<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
final class Values
{
    public static function copy(mixed $value): mixed
    {
        if(!self::plain($value))throw new \DomainException('Nur einfache Erweiterungswerte sind erlaubt.');
        // Detach PHP references as well as ordinary copy-on-write arrays.
        return unserialize(serialize($value),['allowed_classes'=>false]);
    }
    public static function plain(mixed $value, int $depth = 0): bool
    {
        if ($depth > 12) return false;
        if (is_array($value)) {
            if (count($value)>2000) return false;
            foreach ($value as $item) if (!self::plain($item,$depth+1)) return false;
            return true;
        }
        return $value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value));
    }
    public static function localPath(string $path): bool
    {
        return $path === '' || (strlen($path)<=500 && preg_match('~^/(?!/)[a-zA-Z0-9/_\-.]*(?:\?[a-zA-Z0-9_=&.-]*)?(?:#[a-zA-Z0-9_-]+)?$~D',$path) === 1 && !in_array('..',explode('/',parse_url($path,PHP_URL_PATH) ?: ''),true));
    }
}
