<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db,Identity};

/** A selected immutable source and target revision already define the original reuse. */
final class VideoReuseRequests
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    private function actor():array
    {
        $actor=$this->access->require('social','read');$editor=$this->access->require('social','edit');
        if($editor['id']!==$actor['id']||$editor['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');
        return$actor;
    }
    private function same(array $actor):void
    {$now=$this->actor();if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}
    private function context(string $item,string $video,string $source,int $version,int $identity):array
    {
        if($this->db->inTransaction())throw new DomainException('Videoübernahme benötigt einen eigenen aktuellen Vorgang.');
        foreach([$item,$video,$source]as$id)Content::id($id);if($version<1||$version>4294967295||$identity<0||$identity>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');
        $actor=$this->actor();(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);
        $original=Db::run($this->db,'SELECT source_id,identity_version FROM native_social_videos WHERE club_id=? AND id=?',[$actor['club_id'],$video])->fetch();
        if(!$original||$original['source_id']!==$source)throw new DomainException('Eigene ursprüngliche Videofassung auswählen.');
        $this->same($actor);return[$actor,$original];
    }
    /** A negative observation is not a statement that an earlier writer failed. */
    public function review(string $item,string $video,string $source,int $version,int $identity):array
    {
        [$actor]=$this->context($item,$video,$source,$version,$identity);$id=Videos::reuseIdentity($actor['club_id'],$item,$version,$identity,$source);
        $saved=(new Videos($this->db,$this->access,$this->mediaRoot))->reuseCheck($item,$id);
        if(!$saved){$this->same($actor);return['found'=>false];}
        if($saved['source_id']!==$source||(int)$saved['item_version']!==$version||(int)$saved['identity_version']!==$identity)throw new DomainException('Ursprüngliche Videoübernahme prüfen.');
        $row=Db::run($this->db,'SELECT created_at FROM native_social_videos WHERE club_id=? AND item_id=? AND id=?',[$actor['club_id'],$item,$id])->fetch();
        if(!$row)throw new DomainException('Ursprüngliche Videoübernahme prüfen.');
        $archived=VideoArchive::installed($this->db)?(bool)Db::run($this->db,'SELECT archived FROM native_video_archives WHERE club_id=? AND source_id=?',[$actor['club_id'],$source])->fetchColumn():false;
        $fileState=VideoRemoval::installed($this->db)?(Db::run($this->db,'SELECT state FROM native_video_removal_states WHERE club_id=? AND source_id=?',[$actor['club_id'],$source])->fetchColumn()?:'active'):'active';
        $this->same($actor);
        return['found'=>true,'item_id'=>$item,'source_id'=>$source,'video_id'=>$id,'item_version'=>$version,'identity_version'=>$identity,'original_name'=>$saved['original_name'],'created_at'=>$row['created_at'],'archived'=>$archived,'file_state'=>$fileState];
    }
    /** Explicitly chooses a new current revision without binding or copying a source. */
    public function prepare(string $item,string $video,string $source,int $version,int $identity):array
    {
        [$actor,$original]=$this->context($item,$video,$source,$version,$identity);$result=$this->review($item,$video,$source,$version,$identity);
        if($result['found'])return['original'=>$result,'prepared'=>null];
        $current=(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);$clubIdentity=Identity::forClub($this->db,$actor['club_id']);
        if($current['kind']!=='reel'||!in_array($current['status'],['draft','review'],true))throw new DomainException('Video in einen eigenen Reel-Entwurf übernehmen.');
        if((int)$original['identity_version']!==$clubIdentity['version'])throw new DomainException('Video enthält einen früheren Vereinsstand. Neue Fassung speichern.');
        if((new VideoArchive($this->db,$this->access))->get($source)['archived'])throw new DomainException('Video zuerst aus dem Archiv zurückholen.');if(!VideoRemoval::available($this->db,$actor['club_id'],$source))throw new DomainException('Ursprüngliche Videodatei nicht verfügbar.');$this->same($actor);
        $latest=$this->review($item,$video,$source,$version,$identity);if($latest['found'])return['original'=>$latest,'prepared'=>null];
        return['original'=>['found'=>false],'prepared'=>['version'=>(string)$current['version'],'identity_version'=>(string)$clubIdentity['version']]];
    }
    public function save(string $item,string $video,string $source,int $version,int $identity):array
    {
        [$actor]=$this->context($item,$video,$source,$version,$identity);$before=$this->review($item,$video,$source,$version,$identity);if($before['found'])return$before;
        try{(new Videos($this->db,$this->access,$this->mediaRoot))->reuse($item,$version,$identity,$video);}
        catch(Throwable$error){if(!$this->db->inTransaction()){$this->same($actor);$known=$this->review($item,$video,$source,$version,$identity);if($known['found'])return$known;}throw$error;}
        $result=$this->review($item,$video,$source,$version,$identity);if(!$result['found'])throw new DomainException('Ursprüngliche Videoübernahme prüfen.');$this->same($actor);return$result;
    }
}
