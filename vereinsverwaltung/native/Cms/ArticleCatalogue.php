<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Bounded article pages; literal Unicode search retains the original editor semantics. */
final class ArticleCatalogue
{
    public const PAGE_SIZE=6;
    public function __construct(private PDO $db,private Access $access){}
    public static function filters(array $input):array
    {
        $q=$input['q']??'';$status=$input['status']??'active';$year=$input['year']??'';$page=$input['page']??1;
        if(!is_string($q)||preg_match('//u',$q)!==1||preg_match('/[\x00-\x1f\x7f]/',$q)||mb_strlen($q)>120
            ||!is_string($status)||!in_array($status,['active','archive','draft','published','review','approved','all'],true)
            ||!is_string($year)||($year!==''&&!preg_match('/\A(?:19|20|21)[0-9]{2}\z/D',$year))
            ||(!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$page)
            ||($page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false)
            throw new InvalidArgumentException('Suchtext, Beitragsstatus, Jahr und Seite prüfen.');
        return ['q'=>trim($q),'status'=>$status,'year'=>$year,'page'=>$page];
    }
    public function listing(array $input=[]):array
    {
        $filters=self::filters($input);
        return Db::atomic($this->db,function()use($filters):array{
            $club=$this->access->require('website','read')['club_id'];
            $from=' FROM khcms_pages p LEFT JOIN khcms_archives a ON a.club_id=p.club_id AND a.page_id=p.id WHERE p.club_id=? AND '.PageTrash::activeSql()." AND JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template'))='news'";
            [$policy,$policyArgs]=TemplatePolicy::readFilter($this->db,$this->access);
            $from.=' AND ('.$policy.')';$args=[$club,...$policyArgs];$status=$filters['status'];
            if($status==='archive')$from.=' AND a.archived_at IS NOT NULL';
            elseif($status!=='all')$from.=' AND a.archived_at IS NULL';
            if($status==='draft')$from.=' AND p.published_revision IS NULL';
            if($status==='published')$from.=' AND p.published_revision IS NOT NULL';
            if(in_array($status,['review','approved'],true)){$from.=" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.news_review')),'draft')=?";$args[]=$status;}
            if($filters['year']!==''){$from.=" AND JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.news_date')) LIKE ?";$args[]=$filters['year'].'-%';}
            $order=" ORDER BY JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.news_date')) COLLATE utf8mb4_bin DESC,p.slug COLLATE utf8mb4_bin,p.id COLLATE utf8mb4_bin";
            $requested=$filters['page'];$ids=[];
            if($filters['q']===''){
                $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from,$args)->fetchColumn();$last=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($requested,$last);
                $ids=Db::run($this->db,'SELECT p.id'.$from.$order.' LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),$args)->fetchAll(PDO::FETCH_COLUMN);
            }else{
                $pattern='/'.preg_quote($filters['q'],'/').'/iu';$total=0;$tail=[];$cursor=null;
                $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                try{
                    $cursor=Db::run($this->db,"SELECT p.id,p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.excerpt')) AS excerpt".$from.$order,$args);
                    while($row=$cursor->fetch(PDO::FETCH_ASSOC)){
                        if(!preg_match($pattern,$row['title'])&&!preg_match($pattern,$row['excerpt'])&&!preg_match($pattern,$row['slug']))continue;
                        if($total%self::PAGE_SIZE===0)$tail=[];$tail[]=$row['id'];
                        if(intdiv($total,self::PAGE_SIZE)+1===$requested)$ids[]=$row['id'];$total++;
                    }
                }finally{$cursor?->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
                $last=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($requested,$last);if($requested>$last)$ids=$tail;
            }
            $store=new Articles($this->db,$this->access);$items=array_map($store->get(...),$ids);
            $filters['page']=$page;return ['items'=>$items,'total'=>$total,'page'=>$page,'last'=>$last,'filters'=>$filters];
        });
    }
}
