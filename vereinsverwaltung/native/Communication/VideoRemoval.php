<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\Conflict;

/** Durable intent before every move or deletion; continuation never invents another original. */
final class VideoRemoval
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_video_removals (
            operation CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,source_id CHAR(32) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,
            actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,retain_until DATETIME(6) NOT NULL,
            UNIQUE(club_id,source_id,operation),
            FOREIGN KEY(club_id,source_id) REFERENCES native_social_video_sources(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        foreach(['native_video_removal_states','native_video_removal_history']as$table){
            $key=$table==='native_video_removal_states'?'PRIMARY KEY(club_id,source_id),INDEX(club_id,state,source_id)':'PRIMARY KEY(club_id,source_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (
                club_id VARCHAR(64) NOT NULL,source_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,
                state VARCHAR(16) NOT NULL,operation CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,actor_name VARCHAR(200) NOT NULL,
                changed_at DATETIME(6) NOT NULL,$key,
                FOREIGN KEY(club_id,source_id,operation) REFERENCES native_video_removals(club_id,source_id,operation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        }
        VideoRetention::install($db);
    }
    public static function installed(PDO $db):bool
    {
        $count=(int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_video_removals','native_video_removal_states','native_video_removal_history')")->fetchColumn();
        if($count!==0&&$count!==3)throw new \DomainException('Video-Papierkorb über den Installer vervollständigen.');
        return $count===3;
    }
    public static function available(PDO $db,string $club,string $source):bool
    {
        if(!self::installed($db))return true;
        $state=Db::run($db,'SELECT state FROM native_video_removal_states WHERE club_id=? AND source_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$source])->fetchColumn();
        return $state===false||$state==='active';
    }
    public function get(string $source):array
    {
        return Db::atomic($this->db,function()use($source):array{
            $club=$this->access->require('social','read')['club_id'];$this->source($source,$club);
            return $this->current($source,$club);
        });
    }
    private function source(string $source,string $club,bool $write=false):array
    {
        Content::id($source);$row=Db::run($this->db,'SELECT * FROM native_social_video_sources WHERE club_id=? AND id=?'.($write?' FOR UPDATE':' FOR SHARE'),[$club,$source])->fetch();
        if(!$row)throw new \DomainException('Eigene Videoquelle fehlt.');return $row;
    }
    private function current(string $source,string $club):array
    {
        $row=self::installed($this->db)?Db::run($this->db,'SELECT s.version,s.state,s.operation,s.actor_id,s.actor_name,s.changed_at,r.retain_until,(r.retain_until<=UTC_TIMESTAMP(6)) AS purge_eligible FROM native_video_removal_states s JOIN native_video_removals r ON r.club_id=s.club_id AND r.source_id=s.source_id AND r.operation=s.operation WHERE s.club_id=? AND s.source_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$club,$source])->fetch():false;
        return $row?array_replace($row,['version'=>(int)$row['version'],'purge_eligible'=>(bool)$row['purge_eligible']]):['version'=>0,'state'=>'active','operation'=>'','actor_id'=>'','actor_name'=>'','changed_at'=>'','retain_until'=>'','purge_eligible'=>false];
    }
    private function change(string $source,string $club,int $version,string $state,string $operation,string $actor):void
    {
        $name=mb_substr($this->access->require('social','manage')['name'],0,200);
        Db::run($this->db,'INSERT INTO native_video_removal_states VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),state=VALUES(state),operation=VALUES(operation),actor_id=VALUES(actor_id),actor_name=VALUES(actor_name),changed_at=VALUES(changed_at)',[$club,$source,$version,$state,$operation,$actor,$name]);
        Db::run($this->db,'INSERT INTO native_video_removal_history SELECT * FROM native_video_removal_states WHERE club_id=? AND source_id=?',[$club,$source]);
    }
    private function unused(string $source,string $club):void
    {
        // Current serializable reads hold item and queue ranges through the intent commit.
        if(Db::run($this->db,"SELECT id FROM khn_social_items WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.approved_video.source_id'))=? LIMIT 1 FOR SHARE",[$club,$source])->fetchColumn())throw new \DomainException('Aktuelle Videofreigabe zuerst zurückziehen.');
        if(Db::run($this->db,"SELECT j.id FROM native_video_capabilities c JOIN native_social_videos v ON v.club_id=c.club_id AND v.id=c.video_id JOIN native_delivery_jobs j ON j.club_id=c.club_id AND j.id=c.job_id WHERE v.club_id=? AND v.source_id=? AND (j.status IN ('pending','processing','uncertain') OR (j.status='sent' AND c.expires_at>UTC_TIMESTAMP(6))) LIMIT 1 FOR SHARE",[$club,$source])->fetchColumn())throw new \DomainException('Laufender oder unklarer Videoauftrag verhindert das Entfernen.');
    }
    private function intent(string $source,int $version,string $operation,callable $change):array
    {
        Content::id($source);Content::id($operation);
        if($version<0||$version>4294967292||$this->db->inTransaction()||!self::installed($this->db))throw new \DomainException('Videozustand über den Installer und im eigenen Vorgang prüfen.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        return Db::atomic($this->db,function()use($source,$version,$operation,$change):array{
            $actor=$this->access->require('social','manage');$club=$actor['club_id'];
            Db::run($this->db,'INSERT INTO khcms_state(club_id,generation) VALUES(?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
            $row=$this->source($source,$club,true);$old=$this->current($source,$club);
            if($old['version']!==$version)throw new Conflict('Videozustand inzwischen geändert. Aktuellen Stand öffnen.');
            $change($row,$old,$actor);return $this->current($source,$club);
        });
    }
    public function beginRemove(string $source,int $version,string $operation):array
    {
        return $this->intent($source,$version,$operation,function(array $row,array $old,array $actor)use($source,$operation,$version):void{
            $club=$actor['club_id'];if($old['state']!=='active')throw new \DomainException('Begonnenen Videovorgang fortsetzen.');
            if(!(new VideoArchive($this->db,$this->access))->get($source)['archived'])throw new \DomainException('Video zuerst archivieren.');
            $this->unused($source,$club);$plan=['club_id'=>$club,'source_id'=>$source,'operation'=>$operation,'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256']];
            (new VideoQuarantine($this->mediaRoot))->verify($plan,false);
            if(Db::run($this->db,'SELECT 1 FROM native_video_removals WHERE operation=? FOR SHARE',[$operation])->fetchColumn())throw new Conflict('Videovorgang bereits verwendet.');
            $days=VideoRetention::current($this->db,$club)['minimum_days'];
            Db::run($this->db,'INSERT INTO native_video_removals VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? DAY))',[$operation,$club,$source,$plan['bytes'],$plan['sha256'],$actor['id'],$days]);
            $this->change($source,$club,$version+1,'removing',$operation,$actor['id']);
        });
    }
    public function beginRestore(string $source,int $version,string $operation):array
    {
        return $this->intent($source,$version,$operation,function(array $row,array $old,array $actor)use($source,$operation,$version):void{
            if($old['state']!=='removed'||$old['operation']!==$operation)throw new Conflict('Originalen Video-Papierkorb erneut öffnen.');
            (new VideoQuarantine($this->mediaRoot))->verify($this->plan($source,$actor['club_id'],$operation),true);
            $this->change($source,$actor['club_id'],$version+1,'restoring',$operation,$actor['id']);
        });
    }
    public function beginPurge(string $source,int $version,string $operation):array
    {
        return $this->intent($source,$version,$operation,function(array $row,array $old,array $actor)use($source,$operation,$version):void{
            $club=$actor['club_id'];if($old['state']!=='removed'||$old['operation']!==$operation||!$old['purge_eligible'])throw new \DomainException('Originale Aufbewahrungsfrist verhindert endgültiges Löschen.');
            $this->unused($source,$club);(new VideoQuarantine($this->mediaRoot))->verify($this->plan($source,$club,$operation),true);
            $this->change($source,$club,$version+1,'purging',$operation,$actor['id']);
        });
    }
    private function plan(string $source,string $club,string $operation):array
    {
        $row=Db::run($this->db,'SELECT * FROM native_video_removals WHERE club_id=? AND source_id=? AND operation=?',[$club,$source,$operation])->fetch();
        if(!$row)throw new \DomainException('Ursprünglicher Videovorgang fehlt.');$row['bytes']=(int)$row['bytes'];return $row;
    }
    public function history(string $source,mixed $page=1):array
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new \DomainException('Video-Papierkorb-Verlaufsseite prüfen.');
        return Db::atomic($this->db,function()use($source,$page):array{
            $club=$this->access->require('social','read')['club_id'];$this->source($source,$club);
            if(!self::installed($this->db))return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_video_removal_history WHERE club_id=? AND source_id=?',[$club,$source])->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,'SELECT version,state,actor_name,changed_at FROM native_video_removal_history WHERE club_id=? AND source_id=? ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10),[$club,$source])->fetchAll();return compact('rows','total','page','pages');
        });
    }
    public function resume(string $source,string $operation):array
    {
        Content::id($source);Content::id($operation);$club=$this->access->require('social','manage')['club_id'];
        if($this->db->inTransaction())throw new \DomainException('Videofortsetzung benötigt einen eigenen Vorgang.');
        $lock='video-removal:'.substr(hash('sha256',$club.':'.$source),0,50);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new Conflict('Videovorgang wird bereits fortgesetzt.');
        try{
            return Db::atomic($this->db,function()use($source,$club,$operation):array{
                $actor=$this->access->require('social','manage');if($actor['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert.');
                Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();$this->source($source,$club,true);$state=$this->current($source,$club);
                if($state['operation']!==$operation)throw new Conflict('Originalen Videovorgang auswählen.');
                $plan=$this->plan($source,$club,$operation);$store=new VideoQuarantine($this->mediaRoot);
                if(in_array($state['state'],['active','removed'],true)){$store->verify($plan,$state['state']==='removed');return $state;}
                if($state['state']==='purged'){$store->verifyPurged($plan);return $state;}
                if(!in_array($state['state'],['removing','restoring','purging'],true))throw new \DomainException('Videozustand prüfen.');
                if($state['state']==='purging')$store->purge($plan);else $store->move($plan,$state['state']==='restoring');
                $next=['removing'=>'removed','restoring'=>'active','purging'=>'purged'][$state['state']];
                $this->change($source,$club,$state['version']+1,$next,$operation,$actor['id']);return $this->current($source,$club);
            });
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
