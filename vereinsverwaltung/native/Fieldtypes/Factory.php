<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;
use Kulturhus\Native\Shared\Db;

/** The native ACL club and a mapped manager module owner are resolved through fixed identities. */
final class Factory
{
    public static function registry(\PDO $db,string $nativeClub,array $owners=[]):Registry
    {
        if(!Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_extensions'")->fetchColumn())return new Registry();
        require_once dirname(__DIR__,2).'/src/bootstrap.php';$ownerClub=$nativeClub;
        if(Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_manager_identities'")->fetchColumn())foreach(Db::run($db,'SELECT DISTINCT manager_club_id FROM native_manager_identities')->fetchAll(\PDO::FETCH_COLUMN)as$club)if(hash_equals(\Kulturhus\Native\Integration\ManagerBridge::clubKey($club),$nativeClub)){$ownerClub=$club;break;}
        $catalog=\Kulturhus\Native\Extensions\Runtime::catalog();if(!$owners)$owners=array_keys($catalog->ordered());$requested=[];foreach($owners as$owner)try{$catalog->get(Binding::name($owner));$requested[]=$owner;}catch(\DomainException){}
        return (new \Kulturhus\Native\Extensions\Runtime($db,$ownerClub,$catalog,$requested))->fieldtypes();
    }
}
