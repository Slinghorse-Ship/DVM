<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,RetentionStore};

/** A future-removal rule; captured original deadlines are never shortened. */
final class VideoRetention
{
    use \Kulturhus\Native\Cms\ClubPublicationLock;
    public const DEFAULT_DAYS=RetentionStore::DEFAULT_DAYS;
    public const MAX_DAYS=RetentionStore::MAX_DAYS;
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db):void
    {
        RetentionStore::install($db,'videos');
    }
    public static function current(PDO $db,string $club):array
    {
        return RetentionStore::current($db,'videos',$club,'Videoaufbewahrung über den Installer vervollständigen.');
    }
    public function get():array
    {return Db::atomic($this->db,fn():array=>self::current($this->db,$this->access->require('social','manage')['club_id']));}
    public function save(mixed $days,int $version,?callable $associate=null):array
    {
        if((!is_int($days)&&!is_string($days))||!preg_match('/^(0|[1-9][0-9]{0,3})$/D',(string)$days)||(int)$days>self::MAX_DAYS||$version<0||$version>=4294967295)throw new \DomainException('Videoaufbewahrung zwischen 0 und 3650 Tagen angeben.');
        if(RetentionStore::count($this->db,'videos')!==2)throw new \DomainException('Videoaufbewahrung über den Installer aktivieren.');
        return Db::atomic($this->db,function()use($days,$version,$associate):array{
            $actor=$this->access->require('social','manage');$club=$actor['club_id'];
            $this->lockClub();
            $saved=RetentionStore::write($this->db,'videos',$club,(int)$days,$version,$actor['id'],'Videoaufbewahrung inzwischen geändert.');if($associate!==null)$associate($saved);return $saved;
        });
    }
}
