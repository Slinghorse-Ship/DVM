<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict};
use Kulturhus\Native\Shared\{Access,Db};

/** Every retained blob counts once, including old byte versions; metadata restores add no bytes. */
final class Quota
{
    use ClubPublicationLock;
    public const MAX_MIB=102400;
    public function __construct(private PDO $db,private Access $access){}
    public function get():array
    {
        $club=$this->access->require('website','read')['club_id'];$policy=self::current($this->db,$club);$usage=self::usage($this->db,$club);
        return $policy+$usage+['remaining_bytes'=>$policy['limit_bytes']===0?null:max(0,$policy['limit_bytes']-$usage['used_bytes'])];
    }
    public function save(mixed $mib,int $version):array
    {
        if((!is_string($mib)&&!is_int($mib))||!preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',(string)$mib)||(int)$mib>self::MAX_MIB||$version<0||$version>=4294967295)throw new InvalidArgumentException('Dateispeichergrenze als ganze MiB zwischen 0 und 102400 angeben.');
        return Db::atomic($this->db,function()use($mib,$version):array{$actor=$this->access->require('website','manage');$this->lockClub();$club=$actor['club_id'];if(self::current($this->db,$club)['version']!==$version)throw new Conflict('Dateispeichergrenze inzwischen geändert. Formular neu laden.');$args=[$club,$version+1,(int)$mib*1048576,$actor['id']];Db::run($this->db,'INSERT INTO khcms_file_quota VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),limit_bytes=VALUES(limit_bytes),updated_by=VALUES(updated_by),created_at=VALUES(created_at)',$args);Db::run($this->db,'INSERT INTO khcms_file_quota_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',$args);return self::current($this->db,$club);});
    }
    public static function assertUpload(PDO $db,string $club,int $bytes):void
    {
        if(!$db->inTransaction()||$bytes<1)throw new DomainException('Dateispeicherprüfung benötigt die Uploadtransaktion.');
        Db::run($db,'INSERT INTO khcms_state(club_id,generation) VALUES(?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        $policy=self::current($db,$club);if($policy['limit_bytes']===0)return;$usage=self::usage($db,$club);
        if($bytes>$policy['limit_bytes']||$usage['used_bytes']>$policy['limit_bytes']-$bytes)throw new DomainException('Dateispeichergrenze erreicht. Grenze erhöhen oder anderen Upload auswählen.');
    }
    private static function current(PDO $db,string $club):array
    {$row=Db::run($db,'SELECT version,limit_bytes FROM khcms_file_quota WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();return ['version'=>$row?(int)$row['version']:0,'limit_bytes'=>$row?(int)$row['limit_bytes']:0];}
    private static function usage(PDO $db,string $club):array
    {
        $row=Db::run($db,"SELECT COUNT(*) AS blob_count,COALESCE(SUM(b.bytes),0) AS used_bytes FROM khcms_file_blobs b WHERE b.club_id=? AND EXISTS(SELECT 1 FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.club_id=b.club_id AND r.source_kind='file' AND r.source_id=b.id AND a.state<>'purged')".($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        return ['blob_count'=>(int)$row['blob_count'],'used_bytes'=>(int)$row['used_bytes']];
    }
}
