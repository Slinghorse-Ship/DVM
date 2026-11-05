<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Cms\Conflict;
use Kulturhus\Native\Shared\{Access,Db};

/** Source-wide private catalogue state. Existing approvals and file bytes stay intact. */
final class VideoArchive
{
    public function __construct(private PDO $db,private Access $access){}

    public static function install(PDO $db):void
    {
        foreach(['native_video_archives','native_video_archive_history']as$table){
            $key=$table==='native_video_archives'?'PRIMARY KEY(club_id,source_id), INDEX(club_id,archived,source_id)':'PRIMARY KEY(club_id,source_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (
                club_id VARCHAR(64) NOT NULL, source_id CHAR(32) NOT NULL,
                version INT UNSIGNED NOT NULL, archived TINYINT NOT NULL,
                actor_id VARCHAR(64) NOT NULL, actor_name VARCHAR(200) NOT NULL,
                changed_at DATETIME(6) NOT NULL, $key,
                FOREIGN KEY(club_id,source_id) REFERENCES native_social_video_sources(club_id,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        }
    }

    public static function installed(PDO $db):bool
    {
        $count=(int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('native_video_archives','native_video_archive_history')")->fetchColumn();
        if($count!==0&&$count!==2)throw new \DomainException('Videoarchiv über den Installer vervollständigen.');
        return $count===2;
    }

    public function get(string $source):array
    {
        return Db::atomic($this->db,function()use($source):array{
            $club=$this->access->require('social','read')['club_id'];$this->source($source,$club);
            return self::current($this->db,$club,$source);
        });
    }

    public function save(string $source,bool $archived,int $version,?callable $associate=null):array
    {
        if($version<0||$version>=4294967295)throw new \DomainException('Videoarchivversion prüfen.');
        if(!self::installed($this->db))throw new \DomainException('Videoarchiv über den Installer aktivieren.');
        return Db::atomic($this->db,function()use($source,$archived,$version,$associate):array{
            $actor=$this->access->require('social','edit');$club=$actor['club_id'];
            // Every writer and a new approval share this original source row, even at version zero.
            $this->source($source,$club,true);$old=self::current($this->db,$club,$source);
            if(!$archived&&!VideoRemoval::available($this->db,$club,$source))throw new \DomainException('Videodatei zuerst aus dem Papierkorb zurückholen.');
            if($old['version']!==$version)throw new Conflict('Videoarchiv inzwischen geändert. Aktuellen Stand öffnen.');
            if($old['archived']===$archived)return $old;
            $args=[$club,$source,$version+1,(int)$archived,$actor['id'],mb_substr($actor['name'],0,200)];
            Db::run($this->db,'INSERT INTO native_video_archives VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),archived=VALUES(archived),actor_id=VALUES(actor_id),actor_name=VALUES(actor_name),changed_at=VALUES(changed_at)',$args);
            Db::run($this->db,'INSERT INTO native_video_archive_history SELECT * FROM native_video_archives WHERE club_id=? AND source_id=?',[$club,$source]);
            if($this->access->require('social','edit')['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert.');
            $saved=self::current($this->db,$club,$source);if($associate!==null)$associate($saved);return $saved;
        });
    }

    public function history(string $source,mixed $page=1):array
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new \DomainException('Videoarchiv-Verlaufsseite prüfen.');
        return Db::atomic($this->db,function()use($source,$page):array{
            $club=$this->access->require('social','read')['club_id'];$this->source($source,$club);
            if(!self::installed($this->db))return ['rows'=>[],'page'=>1,'pages'=>1,'total'=>0];
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_video_archive_history WHERE club_id=? AND source_id=?',[$club,$source])->fetchColumn();
            $pages=max(1,(int)ceil($total/10));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,'SELECT version,archived,actor_name,changed_at FROM native_video_archive_history WHERE club_id=? AND source_id=? ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10),[$club,$source])->fetchAll();
            return compact('rows','page','pages','total');
        });
    }

    /** Only new choices are blocked. Existing approved originals remain available to their worker. */
    public static function assertSelectable(PDO $db,string $club,string $source):void
    {
        if(!$db->inTransaction())throw new \DomainException('Videoauswahl benötigt die Freigabetransaktion.');
        Content::id($source);
        if(!Db::run($db,'SELECT id FROM native_social_video_sources WHERE club_id=? AND id=? FOR SHARE',[$club,$source])->fetchColumn())throw new \DomainException('Eigene Videoquelle fehlt.');
        if(self::current($db,$club,$source)['archived'])throw new \DomainException('Video zuerst aus dem Archiv zurückholen.');
        if(!VideoRemoval::available($db,$club,$source))throw new \DomainException('Ursprüngliche Videodatei nicht verfügbar.');
    }

    private static function current(PDO $db,string $club,string $source):array
    {
        $row=self::installed($db)?Db::run($db,'SELECT version,archived,actor_name,changed_at FROM native_video_archives WHERE club_id=? AND source_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$source])->fetch():false;
        return $row?['version'=>(int)$row['version'],'archived'=>(bool)$row['archived'],'actor_name'=>$row['actor_name'],'changed_at'=>$row['changed_at']]:['version'=>0,'archived'=>false,'actor_name'=>'','changed_at'=>''];
    }

    private function source(string $source,string $club,bool $write=false):void
    {
        Content::id($source);
        if(!Db::run($this->db,'SELECT id FROM native_social_video_sources WHERE club_id=? AND id=?'.($write?' FOR UPDATE':' FOR SHARE'),[$club,$source])->fetchColumn())throw new \DomainException('Eigene Videoquelle fehlt.');
    }
}
