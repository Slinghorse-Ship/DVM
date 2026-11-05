<?php declare(strict_types=1);
/* Project-authored modules. No external bootstrap or transport. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kulturhus\\Native\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]*$/D', $relative)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
}, true, true);
