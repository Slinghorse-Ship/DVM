<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Optional per-club CMS photo budget. Historic originals are never removed to make space. */
final class MediaQuota
{
    use ClubPublicationLock;
    public const MAX_MIB = 102400;
    public function __construct(private PDO $db, private Access $access) {}

    public static function install(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_media_quota (club_id VARCHAR(64) NOT NULL PRIMARY KEY, version INT UNSIGNED NOT NULL, limit_bytes BIGINT UNSIGNED NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_media_quota_history (club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, limit_bytes BIGINT UNSIGNED NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function get(): array
    {
        return Db::atomic($this->db,function():array{
            $actor=$this->access->require('website','read');$club=$actor['club_id'];
            $policy=self::policy($this->db,$club);
            $usage=self::usage($this->db,$club);
            if($this->access->require('website','read')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $policy+$usage+['remaining_bytes'=>$policy['limit_bytes']===0?null:max(0,$policy['limit_bytes']-$usage['used_bytes'])];
        });
    }

    public function save(mixed $mib, int $version): array
    {
        $this->access->require('website','manage');
        if((!is_int($mib)&&!is_string($mib))||!preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',(string)$mib)||(int)$mib>self::MAX_MIB||$version<0||$version>=4294967295)throw new InvalidArgumentException('Bildspeichergrenze als ganze MiB zwischen 0 und 102400 angeben.');
        $bytes=(int)$mib*1048576;
        return Db::atomic($this->db,function()use($bytes,$version):array{
            $actor=$this->access->require('website','manage');$this->lockClub();$club=$actor['club_id'];
            $old=self::policy($this->db,$club);
            if($old['version']!==$version)throw new Conflict('Bildspeichergrenze inzwischen geändert. Formular neu laden.');
            Db::run($this->db,'INSERT INTO khcms_media_quota VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),limit_bytes=VALUES(limit_bytes),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$version+1,$bytes,$actor['id']]);
            Db::run($this->db,'INSERT INTO khcms_media_quota_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$version+1,$bytes,$actor['id']]);
            return self::policy($this->db,$club);
        });
    }

    /** Current locking reads remain correct even inside a caller's older repeatable-read snapshot. */
    public static function assertUpload(PDO $db, string $club, int $bytes): void
    {
        if(!$db->inTransaction()||$bytes<1)throw new DomainException('Bildspeicherprüfung benötigt die Uploadtransaktion.');
        Db::run($db,'INSERT INTO khcms_state (club_id,generation) VALUES (?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);
        Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        $policy=self::policy($db,$club);
        if($policy['limit_bytes']===0)return;
        $usage=self::usage($db,$club);
        if($bytes>$policy['limit_bytes']||$usage['used_bytes']>$policy['limit_bytes']-$bytes)throw new DomainException('Bildspeichergrenze erreicht. Grenze erhöhen oder anderen Upload wählen.');
    }

    private static function policy(PDO $db, string $club): array
    {
        $row=Db::run($db,'SELECT version,limit_bytes FROM khcms_media_quota WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        return ['version'=>$row?(int)$row['version']:0,'limit_bytes'=>$row?(int)$row['limit_bytes']:0];
    }

    private static function usage(PDO $db, string $club): array
    {
        $sizes=[];$valid=[];
        foreach(['original','large','medium','thumb']as$size){
            $value="JSON_EXTRACT(m.variants_json,'$.$size.bytes')";
            $sizes[]="CAST(JSON_UNQUOTE($value) AS UNSIGNED)";
            $valid[]="JSON_TYPE($value)='INTEGER' AND CAST(JSON_UNQUOTE($value) AS UNSIGNED)>0";
        }
        $sum=implode('+',$sizes);$complete=implode(' AND ',$valid);
        $join=MediaAvailability::installed($db)?" LEFT JOIN khcms_media_removal_states removal ON removal.club_id=m.club_id AND removal.media_id=m.id":'';
        $active=$join===''?'':" AND (removal.media_id IS NULL OR removal.state<>'purged')";
        $row=Db::run($db,"SELECT COUNT(*) AS image_count,COALESCE(SUM($sum),0) AS used_bytes,COALESCE(SUM(CASE WHEN $complete THEN 0 ELSE 1 END),0) AS invalid_sizes FROM khcms_media m$join WHERE m.club_id=?$active".($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        if((int)$row['invalid_sizes']!==0)throw new DomainException('Gespeicherte Bildgrößen prüfen.');
        return ['image_count'=>(int)$row['image_count'],'used_bytes'=>(int)$row['used_bytes']];
    }
}
