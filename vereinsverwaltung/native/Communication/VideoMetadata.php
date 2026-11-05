<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,MediaDescription};
use Kulturhus\Native\Cms\{Conflict,MediaPolicy};

/** Source-wide details and private proofs; saving metadata never changes video bytes. */
final class VideoMetadata
{
    use \Kulturhus\Native\Cms\ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access,private ?SecretBox $box=null) {}

    public static function installed(PDO $db): bool
    {return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_video_metadata','native_video_metadata_revisions')")->fetchColumn()===2;}

    public function get(string $source): array
    {
        $club=$this->access->require('social','read')['club_id'];
        $value=self::current($this->db,$club,$source);
        if($this->access->require('social','read')['club_id']!==$club)throw new DomainException('Vereinszugehörigkeit geändert.');
        return $value;
    }

    public function save(string $source,array $input,int $version,?string $proof=null,?callable $associate=null): array
    {
        $this->access->require('social','edit');
        if($version<0||$version>=4294967295)throw new InvalidArgumentException('Gespeicherte Videodatenversion angeben.');
        $value=MediaDescription::normalize($input,'Video');
        if($proof!==null){$this->access->require('social','manage');if(!$this->box)throw new DomainException('Privaten Nachweiszugang prüfen.');$proof=MediaDescription::text($proof,4000,false,true,'Video');}
        if(!self::installed($this->db))throw new DomainException('Videoangaben über den Installer aktivieren.');
        return Db::atomic($this->db,function()use($source,$value,$version,$proof,$associate):array{
            $actor=$this->access->require('social','edit');if($proof!==null)$this->access->require('social','manage');$club=$actor['club_id'];$this->lockClub();
            $old=self::current($this->db,$club,$source);if($old['version']!==$version)throw new Conflict('Videoangaben inzwischen geändert. Formular neu laden.');
            $cipher=Db::run($this->db,'SELECT consent_cipher FROM native_video_metadata WHERE club_id=? AND source_id=? FOR UPDATE',[$club,$source])->fetchColumn();$cipher=$cipher===false?null:$cipher;
            if($proof!==null)$cipher=$proof===''?null:$this->box->seal(self::context($club,$source),['reference'=>$proof]);
            $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            Db::run($this->db,'INSERT INTO native_video_metadata VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),payload_json=VALUES(payload_json),consent_cipher=VALUES(consent_cipher),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$source,$version+1,$json,$cipher,$actor['id']]);
            Db::run($this->db,'INSERT INTO native_video_metadata_revisions VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$club,$source,$version+1,$json,$cipher,$actor['id']]);
            $saved=self::current($this->db,$club,$source);if($associate!==null)$associate($saved);return $saved;
        });
    }

    public function privateProof(string $source,?int $revision=null): string
    {
        $club=$this->access->require('social','manage')['club_id'];self::current($this->db,$club,$source);
        if(!self::installed($this->db))return '';
        if($revision!==null&&$revision<1)throw new InvalidArgumentException('Nachweisversion prüfen.');
        $row=Db::run($this->db,'SELECT consent_cipher FROM '.($revision===null?'native_video_metadata':'native_video_metadata_revisions').' WHERE club_id=? AND source_id=?'.($revision===null?'':' AND version=?'),$revision===null?[$club,$source]:[$club,$source,$revision])->fetch();
        if($revision!==null&&!$row)throw new DomainException('Eigene Nachweisversion fehlt.');
        if(empty($row['consent_cipher']))return '';
        if(!$this->box)throw new DomainException('Privaten Nachweiszugang prüfen.');
        return $this->box->open(self::context($club,$source),$row['consent_cipher'])['reference'];
    }

    public function history(string $source,mixed $page=1): array
    {
        $club=$this->access->require('social','read')['club_id'];self::current($this->db,$club,$source);
        if(!self::installed($this->db))return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page))throw new InvalidArgumentException('Angabenseite prüfen.');
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_video_metadata_revisions WHERE club_id=? AND source_id=?',[$club,$source])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
        $rows=Db::run($this->db,'SELECT version,payload_json,consent_cipher IS NOT NULL AS has_proof,actor_id,created_at FROM native_video_metadata_revisions WHERE club_id=? AND source_id=? ORDER BY version DESC LIMIT 6 OFFSET '.(($page-1)*6),[$club,$source])->fetchAll();
        return compact('rows','total','page','pages');
    }

    public static function current(PDO $db,string $club,string $source): array
    {
        Content::id($source);
        $asset=Db::run($db,'SELECT original_name FROM native_social_video_sources WHERE club_id=? AND id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$source])->fetch();
        if(!$asset)throw new DomainException('Eigene ursprüngliche Videoquelle fehlt.');
        $defaults=['alt'=>$asset['original_name'],'credit'=>'','source_name'=>'','source_url'=>'','license_name'=>'','license_url'=>'','rights_state'=>'unknown','consent_state'=>'unknown','expires_on'=>'','channels'=>array_keys(MediaDescription::CHANNELS)];
        $row=self::installed($db)?Db::run($db,'SELECT version,payload_json,consent_cipher IS NOT NULL AS has_proof FROM native_video_metadata WHERE club_id=? AND source_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club,$source])->fetch():false;
        return ($row?json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR):$defaults)+['version'=>$row?(int)$row['version']:0,'has_proof'=>$row&&(bool)$row['has_proof'],'usage_policy'=>MediaPolicy::current($db,$club)];
    }

    public static function snapshot(array $metadata): array
    {return ['version'=>$metadata['version'],'public'=>array_intersect_key($metadata,array_flip(MediaDescription::PUBLIC_FIELDS))];}

    public static function assertUsage(array $metadata,string $channel='social'): void
    {
        if(MediaDescription::usable($metadata,$channel))return;
        $policy=$metadata['usage_policy'];
        if($policy['check_rights']&&($metadata['rights_state']!=='cleared'||!in_array($metadata['consent_state'],['recorded','not_required'],true)))throw new DomainException('Videorechte und Einwilligung vor Verwendung klären.');
        if($policy['require_details'])foreach(['credit','source_name','license_name']as$key)if(trim($metadata[$key])==='')throw new DomainException('Credit, Quelle und Lizenz in den Videoangaben ergänzen.');
        throw new DomainException('Videonutzung gesperrt, abgelaufen oder für diesen Kanal nicht erlaubt.');
    }

    private static function context(string $club,string $source): string{return 'video-consent:'.$club.':'.$source;}
}
