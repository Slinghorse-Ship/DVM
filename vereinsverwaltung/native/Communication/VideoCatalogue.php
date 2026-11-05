<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Six own active immutable sources; multiple versions never duplicate a source in the catalogue. */
final class VideoCatalogue
{
    public function __construct(private PDO $db,private Access $access){}
    public function page(string $query='',mixed $page=1):array
    {
        if($this->db->inTransaction())throw new DomainException('Videoauswahl benötigt einen eigenen aktuellen Lesevorgang.');
        $query=Content::text($query,200);
        if((!is_string($page)&&!is_int($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Videoauswahlseite prüfen.');
        $actor=$this->access->require('social','edit');$club=$actor['club_id'];$read=$this->access->require('social','read');if($read['id']!==$actor['id']||$read['club_id']!==$club)throw new DomainException('Aktuelle Vereinszuordnung prüfen.');
        if((int)Db::run($this->db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_social_video_sources','native_social_videos')")->fetchColumn()!==2)throw new DomainException('Videomodul über den Installer aktualisieren.');
        $archive=VideoArchive::installed($this->db);$removal=VideoRemoval::installed($this->db);
        $joins=($archive?' LEFT JOIN native_video_archives a ON a.club_id=s.club_id AND a.source_id=s.id':'').($removal?' LEFT JOIN native_video_removal_states r ON r.club_id=s.club_id AND r.source_id=s.id':'');
        $where='s.club_id=?'.($archive?' AND COALESCE(a.archived,0)=0':'').($removal?" AND COALESCE(r.state,'active')='active'":'');$args=[$club];
        if($query!==''){$where.=" AND s.original_name LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';}
        // The earliest immutable version carries the original rendering context, rather than a later reuse claim.
        $from=' FROM native_social_video_sources s JOIN native_social_videos v ON v.club_id=s.club_id AND v.source_id=s.id AND v.id=(SELECT origin.id FROM native_social_videos origin WHERE origin.club_id=s.club_id AND origin.source_id=s.id ORDER BY origin.created_at,origin.id LIMIT 1)'.$joins.' WHERE '.$where;
        $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        $rows=Db::run($this->db,'SELECT v.id AS video_id,s.id AS source_id,s.original_name,s.bytes,s.profile_json,v.identity_version,s.created_at'.$from.' ORDER BY s.created_at DESC,s.id DESC LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();
        foreach($rows as&$row){$row['bytes']=(int)$row['bytes'];$row['identity_version']=(int)$row['identity_version'];$row['profile']=json_decode($row['profile_json'],true,16,JSON_THROW_ON_ERROR);unset($row['profile_json']);}unset($row);
        foreach(['read','edit']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$club)throw new DomainException('Person oder Vereinszuordnung geändert.');}
        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,'query'=>$query];
    }
}
