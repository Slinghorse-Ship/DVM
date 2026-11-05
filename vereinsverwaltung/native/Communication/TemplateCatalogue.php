<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Six metadata rows, one selected payload; no hidden 500-template limit. */
final class TemplateCatalogue
{
    public static function page(PDO $db,Access $access,string $module,array $state): array
    {
        $access->require($module,'read');
        if(!in_array($module,['newsletter','press','social'],true))throw new DomainException('Vorlagenbereich prüfen.');
        $query=Content::text($state['query']??'',200);$page=SubscriberSelection::number($state['page']??1,1000000);
        $table=Schema::table($module,'templates');$where='club_id=?';$params=[$access->club()];
        if($query!==''){$where.=" AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name'))) LIKE LOWER(?) ESCAPE '!'";$params[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
        $builtins=array_values(array_filter(Content::defaults($module),static fn(array $t):bool=>($module!=='social'||($state['reels_available']??false)||$t['kind']!=='reel')&&($query===''||mb_stripos($t['name'],$query,0,'UTF-8')!==false)));
        $total=count($builtins)+(int)Db::run($db,"SELECT COUNT(*) FROM $table WHERE $where",$params)->fetchColumn();
        $pages=max(1,(int)ceil($total/6));$page=max(1,min($pages,$page));$offset=($page-1)*6;
        $rows=array_slice($builtins,$offset,6);foreach($rows as&$r)$r=['id'=>$r['id'],'name'=>$r['name'],'version'=>1,'builtin'=>true];unset($r);
        $count=6-count($rows);$offset=max(0,$offset-count($builtins));
        if($count){$own=Db::run($db,"SELECT t.id,t.version,t.updated_at,JSON_UNQUOTE(JSON_EXTRACT(t.payload,'$.name')) AS name FROM $table t JOIN (SELECT id FROM $table WHERE $where ORDER BY updated_at DESC,id DESC LIMIT $count OFFSET $offset) selected ON selected.id=t.id",$params)->fetchAll(PDO::FETCH_ASSOC);usort($own,static fn(array $a,array $b):int=>strcmp($b['updated_at'],$a['updated_at'])?:strcmp($b['id'],$a['id']));foreach($own as$r)$rows[]=['id'=>$r['id'],'name'=>$r['name'],'version'=>(int)$r['version'],'builtin'=>false];}
        $access->require($module,'read');return array_replace($state,['query'=>$query,'page'=>$page,'pages'=>$pages,'total'=>$total,'rows'=>$rows]);
    }

    public static function history(PDO $db,Access $access,string $module,string $id,int $page): array
    {
        $current=(new Workspace($db,$access,$module))->getTemplate($id);$table=Schema::table($module,'template_revisions');
        if($current['builtin'])return['rows'=>[],'page'=>1,'pages'=>1,'total'=>0];
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM $table WHERE club_id=? AND item_id=?",[$access->club(),$id])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=max(1,min($page,$pages));$offset=($page-1)*6;
        $rows=Db::run($db,"SELECT version,created_at,actor_id FROM $table WHERE club_id=? AND item_id=? ORDER BY version DESC LIMIT 6 OFFSET $offset",[$access->club(),$id])->fetchAll(PDO::FETCH_ASSOC);
        return compact('rows','page','pages','total');
    }

    public static function revision(PDO $db,Access $access,string $module,string $id,int $version): array
    {
        $current=(new Workspace($db,$access,$module))->getTemplate($id);
        if($current['builtin']){if($version!==1)throw new DomainException('Fassung nicht verfügbar.');return$current;}
        $table=Schema::table($module,'template_revisions');$raw=Db::run($db,"SELECT payload FROM $table WHERE club_id=? AND item_id=? AND version=?",[$access->club(),$id,$version])->fetchColumn();
        if(!is_string($raw))throw new DomainException('Fassung nicht verfügbar.');return json_decode($raw,true,64,JSON_THROW_ON_ERROR)+['id'=>$id,'version'=>$version,'builtin'=>false];
    }
}
