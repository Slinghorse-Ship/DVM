<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Verein\Pagination;
require_once dirname(__DIR__,2).'/src/Shared/Pagination.php';
use Kulturhus\Native\Shared\{Access,Db};
final class ContactPage
{
    public static function query(PDO $db,Access $access,string $module,string $search,int $page=1,int $size=25):array
    {
        if(!in_array($module,['newsletter','press'],true)||$page<1||$page>40000||$size<1||$size>100)throw new \DomainException('Ungültige Kontaktseite.');$access->require($module,$module==='newsletter'?'manage':'read');$search=Content::text($search,200);
        $table=$module==='newsletter'?'khn_newsletter_subscribers':'khn_press_contacts';$columns=$module==='newsletter'?'id,email,name,status,purposes,version':'id,email,name,outlet,active,version';$where='club_id=?';$values=[$access->club()];
        if($search!==''){$where.=" AND (email LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!')";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$values[]=$like;$values[]=$like;}
        $total=(int)Db::run($db,"SELECT COUNT(*) FROM $table WHERE $where",$values)->fetchColumn();$window=Pagination::window($total,$page,$size);$page=$window['page'];$rows=Db::run($db,"SELECT $columns FROM $table WHERE $where ORDER BY name,id LIMIT $size OFFSET ".(($page-1)*$size),$values)->fetchAll();
        if($module==='newsletter')foreach($rows as &$row)$row['purposes']=json_decode($row['purposes'],true,16,JSON_THROW_ON_ERROR);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$window['pages'],'search'=>$search];
    }
}
