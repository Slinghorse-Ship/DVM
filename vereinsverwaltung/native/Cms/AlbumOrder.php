<?php
declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Public display order has its own revision stream and never promotes an album draft. */
final class AlbumOrder
{
    public function __construct(private readonly PDO $db, private readonly Access $access) {}

    public function state(): array
    {
        $this->access->require('website','read');
        $row=Db::run($this->db,'SELECT version,ids_json FROM khcms_album_order WHERE club_id=?',[$this->access->club()])->fetch(PDO::FETCH_ASSOC);
        return $row?['version'=>(int)$row['version'],'ids'=>json_decode($row['ids_json'],true,512,JSON_THROW_ON_ERROR)]:['version'=>0,'ids'=>[]];
    }

    public function sort(array $albums): array
    {
        return $this->ordered($albums,$this->state()['ids']);
    }

    /** Exact original PHP comparison shared by small private and published keys. */
    public static function sortKeys(array $keys,array $ids): array
    {
        $ranks=array_flip($ids);
        usort($keys,static fn($a,$b)=>[$ranks[$a['id']]??PHP_INT_MAX,(int)$a['nav_order'],$a['title'],$a['id']]<=>[$ranks[$b['id']]??PHP_INT_MAX,(int)$b['nav_order'],$b['title'],$b['id']]);
        return $keys;
    }

    /** Complete order version, not a page version. Movement acts on the unfiltered album list. */
    public function move(string $id,string $direction,int $expectedVersion): array
    {
        $this->access->require('website','publish');
        if(!in_array($direction,['up','down'],true))throw new InvalidArgumentException('Ungültige Sortierrichtung.');
        return Db::atomic($this->db,function()use($id,$direction,$expectedVersion):array{
            $actor=$this->access->require('website','publish');$club=$this->access->club();
            Db::run($this->db,'INSERT INTO khcms_state(club_id,generation) VALUES(?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
            $row=Db::run($this->db,'SELECT version,ids_json FROM khcms_album_order WHERE club_id=? FOR UPDATE',[$club])->fetch(PDO::FETCH_ASSOC);
            $version=$row?(int)$row['version']:0;
            if($expectedVersion<0||$version!==$expectedVersion)throw new Conflict('Albumreihenfolge wurde inzwischen geändert. Bitte neu laden.');
            // Complete original identities are private here: filtering before finding the
            // actual neighbor would skip protected albums or erase them from the saved order.
            $ids=$this->completeOrder($row?json_decode($row['ids_json'],true,512,JSON_THROW_ON_ERROR):[]);
            if(count($ids)>500)throw new InvalidArgumentException('Albumreihenfolge ist auf 500 Alben begrenzt.');
            $index=array_search($id,$ids,true);if($index===false)throw new DomainException('Album nicht verfügbar.');
            TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
            $next=$index+($direction==='up'?-1:1);
            if($next<0||$next>=count($ids))return ['version'=>$version,'ids'=>$ids];
            TemplatePolicy::assertCapability($this->db,$this->access,$ids[$next],'publish');
            [$ids[$index],$ids[$next]]=[$ids[$next],$ids[$index]];$version++;
            $json=json_encode($ids,JSON_THROW_ON_ERROR);
            Db::run($this->db,'INSERT INTO khcms_album_order(club_id,version,ids_json,updated_by,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=?,ids_json=?,updated_by=?,updated_at=UTC_TIMESTAMP(6)',[$club,$version,$json,$actor['id'],$version,$json,$actor['id']]);
            Db::run($this->db,'INSERT INTO khcms_album_order_revisions(club_id,version,ids_json,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$version,$json,$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            return ['version'=>$version,'ids'=>$ids];
        });
    }

    /** Read-only UI projection: a move needs both the selected and its real adjacent album. */
    public function movements(array $visibleIds): array
    {
        if(!array_is_list($visibleIds)||count($visibleIds)>500)throw new DomainException('Gespeicherte Albumreihenfolge prüfen.');
        $result=[];foreach($visibleIds as $id){if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($result[$id]))throw new DomainException('Gespeicherte Albumreihenfolge prüfen.');$result[$id]=['up'=>false,'down'=>false];}
        if(!$visibleIds)return $result;
        try{$this->access->require('website','publish');}catch(DomainException){return $result;}
        $ids=$this->completeOrder($this->state()['ids']);if(!$ids||count($ids)>500)return $result;
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',false,'publish');
        $allowed=array_fill_keys(Db::run($this->db,'SELECT p.id FROM khcms_pages p WHERE p.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND ('.$policy.')',[...$ids,...$parameters])->fetchAll(PDO::FETCH_COLUMN),true);
        $positions=array_flip($ids);
        foreach($visibleIds as $id){
            if(!isset($allowed[$id],$positions[$id]))continue;
            foreach(['up'=>-1,'down'=>1] as $direction=>$step){$neighbor=$ids[$positions[$id]+$step]??null;$result[$id][$direction]=$neighbor!==null&&isset($allowed[$neighbor]);}
        }
        return $result;
    }

    /** Only bounded original identity/order keys; never exposed as a private catalogue DTO. */
    private function completeOrder(array $order): array
    {
        if(!array_is_list($order)||count($order)>500)throw new DomainException('Gespeicherte Albumreihenfolge prüfen.');
        foreach($order as $id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Gespeicherte Albumreihenfolge prüfen.');
        $keys=Db::run($this->db,"SELECT p.id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.nav_order')),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.nav_order')),'0') AS nav_order,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')),'') AS title FROM khcms_pages p LEFT JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision AND p.published_slug<>'' AND ".NewsChannels::where()." WHERE p.club_id=? AND ".PageTrash::activeSql()." AND NOT EXISTS(SELECT 1 FROM khcms_archives a WHERE a.club_id=p.club_id AND a.page_id=p.id) AND p.slug<>'galerie' AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.template')))='gallery' ORDER BY p.updated_at DESC,p.id LIMIT 501",[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        return array_column(count($keys)<501?self::sortKeys($keys,$order):$keys,'id');
    }

    private function ordered(array $albums,array $ids): array
    {
        $ranks=array_flip($ids);$baseline=[];$pages=new Pages($this->db,$this->access);
        foreach($albums as $album){
            // A draft may contain unfinished order/title changes. Initial public ordering uses
            // its published keys; unapproved edits cannot shift other albums when a row moves.
            $public=!empty($album['published_slug'])?$pages->publicPage($album['published_slug']):null;
            $baseline[$album['id']]=$public??$album;
        }
        usort($albums,static fn(array $a,array $b):int=>[$ranks[$a['id']]??PHP_INT_MAX,$baseline[$a['id']]['nav_order'],$baseline[$a['id']]['title'],$a['id']]<=>[$ranks[$b['id']]??PHP_INT_MAX,$baseline[$b['id']]['nav_order'],$baseline[$b['id']]['title'],$b['id']]);
        return $albums;
    }
}
