<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Short, complete catalogue of own authored social content and newsletter editions. */
final class EditorialCatalogue
{
    public static function page(PDO $db,Access $access,array $input=[]):array
    {
        $query=Content::text($input['hub_query']??'',200);$type=$input['hub_type']??'all';$page=SubscriberSelection::number($input['hub_page']??'1',1000000);
        if(!is_string($type)||!in_array($type,['all','social','newsletter'],true))throw new DomainException('Inhaltsart prüfen.',422);
        return Db::atomic($db,function()use($db,$access,$query,$type,$page):array{
            $modules=[];$club=null;
            foreach(['social','newsletter']as$module){try{$scope=$access->require($module,'read');}catch(DomainException){continue;}if($club!==null&&$scope['club_id']!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.',409);$club=$scope['club_id'];$modules[]=$module;}
            if(!$modules)throw new DomainException('Keine Berechtigung für diese Inhalte.',403);
            if($type!=='all'&&!in_array($type,$modules,true))throw new DomainException('Für diesen Kanal fehlt die Leseberechtigung.',403);
            $sources=[];$params=[];$total=0;
            foreach($modules as$module){if($type!=='all'&&$type!==$module)continue;$table=Schema::table($module,'items');$where='club_id=?';$args=[$club];if($query!==''){$where.=" AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title'))) LIKE LOWER(?) ESCAPE '!'";$args[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}$total+=(int)Db::run($db,"SELECT COUNT(*) FROM $table WHERE $where",$args)->fetchColumn();$sources[]="SELECT '$module' AS module,id,updated_at FROM $table WHERE $where";array_push($params,...$args);}
            $pages=max(1,(int)ceil($total/6));$page=min($page,$pages);$ids=Db::run($db,'SELECT module,id,updated_at FROM ('.implode(' UNION ALL ',$sources).') own_items ORDER BY updated_at DESC,module,id DESC LIMIT 6 OFFSET '.(($page-1)*6),$params)->fetchAll(PDO::FETCH_ASSOC);$rows=[];
            // Project title/format only after selecting six stable identities.
            foreach($ids as$id){$table=Schema::table($id['module'],'items');$row=Db::run($db,"SELECT id,version,status,updated_at,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title')) title,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.kind')) kind FROM $table WHERE club_id=? AND id=?",[$club,$id['id']])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Inhalte wurden inzwischen geändert. Bitte erneut öffnen.',409);$row['module']=$id['module'];if($id['module']==='newsletter')$row['kind']='newsletter';$rows[]=$row;}
            foreach($modules as$module)if($access->require($module,'read')['club_id']!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.',409);
            return compact('rows','total','pages','page','query','type','modules');
        });
    }
}
