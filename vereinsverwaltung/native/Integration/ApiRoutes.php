<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;

/** Own the entire API namespace before public slug resolution. Credentials remain header/session only. */
final class ApiRoutes
{
    public static function matches(string $path): bool
    {
        return $path==='/api/v1' || str_starts_with($path,'/api/v1/');
    }

    public static function serve(PDO $db,string $club,string $origin,string $mediaRoot): bool
    {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if (!is_string($path) || !self::matches($path)) return false;
        // Apache internal rewrites can prefix an explicitly forwarded header. An existing
        // Authorization value always wins, including an invalid or empty value.
        if (!array_key_exists('HTTP_AUTHORIZATION',$_SERVER) && array_key_exists('REDIRECT_HTTP_AUTHORIZATION',$_SERVER)) {
            $_SERVER['HTTP_AUTHORIZATION']=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $session=null;$csrf=null;
        if (!array_key_exists('HTTP_AUTHORIZATION',$_SERVER)) {
            try {
                ManagerSession::start();
                if (is_string($_SESSION['auth_token']??null)) $session=$_SESSION['auth_token'];
                if (is_string($_SESSION['csrf']??null)) $csrf=$_SESSION['csrf'];
            } catch (\RuntimeException) {
                // A bearer-only host does not need a PHP session store.
            }
        }
        \Kulturhus\Native\Api\Http::serve($db,$club,$origin,$mediaRoot,dirname($mediaRoot).'/website-documents',$session,$csrf);
        return true;
    }
}
