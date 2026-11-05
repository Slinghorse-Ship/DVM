<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Optional website PDF budget; existing immutable originals are never removed. */
final class DocumentQuota
{
    use ClubPublicationLock;
    public const MAX_MIB=102400;
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db): void
    {
        foreach(['khcms_document_quota'=>'updated_by','khcms_document_quota_history'=>'actor_id']as$table=>$actor){
            $key=$table==='khcms_document_quota'?'PRIMARY KEY(club_id)':'PRIMARY KEY(club_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,limit_bytes BIGINT UNSIGNED NOT NULL,$actor VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,$key) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
    public static function installed(PDO $db): bool { return self::tables($db)===2; }
    public function get(): array
    {
        return Db::atomic($this->db,function():array{
            $club=$this->access->require('website','read')['club_id'];$policy=self::current($this->db,$club);$usage=self::usage($this->db,$club);
            if($this->access->require('website','read')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $policy+$usage+['remaining_bytes'=>$policy['limit_bytes']===0?null:max(0,$policy['limit_bytes']-$usage['used_bytes'])];
        });
    }
    public function save(mixed $mib,int $version): array
    {
        $this->access->require('website','manage');
        if((!is_int($mib)&&!is_string($mib))||!preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',(string)$mib)||(int)$mib>self::MAX_MIB||$version<0||$version>=4294967295)throw new InvalidArgumentException('Dokumentenspeichergrenze als ganze MiB zwischen 0 und 102400 angeben.');
        if(!self::installed($this->db))throw new DomainException('Dokumentenspeichergrenze benötigt das CMS-Update im Installer.');
        return Db::atomic($this->db,function()use($mib,$version):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();$old=self::current($this->db,$club);
            if($old['version']!==$version)throw new Conflict('Dokumentenspeichergrenze inzwischen geändert. Aktuellen Stand öffnen.');
            $args=[$club,$version+1,(int)$mib*1048576,$actor['id']];
            Db::run($this->db,'INSERT INTO khcms_document_quota VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),limit_bytes=VALUES(limit_bytes),updated_by=VALUES(updated_by),created_at=VALUES(created_at)',$args);
            Db::run($this->db,'INSERT INTO khcms_document_quota_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',$args);
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return self::current($this->db,$club);
        });
    }
    /** The same club lock serializes policy and uploads; current reads defeat older snapshots. */
    public static function assertUpload(PDO $db,string $club,int $bytes): void
    {
        if(!$db->inTransaction()||$bytes<0)throw new DomainException('Dokumentenspeicherprüfung benötigt die Uploadtransaktion.');
        Db::run($db,'INSERT INTO khcms_state (club_id,generation) VALUES (?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);
        Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        $policy=self::current($db,$club);if($policy['limit_bytes']===0||$bytes===0)return;
        $usage=self::usage($db,$club);
        if($bytes>$policy['limit_bytes']||$usage['used_bytes']>$policy['limit_bytes']-$bytes)throw new DomainException('Dokumentenspeichergrenze erreicht. Grenze erhöhen oder anderen Upload wählen.');
    }
    private static function current(PDO $db,string $club): array
    {
        $tables=self::tables($db);if($tables===0)return ['version'=>0,'limit_bytes'=>0];
        if($tables!==2)throw new DomainException('Dokumentenspeichergrenze benötigt das CMS-Update im Installer.');
        $row=Db::run($db,'SELECT version,limit_bytes FROM khcms_document_quota WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        if(!$row)return ['version'=>0,'limit_bytes'=>0];
        if((int)$row['version']<1||(int)$row['limit_bytes']<0||(int)$row['limit_bytes']>self::MAX_MIB*1048576)throw new DomainException('Gespeicherte Dokumentenspeichergrenze prüfen.');
        return ['version'=>(int)$row['version'],'limit_bytes'=>(int)$row['limit_bytes']];
    }
    private static function usage(PDO $db,string $club): array
    {
        $row=Db::run($db,'SELECT COUNT(*) AS document_count,COALESCE(SUM(bytes),0) AS used_bytes,COALESCE(SUM(CASE WHEN bytes<8 OR bytes>? THEN 1 ELSE 0 END),0) AS invalid_sizes FROM khcms_documents WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[WebsiteDocuments::MAX_BYTES,$club])->fetch();
        if((int)$row['invalid_sizes']!==0)throw new DomainException('Gespeicherte Dokumentengrößen prüfen.');
        return ['document_count'=>(int)$row['document_count'],'used_bytes'=>(int)$row['used_bytes']];
    }
    private static function tables(PDO $db): int
    {
        return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('khcms_document_quota','khcms_document_quota_history') AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    }
}
