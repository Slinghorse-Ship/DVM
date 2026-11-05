<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{CameraPhotos,Media};

/** Social photo originals use the existing editorial receipt table and Media's owned transaction. */
final class PhotoSaveRequests
{
    private const MODULE='social-photo';
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot) {}
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');$this->access->require('website','read');
        if($write){$this->access->require('social','edit');$this->access->require('website','edit');}
        return$actor;
    }
    private function key(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Fotoformular öffnen.');
        if($this->db->inTransaction())throw new DomainException('Foto braucht einen eigenen Speichervorgang.');
    }
    private function sameActor(array $actor,bool $write):void
    {
        $current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],self::MODULE,$actor['id'],$key])->fetch();
    }
    private function files(string $id):array
    {
        $out=[];$media=new Media($this->db,$this->access,$this->mediaRoot);
        foreach(['original','large','medium','thumb']as$size)$out[$size]=$media->variant($id,$size)['sha256'];
        return$out;
    }
    private function original(array $row,string $itemId):array
    {
        if($row['item_id']!==$itemId||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Fotonachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Fotonachweis prüfen.');}
        $media=$snapshot['media']??null;
        if(!is_array($media)||($snapshot['module']??null)!==self::MODULE||($snapshot['actor_id']??null)!==$row['actor_id']||($snapshot['item_version']??null)!==(int)$row['item_version']||($media['club_id']??null)!==$row['club_id']||($media['created_by']??null)!==$row['actor_id'])throw new DomainException('Gespeicherten Fotonachweis prüfen.');
        Content::id($media['id']??'');
        $actual=Db::run($this->db,'SELECT * FROM khcms_media WHERE club_id=? AND id=?',[$row['club_id'],$media['id']])->fetch();
        if($actual!==$media)throw new DomainException('Gespeicherten Fotonachweis prüfen.');
        try{$hashes=$this->files($media['id']);}catch(\RuntimeException|\InvalidArgumentException $error){throw new DomainException('Foto bereits gespeichert, aber nicht verfügbar. Bilderbestand prüfen.',0,$error);}
        if($hashes!==($snapshot['files']??null)||$hashes['original']!==$media['sha256'])throw new DomainException('Gespeicherten Fotonachweis prüfen.');
        return ['id'=>$media['id'],'item_id'=>$itemId,'item_version'=>(int)$row['item_version'],'name'=>$media['alt'],'url'=>'/media-preview/'.$media['id'].'/large','thumb'=>'/media-preview/'.$media['id'].'/thumb','original_url'=>'/media-preview/'.$media['id'].'/original'];
    }
    private function lock(array $actor,string $key):string
    {
        $lock='social-photo:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Foto wird noch gespeichert. Gespeichertes Foto prüfen.');return$lock;
    }
    public function review(string $itemId,string $key):?array
    {
        $actor=$this->actor();$this->key($key);(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($itemId);$lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$itemId):null;$this->sameActor($actor,false);return$result;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    public function save(string $itemId,int $version,string $source,string $name,string $alt,string $key):array
    {
        $actor=$this->actor(true);$this->key($key);Content::id($itemId);
        $name=basename(str_replace('\\','/',$name));
        if($version<1||$version>=4294967295||$name===''||preg_match('//u',$name)!==1||mb_strlen($name)>240||preg_match('/[\x00-\x1F\x7F]/',$name))throw new DomainException('Fotoformular und Dateiname prüfen.');
        if(preg_match('//u',$alt)!==1||mb_strlen($alt)>500||preg_match('/[\x00-\x1F\x7F]/',$alt))throw new DomainException('Alternativtext: maximal 500 UTF-8-Zeichen ohne Steuerzeichen.');
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)||is_link($source)||!is_file($source)||filesize($source)<1||filesize($source)>CameraPhotos::INPUT_BYTES)throw new DomainException('Bilddatei prüfen (höchstens 256 MB).');
        $proof=hash_file('sha256',$source);$hash=hash('sha256',json_encode([$itemId,$version,$name,$alt,$proof],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,true);$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zum ursprünglichen Foto. Gespeichertes Foto prüfen.');return$this->original($row,$itemId);}
            $item=(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($itemId);if($item['version']!==$version)throw new DomainException('Beitrag inzwischen geändert. Fotoformular neu öffnen.');
            try{
                (new Media($this->db,$this->access,$this->mediaRoot))->upload($source,$name,$alt,null,function(array $saved)use($actor,$itemId,$version,$key,$hash,$proof,$source):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Fototransaktion fehlt.');$this->sameActor($actor,true);
                    if((new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($itemId,true)['version']!==$version)throw new DomainException('Beitrag inzwischen geändert. Fotoformular neu öffnen.');
                    if(!hash_equals($proof,hash_file('sha256',$source)))throw new DomainException('Fotodatei während der Speicherung geändert.');
                    $media=Db::run($this->db,'SELECT * FROM khcms_media WHERE club_id=? AND id=?',[$actor['club_id'],$saved['id']])->fetch();
                    $snapshot=json_encode(['module'=>self::MODULE,'actor_id'=>$actor['id'],'item_version'=>$version,'media'=>$media,'files'=>$this->files($saved['id'])],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],self::MODULE,$actor['id'],$key,$hash,$itemId,$version,$snapshot,hash('sha256',$snapshot)]);
                });
                return$this->original($this->row($actor,$key),$itemId);
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->sameActor($actor,true);$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash))return$this->original($row,$itemId);}throw$error;}
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
