<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once dirname(__DIR__, 1).'/Infrastructure/Runtime/ProductionRuntime.php';
final class Permissions
{
    public static function finance(PDO $db,string $actor,bool $write=false):array
    {
        $current=self::actor($db,$actor);
        if(!in_array($current['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        return $current;
    }
    private static ?\WeakMap $navigationActors=null;
    /** One read-only menu calculation; service and later request checks stay live. */
    public static function navigationRead(callable $read):array
    {
        if(self::$navigationActors!==null)return $read();
        self::$navigationActors=new \WeakMap();
        try{return $read();}finally{self::$navigationActors=null;}
    }
    public static function actor(PDO $db,string $id):array
    {
        if(self::$navigationActors===null||$db->inTransaction())return self::readActor($db,$id);
        $actors=self::$navigationActors[$db]??[];
        if(isset($actors[$id]))return $actors[$id];
        $a=self::readActor($db,$id);$actors[$id]=$a;self::$navigationActors[$db]=$actors;return $a;
    }
    private static function readActor(PDO $db,string $id):array
    {
        $lock=$db->inTransaction()?' FOR SHARE':'';
        $q=$db->prepare("SELECT a.*,r.website_access,r.members_access,r.finance_access,r.settings_access,r.inventory_access,r.loans_access,r.documents_access,COALESCE(r.press_access,a.press_access) AS press_access,COALESCE(r.minutes_access,a.minutes_access,CASE WHEN a.role IN ('member','members','treasurer') THEN 'read' ELSE 'none' END) AS minutes_access FROM actors a LEFT JOIN settings_role_assignments x ON x.actor_id=a.id AND x.club_id=a.club_id LEFT JOIN settings_roles r ON r.id=x.role_id AND r.club_id=x.club_id WHERE a.id=? AND a.active=1".$lock);$q->execute([$id]);$a=$q->fetch();
        if(!$a)throw new DomainException('Zugang nicht aktiv.');
        // Disabled identity bindings also stop queued work which rechecks the actor directly.
        if(!ProductionRuntime::nativeManager()){
            $binding=$db->prepare('SELECT enabled FROM processwire_identities WHERE actor_id=?'.$lock);$binding->execute([$id]);$enabled=$binding->fetchColumn();
            if($enabled!==false && !(bool)$enabled)throw new DomainException('Kontozuordnung gesperrt.');
        }
        // The editorial login is always a separate trust boundary, even with a corrupt assignment.
        if($a['role']==='editor'){$a['members_access']='none';$a['finance_access']='none';$a['settings_access']=0;$a['minutes_access']='none';}
        if($a['members_access']===null)$a['members_access']=$a['role']==='members'?'write':'none';
        if($a['finance_access']===null){$q=$db->prepare("SELECT 1 FROM actor_capabilities WHERE actor_id=? AND capability='local_finance'".$lock);$q->execute([$id]);$a['finance_access']=($a['role']==='treasurer'||$q->fetchColumn())?'write':'none';}
        if($a['settings_access']===null)$a['settings_access']=$a['role']==='members'?1:0;
        foreach(['inventory_access','loans_access','documents_access'] as $field){if($a['role']==='editor')$a[$field]='none';elseif($a[$field]===null)$a[$field]=$a['role']==='members'?'write':'none';}
        // Only an explicitly assigned central superuser receives full technical rights.
        // Board office and personal voting eligibility are intentionally not inferred here.
        if($a['role']!=='editor'&&!empty($a['is_superuser'])){foreach(['members_access','finance_access','inventory_access','loans_access','documents_access','press_access','minutes_access'] as $field)$a[$field]='write';$a['settings_access']=1;}
        return $a;
    }
}
