<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Current role writers retain the original assignment row and monotonically increasing version. */
final class RoleAssignments
{
    public static function defaultsSupported(PDO $db):bool
    {
        return $db->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='settings_role_assignments' AND column_name='role_id'")->fetchColumn()==='YES';
    }
    public static function lockClub(PDO $db,string $club):void
    {
        if(!$db->inTransaction())throw new DomainException('Rollenzuordnung benötigt eine eigene laufende Transaktion.');
        $q=$db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen Verein auswählen.');
    }
    /** Caller supplies its authenticated owned target; this operation never changes actor/profile/login. */
    public static function write(PDO $db,string $club,string $actor,string $role,int $version,bool $defaults=false):void
    {
        if(!$db->inTransaction()||$version<0||($defaults&&$role!==''))throw new DomainException('Ursprüngliche eigene Rollenzuordnung prüfen.');
        if($defaults&&!self::defaultsSupported($db))throw new DomainException('Zurücksetzen auf Grundrolle benötigt das aktuelle installierte Paket.');
        if(!$defaults&&$role==='')throw new DomainException('Vereinsrolle auswählen.');
        $q=$db->prepare('SELECT id FROM actors WHERE id=? AND club_id=? FOR SHARE');$q->execute([$actor,$club]);if(!$q->fetchColumn())throw new DomainException('Eigenes Konto auswählen.');
        $q=$db->prepare('SELECT version FROM settings_role_assignments WHERE actor_id=? AND club_id=? FOR UPDATE');$q->execute([$actor,$club]);$old=$q->fetchColumn();if((int)($old===false?0:$old)!==$version)throw new DomainException('Rollenzuordnung inzwischen geändert. Aktuellen Stand neu öffnen.');
        if(!$defaults){$q=$db->prepare('SELECT id FROM settings_roles WHERE club_id=? AND id=? FOR SHARE');$q->execute([$club,$role]);if(!$q->fetchColumn())throw new DomainException('Eigene Vereinsrolle auswählen.');}
        $q=$db->prepare('INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),version=VALUES(version)');$q->execute([$actor,$club,$defaults?null:$role,$version+1]);
    }
}
