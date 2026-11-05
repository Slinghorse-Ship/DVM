<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db};

final class SubscriberCatalogue
{
    public static function page(PDO $db,Access $access,array $state): array
    {
        $access->require('newsletter','manage');
        $selected=SubscriberSelection::versions($state['selected']??[]);
        $query=Content::text($state['query']??'',200);
        $status=$state['status']??'all';$mode=$state['mode']??'all';$page=SubscriberSelection::number($state['page']??1);
        if (!in_array($status,['all','confirmed','pending','unsubscribed'],true)||!in_array($mode,['all','selected'],true)) throw new DomainException('Empfängerfilter prüfen.');
        $club=$access->club();$where='club_id=?';$params=[$club];
        if ($selected) {
            $in=implode(',',array_fill(0,count($selected),'?'));
            if ((int)Db::run($db,"SELECT COUNT(*) FROM khn_newsletter_subscribers WHERE club_id=? AND id IN ($in)",[$club,...array_keys($selected)])->fetchColumn()!==count($selected)) throw new DomainException('Ausgewählter Empfänger nicht verfügbar.');
        }
        if ($mode==='selected') {
            if (!$selected) $where.=' AND 1=0';
            else { $where.=' AND id IN ('.implode(',',array_fill(0,count($selected),'?')).')';array_push($params,...array_keys($selected)); }
        } else {
            if ($status!=='all') {$where.=' AND status=?';$params[]=$status;}
            if ($query!=='') { $where.=" AND (email LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!')";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';array_push($params,$like,$like); }
        }
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM khn_newsletter_subscribers WHERE $where",$params)->fetchColumn();
        $pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $rows=Db::run($db,"SELECT id,email,name,status,purposes,version FROM khn_newsletter_subscribers WHERE $where ORDER BY name,id LIMIT 6 OFFSET ".(($page-1)*6),$params)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['version']=(int)$row['version'];$row['purposes']=json_decode($row['purposes'],true,16,JSON_THROW_ON_ERROR);}unset($row);
        return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,...$state,'query'=>$query,'status'=>$status,'mode'=>$mode,'page'=>$page,'selected'=>$selected];
    }
}
