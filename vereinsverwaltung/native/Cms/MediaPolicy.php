<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Optional publication checks shared by every CMS image use; uploads stay independent. */
final class MediaPolicy
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db):void
    {
        foreach(['khcms_media_policy'=>'updated_by','khcms_media_policy_history'=>'actor_id']as$table=>$actor){
            $key=$table==='khcms_media_policy'?'PRIMARY KEY(club_id)':'PRIMARY KEY(club_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, check_rights TINYINT UNSIGNED NOT NULL, require_details TINYINT UNSIGNED NOT NULL, $actor VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, $key) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
    public function get():array
    {
        $actor=$this->access->require('website','read');
        return self::current($this->db,$actor['club_id']);
    }
    public static function current(PDO $db,string $club):array
    {
        $row=Db::run($db,'SELECT version,check_rights,require_details FROM khcms_media_policy WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        return ['version'=>$row?(int)$row['version']:0,'check_rights'=>$row?(bool)$row['check_rights']:false,'require_details'=>$row?(bool)$row['require_details']:false];
    }
    public function save(mixed $check,mixed $details,int $version):array
    {
        $this->access->require('website','manage');
        foreach([$check,$details]as$value)if(!in_array($value,[true,false,0,1,'0','1'],true))throw new InvalidArgumentException('Bildprüfung auswählen.');
        if($version<0||$version>=4294967295)throw new InvalidArgumentException('Gespeicherte Prüfversion angeben.');
        return Db::atomic($this->db,function()use($check,$details,$version):array{
            $actor=$this->access->require('website','manage');$this->lockClub();$club=$actor['club_id'];
            if(self::current($this->db,$club)['version']!==$version)throw new Conflict('Bildprüfung inzwischen geändert. Formular neu laden.');
            $args=[$club,$version+1,(int)$check,(int)$details,$actor['id']];
            Db::run($this->db,'INSERT INTO khcms_media_policy VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),check_rights=VALUES(check_rights),require_details=VALUES(require_details),updated_by=VALUES(updated_by),created_at=VALUES(created_at)',$args);
            Db::run($this->db,'INSERT INTO khcms_media_policy_history VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',$args);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            return self::current($this->db,$club);
        });
    }
}
