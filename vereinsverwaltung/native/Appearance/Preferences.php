<?php declare(strict_types=1);
namespace Kulturhus\Native\Appearance;

use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\{ManagerAccess,ManagerBridge};

/** One authority: existing own account Settings when bound; otherwise native own CAS. */
final class Preferences
{
    public function __construct(private \PDO $db,private Access $access){require_once dirname(__DIR__,2).'/src/autoload.php';}
    public function state():array
    {
        if(!$this->db->inTransaction())return Db::atomic($this->db,fn():array=>$this->state());
        [$user,$binding]=$this->identity();
        if($binding){$saved=(new \Kulturhus\Verein\Settings($this->db,$binding['manager_actor_id']))->section('account');return ['data'=>Values::fromAccount($saved['data']),'version'=>$saved['version'],'scope'=>$this->scope($user,$binding),'storage'=>'account','updated_at'=>$saved['updated_at']];}
        try{$saved=Db::run($this->db,'SELECT version,payload_json,changed_at FROM native_appearance_preferences WHERE club_id=? AND actor_id=?',[$user['club_id'],$user['id']])->fetch();}
        catch(\PDOException $error){if($error->getCode()!=='42S02')throw$error;$saved=false;}
        return ['data'=>$saved?Values::normalize(json_decode($saved['payload_json'],true,8,JSON_THROW_ON_ERROR)):Values::defaults(),'version'=>$saved?(int)$saved['version']:0,'scope'=>$this->scope($user,null),'storage'=>'native','updated_at'=>$saved['changed_at']??''];
    }
    public function save(array $data,int $version,string $scope):array
    {
        $data=Values::normalize($data);if($version<0||$version>1000000000)throw new \DomainException('Gespeicherte Anzeigeversion prüfen.');
        $owner=$this->access->user();
        return Db::atomic($this->db,function()use($data,$version,$scope,$owner):array{
            // Lock before Access's shared locking read: two first saves must not both upgrade a shared lock.
            if(!Db::run($this->db,'SELECT 1 FROM native_users WHERE id=? AND club_id=? AND active=1 FOR UPDATE',[$owner['id'],$owner['club_id']])->fetchColumn())throw new \DomainException('Eigener Zugang nicht mehr verfügbar.');
            [$user,$binding]=$this->identity();
            if($user['id']!==$owner['id']||$user['club_id']!==$owner['club_id'])throw new Conflict('Die Kontozuordnung hat sich geändert. Aktuellen Stand öffnen.');
            if(!hash_equals($this->scope($user,$binding),$scope))throw new Conflict('Die Kontozuordnung hat sich geändert. Aktuellen Stand öffnen.');
            if($binding){(new \Kulturhus\Verein\Settings($this->db,$binding['manager_actor_id']))->saveAppearance($data,$version);return$this->state();}
            try{$saved=Db::run($this->db,'SELECT version FROM native_appearance_preferences WHERE club_id=? AND actor_id=? FOR UPDATE',[$user['club_id'],$user['id']])->fetchColumn();}
            catch(\PDOException $error){if($error->getCode()!=='42S02')throw$error;throw new \DomainException('Anzeigeeinstellungen sind noch nicht eingerichtet. Bitte die Schemawartung ausführen.');}
            if(($saved===false?0:(int)$saved)!==$version)throw new Conflict('Deine Anzeigeeinstellungen wurden inzwischen geändert. Aktuellen Stand öffnen.');
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$time=gmdate('Y-m-d H:i:s');
            Db::run($this->db,'INSERT INTO native_appearance_preferences (club_id,actor_id,version,payload_json,changed_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),payload_json=VALUES(payload_json),changed_at=VALUES(changed_at)',[$user['club_id'],$user['id'],$version+1,$json,$time]);
            Db::run($this->db,'INSERT INTO native_appearance_revisions (club_id,actor_id,version,payload_json,changed_at) VALUES (?,?,?,?,?)',[$user['club_id'],$user['id'],$version+1,$json,$time]);
            return$this->state();
        });
    }
    /** Live session access and exact server-owned binding; no grants infer account ownership. */
    private function identity():array
    {
        $user=$this->access->user();
        try{$binding=Db::run($this->db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$user['id']])->fetch();}
        catch(\PDOException $error){if($error->getCode()!=='42S02')throw$error;$binding=false;}
        if($binding&&(!hash_equals(ManagerBridge::actorKey($binding['manager_actor_id'],$binding['manager_club_id']),$user['id'])||!hash_equals(ManagerBridge::clubKey($binding['manager_club_id']),$user['club_id'])))throw new \DomainException('Persönliche Vereinszuordnung prüfen.');
        if($binding){$actor=Db::run($this->db,'SELECT club_id,active FROM actors WHERE id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$binding['manager_actor_id']])->fetch();if(!$actor||!(int)$actor['active']||$actor['club_id']!==$binding['manager_club_id'])throw new \DomainException('Persönliche Vereinszuordnung geändert.');}
        if($this->access instanceof ManagerAccess&&(!$binding||$this->access->managerActor()!==$binding['manager_actor_id']))throw new \DomainException('Persönliche Vereinszuordnung geändert.');
        return[$user,$binding?:null];
    }
    private function scope(array $user,?array $binding):string{return hash('sha256',json_encode([$user['id'],$user['club_id'],$binding],JSON_THROW_ON_ERROR));}
}
