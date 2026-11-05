<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Exact selected private source, checked again at approval and every later use. */
final class VideoApproval
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot) {}

    public function capture(array $item,string $id): array
    {
        if(!$this->db->inTransaction())throw new DomainException('Videofreigabe benötigt einen eigenen Vorgang.');
        $actor=$this->access->require('social','publish');
        if($item['kind']!=='reel'||$item['club_id']!==$actor['club_id'])throw new DomainException('Eigenes Reel erneut öffnen.');
        $row=$this->row($item,$id);
        VideoArchive::assertSelectable($this->db,$actor['club_id'],$row['source_id']);
        if((int)$row['item_version']!==$item['version'])throw new DomainException('Video gehört zu einem früheren Inhaltsstand.');
        $file=$this->checkedFile($id,$row);
        $metadata=VideoMetadata::current($this->db,$actor['club_id'],$row['source_id']);VideoMetadata::assertUsage($metadata);$snapshot=VideoMetadata::snapshot($metadata);
        $this->access->require('social','publish');
        return [
            'id'=>$id,'source_id'=>$row['source_id'],'sha256'=>$file['sha256'],
            'bytes'=>$file['bytes'],'profile'=>$file['profile'],
            'item_version'=>(int)$row['item_version'],'content_hash'=>$row['content_hash'],
            'identity_version'=>(int)$row['identity_version'],
            'metadata_version'=>$snapshot['version'],'metadata_public'=>$snapshot['public'],
        ];
    }

    public function check(array $item): array
    {
        $approved=$item['approved_video']??null;
        if($item['kind']!=='reel'||!is_array($approved)||!is_string($approved['id']??null)||!is_int($item['approved_version']??null))throw new DomainException('Aktuelle Videofassung zuerst freigeben.');
        $row=$this->row($item,$approved['id']);
        $file=$this->checkedFile($approved['id'],$row);
        $actual=[
            'id'=>$approved['id'],'source_id'=>$row['source_id'],'sha256'=>$file['sha256'],
            'bytes'=>$file['bytes'],'profile'=>$file['profile'],
            'item_version'=>(int)$row['item_version'],'content_hash'=>$row['content_hash'],
            'identity_version'=>(int)$row['identity_version'],
        ];
        $metadata=VideoMetadata::current($this->db,$item['club_id'],$row['source_id']);VideoMetadata::assertUsage($metadata);$snapshot=VideoMetadata::snapshot($metadata);
        if(array_key_exists('metadata_version',$approved)){$actual['metadata_version']=$snapshot['version'];$actual['metadata_public']=$snapshot['public'];}
        elseif($snapshot['version']!==0)throw new DomainException('Videoangaben wurden geändert. Freigabe erneuern.');
        if($actual['item_version']+1!==$item['approved_version']||self::canonical($actual)!==self::canonical($approved))throw new DomainException('Freigegebene originale Videofassung wurde geändert.');
        return $actual;
    }

    private function row(array $item,string $id): array
    {
        Content::id($id);
        $club=$this->access->require('social','read')['club_id'];
        $store=new Videos($this->db,$this->access,$this->mediaRoot);
        if(!$store->installed())throw new DomainException('Videos über den Installer aktivieren.');
        $row=Db::run($this->db,'SELECT v.*,s.sha256,s.bytes,s.profile_json FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.item_id=? AND v.id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$club,$item['id'],$id])->fetch();
        if(!$row||$item['club_id']!==$club||!hash_equals($row['content_hash'],Images::fingerprint($item)))throw new DomainException('Video gehört nicht zur aktuellen eigenen Inhaltsfassung.');
        $identity=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$club])->fetchColumn();
        if((int)$row['identity_version']!==($identity===false?0:(int)$identity))throw new DomainException('Vereinsdaten wurden geändert. Video erneut speichern.');
        return $row;
    }

    private function checkedFile(string $id,array $row): array
    {
        $file=(new Videos($this->db,$this->access,$this->mediaRoot))->file($id);
        $profile=ReelFile::inspect($file['path']);
        if(!hash_equals($row['sha256'],$file['sha256'])||(int)$row['bytes']!==$file['bytes']||self::canonical($profile)!==self::canonical($file['profile']))throw new DomainException('Originale Videobytes oder Tonprofil fehlen.');
        return $file;
    }

    private static function canonical(mixed $value): string
    {
        $normalize=static function(mixed $entry)use(&$normalize):mixed {
            if(!is_array($entry))return $entry;
            if(!array_is_list($entry))ksort($entry,SORT_STRING);
            foreach($entry as$key=>$part)$entry[$key]=$normalize($part);
            return $entry;
        };
        return json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
}
