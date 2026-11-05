<?php declare(strict_types=1);
require_once __DIR__.'/SourceFiles.php';
require_once dirname(__DIR__).'/native/bootstrap.php';
spl_autoload_register(static function(string $class): void {
    $prefix='Kulturhus\\Verein\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $file=\Kulturhus\Verein\SourceFiles::file($relative.'.php');
    if (is_file($file)) require_once $file;
}, true, true);
