<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** A file operation is identified by its existing durable operation and exact original phase. */
final class VideoRemovalRequests
{
    private const PHASES=['remove'=>['removing','removed'],'restore'=>['restoring','active'],'purge'=>['purging','purged']];
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');if($write)$this->access->require('social','manage');return$actor;
    }
    private function sameActor(array $actor,bool $write=false):void
    {
        $current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function context(array $actor,string $item,string $source):void
    {
        if($this->db->inTransaction())throw new DomainException('Videodateivorgang braucht einen eigenen aktuellen Vorgang.');Content::id($item);Content::id($source);
        if(!VideoRemoval::installed($this->db))throw new DomainException('Video-Papierkorb über den Installer vervollständigen.');
        (new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);
        if(!Db::run($this->db,'SELECT 1 FROM native_social_videos WHERE club_id=? AND item_id=? AND source_id=?',[$actor['club_id'],$item,$source])->fetchColumn())throw new DomainException('Video dieser eigenen Inhaltsfassung auswählen.');
    }
    private function input(string $kind,int $version,string $operation):void
    {
        Content::id($operation);if(!in_array($kind,['remove','restore','purge','resume'],true)||$version<($kind==='resume'?1:0)||$version>($kind==='resume'?4294967293:4294967292))throw new DomainException('Ursprünglichen Videodateivorgang erneut öffnen.');
    }
    private function canManage():bool
    {
        try{$this->access->require('social','manage');return true;}catch(DomainException){return false;}
    }
    /** Latest state is explicitly separate from the outcome of an earlier phase. */
    public function status(string $item,string $source):array
    {
        $actor=$this->actor();$this->context($actor,$item,$source);
        return Db::atomic($this->db,function()use($actor,$source):array{
            $this->sameActor($actor);$state=(new VideoRemoval($this->db,$this->access,$this->mediaRoot))->get($source);$manage=$this->canManage();$this->sameActor($actor);
            return$manage?$state:array_intersect_key($state,array_flip(['version','state','actor_name','changed_at']));
        });
    }
    /** Deliberate preparation reads current state; it never starts a file change. */
    public function prepare(string $item,string $source,string $kind):array
    {
        $actor=$this->actor(true);$current=$this->status($item,$source);$this->sameActor($actor,true);
        $allowed=match($current['state']){
            'active'=>['remove'],
            'removed'=>!empty($current['purge_eligible'])?['restore','purge']:['restore'],
            'removing','restoring','purging'=>['resume'],
            default=>[],
        };
        if(!in_array($kind,$allowed,true))throw new DomainException('Aktuellen Videodateizustand prüfen und eine mögliche Aktion wählen.');
        $operation=$kind==='remove'?bin2hex(random_bytes(16)):($current['operation']??'');Content::id($operation);
        return['kind'=>$kind,'removal_version'=>(string)$current['version'],'removal_operation'=>$operation,'current'=>$current];
    }
    public function review(string $item,string $source,string $kind,int $version,string $operation):?array
    {
        $actor=$this->actor();$this->context($actor,$item,$source);$this->input($kind,$version,$operation);
        return Db::atomic($this->db,function()use($actor,$item,$source,$kind,$version,$operation):?array{
            $this->sameActor($actor);
            $original=Db::run($this->db,'SELECT * FROM native_social_video_sources WHERE club_id=? AND id=? FOR SHARE',[$actor['club_id'],$source])->fetch();if(!$original)throw new DomainException('Eigene Videoquelle fehlt.');
            $plan=Db::run($this->db,'SELECT * FROM native_video_removals WHERE club_id=? AND source_id=? AND operation=? FOR SHARE',[$actor['club_id'],$source,$operation])->fetch();
            if(!$plan){$this->sameActor($actor);return null;}
            if($plan['bytes']!==$original['bytes']||!hash_equals($plan['sha256'],$original['sha256']))throw new DomainException('Ursprünglichen Videodateinachweis prüfen.');
            $pendingVersion=$kind==='resume'?$version:$version+1;
            $pending=Db::run($this->db,'SELECT * FROM native_video_removal_history WHERE club_id=? AND source_id=? AND version=? FOR SHARE',[$actor['club_id'],$source,$pendingVersion])->fetch();
            $finished=Db::run($this->db,'SELECT * FROM native_video_removal_history WHERE club_id=? AND source_id=? AND version=? FOR SHARE',[$actor['club_id'],$source,$pendingVersion+1])->fetch();
            if(!$pending){if($finished&&$finished['operation']===$operation)throw new DomainException('Ursprünglichen Videodateinachweis prüfen.');$this->sameActor($actor);return null;}
            if($pending['operation']!==$operation){$this->sameActor($actor);return null;}
            $phase=$kind==='resume'?array_search($pending['state'],array_column(self::PHASES,0),true):false;
            $phase=$kind==='resume'?($phase===false?null:array_keys(self::PHASES)[$phase]):$kind;
            if($phase===null||$pending['state']!==self::PHASES[$phase][0]){$this->sameActor($actor);return null;}
            if($finished&&($finished['operation']!==$operation||$finished['state']!==self::PHASES[$phase][1]))throw new DomainException('Ursprünglichen Videodateinachweis prüfen.');
            if(!$this->canManage()&&$pending['actor_id']!==$actor['id']&&($finished['actor_id']??null)!==$actor['id'])throw new DomainException('Nur den eigenen ursprünglichen Videodateivorgang prüfen.');
            $current=(new VideoRemoval($this->db,$this->access,$this->mediaRoot))->get($source);
            if(!$finished&&($current['operation']!==$operation||$current['version']!==$pendingVersion||$current['state']!==$pending['state']))throw new DomainException('Ursprünglichen Videodateinachweis prüfen.');
            $row=$finished?:$pending;$result=['item_id'=>$item,'source_id'=>$source,'operation'=>$operation,'requested_kind'=>$kind,'requested_version'=>$version,'phase'=>$phase,'complete'=>$finished!==false,'version'=>$row['version'],'state'=>$row['state'],'actor_name'=>$row['actor_name'],'changed_at'=>$row['changed_at'],'retain_until'=>$plan['retain_until']];
            $this->sameActor($actor);return$result;
        });
    }
    public function save(string $item,string $source,string $kind,int $version,string $operation,bool $confirmed=false):array
    {
        $actor=$this->actor(true);$this->context($actor,$item,$source);$this->input($kind,$version,$operation);
        $original=$this->review($item,$source,$kind,$version,$operation);$this->sameActor($actor,true);if($original&&$original['complete'])return$original;
        if(!$original&&$kind!=='resume'&&!$confirmed)throw new DomainException('Dateiänderung ausdrücklich bestätigen.');
        $service=new VideoRemoval($this->db,$this->access,$this->mediaRoot);
        try{
            if(!$original){
                if($kind==='resume')throw new DomainException('Ursprünglicher Videodateivorgang fehlt.');
                $method=['remove'=>'beginRemove','restore'=>'beginRestore','purge'=>'beginPurge'][$kind];$service->$method($source,$version,$operation);
            }
            $this->sameActor($actor,true);$service->resume($source,$operation);
        }catch(Throwable $error){
            if(!$this->db->inTransaction()){$this->sameActor($actor,true);$known=$this->review($item,$source,$kind,$version,$operation);if($known&&($known['complete']||!($error instanceof DomainException || $error instanceof \Kulturhus\Native\Cms\Conflict))){$this->sameActor($actor,true);return$known;}}
            throw$error;
        }
        $result=$this->review($item,$source,$kind,$version,$operation);if(!$result)throw new DomainException('Ursprünglichen Videodateinachweis prüfen.');$this->sameActor($actor,true);return$result;
    }
}
