<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict};
use Kulturhus\Native\Shared\{Access,Db};

/** Detaching releases this sequence only; the original image retention service still owns all bytes. */
final class ImageGroups
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access,private string $fileRoot,private string $mediaRoot){}
    public function detach(string $id,int $version):array{return $this->change($id,$version,false);}
    public function restore(string $id,int $version):array{return $this->change($id,$version,true);}
    private function change(string $id,int $version,bool $restore):array
    {
        AssetVersions::identifier($id);if($version<1||$version>=4294967294)throw new InvalidArgumentException('Gespeicherte Bildfolge prüfen.');
        return Db::atomic($this->db,function()use($id,$version,$restore):array{
            $actor=$this->access->require('website','manage');$this->lockClub();$row=Db::run($this->db,'SELECT kind,state,version FROM khcms_assets WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$id])->fetch();
            if(!$row||$row['kind']!=='image'||$row['state']!==($restore?'detached':'active'))throw new DomainException('Bildfolge nicht verfügbar.');
            if((int)$row['version']!==$version)throw new Conflict('Bildfolge inzwischen geändert. Aktuellen Stand öffnen.');
            $service=new AssetVersions($this->db,$this->access,$this->fileRoot,$this->mediaRoot);
            if($restore){
                $originals=Db::run($this->db,"SELECT DISTINCT source_id FROM khcms_asset_revisions WHERE club_id=? AND asset_id=? AND source_kind='media' ORDER BY source_id",[$actor['club_id'],$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach($originals as$source){
                    if(!Db::run($this->db,'SELECT id FROM khcms_media WHERE club_id=? AND id=? FOR SHARE',[$actor['club_id'],$source])->fetchColumn())throw new DomainException('Eine ursprüngliche Bildfassung ist nicht mehr verfügbar.');
                }
                // Equal pixel originals are checked once even when many metadata revisions capture them.
                $descriptors=Db::run($this->db,"SELECT DISTINCT source_id,bytes,sha256 FROM khcms_asset_revisions WHERE club_id=? AND asset_id=? AND source_kind='media' ORDER BY source_id",[$actor['club_id'],$id])->fetchAll();
                foreach($descriptors as$original){
                    try{$file=(new \Kulturhus\Native\Cms\Media($this->db,$this->access,$this->mediaRoot))->variant($original['source_id'],'original');}
                    catch(\RuntimeException $error){throw new DomainException('Eine ursprüngliche Bildfassung ist nicht mehr verfügbar.',0,$error);}
                    if($file['bytes']!==(int)$original['bytes']||!hash_equals($file['sha256'],$original['sha256']))throw new DomainException('Eine ursprüngliche Bildfassung wurde verändert.');
                }
            }
            $state=$restore?'active':'detached';Db::run($this->db,'UPDATE khcms_assets SET state=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND version=?',[$state,$actor['club_id'],$id,$version]);
            Db::run($this->db,'INSERT INTO khcms_image_group_history VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],$id,$version+1,$state,$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$actor['club_id']]);return $service->get($id,true);
        });
    }
    public function history(string $id,int $page=1):array
    {
        AssetVersions::identifier($id);$club=$this->access->require('website','manage')['club_id'];if($page<1||$page>1000000)throw new InvalidArgumentException('Verlaufsseite prüfen.');
        (new AssetVersions($this->db,$this->access,$this->fileRoot,$this->mediaRoot))->get($id,true);$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_image_group_history WHERE club_id=? AND asset_id=?',[$club,$id])->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT version,state,actor_id,changed_at FROM khcms_image_group_history WHERE club_id=? AND asset_id=? ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10),[$club,$id])->fetchAll();return compact('rows','total','page','pages');
    }
}
