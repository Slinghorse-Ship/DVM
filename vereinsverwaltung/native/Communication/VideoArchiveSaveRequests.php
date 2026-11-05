<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** A source catalogue change and its immutable personal confirmation commit together. */
final class VideoArchiveSaveRequests
{
    private const MODULE='video-archive';
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');if($write)$this->access->require('social','edit');return$actor;
    }
    private function sameActor(array $actor,bool $write=false):void
    {
        $current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function context(array $actor,string $item,string $source,string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Videoarchivformular öffnen.');
        if($this->db->inTransaction())throw new DomainException('Videoarchiv braucht einen eigenen Speichervorgang.');Content::id($item);Content::id($source);
        (new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);
        if(!Db::run($this->db,'SELECT 1 FROM native_social_videos WHERE club_id=? AND item_id=? AND source_id=?',[$actor['club_id'],$item,$source])->fetchColumn())throw new DomainException('Eigene Videoquelle erneut öffnen.');
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],self::MODULE,$actor['id'],$key])->fetch();
    }
    private function lock(array $actor,string $key):string
    {
        $lock='video-archive:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Videoarchiv wird noch gespeichert. Gespeicherte Archivwahl prüfen.');return$lock;
    }
    private function original(array $row,string $item,string $source):array
    {
        if($row['item_id']!==$item||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Videoarchivnachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Videoarchivnachweis prüfen.');}$history=$snapshot['history']??null;
        if(($snapshot['module']??null)!==self::MODULE||!is_array($history)||($history['club_id']??null)!==$row['club_id']||($history['actor_id']??null)!==$row['actor_id']||($history['source_id']??null)!==$source||!is_int($history['version']??null)||$history['version']<1||$history['version']!==(int)$row['item_version']||!in_array($history['archived']??null,[0,1],true))throw new DomainException('Gespeicherten Videoarchivnachweis prüfen.');
        $actual=Db::run($this->db,'SELECT * FROM native_video_archive_history WHERE club_id=? AND source_id=? AND version=?',[$row['club_id'],$source,$history['version']])->fetch();if($actual!==$history)throw new DomainException('Gespeicherten Videoarchivnachweis prüfen.');
        return['request_key'=>$row['request_key'],'item_id'=>$item,'source_id'=>$source,'version'=>$history['version'],'archived'=>(bool)$history['archived'],'actor_name'=>$history['actor_name'],'changed_at'=>$history['changed_at']];
    }
    public function review(string $item,string $source,string $key):?array
    {
        $actor=$this->actor();$this->context($actor,$item,$source,$key);$lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$item,$source):null;$this->sameActor($actor);return$result;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    public function save(string $item,string $source,bool $archived,int $version,string $key):array
    {
        $actor=$this->actor(true);$this->context($actor,$item,$source,$key);if($version<0||$version>=4294967295)throw new DomainException('Videoarchivversion prüfen.');
        $hash=hash('sha256',json_encode([$item,$source,$version,$archived],JSON_THROW_ON_ERROR));$lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,true);$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zur ursprünglichen Archivwahl. Gespeicherte Archivwahl prüfen.');$result=$this->original($row,$item,$source);$this->sameActor($actor,true);return$result;}
            try{
                (new VideoArchive($this->db,$this->access))->save($source,$archived,$version,function(array $saved)use($actor,$item,$source,$key,$hash):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Videoarchivtransaktion fehlt.');$this->sameActor($actor,true);
                    $history=Db::run($this->db,'SELECT * FROM native_video_archive_history WHERE club_id=? AND source_id=? AND version=?',[$actor['club_id'],$source,$saved['version']])->fetch();if(!$history)throw new DomainException('Gespeicherten Videoarchivnachweis prüfen.');
                    $snapshot=json_encode(['module'=>self::MODULE,'history'=>$history],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],self::MODULE,$actor['id'],$key,$hash,$item,$saved['version'],$snapshot,hash('sha256',$snapshot)]);
                });
                $row=$this->row($actor,$key);if(!$row)throw new DomainException('Archivwahl bereits aktiv. Aktuellen Stand prüfen.');$result=$this->original($row,$item,$source);$this->sameActor($actor,true);return$result;
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->sameActor($actor,true);$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash)){$result=$this->original($row,$item,$source);$this->sameActor($actor,true);return$result;}}throw$error;}
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
