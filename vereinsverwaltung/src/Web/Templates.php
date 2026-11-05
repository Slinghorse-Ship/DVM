<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;

/** Stable template keys; physical files belong to their respective modules. */
final class Templates
{
    public static function path(string $key): string
    {
        static $files = null;
        $directory = dirname(__DIR__, 2) . '/views/';
        $files ??= json_decode(file_get_contents($directory . 'templates.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!preg_match('/^[a-z0-9-]+$/D', $key) || !isset($files[$key])) {
            throw new DomainException('Diese Ansicht ist nicht verfügbar.');
        }
        return $directory . $files[$key];
    }
}
