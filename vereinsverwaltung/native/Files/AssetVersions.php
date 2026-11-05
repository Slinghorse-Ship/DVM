<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,Media,MediaMetadata,UploadTransaction};
use Kulturhus\Native\Shared\{Access,Db};

/** Logical head changes never rewrite an earlier revision, blob or existing image. */
final class AssetVersions
{
    use ClubPublicationLock;
    public const MAX_REVISIONS=10000;
    private BlobStore $store;
    public function __construct(private PDO $db,private Access $access,string $fileRoot,private ?string $mediaRoot=null)
    {$this->store=new BlobStore($fileRoot);}

    public function uploadMany(array $files,?callable $associate=null,?UploadTransaction $transaction=null):mixed
    {
        $this->access->require('website','edit');
        if($transaction===null)return UploadTransaction::run($this->db,fn($tx)=>$this->uploadMany($files,$associate,$tx));
        return $transaction->segment($this->db,function()use($files,$associate,$transaction):mixed{
            if(!array_is_list($files)||count($files)<1||count($files)>10)throw new InvalidArgumentException('Eine bis zehn Dateien gleichzeitig auswählen.');
            $prepared=[];$total=0;
            foreach($files as$file){if(!is_array($file)||array_diff(array_keys($file),['path','name','metadata'])||!is_string($file['path']??null)||!is_string($file['name']??null)||!is_array($file['metadata']??[]))throw new InvalidArgumentException('Uploaddatei prüfen.');$descriptor=Formats::inspect($file['path'],$file['name']);$total+=$descriptor['bytes'];if($total>Formats::MAX_BATCH_BYTES)throw new InvalidArgumentException('Zusammen höchstens 50 MiB hochladen.');$metadata=Metadata::normalize($file['metadata']??[],$descriptor['name']);$this->rights($metadata,Metadata::normalize([],$descriptor['name']));$prepared[]=[$file['path'],$descriptor,$metadata];}
            $actor=$this->access->require('website','edit');$this->lockClub();Quota::assertUpload($this->db,$actor['club_id'],$total);$result=[];
            foreach($prepared as[$source,$descriptor,$metadata]){$blob=$this->newId();$this->store->write($this->db,$transaction,$actor['club_id'],$blob,$source,$descriptor);Db::run($this->db,'INSERT INTO khcms_file_blobs VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$blob,$actor['club_id'],$descriptor['name'],$descriptor['mime'],$descriptor['bytes'],$descriptor['sha256'],$actor['id']]);$result[]=$this->create('file',$blob,$descriptor,$metadata,$actor);}
            return $associate?$associate($result):$result;
        });
    }

    public function replace(string $id,int $version,string $source,string $name,?array $metadata=null,?UploadTransaction $transaction=null):array
    {
        $this->access->require('website','edit');self::identifier($id);self::counter($version);
        if($transaction===null)return UploadTransaction::run($this->db,fn($tx)=>$this->replace($id,$version,$source,$name,$metadata,$tx));
        return $transaction->segment($this->db,function()use($id,$version,$source,$name,$metadata,$transaction):array{
            $descriptor=Formats::inspect($source,$name);$actor=$this->access->require('website','edit');$this->lockClub();$asset=$this->locked($id,$version);
            if($asset['kind']!=='file')throw new InvalidArgumentException('Für ein Foto den Fotoimport verwenden.');
            $next=$metadata===null?$asset['metadata']:Metadata::normalize($metadata,$descriptor['name']);$this->rights($next,$asset['metadata']);Quota::assertUpload($this->db,$actor['club_id'],$descriptor['bytes']);
            $blob=$this->newId();$this->store->write($this->db,$transaction,$actor['club_id'],$blob,$source,$descriptor);Db::run($this->db,'INSERT INTO khcms_file_blobs VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$blob,$actor['club_id'],$descriptor['name'],$descriptor['mime'],$descriptor['bytes'],$descriptor['sha256'],$actor['id']]);
            return $this->append($asset,'file',$blob,$descriptor,$next,$actor);
        });
    }

    /** Called with the immutable result of the existing Media/CameraPhotos pipeline. */
    public function linkImage(string $mediaId,?string $id=null,?int $version=null,?array $metadata=null):array
    {
        self::identifier($mediaId);if($id!==null){self::identifier($id);self::counter($version??0);}
        return Db::atomic($this->db,function()use($mediaId,$id,$version,$metadata):array{
            $actor=$this->access->require('website','edit');$this->lockClub();$media=$this->media();$file=$media->variant($mediaId,'original');
            $current=(new MediaMetadata($this->db,$this->access))->get($mediaId);$meta=Metadata::normalize($metadata??array_intersect_key($current,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])),$file['name']);
            // Image usage authority stays in MediaMetadata; this snapshot cannot override it.
            foreach(Metadata::RIGHTS as$key)$meta[$key]=$current[$key];
            $descriptor=['name'=>$file['name'],'mime'=>$file['mime'],'bytes'=>$file['bytes'],'sha256'=>$file['sha256']];
            $existing=Db::run($this->db,"SELECT a.id FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.club_id=? AND r.source_kind='media' AND r.source_id=? AND a.state<>'purged' LIMIT 1 FOR SHARE",[$actor['club_id'],$mediaId])->fetchColumn();
            if($existing&&($id===null||$existing!==$id))throw new Conflict('Dieses Foto gehört bereits zu einer Versionsfolge. Bestehenden Verlauf öffnen.');
            if($id===null){
                if($metadata!==null)(new MediaMetadata($this->db,$this->access))->save($mediaId,array_intersect_key($meta,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])),$current['version']);
                return $this->create('image',$mediaId,$descriptor,$meta,$actor);
            }
            $asset=$this->locked($id,$version);if($asset['kind']!=='image')throw new InvalidArgumentException('Für eine Datei den Dateiupload verwenden.');
            $meta=$metadata===null?array_replace($asset['metadata'],$asset['source_metadata']??[]):$meta;
            $meta=array_replace($meta,Metadata::restrict($asset['usage'],$current));
            (new MediaMetadata($this->db,$this->access))->save($mediaId,array_intersect_key($meta,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])),$current['version']);
            return $this->append($asset,'media',$mediaId,$descriptor,$meta,$actor);
        });
    }

    public function metadata(string $id,int $version,array $input,?int $mediaVersion=null):array
    {
        self::identifier($id);self::counter($version);
        return Db::atomic($this->db,function()use($id,$version,$input,$mediaVersion):array{
            $actor=$this->access->require('website','edit');$this->lockClub();$asset=$this->locked($id,$version);$next=Metadata::normalize($input,$asset['name']);$this->verify($asset);
            if($asset['source_kind']==='media'){
                $metadata=new MediaMetadata($this->db,$this->access);$current=$metadata->get($asset['source_id']);
                if($mediaVersion===null||$mediaVersion<0||$mediaVersion!==$current['version'])throw new Conflict('Bildangaben außerhalb der Versionsfolge geändert. Aktuellen Stand öffnen.');
                $this->rights($next,array_replace($asset['metadata'],$current));
                $metadata->save($asset['source_id'],array_intersect_key($next,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])),$current['version']);
            }else{$this->rights($next,$asset['metadata']);if($mediaVersion!==null)throw new InvalidArgumentException('Bildversion nur für Bilddateien angeben.');}
            return $this->append($asset,$asset['source_kind'],$asset['source_id'],$asset,$next,$actor);
        });
    }

    public function restore(string $id,string $revisionId,int $version):array
    {
        self::identifier($id);self::identifier($revisionId);self::counter($version);
        return Db::atomic($this->db,function()use($id,$revisionId,$version):array{
            $actor=$this->access->require('website','edit');$this->lockClub();$asset=$this->locked($id,$version);$original=$this->revision($revisionId);
            if($original['asset_id']!==$id)throw new DomainException('Version gehört nicht zu dieser Datei.');$this->verify($original);
            $next=Metadata::restore($original['metadata'],$asset['metadata']);
            if($original['source_kind']==='media'){
                $service=new MediaMetadata($this->db,$this->access);$current=$service->get($original['source_id']);$next=array_replace($original['metadata'],Metadata::restrict($asset['usage'],$current));
                $service->save($original['source_id'],array_intersect_key($next,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])),$current['version']);
            }
            return $this->append($asset,$original['source_kind'],$original['source_id'],$original,$next,$actor,$revisionId);
        });
    }

    public function get(string $id,bool $includeRemoved=false):array
    {
        self::identifier($id);$club=$this->access->require('website','read')['club_id'];
        $row=Db::run($this->db,'SELECT a.*,r.id AS revision_id,r.source_kind,r.source_id,r.original_name,r.mime,r.bytes,r.sha256,r.metadata_json,r.actor_id,r.restored_from,r.created_at AS revision_created_at FROM khcms_assets a JOIN khcms_asset_revisions r ON r.id=a.head_id AND r.asset_id=a.id AND r.club_id=a.club_id WHERE a.id=? AND a.club_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$id,$club])->fetch();
        if(!$row||(!$includeRemoved&&$row['state']!=='active'))throw new DomainException('Datei nicht verfügbar.');
        if($row['state']!=='active')$this->access->require('website','manage');
        $result=self::present($row);$result['usage']=array_intersect_key($result['metadata'],array_flip(Metadata::RIGHTS));
        if($result['source_kind']==='media')try{
            $source=(new MediaMetadata($this->db,$this->access))->get($result['source_id']);$result['usage']=array_intersect_key($source,array_flip(Metadata::RIGHTS));$result['source_metadata']=array_intersect_key($source,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS]));$result['source_metadata_version']=$source['version'];
        }catch(DomainException $error){if($result['state']==='active')throw $error;}
        return $result;
    }
    public function revision(string $id,bool $includeRemoved=false):array
    {
        self::identifier($id);$club=$this->access->require('website','read')['club_id'];
        $row=Db::run($this->db,'SELECT r.*,a.kind,a.state FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.id=? AND r.club_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$id,$club])->fetch();
        if(!$row||(!$includeRemoved&&$row['state']!=='active'))throw new DomainException('Dateifassung nicht verfügbar.');if($row['state']!=='active')$this->access->require('website','manage');$row['revision_id']=$row['id'];$row['id']=$row['asset_id'];$row['version']=$row['revision'];return self::present($row);
    }
    public function history(string $id,int $page=1):array
    {
        $asset=$this->get($id,true);if($page<1||$page>1000000)throw new InvalidArgumentException('Verlaufsseite prüfen.');$club=$this->access->require('website','read')['club_id'];$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_asset_revisions WHERE asset_id=? AND club_id=?',[$id,$club])->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT id AS revision_id,revision,original_name AS name,mime,bytes,sha256,metadata_json,restored_from,actor_id,created_at FROM khcms_asset_revisions WHERE asset_id=? AND club_id=? ORDER BY revision DESC LIMIT 10 OFFSET '.(($page-1)*10),[$id,$club])->fetchAll();
        foreach($rows as&$row){$row['metadata']=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);unset($row['metadata_json']);$row['revision']=(int)$row['revision'];$row['bytes']=(int)$row['bytes'];}return compact('asset','rows','page','pages','total');
    }
    public function file(string $revisionId):array
    {$revision=$this->revision($revisionId);return $this->verify($revision);}

    private function create(string $kind,string $source,array $descriptor,array $metadata,array $actor):array
    {
        $id=$this->newId();Db::run($this->db,"INSERT INTO khcms_assets VALUES(?,?,?,0,NULL,0,'active',?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$actor['club_id'],$kind,$actor['id']]);return $this->append(['id'=>$id,'version'=>0,'revision'=>0],$kind==='image'?'media':'file',$source,$descriptor,$metadata,$actor);
    }
    private function append(array $asset,string $kind,string $source,array $descriptor,array $metadata,array $actor,?string $restored=null):array
    {
        if($asset['revision']>=self::MAX_REVISIONS||$asset['version']>=4294967294)throw new DomainException('Versionsgrenze erreicht. Neue Datei anlegen.');
        $id=$this->newId();$name=$descriptor['name']??$descriptor['original_name'];$json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        Db::run($this->db,'INSERT INTO khcms_asset_revisions VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$asset['id'],$actor['club_id'],$asset['revision']+1,$kind,$source,$name,$descriptor['mime'],$descriptor['bytes'],$descriptor['sha256'],$json,$restored,$actor['id']]);
        Db::run($this->db,'UPDATE khcms_assets SET version=version+1,revision=revision+1,head_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=? AND version=?',[$id,$asset['id'],$actor['club_id'],$asset['version']]);Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$actor['club_id']]);return $this->get($asset['id']);
    }
    private function locked(string $id,int $version):array
    {$actor=$this->access->require('website','edit');$row=Db::run($this->db,'SELECT version,state FROM khcms_assets WHERE id=? AND club_id=? FOR UPDATE',[$id,$actor['club_id']])->fetch();if(!$row||$row['state']!=='active')throw new DomainException('Datei nicht verfügbar.');if((int)$row['version']!==$version)throw new Conflict('Datei inzwischen geändert. Aktuellen Stand öffnen.');return $this->get($id);}
    private function verify(array $revision):array
    {
        if($revision['source_kind']==='file')return $this->store->file($this->access->club(),$revision['source_id'],$revision);
        if($revision['source_kind']!=='media')throw new DomainException('Gespeicherte Dateiquelle prüfen.');try{$file=$this->media()->variant($revision['source_id'],'original');}catch(\RuntimeException $error){throw new DomainException('Ursprüngliche Bildfassung nicht verfügbar.',0,$error);}if($file['bytes']!==$revision['bytes']||!hash_equals($file['sha256'],$revision['sha256']))throw new DomainException('Ursprüngliche Bildfassung wurde verändert.');return $file;
    }
    private function media():Media
    {if($this->mediaRoot===null)throw new DomainException('Bildspeicher nicht verfügbar.');return new Media($this->db,$this->access,$this->mediaRoot);}
    private function rights(array $next,array $current):void
    {if(!Metadata::sameRights($next,$current))$this->access->require('website','manage');}
    private function newId():string
    {
        do{$id=bin2hex(random_bytes(16));$exists=Db::run($this->db,'SELECT 1 FROM khcms_assets WHERE id=? UNION ALL SELECT 1 FROM khcms_asset_revisions WHERE id=? UNION ALL SELECT 1 FROM khcms_file_blobs WHERE id=? UNION ALL SELECT 1 FROM khcms_documents WHERE id=? LIMIT 1',[$id,$id,$id,$id])->fetchColumn();}while($exists);return $id;
    }
    private static function present(array $row):array
    {
        $row['name']=$row['original_name'];unset($row['original_name']);$row['metadata']=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);unset($row['metadata_json']);foreach(['version','revision','bytes']as$key)$row[$key]=(int)$row[$key];return $row;
    }
    public static function identifier(string $id):void
    {if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Datei- und Versionskennung prüfen.');}
    private static function counter(int $version):void
    {if($version<1||$version>=4294967295)throw new InvalidArgumentException('Gespeicherte Dateiversion prüfen.');}
}
