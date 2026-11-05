<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db};

final class PressContactCatalogue
{
    public static function page(PDO $db,Access $access,array $state): array
    {
        $a=$access->require('press','read');$selected=SubscriberSelection::versions($state['selected']??[]);$query=Content::text($state['query']??'',200);$status=$state['status']??'all';$mode=$state['mode']??'all';$page=SubscriberSelection::number($state['page']??1);
        if(!in_array($status,['all','active','inactive'],true)||!in_array($mode,['all','selected'],true))throw new DomainException('Kontaktfilter prüfen.');
        $where='club_id=?';$args=[$a['club_id']];
        if($status!=='all'){$where.=' AND active=?';$args[]=$status==='active'?1:0;}
        if($mode==='selected'){if(!$selected)$where.=' AND 1=0';else{$where.=' AND id IN ('.implode(',',array_fill(0,count($selected),'?')).')';array_push($args,...array_keys($selected));}}
        if($query!==''){$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';$where.=" AND (name COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!' OR email COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!' OR outlet COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!')";array_push($args,$like,$like,$like);}
        $total=(int)Db::run($db,'SELECT COUNT(*) FROM khn_press_contacts WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $rows=Db::run($db,'SELECT id,name,email,outlet,active,version FROM khn_press_contacts WHERE '.$where.' ORDER BY outlet,name,id LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll(PDO::FETCH_ASSOC);
        $focus=$state['focus']??'';if(!is_string($focus))throw new DomainException('Kontakt auswählen.');if($focus!=='')(new PressContacts($db,$access))->get($focus);
        return array_replace($state,['selected'=>$selected,'query'=>$query,'status'=>$status,'mode'=>$mode,'page'=>$page,'total'=>$total,'pages'=>$pages,'rows'=>$rows,'focus'=>$focus]);
    }
}
