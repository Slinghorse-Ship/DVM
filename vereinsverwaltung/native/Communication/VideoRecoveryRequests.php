<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db,Identity};

/** The existing original-file recovery plan authorises one copy; it is not a new upload. */
final class VideoRecoveryRequests
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');foreach($write?['manage','edit']:['manage']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');}return$actor;
    }
    private function same(array $actor,bool $write=false):void
    {$current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');}
    private function context(string $item,string $source):array
    {
        if($this->db->inTransaction())throw new DomainException('Video-Wiederherstellungsprüfung benötigt einen eigenen aktuellen Vorgang.');Content::id($item);Content::id($source);$actor=$this->actor();(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);$this->same($actor);return$actor;
    }
    public static function name(string $name):string
    {$name=Content::text($name,255,true);if(basename(str_replace('\\','/',$name))!==$name)throw new DomainException('Einfachen Videonamen angeben.');return$name;}
    private function fingerprint(string $fingerprint):void
    {if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new DomainException('Videodatei erneut prüfen.');}
    /** Only facts of this original plan, with its actual final video revision when complete. */
    public function review(string $item,string $source,string $fingerprint,string $name):array
    {
        $actor=$this->context($item,$source);$this->fingerprint($fingerprint);$name=self::name($name);$plan=(new VideoRecovery($this->db,$this->access,$this->mediaRoot))->prepare($source);
        if($plan['fingerprint']!==$fingerprint)throw new DomainException('Ursprünglichen Video-Wiederherstellungsvorgang prüfen.');
        if($plan['state']==='new'){$this->same($actor);return['found'=>false];}
        if($plan['item_id']!==$item||$plan['original_name']!==$name)throw new DomainException('Ursprünglichen Wiederherstellungsbeitrag und Videonamen beibehalten.');
        $result=['found'=>true,'item_id'=>$item,'original_id'=>$source,'fingerprint'=>$fingerprint,'original_name'=>$plan['original_name'],'state'=>$plan['state'],'created_at'=>$plan['created_at']];
        if($plan['state']==='complete'){
            $video=Db::run($this->db,'SELECT item_id,source_id,item_version,identity_version,created_at FROM native_social_videos WHERE club_id=? AND id=?',[$actor['club_id'],$plan['video_id']])->fetch();
            if(!$video||$video['item_id']!==$item||$video['source_id']!==$plan['target_id'])throw new DomainException('Ursprünglichen Video-Wiederherstellungsvorgang prüfen.');
            $result+=['target_id'=>$plan['target_id'],'video_id'=>$plan['video_id'],'item_version'=>(int)$video['item_version'],'identity_version'=>(int)$video['identity_version'],'completed_at'=>$video['created_at'],'file_state'=>$plan['file_state'],'archived'=>$plan['archived']];
        }
        $this->same($actor);return$result;
    }
    public function current(string $item,string $source):array
    {
        $actor=$this->context($item,$source);$work=(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);$plan=(new VideoRecovery($this->db,$this->access,$this->mediaRoot))->prepare($source);if(isset($plan['item_id'])&&$plan['item_id']!==$item)throw new DomainException('Ursprünglichen Vorgang und Beitrag auswählen.');$identity=Identity::forClub($this->db,$actor['club_id']);$this->same($actor);
        return['item_version'=>$work['version'],'identity_version'=>$identity['version'],'item_status'=>$work['status'],'item_kind'=>$work['kind'],'original_id'=>$source,'fingerprint'=>$plan['fingerprint'],'state'=>$plan['state'],'original_name'=>$plan['original_name']??null];
    }
    public function prepare(string $item,string $source,string $fingerprint,string $name):array
    {
        $actor=$this->actor(true);$name=self::name($name);$this->review($item,$source,$fingerprint,$name);$current=$this->current($item,$source);$this->same($actor,true);
        if($current['fingerprint']!==$fingerprint||$current['original_name']!==null&&$current['original_name']!==$name)throw new DomainException('Ursprünglichen Wiederherstellungsbeitrag und Videonamen beibehalten.');
        if($current['state']==='complete')throw new DomainException('Videofassung bereits gespeichert. Vorgang prüfen.');
        if($current['item_kind']!=='reel'||!in_array($current['item_status'],['draft','review'],true))throw new DomainException('Video in einen eigenen Reel-Entwurf wiederherstellen.');
        return['version'=>(string)$current['item_version'],'identity_version'=>(string)$current['identity_version'],'recovery_name'=>$name,'source_state'=>$current['state']];
    }
    public function save(string $item,string $source,string $fingerprint,string $name,int $version,int $identity):array
    {
        if($version<1||$version>4294967295||$identity<0||$identity>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');$actor=$this->actor(true);$name=self::name($name);$before=$this->review($item,$source,$fingerprint,$name);$this->same($actor,true);
        if($before['found']&&$before['state']==='complete')return$before;
        try{(new VideoRecovery($this->db,$this->access,$this->mediaRoot))->recover($source,$fingerprint,$item,$version,$identity,$name);}
        catch(Throwable$error){
            if(!$this->db->inTransaction()){$this->same($actor,true);$known=$this->review($item,$source,$fingerprint,$name);if($known['found']&&($known['state']==='complete'||!($error instanceof DomainException||$error instanceof \Kulturhus\Native\Cms\Conflict))){$this->same($actor,true);return$known;}}throw$error;
        }
        $result=$this->review($item,$source,$fingerprint,$name);if(!$result['found'])throw new DomainException('Ursprünglichen Video-Wiederherstellungsvorgang prüfen.');$this->same($actor,true);return$result;
    }
}
