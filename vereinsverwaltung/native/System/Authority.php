<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Integration\{ManagerAccess,ManagerBridge};
use Kulturhus\Native\Shared\{Access,Db};

/** Personal web sessions and scheduled owners retain the original current authority. */
final class Authority
{
    public function __construct(private readonly \PDO $db,public readonly Access $access){}
    public function require():array
    {
        $actor=$this->access->require('website','manage');
        if($this->access instanceof ManagerAccess){if(!(new \Kulturhus\Verein\Settings($this->db,$this->access->managerActor()))->canManage())throw new \DomainException('Keine Berechtigung für die Systemverwaltung.');}
        else $this->access->require('accounts','manage');
        return $actor;
    }
    public function owner():array
    {
        $actor=$this->require();$account=Db::run($this->db,'SELECT version,active,verified,suspended FROM native_auth_accounts WHERE actor_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$actor['id']])->fetch();
        if(!$account||!$account['active']||!$account['verified']||$account['suspended'])throw new \DomainException('Aktuelles persönliches Verwaltungskonto erforderlich.');
        return ['id'=>$actor['id'],'club'=>$actor['club_id'],'manager_actor'=>$this->access instanceof ManagerAccess?$this->access->managerActor():null,'account_version'=>(int)$account['version']];
    }
    public function club():string{return $this->require()['club_id'];}
    public function managerClub():?string
    {if(!$this->access instanceof ManagerAccess)return null;return (new ManagerBridge($this->db,$this->access->managerActor(),'native',static fn()=>null))->managerClub();}
    public static function scheduled(\PDO $db,array $task):self
    {
        $guard=static function()use($db,$task):void{
            $account=Db::run($db,'SELECT version,active,verified,suspended FROM native_auth_accounts WHERE actor_id=?'.($db->inTransaction()?' FOR SHARE':''),[$task['owner_id']])->fetch();
            if(!$account||!$account['active']||!$account['verified']||$account['suspended']||(int)$account['version']!==(int)$task['account_version'])throw new \DomainException('Zeitplan benötigt eine aktuelle persönliche Freigabe.');
            if($task['manager_actor']!==null){
                $binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($db->inTransaction()?' FOR SHARE':''),[$task['owner_id']])->fetch();
                if(!$binding||$binding['manager_actor_id']!==$task['manager_actor']||ManagerBridge::clubKey($binding['manager_club_id'])!==$task['club_id']||ManagerBridge::actorKey($binding['manager_actor_id'],$binding['manager_club_id'])!==$task['owner_id'])throw new \DomainException('Ursprüngliche persönliche Vereinszuordnung des Zeitplans geändert.');
            }
        };
        $guard();$access=$task['manager_actor']!==null?new ManagerAccess($db,new ManagerBridge($db,$task['manager_actor'],'native',static function()use($guard):void{$guard();})):new Access($db,$task['owner_id']);$authority=new self($db,$access);$owner=$authority->owner();if($owner['id']!==$task['owner_id']||$owner['club']!==$task['club_id'])throw new \DomainException('Ursprüngliche Zeitplanverantwortung geändert.');return $authority;
    }
}
