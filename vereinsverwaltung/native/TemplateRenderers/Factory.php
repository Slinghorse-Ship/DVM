<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;
use Kulturhus\Native\Shared\Db;
final class Factory
{
    public static function registry(\PDO $db,string $nativeClub):Registry
    {
        if(!Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_extensions'")->fetchColumn())return new Registry();
        require_once dirname(__DIR__,2).'/src/bootstrap.php';
        $ownerClub=$nativeClub;
        if(Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_manager_identities'")->fetchColumn())foreach(Db::run($db,'SELECT DISTINCT manager_club_id FROM native_manager_identities')->fetchAll(\PDO::FETCH_COLUMN)as$club)if(hash_equals(\Kulturhus\Native\Integration\ManagerBridge::clubKey($club),$nativeClub)){$ownerClub=$club;break;}
        return (new \Kulturhus\Native\Extensions\Runtime($db,$ownerClub))->renderers();
    }
    /** Caller holds its existing model save lock and supplies the real old definition. */
    public static function normalize(\PDO $db,string $club,mixed $value,mixed $previous=null):?array
    {
        if($value===null)return null;
        $binding=Binding::normalize($value);$old=$previous===null?null:Binding::normalize($previous);
        return View::validate($binding,$old,self::registry($db,$club));
    }
    public static function assertPublication(\PDO $db,string $club,array $payload):void
    {
        if(!isset($payload['template_definition']['frontend_renderer']))return;
        try{$binding=Binding::normalize($payload['template_definition']['frontend_renderer']);if(!self::registry($db,$club)->available($binding))throw new \DomainException();}catch(\Throwable){throw new \Kulturhus\Native\Cms\Conflict('Die Frontenddarstellung ist nicht verfügbar. Darstellung prüfen und den Entwurf erneut speichern.');}
    }
}
