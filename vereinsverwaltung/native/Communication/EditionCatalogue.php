<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Complete own edition metadata and history without loading unrelated originals. */
final class EditionCatalogue
{
    public const STATES=['all'=>'Alle','draft'=>'Entwürfe','review'=>'In Prüfung','approved'=>'Freigegeben','scheduled'=>'Geplant','local_ready'=>'Bereitgestellt','ambiguous'=>'Ergebnis offen'];
    public static function page(PDO $db,Access $access,string $module,array $query=[]): array
    {
        $access->require($module,'read');if(!in_array($module,['newsletter','press'],true))throw new DomainException('Ausgabenbereich prüfen.');
        $text=Content::text($query['edition_query']??'',200);$state=$query['edition_state']??'all';if(!is_string($state)||!isset(self::STATES[$state]))throw new DomainException('Ausgabenstatus prüfen.');
        $page=SubscriberSelection::number($query['edition_page']??1,1000000);$table=Schema::table($module,'items');$where='club_id=?';$params=[$access->club()];
        if($text!==''){$where.=" AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title'))) LIKE LOWER(?) ESCAPE '!'";$params[]='%'.strtr($text,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
        if($state!=='all'){$where.=' AND status=?';$params[]=$state;}
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM $table WHERE $where",$params)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);$offset=($page-1)*6;
        // Materialise six IDs before any JSON projection: MySQL otherwise carries
        // large source payloads into filesort, even for a short extracted title.
        $rows=Db::run($db,"SELECT i.id,i.version,i.status,i.updated_at,JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.title')) AS title FROM $table i JOIN (SELECT id FROM $table WHERE $where ORDER BY updated_at DESC,id DESC LIMIT 6 OFFSET $offset) selected ON selected.id=i.id",$params)->fetchAll(PDO::FETCH_ASSOC);
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['updated_at'],$a['updated_at'])?:strcmp($b['id'],$a['id']));
        $access->require($module,'read');return['query'=>$text,'state'=>$state,'page'=>$page,'pages'=>$pages,'total'=>$total,'rows'=>$rows];
    }

    public static function history(PDO $db,Access $access,string $module,string $id,mixed $page=1): array
    {
        $access->require($module,'read');Content::id($id);$items=Schema::table($module,'items');$history=Schema::table($module,'revisions');$page=SubscriberSelection::number($page,1000000);$params=[$access->club(),$id];
        if(!Db::run($db,"SELECT 1 FROM $items WHERE club_id=? AND id=?",$params)->fetchColumn())throw new DomainException('Ausgabe nicht verfügbar.');
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM $history WHERE club_id=? AND item_id=?",$params)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);$offset=($page-1)*6;
        $rows=Db::run($db,"SELECT version,actor_id,created_at FROM $history WHERE club_id=? AND item_id=? ORDER BY version DESC LIMIT 6 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC);$access->require($module,'read');return compact('rows','total','page','pages');
    }
}
