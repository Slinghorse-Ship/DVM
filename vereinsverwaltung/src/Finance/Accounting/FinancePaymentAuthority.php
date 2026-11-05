<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/**
 * Authority for an already reviewed outgoing payment.
 *
 * Invoice review remains a separate decision. This authority answers which
 * confirmed board office or superuser may handle the resulting bank order.
 */
final class FinancePaymentAuthority
{
    /** These are the board offices that may operate a reviewed payment. */
    public const OFFICES = ['Kassenwart','1. Vorstand','2. Vorstand','Schriftwart','Schriftführer'];

    public static function find(PDO $db,string $actor):?array
    {
        try {
            $a=Permissions::actor($db,$actor);
            if($a['role']==='editor'||$a['finance_access']!=='write')return null;
        } catch(DomainException) { return null; }

        $nameSql=implode(',',array_fill(0,count(self::OFFICES),'?'));
        $params=array_merge([$a['club_id'],$actor],self::OFFICES);
        $row=DocumentCatalog::query($db,"SELECT b.version,b.office,c.checked_by,c.checked_at,COALESCE(p.name,b.actor_id) AS actor_name
            FROM admission_board b
            JOIN admission_board_checks c ON c.club_id=b.club_id AND c.actor_id=b.actor_id AND c.board_version=b.version AND c.office=b.office
            JOIN actors x ON x.id=b.actor_id AND x.club_id=b.club_id
            LEFT JOIN contacts p ON p.id=x.contact_id AND p.club_id=x.club_id
            WHERE b.club_id=? AND b.actor_id=? AND b.enabled=1 AND b.office IN ($nameSql)".($db->inTransaction()?' FOR SHARE NOWAIT':''), $params)->fetch();
        if($row)return $row;
        if(!empty($a['is_superuser']))return [
            // Superuser authority has no board-row version; use a stable
            // positive marker because approval history requires one.
            'version'=>1,
            'office'=>'Superuser',
            'checked_by'=>$a['id'],
            'checked_at'=>null,
            'actor_name'=>(string)$a['id'],
        ];
        return null;
    }

    public static function require(PDO $db,string $actor):array
    {
        $authority=self::find($db,$actor);
        if(!$authority)throw new DomainException('Für diesen Zahlungsauftrag ist ein bestätigtes Vorstandsamt oder der Superuser mit Finanz-Schreibrecht erforderlich.');
        return $authority;
    }

    public static function label(array $authority):string
    {
        return ($authority['office']??'')==='Superuser'?'Superuser':('bestätigtes '.(string)($authority['office']??'Vorstandsamt'));
    }
}
