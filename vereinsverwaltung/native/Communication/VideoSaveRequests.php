<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** A saved MP4 and its actor-bound original receipt share the video's owned transaction. */
final class VideoSaveRequests
{
    private const MODULE='social-video';
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');if($write)$this->access->require('social','edit');return$actor;
    }
    private function key(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Videoformular öffnen.');
        if($this->db->inTransaction())throw new DomainException('Video braucht einen eigenen Speichervorgang.');
    }
    private function sameActor(array $actor,bool $write):void
    {
        $current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],self::MODULE,$actor['id'],$key])->fetch();
    }
    private function lock(array $actor,string $key):string
    {
        $lock='social-video:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Video wird noch gespeichert. Gespeichertes Video prüfen.');return$lock;
    }
    private function original(array $row,string $item):array
    {
        if($row['item_id']!==$item||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Videonachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Videonachweis prüfen.');}
        $video=$snapshot['video']??null;$source=$snapshot['source']??null;
        if(!is_array($video)||!is_array($source)||($snapshot['module']??null)!==self::MODULE||($snapshot['actor_id']??null)!==$row['actor_id']||($video['club_id']??null)!==$row['club_id']||($source['club_id']??null)!==$row['club_id']||($source['actor_id']??null)!==$row['actor_id']||($video['item_id']??null)!==$item||($video['item_version']??null)!==(int)$row['item_version']||($video['source_id']??null)!==($source['id']??null))throw new DomainException('Gespeicherten Videonachweis prüfen.');
        Content::id($video['id']??null);Content::id($source['id']??null);
        $actualVideo=Db::run($this->db,'SELECT * FROM native_social_videos WHERE club_id=? AND id=?',[$row['club_id'],$video['id']])->fetch();
        $actualSource=Db::run($this->db,'SELECT * FROM native_social_video_sources WHERE club_id=? AND id=?',[$row['club_id'],$source['id']])->fetch();
        if($actualVideo!==$video||$actualSource!==$source)throw new DomainException('Gespeicherten Videonachweis prüfen.');
        $file=(new Videos($this->db,$this->access,$this->mediaRoot))->file($video['id']);
        if($file['sha256']!==$source['sha256']||$file['bytes']!==(int)$source['bytes'])throw new DomainException('Gespeicherten Videonachweis prüfen.');
        return['id'=>$video['id'],'request_key'=>$row['request_key'],'item_id'=>$item,'item_version'=>(int)$row['item_version'],'identity_version'=>(int)$video['identity_version'],'name'=>$source['original_name'],'sha256'=>$source['sha256'],'bytes'=>(int)$source['bytes'],'original_url'=>'/social-video/'.$video['id']];
    }
    public function review(string $item,string $key):?array
    {
        $actor=$this->actor();$this->key($key);(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);$lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$item):null;$this->sameActor($actor,false);return$result;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    public function save(string $item,int $version,int $identity,string $source,string $name,string $key):array
    {
        $actor=$this->actor(true);$this->key($key);Content::id($item);$name=basename(str_replace('\\','/',$name));
        if($version<1||$version>=4294967295||$identity<0||$identity>=4294967295||$name===''||preg_match('//u',$name)!==1||mb_strlen($name)>255||preg_match('/[\x00-\x1F\x7F]/',$name))throw new DomainException('Videoformular und Dateiname prüfen.');
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)||is_link($source)||!is_file($source)||filesize($source)<32||filesize($source)>ReelFile::MAX_BYTES)throw new DomainException('MP4-Datei darf höchstens 100 MiB groß sein.');
        $proof=hash_file('sha256',$source);$hash=hash('sha256',json_encode([$item,$version,$identity,$name,$proof],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,true);$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zum ursprünglichen Video. Gespeichertes Video prüfen.');$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;}
            try{
                (new Videos($this->db,$this->access,$this->mediaRoot))->save($item,$version,$identity,$source,$name,function(string $id)use($actor,$item,$version,$key,$hash,$proof):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Videotransaktion fehlt.');$this->sameActor($actor,true);
                    $video=Db::run($this->db,'SELECT * FROM native_social_videos WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch();
                    $original=Db::run($this->db,'SELECT * FROM native_social_video_sources WHERE club_id=? AND id=?',[$actor['club_id'],$video['source_id']])->fetch();
                    if(!hash_equals($proof,$original['sha256']))throw new DomainException('Videodatei während der Speicherung geändert.');
                    $snapshot=json_encode(['module'=>self::MODULE,'actor_id'=>$actor['id'],'video'=>$video,'source'=>$original],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],self::MODULE,$actor['id'],$key,$hash,$item,$version,$snapshot,hash('sha256',$snapshot)]);
                });
                $row=$this->row($actor,$key);if(!$row)throw new DomainException('Gespeicherten Videonachweis prüfen.');$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->sameActor($actor,true);$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash)){$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;}}throw$error;}
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
