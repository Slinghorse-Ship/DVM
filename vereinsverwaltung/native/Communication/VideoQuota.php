<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict};
use Kulturhus\Native\Shared\{Access,Db};

/** Optional immutable private video source budget; existing immutable originals are never removed. */
final class VideoQuota
{
    use ClubPublicationLock;
    public const MAX_MIB=102400;
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db): void
    {
        foreach(['native_video_quota'=>'updated_by','native_video_quota_history'=>'actor_id']as$table=>$actor){
            $key=$table==='native_video_quota'?'PRIMARY KEY(club_id)':'PRIMARY KEY(club_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,limit_bytes BIGINT UNSIGNED NOT NULL,$actor VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,$key) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
    public static function installed(PDO $db): bool { return self::tables($db)===2; }
    public function get(): array
    {
        return Db::atomic($this->db,function():array{
            $club=$this->access->require('social','read')['club_id'];$policy=self::current($this->db,$club);$usage=self::usage($this->db,$club);
            if($this->access->require('social','read')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $policy+$usage+['remaining_bytes'=>$policy['limit_bytes']===0?null:max(0,$policy['limit_bytes']-$usage['used_bytes'])];
        });
    }
    public function save(mixed $mib,int $version,?callable $associate=null): array
    {
        $this->access->require('social','manage');
        if((!is_int($mib)&&!is_string($mib))||!preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',(string)$mib)||(int)$mib>self::MAX_MIB||$version<0||$version>=4294967295)throw new InvalidArgumentException('Videospeichergrenze als ganze MiB zwischen 0 und 102400 angeben.');
        if(!self::installed($this->db))throw new DomainException('Videospeichergrenze benötigt das Kommunikationsupdate im Installer.');
        return Db::atomic($this->db,function()use($mib,$version,$associate):array{
            $actor=$this->access->require('social','manage');$club=$actor['club_id'];$this->lockClub();$old=self::current($this->db,$club);
            if($old['version']!==$version)throw new Conflict('Videospeichergrenze inzwischen geändert. Aktuellen Stand öffnen.');
            $args=[$club,$version+1,(int)$mib*1048576,$actor['id']];
            Db::run($this->db,'INSERT INTO native_video_quota VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),limit_bytes=VALUES(limit_bytes),updated_by=VALUES(updated_by),created_at=VALUES(created_at)',$args);
            Db::run($this->db,'INSERT INTO native_video_quota_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',$args);
            if($this->access->require('social','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            $saved=self::current($this->db,$club);if($associate!==null)$associate($saved);return $saved;
        });
    }
    /** The same club lock serializes policy and uploads; current reads defeat older snapshots. */
    public static function assertUpload(PDO $db,string $club,int $bytes): void
    {
        if(!$db->inTransaction()||$bytes<0)throw new DomainException('Videospeicherprüfung benötigt die Uploadtransaktion.');
        Db::run($db,'INSERT INTO khcms_state (club_id,generation) VALUES (?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);
        Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        $policy=self::current($db,$club);if($policy['limit_bytes']===0||$bytes===0)return;
        $usage=self::usage($db,$club);
        if($bytes>$policy['limit_bytes']||$usage['used_bytes']>$policy['limit_bytes']-$bytes)throw new DomainException('Videospeichergrenze erreicht. Grenze erhöhen oder anderen Upload wählen.');
    }
    private static function current(PDO $db,string $club): array
    {
        $tables=self::tables($db);if($tables===0)return ['version'=>0,'limit_bytes'=>0];
        if($tables!==2)throw new DomainException('Videospeichergrenze benötigt das Kommunikationsupdate im Installer.');
        $row=Db::run($db,'SELECT version,limit_bytes FROM native_video_quota WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        if(!$row)return ['version'=>0,'limit_bytes'=>0];
        if((int)$row['version']<1||(int)$row['limit_bytes']<0||(int)$row['limit_bytes']>self::MAX_MIB*1048576)throw new DomainException('Gespeicherte Videospeichergrenze prüfen.');
        return ['version'=>(int)$row['version'],'limit_bytes'=>(int)$row['limit_bytes']];
    }
    private static function usage(PDO $db,string $club): array
    {
        $removal=VideoRemoval::installed($db);
        $join=$removal?' LEFT JOIN native_video_removal_states r ON r.club_id=s.club_id AND r.source_id=s.id':'';
        $retained=$removal?"COALESCE(r.state,'active')<>'purged'":'1=1';
        $row=Db::run($db,'SELECT COALESCE(SUM('.$retained.'),0) AS source_count,COALESCE(SUM(CASE WHEN '.$retained.' THEN s.bytes ELSE 0 END),0) AS used_bytes,COALESCE(SUM(CASE WHEN s.bytes<32 OR s.bytes>? THEN 1 ELSE 0 END),0) AS invalid_sizes FROM native_social_video_sources s'.$join.' WHERE s.club_id=?'.($db->inTransaction()?' FOR SHARE':''),[ReelFile::MAX_BYTES,$club])->fetch();
        if((int)$row['invalid_sizes']!==0)throw new DomainException('Gespeicherte Dokumentengrößen prüfen.');
        return ['source_count'=>(int)$row['source_count'],'used_bytes'=>(int)$row['used_bytes']];
    }
    private static function tables(PDO $db): int
    {
        return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_video_quota','native_video_quota_history') AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    }
}
