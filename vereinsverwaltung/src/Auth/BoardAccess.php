<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Explicit personal authority; general member/finance/settings roles never imply a board office. */
final class BoardAccess
{
    public static function superuser(PDO $db,string $actor): bool
    {
        try{$a=Permissions::actor($db,$actor);return $a['role']!=='editor'&&(bool)($a['is_superuser']??false);}
        catch(DomainException){return false;}
    }
    public static function allowed(PDO $db,string $actor): bool
    {
        try{$a=Permissions::actor($db,$actor);if($a['role']==='editor')return false;
            return (bool)($a['is_superuser']??false)||(bool)DocumentCatalog::query($db,'SELECT 1 FROM admission_board WHERE club_id=? AND actor_id=? AND enabled=1'.($db->inTransaction()?' FOR SHARE':''),[$a['club_id'],$a['id']])->fetchColumn();
        }catch(DomainException){return false;}
    }
    public static function require(PDO $db,string $actor): array
    {
        if(!self::allowed($db,$actor))throw new DomainException('Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.');
        return Permissions::actor($db,$actor);
    }
}
