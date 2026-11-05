<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{PageTrash,Pages,TemplatePolicy};
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

final class Catalog
{
    public function __construct(private PDO $db,private Access $access){}
    public function listing(string $query='',int $page=1):array
    {
        $this->access->require('website','manage');if(!preg_match('//u',$query)||mb_strlen($query)>100||$page<1||$page>1000000)throw new InvalidArgumentException('Seitensuche prüfen.');$query=trim($query);
        [$where,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);$where='('.$where.') AND '.PageTrash::activeSql().' AND NOT EXISTS(SELECT 1 FROM khcms_archives a WHERE a.page_id=p.id AND a.club_id=p.club_id)';
        if($query!==''){$where.=" AND (JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!')";$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';array_push($parameters,$needle,$needle);}
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_pages p WHERE '.$where,$parameters)->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);$ids=Db::run($this->db,'SELECT p.id FROM khcms_pages p WHERE '.$where.' ORDER BY p.slug,p.id LIMIT 10 OFFSET '.(($page-1)*10),$parameters)->fetchAll(PDO::FETCH_COLUMN);$service=new Pages($this->db,$this->access);$rows=[];foreach($ids as$id){$row=$service->get($id);$rows[]=['id'=>$id,'title'=>$row['title'],'slug'=>$row['slug'],'version'=>$row['version']];}return compact('query','rows','page','pages','total');
    }
}
