<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Bounded metadata only. A picker always returns an immutable revision identifier. */
final class Catalog
{
    public function __construct(private PDO $db,private Access $access){}
    public function listing(string $query='',int $page=1,string $kind='all',bool $mine=false,string $tag='',bool $removed=false):array
    {
        if(!preg_match('//u',$query)||mb_strlen($query)>200||preg_match('/[\x00-\x1f\x7f]/',$query)||$page<1||$page>1000000||!in_array($kind,['all','file','image'],true)||!preg_match('//u',$tag)||mb_strlen($tag)>60||preg_match('/[\x00-\x1f\x7f]/',$tag))throw new InvalidArgumentException('Dateisuche prüfen.');
        $actor=$this->access->require('website',$removed?'manage':'read');$where=['a.club_id=?',$removed?"a.state IN ('removed','purging','purged','detached')":"a.state='active'"];$parameters=[$actor['club_id']];
        if($kind!=='all'){$where[]='a.kind=?';$parameters[]=$kind;}
        if($mine){$where[]='a.created_by=?';$parameters[]=$actor['id'];}
        if(trim($query)!==''){$where[]="(LOWER(r.original_name) LIKE ? ESCAPE '=' OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.title'))) LIKE ? ESCAPE '=' OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.description'))) LIKE ? ESCAPE '=' OR LOWER(JSON_EXTRACT(r.metadata_json,'$.tags')) LIKE ? ESCAPE '=')";$match='%'.str_replace(['=','%','_'],['==','=%','=_'],mb_strtolower(trim($query),'UTF-8')).'%';array_push($parameters,$match,$match,$match,$match);}
        if($tag!==''){$where[]="JSON_CONTAINS(JSON_EXTRACT(r.metadata_json,'$.tags'),JSON_QUOTE(?))";$parameters[]=$tag;}
        $from=' FROM khcms_assets a JOIN khcms_asset_revisions r ON r.id=a.head_id AND r.asset_id=a.id AND r.club_id=a.club_id WHERE '.implode(' AND ',$where);$total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from,$parameters)->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT a.id,a.kind,a.version,a.revision,a.state,a.created_by,a.updated_at,r.id AS revision_id,r.original_name AS name,r.mime,r.bytes,r.sha256,r.source_kind,r.source_id,r.metadata_json'.$from.' ORDER BY a.updated_at DESC,a.id DESC LIMIT 10 OFFSET '.(($page-1)*10),$parameters)->fetchAll();
        foreach($rows as&$row){$row['metadata']=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);unset($row['metadata_json']);foreach(['version','revision','bytes']as$key)$row[$key]=(int)$row[$key];}
        return compact('rows','total','pages','page','query','kind','mine','tag','removed');
    }
}
