<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
use DomainException;
class Access
{
    public function __construct(private PDO $db, private string $actorId) {}
    public function user(): array
    {
        $user = Db::run($this->db, 'SELECT id,club_id,name,active FROM native_users WHERE id=? AND active=1'.($this->db->inTransaction()?' FOR SHARE':''), [$this->actorId])->fetch();
        if (!$user) throw new DomainException('Benutzer nicht freigeschaltet.');
        return $user;
    }
    public function club(): string { return $this->user()['club_id']; }
    public function require(string $module, string $capability): array
    {
        $user = $this->user();
        if (!Db::run($this->db, 'SELECT 1 FROM native_grants WHERE actor_id=? AND module=? AND capability=?'.($this->db->inTransaction()?' FOR SHARE':''), [$user['id'],$module,$capability])->fetchColumn()) {
            throw new DomainException('Für diese Aktion fehlt die Berechtigung.');
        }
        return $user;
    }
    public function directory(string $module): array
    {
        $user = $this->require($module, 'read');
        return Db::run($this->db, "SELECT u.id,u.club_id,u.name,u.active FROM native_users u JOIN native_grants g ON g.actor_id=u.id AND g.module=? AND g.capability='read' WHERE u.club_id=? AND u.active=1 ORDER BY u.name,u.id".($this->db->inTransaction()?' FOR SHARE':''), [$module,$user['club_id']])->fetchAll();
    }
}
