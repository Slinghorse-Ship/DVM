<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Reversible catalogue state; uploaded pixels and every existing use stay intact. */
final class MediaArchive
{
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db):void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_media_archives (club_id VARCHAR(64) NOT NULL, media_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL, archived TINYINT NOT NULL, actor_id VARCHAR(64) NOT NULL, actor_name VARCHAR(200) NOT NULL, changed_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,media_id), KEY media_archive_state(club_id,archived,media_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_media_archive_history (club_id VARCHAR(64) NOT NULL, media_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL, archived TINYINT NOT NULL, actor_id VARCHAR(64) NOT NULL, actor_name VARCHAR(200) NOT NULL, changed_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,media_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    public function get(string $id):array
    {
        return Db::atomic($this->db,function()use($id):array{$actor=$this->access->require('website','read');$this->asset($id,$actor['club_id']);return $this->current($id,$actor['club_id']);});
    }
    public function save(string $id,bool $archived,int $version):array
    {
        if($version<0||$version>1000000000)throw new InvalidArgumentException('Gespeicherte Archivversion prüfen.');
        return Db::atomic($this->db,function()use($id,$archived,$version):array{
            $actor=$this->access->require('website','edit');$club=$actor['club_id'];$this->asset($id,$club,true);
            $current=$this->current($id,$club);
            if($current['version']!==$version)throw new Conflict('Der Archivstatus wurde geändert. Aktuellen Stand öffnen.');
            if($current['archived']===$archived)return $current;
            $next=$version+1;$name=mb_substr($actor['name'],0,200);
            Db::run($this->db,'INSERT INTO khcms_media_archives(club_id,media_id,version,archived,actor_id,actor_name,changed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),archived=VALUES(archived),actor_id=VALUES(actor_id),actor_name=VALUES(actor_name),changed_at=VALUES(changed_at)',[$club,$id,$next,(int)$archived,$actor['id'],$name]);
            Db::run($this->db,'INSERT INTO khcms_media_archive_history SELECT club_id,media_id,version,archived,actor_id,actor_name,changed_at FROM khcms_media_archives WHERE club_id=? AND media_id=?',[$club,$id]);
            return $this->current($id,$club);
        });
    }
    public function history(string $id,mixed $page=1):array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new InvalidArgumentException('Archivverlaufsseite prüfen.');
        return Db::atomic($this->db,function()use($id,$page):array{
            $actor=$this->access->require('website','read');$club=$actor['club_id'];$this->asset($id,$club);
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_media_archive_history WHERE club_id=? AND media_id=?',[$club,$id])->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,'SELECT version,archived,actor_name,changed_at FROM khcms_media_archive_history WHERE club_id=? AND media_id=? ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10),[$club,$id])->fetchAll();
            return compact('rows','total','pages','page');
        });
    }
    private function current(string $id,string $club):array
    {
        $row=Db::run($this->db,'SELECT version,archived,actor_name,changed_at FROM khcms_media_archives WHERE club_id=? AND media_id=?',[$club,$id])->fetch();
        return $row?['version'=>(int)$row['version'],'archived'=>(bool)$row['archived'],'actor_name'=>$row['actor_name'],'changed_at'=>$row['changed_at']]:['version'=>0,'archived'=>false,'actor_name'=>'','changed_at'=>''];
    }
    private function asset(string $id,string $club,bool $lock=false):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bildkennung prüfen.');
        if(!Db::run($this->db,'SELECT id FROM khcms_media WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':' FOR SHARE'),[$club,$id])->fetchColumn())throw new DomainException('Bild nicht verfügbar.');
    }
}
