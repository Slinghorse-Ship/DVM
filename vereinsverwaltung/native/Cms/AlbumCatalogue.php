<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Original PHP ordering over small keys; no complete page, revision or variant payloads. */
final class AlbumCatalogue
{
    public const PAGE_SIZE=25;
    public function __construct(private PDO $db,private Access $access) {}

    public function listing(array $filters=[]): array
    {
        $query=$filters['q']??'';$status=$filters['status']??'all';$requested=$filters['album_list_page']??'1';
        if(!is_string($query)||preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query)||mb_strlen($query)>120
            ||!is_string($status)||!in_array($status,['all','draft','published'],true)
            ||(!is_string($requested)&&!is_int($requested))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$requested)
            ||($requested=filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false)
            throw new InvalidArgumentException('Suchtext, Albumstatus und Seitenzahl prüfen.');
        $query=trim($query);$pattern='/'.preg_quote($query,'/').'/iu';
        return Db::atomic($this->db,function()use($query,$pattern,$status,$requested):array{
            $club=$this->access->require('website','read')['club_id'];$state=(new AlbumOrder($this->db,$this->access))->state();
            [$from,$parameters]=$this->from();
            $ids=$this->orderedIds($club,$state);$ranks=array_flip($ids);$matches=[];
            if($query===''&&$status==='all')$matches=$ids;
            else{
                $projection='p.id,p.published_revision';
                if($query!=='')$projection.=",p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.excerpt')) AS excerpt,JSON_EXTRACT(p.draft_json,'$.gallery_items') AS items_json";
                $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$cursor=null;
                $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                try{
                    $cursor=Db::run($this->db,'SELECT '.$projection.$from,$parameters);
                    while($row=$cursor->fetch(PDO::FETCH_ASSOC)){
                        if(($status==='draft'&&$row['published_revision']!==null)||($status==='published'&&$row['published_revision']===null))continue;
                        if($query!==''){
                            $text=$row['title']."\n".$row['slug']."\n".$row['excerpt'];
                            foreach(json_decode($row['items_json']??'[]',true,16,JSON_THROW_ON_ERROR)??[]as$entry)$text.="\n".$entry['caption']."\n".implode(' ',$entry['tags']);
                            if(!preg_match($pattern,$text))continue;
                        }
                        $matches[$ranks[$row['id']]]=$row['id'];
                    }
                }finally{
                    $cursor?->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
                }
                ksort($matches,SORT_NUMERIC);$matches=array_values($matches);
            }
            $total=count($matches);$last=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($requested,$last);$window=array_slice($matches,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE);$items=[];
            if($window){
                $projection="p.id,p.version,p.slug,p.published_slug,p.published_revision,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.excerpt')) AS excerpt,COALESCE(JSON_LENGTH(JSON_EXTRACT(p.draft_json,'$.media_ids')),0) AS image_count,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.media_ids[0]')) AS cover_id";
                $rows=Db::run($this->db,'SELECT '.$projection.$from.' AND p.id IN ('.implode(',',array_fill(0,count($window),'?')).')',[...$parameters,...$window])->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
                foreach($window as$id){$row=$rows[$id];$row['id']=$id;$row['version']=(int)$row['version'];$row['image_count']=(int)$row['image_count'];$row['published_revision']=$row['published_revision']===null?null:(int)$row['published_revision'];$row['has_unpublished_changes']=$row['published_revision']!==null&&$row['version']!==$row['published_revision'];$row['rank']=$ranks[$id];$items[]=$row;}
            }
            return ['items'=>$items,'total'=>$total,'all_total'=>count($ids),'page'=>$page,'last'=>$last,'order_version'=>$state['version'],'q'=>$query,'status'=>$status];
        });
    }

    /** Inspect at most one identity beyond the existing 500 album movement boundary. */
    public function order(): array
    {
        return Db::atomic($this->db,function():array{
            $club=$this->access->require('website','read')['club_id'];$state=(new AlbumOrder($this->db,$this->access))->state();
            return $this->orderedIds($club,$state,501);
        });
    }

    private function orderedIds(string $club,array $state,int $limit=0): array
    {
        $ids=$state['ids'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>500)throw new \DomainException('Gespeicherte Albumreihenfolge prüfen.');
        foreach($ids as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \DomainException('Gespeicherte Albumreihenfolge prüfen.');
        [$from,$parameters]=$this->from();
        $keys=Db::run($this->db,"SELECT p.id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.nav_order')),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.nav_order')),'0') AS nav_order,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')),'') AS title".$from.' ORDER BY p.updated_at DESC,p.id'.($limit?' LIMIT '.$limit:''),$parameters)->fetchAll(PDO::FETCH_ASSOC);
        if(!$limit||count($keys)<$limit){
            // Numeric titles must retain the same PHP comparison and input order
            // as the public AlbumOrder projection, including mixed numeric titles.
            $keys=AlbumOrder::sortKeys($keys,$ids);
        }
        return array_column($keys,'id');
    }

    private function from(): array
    {
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        return [" FROM khcms_pages p LEFT JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision AND p.published_slug<>'' AND ".NewsChannels::where()."
            WHERE (".$policy.") AND ".PageTrash::activeSql()." AND NOT EXISTS(SELECT 1 FROM khcms_archives a WHERE a.club_id=p.club_id AND a.page_id=p.id)
            AND p.slug<>'galerie' AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template')))='gallery'",$parameters];
    }
}
