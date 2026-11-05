<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db,MediaDescription,SecretBox};

/** A public metadata revision and its personal receipt commit together; private proof stays encrypted. */
final class VideoMetadataSaveRequests
{
    private const MODULE='video-metadata';
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private ?SecretBox $box=null){}
    private function actor(bool $write=false,bool $proof=false):array
    {
        $actor=$this->access->require('social','read');if($write)$this->access->require('social','edit');if($proof)$this->access->require('social','manage');return$actor;
    }
    private function sameActor(array $actor,bool $write=false,bool $proof=false):void
    {
        $now=$this->actor($write,$proof);if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function context(array $actor,string $item,string $source,string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Videoangabenformular öffnen.');
        if($this->db->inTransaction())throw new DomainException('Videoangaben brauchen einen eigenen Speichervorgang.');Content::id($item);Content::id($source);
        (new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);
        if(!Db::run($this->db,'SELECT 1 FROM native_social_videos WHERE club_id=? AND item_id=? AND source_id=?',[$actor['club_id'],$item,$source])->fetchColumn())throw new DomainException('Eigene Videoquelle erneut öffnen.');
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],self::MODULE,$actor['id'],$key])->fetch();
    }
    private function lock(array $actor,string $key):string
    {
        $lock='video-metadata:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Videoangaben werden noch gespeichert. Gespeicherte Angaben prüfen.');return$lock;
    }
    private function original(array $row,string $item,string $source):array
    {
        if($row['item_id']!==$item||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');}
        if(($snapshot['module']??null)!==self::MODULE||($snapshot['club_id']??null)!==$row['club_id']||($snapshot['actor_id']??null)!==$row['actor_id']||($snapshot['source_id']??null)!==$source||!is_int($snapshot['version']??null)||$snapshot['version']!==(int)$row['item_version'])throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');
        $revision=Db::run($this->db,'SELECT * FROM native_video_metadata_revisions WHERE club_id=? AND source_id=? AND version=?',[$row['club_id'],$source,$snapshot['version']])->fetch();
        // Hash the entire original revision, including its opaque encrypted proof; never copy its cipher into a receipt or response.
        if(!$revision||$revision['actor_id']!==$row['actor_id']||!hash_equals($snapshot['revision_sha256']??'',hash('sha256',json_encode($revision,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))))throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');
        $metadata=json_decode($revision['payload_json'],true,32,JSON_THROW_ON_ERROR);
        return['request_key'=>$row['request_key'],'item_id'=>$item,'source_id'=>$source,'version'=>$snapshot['version'],'metadata'=>$metadata,'has_proof'=>$revision['consent_cipher']!==null];
    }
    public function review(string $item,string $source,string $key):?array
    {
        $actor=$this->actor();$this->context($actor,$item,$source,$key);$lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$item,$source):null;$this->sameActor($actor);return$result;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    public function save(string $item,string $source,array $input,int $version,?string $proof,string $key):array
    {
        $actor=$this->actor(true,$proof!==null);$this->context($actor,$item,$source,$key);
        if($version<0||$version>=4294967295)throw new DomainException('Gespeicherte Videodatenversion angeben.');$value=MediaDescription::normalize($input,'Video');if($proof!==null)$proof=MediaDescription::text($proof,4000,false,true,'Video');
        $hash=hash('sha256',json_encode([$item,$source,$version,$value,$proof===null?null:hash('sha256',$proof)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,true,$proof!==null);$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zu den ursprünglichen Videoangaben. Gespeicherte Angaben prüfen.');$result=$this->original($row,$item,$source);$this->sameActor($actor,true,$proof!==null);return$result;}
            try{
                (new VideoMetadata($this->db,$this->access,$this->box))->save($source,$value,$version,$proof,function(array $saved)use($actor,$item,$source,$key,$hash,$proof):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Videoangabentransaktion fehlt.');$this->sameActor($actor,true,$proof!==null);
                    $revision=Db::run($this->db,'SELECT * FROM native_video_metadata_revisions WHERE club_id=? AND source_id=? AND version=?',[$actor['club_id'],$source,$saved['version']])->fetch();if(!$revision)throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');
                    $snapshot=json_encode(['module'=>self::MODULE,'club_id'=>$actor['club_id'],'actor_id'=>$actor['id'],'source_id'=>$source,'version'=>$saved['version'],'revision_sha256'=>hash('sha256',json_encode($revision,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],self::MODULE,$actor['id'],$key,$hash,$item,$saved['version'],$snapshot,hash('sha256',$snapshot)]);
                });
                $row=$this->row($actor,$key);if(!$row)throw new DomainException('Gespeicherten Videoangabennachweis prüfen.');$result=$this->original($row,$item,$source);$this->sameActor($actor,true,$proof!==null);return$result;
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->sameActor($actor,true,$proof!==null);$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash)){$result=$this->original($row,$item,$source);$this->sameActor($actor,true,$proof!==null);return$result;}}throw$error;}
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
