<?php declare(strict_types=1);
namespace Kulturhus\Native\System;

/** Navigation follows the same fresh personal/source authority as the actual module. */
final class Navigation
{
    public static function resolver(\PDO $db):\Closure{return static fn(string $session):bool=>self::available($db,$session);}
    public static function manager(\PDO $db,string $serverActor):bool
    {
        try{$bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$serverActor,'native',static fn()=>null);$access=new \Kulturhus\Native\Integration\ManagerAccess($db,$bridge);(new Authority($db,$access))->require();return true;}catch(\Throwable){return false;}
    }
    public static function available(\PDO $db,#[\SensitiveParameter]string $session):bool
    {
        if($session==='')return false;
        try{$context=\Kulturhus\Native\Api\Factory::session($db,$session);(new Authority($db,$context->access))->require();return true;}catch(\Throwable){return false;}
    }
}
