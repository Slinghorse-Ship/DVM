<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Resolve module locations while the existing public class names stay stable. */
final class SourceFiles
{
    private static ?array $modules = null;

    public static function file(string $relative): string
    {
        if (!preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.php\z~D',$relative)) throw new \InvalidArgumentException('Ungültiger Quellpfad.');
        self::$modules ??= json_decode((string)file_get_contents(__DIR__.'/module-files.json'), true, 64, JSON_THROW_ON_ERROR);
        $relative = self::$modules[$relative] ?? $relative;
        return __DIR__.'/'.$relative;
    }
}
