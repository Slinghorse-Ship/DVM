<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,RetentionStore};

/** A club's rule applies only to future removals; recorded original deadlines never change. */
final class MediaRetention
{
    use ClubPublicationLock;
    public const DEFAULT_DAYS=RetentionStore::DEFAULT_DAYS;
    public const MAX_DAYS=RetentionStore::MAX_DAYS;
    public function __construct(private PDO $db,private Access $access){}

    public static function install(PDO $db): void
    {
        RetentionStore::install($db,'images');
    }
    public static function installed(PDO $db): bool
    {
        return self::tables($db)===2;
    }
    public function get(): array
    {
        return Db::atomic($this->db,function():array{
            $club=$this->access->require('website','manage')['club_id'];$rule=self::current($this->db,$club);
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $rule;
        });
    }
    /** Locking reads see a newly committed rule even inside an earlier caller snapshot. */
    public static function current(PDO $db,string $club): array
    {
        return RetentionStore::current($db,'images',$club,'Aufbewahrung benötigt das CMS-Update im Installer.');
    }
    public function save(mixed $days,int $version): array
    {
        $this->access->require('website','manage');
        if((!is_int($days)&&!is_string($days))||!preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D',(string)$days)||(int)$days>self::MAX_DAYS||$version<0||$version>=4294967295)throw new InvalidArgumentException('Mindestaufbewahrung als ganze Tage zwischen 0 und 3650 angeben.');
        if(!self::installed($this->db))throw new DomainException('Aufbewahrung benötigt das CMS-Update im Installer.');
        return Db::atomic($this->db,function()use($days,$version):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();
            $result=RetentionStore::write($this->db,'images',$club,(int)$days,$version,$actor['id'],'Aufbewahrung inzwischen geändert. Aktuellen Stand öffnen.');
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $result;
        });
    }
    private static function tables(PDO $db): int
    {
        return RetentionStore::count($db,'images');
    }
}
