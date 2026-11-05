<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Bounded private metadata pages, exact approved originals and the latest editable slides. */
final class ImageHistory
{
    public const PAGE_SIZE = 6;
    // Bound the SQL sort key too: an uncast JSON string reserves the whole large
    // metadata width per sort record even when no document is selected.
    private const GENERATION = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.generated_at')),DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s.%f')) AS CHAR(26))";
    private const ORDER = 'generation_stamp DESC,id DESC';
    public function __construct(private PDO $db,private Access $access) {}

    public function page(string $item,mixed $page=1,string $anchor='',mixed $expectedTotal=null): array
    {
        Content::id($item);
        if ((!is_string($page)&&!is_int($page)) || !preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page) || (int)$page>1000000) throw new DomainException('Bildverlaufsseite prüfen.');
        if ($anchor!=='') Content::id($anchor);
        if($expectedTotal!==null&&((!is_string($expectedTotal)&&!is_int($expectedTotal))||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$expectedTotal)||(int)$expectedTotal>4294967295))throw new DomainException('Bildverlaufsstand prüfen.');
        return Db::atomic($this->db,function()use($item,$page,$anchor,$expectedTotal):array {
            $club=$this->club();
            if (!Db::run($this->db,'SELECT id FROM khn_social_items WHERE club_id=? AND id=?',[$club,$item])->fetch()) throw new DomainException('Ursprünglicher Social-Inhalt fehlt.');
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_social_images WHERE club_id=? AND item_id=?',[$club,$item])->fetchColumn();
            $list=$this->projection('id,item_version,content_hash,slide,kind,sha256,created_at','club_id=? AND item_id=?');
            $ceiling=Db::run($this->db,'SELECT id FROM '.$list.' ORDER BY '.self::ORDER.' LIMIT 1',[$club,$item])->fetch();
            $reset=$anchor!==''&&($expectedTotal===null||(int)$expectedTotal!==$total||$anchor!==($ceiling['id']??''));
            $pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=$reset?1:min((int)$page,$pages);$offset=($page-1)*self::PAGE_SIZE;
            $rows=Db::run($this->db,'SELECT id,item_version,content_hash,slide,kind,sha256,created_at FROM '.$list.' ORDER BY '.self::ORDER.' LIMIT '.self::PAGE_SIZE.' OFFSET '.$offset,[$club,$item])->fetchAll();
            $this->sameClub($club);
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'anchor'=>$ceiling['id']??'','reset'=>$reset];
        });
    }

    public function originals(string $item,array $ids): array
    {
        Content::id($item);
        if (!array_is_list($ids)||count($ids)>10) throw new DomainException('Höchstens zehn genaue Bildoriginale wählen.');
        foreach($ids as$id)Content::id($id);
        if(count(array_unique($ids,SORT_STRING))!==count($ids))throw new DomainException('Bildoriginale nur einmal wählen.');
        return Db::atomic($this->db,function()use($item,$ids):array {
            $club=$this->club();$rows=[];
            if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
                $rows=Db::run($this->db,'SELECT id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at FROM native_social_images WHERE club_id=? AND item_id=? AND id IN ('.$marks.') FOR SHARE',[$club,$item,...$ids])->fetchAll();
                if(count($rows)!==count($ids))throw new DomainException('Genaues ursprüngliches Bild nicht verfügbar.');
                $byId=array_column($rows,null,'id');$rows=array_map(static fn($id)=>$byId[$id],$ids);
            }
            $this->sameClub($club);return $rows;
        });
    }

    public function documents(string $item,string $kind): array
    {
        Content::id($item);if(!in_array($kind,['post','story','carousel'],true))throw new DomainException('Bildart prüfen.');
        return Db::atomic($this->db,function()use($item,$kind):array {
            $club=$this->club();
            $order=self::ORDER;
            $list=$this->projection('id,slide',"club_id=? AND item_id=? AND kind=? AND slide BETWEEN 0 AND 9 AND JSON_CONTAINS_PATH(metadata_json,'one','$.document')");
            $selected=Db::run($this->db,"SELECT id,slide FROM (SELECT id,slide,ROW_NUMBER() OVER(PARTITION BY slide ORDER BY $order) AS rank_number FROM $list) AS originals WHERE rank_number=1 ORDER BY slide LIMIT 10",[$club,$item,$kind])->fetchAll();
            $rows=$this->originals($item,array_column($selected,'id'));
            $this->sameClub($club);return $rows;
        });
    }

    public function newest(string $item,int $version,string $fingerprint,string $kind,int $slides): array
    {
        Content::id($item);if($slides<1||$slides>10||$version<1||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||!in_array($kind,['post','story','carousel'],true))throw new DomainException('Freigegebene Bildfassung prüfen.');
        return Db::atomic($this->db,function()use($item,$version,$fingerprint,$kind,$slides):array {
            $club=$this->club();$ids=[];
            $list=$this->projection('id','club_id=? AND item_id=? AND item_version=? AND content_hash=? AND kind=? AND slide=?',true);
            for($slide=0;$slide<$slides;++$slide){$id=Db::run($this->db,'SELECT id FROM '.$list.' ORDER BY '.self::ORDER.' LIMIT 1',[$club,$item,$version,$fingerprint,$kind,$slide])->fetchColumn();if($id!==false)$ids[]=$id;}
            $this->sameClub($club);return $this->originals($item,$ids);
        });
    }

    /** LIMIT prevents derived-table merging: sorting must never carry the large JSON column. */
    private function projection(string $fields,string $where,bool $current=false): string
    {
        return '(SELECT '.$fields.','.self::GENERATION.' AS generation_stamp FROM native_social_images WHERE '.$where.' LIMIT 18446744073709551615'.($current?' FOR SHARE':'').') AS image_metadata';
    }

    private function club(): string { return (string)$this->access->require('social','read')['club_id']; }
    private function sameClub(string $club): void { if($this->club()!==$club)throw new DomainException('Vereinszugehörigkeit geändert.'); }
}
