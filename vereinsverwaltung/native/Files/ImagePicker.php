<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\MediaAvailability;
use Kulturhus\Native\Shared\{Access,Db};

/** Existing native originals only: bounded labels, no pixel copy and no duplicate sequence ownership. */
final class ImagePicker
{
    public function __construct(private PDO $db,private Access $access){}
    public function listing(string $query='',int $page=1):array
    {
        if(!preg_match('//u',$query)||mb_strlen($query)>120||preg_match('/[\x00-\x1f\x7f]/',$query)||$page<1||$page>1000000)throw new InvalidArgumentException('Bildsuche prüfen.');
        $club=$this->access->require('website','edit')['club_id'];$where="m.club_id=? AND ".MediaAvailability::activeSql($this->db,'m')." AND NOT EXISTS(SELECT 1 FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.club_id=m.club_id AND r.source_kind='media' AND r.source_id=m.id AND a.state<>'purged')";$args=[$club];
        if($query!==''){$where.=" AND LOWER(CONCAT(m.alt,' ',m.original_name)) LIKE LOWER(?) ESCAPE '!'";$args[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_media m WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT m.id,m.original_name AS name,m.alt FROM khcms_media m WHERE '.$where.' ORDER BY LOWER(m.original_name),m.id LIMIT 25 OFFSET '.(($page-1)*25),$args)->fetchAll();return compact('rows','total','page','pages','query');
    }
}
