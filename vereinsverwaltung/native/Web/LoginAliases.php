<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Fixed, read-only legacy entry points; account authorization stays at the destination. */
final class LoginAliases
{
    public static function target(string $method, string $path, array $query): ?string
    {
        if (!in_array($method, ['GET', 'HEAD'], true) || rtrim($path, '/') !== '/login') return null;
        $forgot = array_key_exists('forgot', $query);
        $security = array_key_exists('security', $query);
        // Ambiguous, array or non-exact values retain the ordinary login route.
        if ($forgot === $security) return null;
        $key = $forgot ? 'forgot' : 'security';
        if ($query[$key] !== '1') return null;
        return $forgot ? '/forgot' : '/account?section=security';
    }

    public static function serve(string $method, string $path, array $query): bool
    {
        $target = self::target($method, $path, $query);
        if ($target === null) return false;
        header('Cache-Control: no-store');
        header('Location: '.$target, true, 302);
        return true;
    }
}
