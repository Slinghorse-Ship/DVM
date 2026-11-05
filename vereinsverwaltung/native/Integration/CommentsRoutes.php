<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use PDO;

/** Fixed public comment endpoint. No personal form state enters the website cache. */
final class CommentsRoutes
{
    public static function serve(PDO $db,Access $publicAccess,string $runtime,string $managerClub,string $origin,?string $csrf=null):bool
    {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if (!is_string($path) || rtrim($path,'/')!=='/_comments') return false;
        header('Cache-Control: private, no-store');
        try {
            $publicAccess->require('website','read');
            if ($managerClub==='' || !hash_equals(ManagerBridge::clubKey($managerClub),$publicAccess->club())) throw new \DomainException('Öffentlicher Vereinszugang nicht verfügbar.');
            if ((int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_comments','native_comment_history','native_comment_settings','native_comment_setting_history') AND TABLE_TYPE='BASE TABLE'")->fetchColumn()!==4) {
                http_response_code(503);if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'Kommentare sind noch nicht eingerichtet.';return true;
            }
            $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:$runtime.'/private.key';
            return \Kulturhus\Native\Comments\Http::serve($db,$publicAccess,SecretBox::fromFile($key),$origin,$csrf);
        } catch (\DomainException) {
            http_response_code(403);if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'Kommentare sind für diesen Zugang nicht verfügbar.';return true;
        }
    }
}
