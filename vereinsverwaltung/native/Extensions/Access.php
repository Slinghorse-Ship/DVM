<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Shared\Db;

final class Access
{
    public function __construct(private readonly \PDO $db,private readonly string $actor,private readonly ?\Closure $sessionGuard=null) {}
    public function actor(): array
    {
        if($this->sessionGuard)($this->sessionGuard)();
        $actor=\Kulturhus\Verein\Permissions::actor($this->db,$this->actor);
        $lock=$this->db->inTransaction()?' FOR SHARE':'';
        $current=Db::run($this->db,'SELECT id,club_id,is_superuser FROM actors WHERE id=?'.$lock,[$this->actor])->fetch();
        if(!$current||!(bool)$current['is_superuser'])throw new \DomainException('Erweiterungen können nur von der Vereinsadministration verwaltet werden.');
        $actor=array_replace($actor,$current);
        $identity=Db::run($this->db,'SELECT a.active,a.verified,a.suspended FROM native_manager_identities i LEFT JOIN native_auth_accounts a ON a.actor_id=i.native_id WHERE i.manager_club_id=? AND i.manager_actor_id=?'.$lock,[$actor['club_id'],$actor['id']])->fetch();
        if(!$identity&&getenv('VEREIN_MODE')==='production')throw new \DomainException('Native Vereinsanmeldung nicht verfügbar.');
        if($identity&&(!(int)$identity['active']||!(int)$identity['verified']||(int)$identity['suspended']))throw new \DomainException('Vereinszugang nicht aktiv.');
        return $actor;
    }
    public function guard(string $club): \Closure
    {
        return function()use($club):bool{return $this->actor()['club_id']===$club;};
    }
}
