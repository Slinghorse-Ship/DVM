<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Verein\Pagination;
use PDO;
require_once dirname(__DIR__,2).'/src/Shared/Pagination.php';

/** Six contact metadata rows; selection belongs to the unsaved composer. */
final class PressRecipientCatalogue
{
    public static function ids(mixed $ids): array
    {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>500)throw new \DomainException('Höchstens 500 Presseempfänger auswählen.');
        return array_values(array_unique(array_map(Content::id(...),$ids)));
    }

    public static function selection(array $post): array
    {
        $ids=self::ids($post['recipient_ids']??[]);
        if(!array_key_exists('press_picker_present',$post))return $ids;
        if($post['press_picker_present']!=='1')throw new \DomainException('Presseauswahl erneut öffnen.');
        $visible=self::ids($post['press_visible']??[]);$checked=self::ids($post['press_choice']??[]);
        if(count($visible)>6||array_diff($checked,$visible))throw new \DomainException('Presseauswahl erneut öffnen.');
        // Keep the original order, remove only explicitly unchecked visible rows,
        // and append new selections. Hidden selections survive every page change.
        $ids=array_values(array_filter($ids,static fn($id)=>!in_array($id,$visible,true)||in_array($id,$checked,true)));
        return self::ids(array_values(array_unique([...$ids,...$checked])));
    }

    public static function state(array $post): array
    {
        $browse=$post['press_browse']??'';
        if(!in_array($browse,['search','previous','next','active','selected'],true))throw new \DomainException('Presseauswahl erneut öffnen.');
        $mode=$post['press_mode']??'active';$page=$post['press_page']??'1';$query=$post['press_query']??'';
        if(!in_array($mode,['active','selected'],true)||!is_string($page)||!preg_match('/^[1-9][0-9]{0,6}$/D',$page)||(int)$page>1000000||!is_string($query))throw new \DomainException('Presseauswahl erneut öffnen.');
        $page=(int)$page;
        if($browse==='previous')$page=max(1,$page-1);
        elseif($browse==='next')$page++;
        else $page=1;
        if(in_array($browse,['active','selected'],true)){$mode=$browse;$query='';}
        return ['recipient_ids'=>self::selection($post),'search'=>Content::text($query,200),'mode'=>$mode,'page'=>$page];
    }

    public static function page(PDO $db,Access $access,array $state): array
    {
        $access->require('press','read');$ids=self::ids($state['recipient_ids']??[]);$mode=$state['mode']??'active';$page=$state['page']??1;
        if(!in_array($mode,['active','selected'],true)||!is_int($page)||$page<1||$page>1000000)throw new \DomainException('Presseauswahl erneut öffnen.');
        $search=Content::text($state['search']??'',200);$club=$access->club();
        $in=$ids?implode(',',array_fill(0,count($ids),'?')):'';
        if($ids&&(int)Db::run($db,"SELECT COUNT(*) FROM khn_press_contacts WHERE club_id=? AND id IN ($in)",[$club,...$ids])->fetchColumn()!==count($ids))throw new \DomainException('Eigene Presseempfänger erneut auswählen.');
        $where='club_id=?';$values=[$club];
        if($mode==='active')$where.=' AND active=1';
        elseif($ids){$where.=" AND id IN ($in)";$values=[...$values,...$ids];}
        else $where.=' AND 1=0';
        if($search!==''){
            $where.=" AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR outlet LIKE ? ESCAPE '!')";
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$values=[...$values,$like,$like,$like];
        }
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM khn_press_contacts WHERE $where",$values)->fetchColumn();$window=Pagination::window($total,$page,6);
        $rows=Db::run($db,"SELECT id,name,email,outlet,active FROM khn_press_contacts WHERE $where ORDER BY outlet,name,id LIMIT 6 OFFSET ".(($window['page']-1)*6),$values)->fetchAll(PDO::FETCH_ASSOC);
        return ['recipient_ids'=>$ids,'search'=>$search,'mode'=>$mode,'rows'=>$rows,'total'=>$total,'page'=>$window['page'],'pages'=>$window['pages']];
    }
}
