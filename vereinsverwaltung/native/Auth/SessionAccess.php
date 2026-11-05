<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;

/** Standalone services recheck the authenticated session inside every rights check. */
final class SessionAccess extends Access
{
    private string $actor;
    private ?ManagerBridge $manager=null;
    public function __construct(private \PDO $db,private Service $auth,private string $token)
    {
        $this->actor=$auth->resolve($token)['actor_id'];
        parent::__construct($db,$this->actor);
        // Pin an existing manager identity before a later request can remove it.
        $this->user();
    }
    public function user():array
    {
        if($this->auth->resolve($this->token)['actor_id']!==$this->actor)throw new \DomainException('Anmeldung nicht mehr gültig.');
        $user=parent::user();$lock=$this->db->inTransaction()?' FOR SHARE':'';
        try{$binding=Db::run($this->db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.$lock,[$user['id']])->fetch();}
        catch(\PDOException $error){if($error->getCode()!=='42S02')throw$error;$binding=false;}
        if($binding){
            if(!hash_equals(ManagerBridge::actorKey($binding['manager_actor_id'],$binding['manager_club_id']),$user['id'])||!hash_equals(ManagerBridge::clubKey($binding['manager_club_id']),$user['club_id']))throw new \DomainException('Persönliche Vereinszuordnung geändert.');
            $actor=Db::run($this->db,'SELECT club_id,active FROM actors WHERE id=?'.$lock,[$binding['manager_actor_id']])->fetch();
            if(!$actor||!(int)$actor['active']||$actor['club_id']!==$binding['manager_club_id'])throw new \DomainException('Persönliche Vereinszuordnung geändert.');
            // This factory only binds the session. Unlike access(), it performs
            // no grant projection; current manager roles remain the authority.
            $this->manager??=ManagerBridge::fromNativeSession($this->db,$this->auth,$this->token);
            return $this->manager->user();
        }
        if($this->manager!==null)throw new \DomainException('Persönliche Vereinszuordnung geändert.');
        return $user;
    }
    public function require(string $module,string $capability):array
    {
        $this->user();
        return $this->manager!==null
            ?$this->manager->requireCapability($module,$capability)
            :parent::require($module,$capability);
    }
    public function directory(string $module):array
    {
        $this->user();
        return $this->manager!==null
            ?$this->manager->directory($module)
            :parent::directory($module);
    }
}
