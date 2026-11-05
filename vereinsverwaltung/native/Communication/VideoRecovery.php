<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\Conflict;
use Kulturhus\Native\Shared\{Access,Db};

/** A durable immutable intention precedes every distinct copy. No approval or provider job is created. */
final class VideoRecovery
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}

    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_video_recoveries (
            original_id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
            target_id CHAR(32) NOT NULL UNIQUE, video_id CHAR(32) NOT NULL UNIQUE,
            item_id CHAR(32) NOT NULL, fingerprint CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL, bytes BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL, profile_json JSON NOT NULL,
            actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
            INDEX(club_id,original_id), FOREIGN KEY(club_id,item_id) REFERENCES khn_social_items(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }

    public static function installed(PDO $db):bool
    {return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_video_recoveries' AND TABLE_TYPE='BASE TABLE'")->fetchColumn()===1;}

    private function actor(bool $write=false):array
    {
        if($this->db->inTransaction()&&!$write)throw new DomainException('Wiederherstellungsprüfung benötigt einen eigenen aktuellen Vorgang.');
        $actor=$this->access->require('social','manage');$this->same($actor,$this->access->require('social','read'));
        if($write)$this->same($actor,$this->access->require('social','edit'));
        if(!self::installed($this->db))throw new DomainException('Video-Wiederherstellung über den Installer aktualisieren.');
        return $actor;
    }
    private function same(array $actor,array $current):void
    {if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new Conflict('Person oder Vereinszuordnung inzwischen geändert.');}

    private static function fingerprint(string $club,string $source,array $proof):string
    {return hash('sha256',json_encode([$club,$source,$proof['bytes'],$proof['sha256']],JSON_THROW_ON_ERROR));}

    private function row(string $source,string $club):?array
    {
        Content::id($source);$row=Db::run($this->db,'SELECT * FROM native_video_recoveries WHERE original_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$source])->fetch();if(!$row)return null;
        if($row['club_id']!==$club)throw new DomainException('Eigene ursprüngliche Videoquelle auswählen.');
        $row['bytes']=(int)$row['bytes'];$row['profile']=json_decode($row['profile_json'],true,16,JSON_THROW_ON_ERROR);unset($row['profile_json']);
        $fingerprint=self::fingerprint($club,$source,$row);
        if($row['fingerprint']!==$fingerprint||$row['target_id']!==substr(hash('sha256','video-recovery-source:'.$fingerprint),0,32)||$row['video_id']!==substr(hash('sha256','video-recovery-version:'.$fingerprint),0,32)||$row['target_id']===$source)throw new DomainException('Ursprünglichen Video-Wiederherstellungsvorgang prüfen.');
        return $row;
    }

    private function unknown(string $source):void
    {
        foreach([['native_social_video_sources','id'],['native_social_videos','source_id']]as[$table,$field])if(Db::run($this->db,"SELECT 1 FROM $table WHERE $field=?".($this->db->inTransaction()?' FOR SHARE':''),[$source])->fetchColumn())throw new Conflict('Datei gehört inzwischen zu einem gespeicherten Video. Original erneut öffnen.');
        if(Db::run($this->db,'SELECT 1 FROM native_video_recoveries WHERE target_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$source])->fetchColumn())throw new DomainException('Vorbereitete Kopie über den ursprünglichen Vorgang fortsetzen.');
    }

    private function completed(array $plan):bool
    {
        $suffix=$this->db->inTransaction()?' FOR SHARE':'';
        $source=Db::run($this->db,'SELECT * FROM native_social_video_sources WHERE id=?'.$suffix,[$plan['target_id']])->fetch();
        $video=Db::run($this->db,'SELECT * FROM native_social_videos WHERE id=?'.$suffix,[$plan['video_id']])->fetch();
        if(!$source&&!$video)return false;
        if(!$source||!$video||$source['club_id']!==$plan['club_id']||$source['sha256']!==$plan['sha256']||(int)$source['bytes']!==$plan['bytes']||$source['original_name']!==$plan['original_name']||json_decode($source['profile_json'],true,16,JSON_THROW_ON_ERROR)!=$plan['profile']||$video['club_id']!==$plan['club_id']||$video['source_id']!==$plan['target_id']||$video['item_id']!==$plan['item_id'])throw new Conflict('Wiederherstellung nicht vollständig bestätigt. Ursprünglichen Vorgang prüfen.');
        return true;
    }

    /** An accepted immutable result outlives its file; only the separate lifecycle may restore or purge bytes. */
    private function outcome(array $plan):array
    {
        $state=(new VideoRemoval($this->db,$this->access,$this->mediaRoot))->get($plan['target_id'])['state'];
        if(!in_array($state,['active','removing','removed','restoring','purging','purged'],true))throw new DomainException('Ursprünglichen Video-Dateizustand prüfen.');
        $archived=(new VideoArchive($this->db,$this->access))->get($plan['target_id'])['archived'];
        if($state==='active')(new VideoRecoveryFile($this->mediaRoot))->verify($plan);
        return ['state'=>'complete','file_state'=>$state,'archived'=>$archived];
    }

    /** GET also confirms an accepted commit after a lost response; it never resumes a write. */
    public function prepare(string $source):array
    {
        $actor=$this->actor();$club=$actor['club_id'];Content::id($source);$store=new VideoRecoveryFile($this->mediaRoot);$plan=$this->row($source,$club);
        if($plan){
            $done=$this->completed($plan);if(!$done){$this->unknown($source);$actual=$store->inspect($club,$source,false);if(self::fingerprint($club,$source,$actual)!==$plan['fingerprint'])throw new Conflict('Ursprüngliche Videodatei inzwischen geändert.');}
            $result=$plan+($done?$this->outcome($plan):['state'=>'pending']);
        }else{
            $this->unknown($source);$proof=$store->inspect($club,$source);$this->unknown($source);
            $result=$proof+['original_id'=>$source,'fingerprint'=>self::fingerprint($club,$source,$proof),'state'=>'new'];
        }
        $this->same($actor,$this->actor());return $result;
    }

    public function listing(string $after=''):array
    {
        if($after!=='')Content::id($after);$actor=$this->actor();
        $rows=Db::run($this->db,'SELECT original_id,item_id,original_name,created_at FROM native_video_recoveries WHERE club_id=? AND original_id>? ORDER BY original_id LIMIT 7',[$actor['club_id'],$after])->fetchAll();
        $more=count($rows)>6;$rows=array_slice($rows,0,6);
        foreach($rows as&$row){$plan=$this->row($row['original_id'],$actor['club_id']);if(!$plan)throw new Conflict('Videovorgang inzwischen geändert.');$row['state']=$this->completed($plan)?'complete':'pending';}unset($row);
        $this->same($actor,$this->actor());
        return ['rows'=>$rows,'after'=>$after,'has_more'=>$more,'next_after'=>$more?$rows[5]['original_id']:''];
    }

    private function current(array $actor,string $item,int $version,int $identity):array
    {
        if($version<1||$version>4294967295||$identity<0||$identity>4294967295)throw new InvalidArgumentException('Aktuellen Inhaltsstand öffnen.');
        $this->same($actor,$this->actor(true));
        $current=(new Workspace($this->db,$this->access,'social'))->get($item,$this->db->inTransaction());
        $iv=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$actor['club_id']])->fetchColumn();
        if($current['version']!==$version||($iv===false?0:(int)$iv)!==$identity)throw new Conflict('Inhalt oder Vereinsdaten inzwischen geändert. Aktuellen Stand öffnen.');
        if($current['kind']!=='reel'||!in_array($current['status'],['draft','review'],true))throw new DomainException('Video in einen eigenen Reel-Entwurf wiederherstellen.');
        return $current;
    }

    public function recover(string $source,string $fingerprint,string $item,int $version,int $identity,string $name='Reel.mp4'):array
    {
        if($this->db->inTransaction())throw new DomainException('Video-Wiederherstellung benötigt einen eigenen Vorgang.');
        Content::id($source);Content::id($item);if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new InvalidArgumentException('Videodatei erneut prüfen.');
        $name=Content::text($name,255,true);if(basename(str_replace('\\','/',$name))!==$name)throw new InvalidArgumentException('Einfachen Videonamen angeben.');
        $actor=$this->actor(true);$club=$actor['club_id'];$lock='video-recovery:'.substr(hash('sha256',$source),0,49);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new Conflict('Video-Wiederherstellung läuft bereits.');
        try{
            $plan=$this->row($source,$club);
            if($plan&&($plan['fingerprint']!==$fingerprint||$plan['item_id']!==$item))throw new Conflict('Ursprünglichen Vorgang und Beitrag auswählen.');
            if($plan&&$this->completed($plan)){$outcome=$this->outcome($plan);$this->same($actor,$this->actor(true));return $plan+$outcome+['created'=>false];}
            $this->current($actor,$item,$version,$identity);
            if(!$plan){
                $prepared=$this->prepare($source);$this->same($actor,$this->actor(true));
                if($prepared['state']!=='new'||$prepared['fingerprint']!==$fingerprint)throw new Conflict('Videodatei inzwischen geändert. Erneut prüfen.');
                $target=substr(hash('sha256','video-recovery-source:'.$fingerprint),0,32);$video=substr(hash('sha256','video-recovery-version:'.$fingerprint),0,32);
                // Never claim pre-existing bytes. Only this immutable intent may authorise prefix continuation.
                $targetPath=dirname($this->mediaRoot).'/communication-videos/'.hash('sha256',$club).'/'.$target.'.mp4';
                if(file_exists($targetPath)||is_link($targetPath))throw new Conflict('Vorhandene Kopie bleibt unverändert. Videospeicher prüfen.');
                Db::atomic($this->db,function()use($actor,$source,$fingerprint,$item,$version,$identity,$name,$target,$video,$prepared):void{
                    $this->current($actor,$item,$version,$identity);VideoQuota::assertUpload($this->db,$actor['club_id'],$prepared['bytes']);$this->unknown($source);
                    Db::run($this->db,'INSERT INTO native_video_recoveries VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$source,$actor['club_id'],$target,$video,$item,$fingerprint,$name,$prepared['bytes'],$prepared['sha256'],json_encode($prepared['profile'],JSON_THROW_ON_ERROR),$actor['id']]);
                });
                $plan=$this->row($source,$club);if(!$plan)throw new Conflict('Wiederherstellungsabsicht nicht bestätigt. Vorgang prüfen.');
            }
            $this->current($actor,$item,$version,$identity);$this->unknown($source);$store=new VideoRecoveryFile($this->mediaRoot);$store->copy($plan);
            Db::atomic($this->db,function()use($actor,$source,$plan,$item,$version,$identity,$store):void{
                $current=$this->current($actor,$item,$version,$identity);VideoQuota::assertUpload($this->db,$actor['club_id'],$plan['bytes']);$this->unknown($source);
                if($this->completed($plan))throw new Conflict('Videofassung bereits gespeichert. Vorgang prüfen.');
                $store->verify($plan);
                $actual=$store->inspect($actor['club_id'],$source,false);if(self::fingerprint($actor['club_id'],$source,$actual)!==$plan['fingerprint'])throw new Conflict('Ursprüngliches Video während der Vorbereitung geändert.');
                Db::run($this->db,'INSERT INTO native_social_video_sources VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$plan['target_id'],$actor['club_id'],$plan['original_name'],$plan['bytes'],$plan['sha256'],json_encode($plan['profile'],JSON_THROW_ON_ERROR),$actor['id']]);
                Db::run($this->db,'INSERT INTO native_social_videos VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$plan['video_id'],$actor['club_id'],$item,$plan['target_id'],$version,Images::fingerprint($current),$identity]);
                $this->same($actor,$this->actor(true));
            });
            return $plan+['created'=>true,'state'=>'complete'];
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
