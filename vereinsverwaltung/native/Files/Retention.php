<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\ClubPublicationLock;
use Kulturhus\Native\Shared\{Access,Db,RetentionStore};

/** Future removals use this rule; recorded original deadlines never change. */
final class Retention
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access){}
    public function get():array
    {$club=$this->access->require('website','manage')['club_id'];return self::current($this->db,$club);}
    public static function current(PDO $db,string $club):array
    {return RetentionStore::current($db,'files',$club,'Dateiaufbewahrung benötigt das CMS-Update im Installer.');}
    public function save(mixed $days,int $version):array
    {
        if((!is_int($days)&&!is_string($days))||!preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D',(string)$days)||(int)$days>RetentionStore::MAX_DAYS||$version<0||$version>=4294967295)throw new InvalidArgumentException('Mindestaufbewahrung als ganze Tage zwischen 0 und 3650 angeben.');
        return Db::atomic($this->db,function()use($days,$version):array{$actor=$this->access->require('website','manage');$this->lockClub();return RetentionStore::write($this->db,'files',$actor['club_id'],(int)$days,$version,$actor['id'],'Dateiaufbewahrung inzwischen geändert. Formular neu laden.');});
    }
}
