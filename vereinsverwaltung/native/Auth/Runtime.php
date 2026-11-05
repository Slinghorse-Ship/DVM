<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

final class Runtime
{
    /** Provisioned out of band; never generate or expose a key in an HTTP request. */
    public static function service(\PDO $db,?string $privateKeyFile=null): Service
    {
        $path=$privateKeyFile??getenv('KH_NATIVE_AUTH_KEY_FILE');
        if(!$path||!is_file($path)||is_link($path))throw new \RuntimeException('Privater Kontoschlüssel nicht eingerichtet.');
        $key=file_get_contents($path);
        if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Ungültiger Kontoschlüssel.');
        return new Service($db,$key);
    }
}
