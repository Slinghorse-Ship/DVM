<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** A fresh serializable database boundary, never a cached observation or user confirmation. */
final class MediaRemovalGuard
{
    public function __construct(private PDO $db,private Access $access){}

    public function run(string $id,callable $change):mixed
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bildkennung prüfen.');
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$this->db->inTransaction())throw new DomainException('Bildänderung benötigt einen eigenen gesicherten Vorgang.');
        // Only the next transaction changes isolation. Normal page and worker connections keep theirs.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->db->beginTransaction();
        try{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];
            $asset=Db::run($this->db,'SELECT * FROM khcms_media WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetch();
            if(!$asset)throw new DomainException('Bild nicht verfügbar.');
            // Serializable aggregate reads hold shared row/range locks on every inspected source.
            // Writers through other modules and even direct SQL cannot add a reference before commit.
            $state=(new MediaReferences($this->db,$this->access))->inspect($id);
            if(!$state['complete'])throw new DomainException('Bildverwendungen sind nicht vollständig prüfbar.');
            if($state['referenced'])throw new DomainException('Das Bild wird noch verwendet.');
            if(\Kulturhus\Native\Files\FileReferences::installed($this->db)&&\Kulturhus\Native\Files\References::imageReferenced($this->db,$club,$id))throw new DomainException('Das Bild gehört zu einer gespeicherten Bildfolge. Diese zuerst in der Dateiablage auflösen.');
            $result=$change($asset);
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Bildverwendungszugang geändert.');
            $this->db->commit();
            return $result;
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }
}
