<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** Actor-bound receipts share the installed editorial table; reading never renders or publishes. */
final class CanvasSaveRequests
{
    private const MODULE='social-canvas';
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot) {}
    private function key(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Bildformular öffnen.');
    }
    private static function ordered(mixed $value):mixed
    {
        if(!is_array($value))return$value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        return array_map(self::ordered(...),$value);
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],self::MODULE,$actor['id'],$key])->fetch();
    }
    private function sameActor(array $actor,string $capability):void
    {
        $current=$this->access->require('social',$capability);
        if($current['club_id']!==$actor['club_id']||$current['id']!==$actor['id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function original(array $row,string $itemId):array
    {
        if($row['item_id']!==$itemId||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Bildnachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Bildnachweis prüfen.');}
        $image=$snapshot['image']??null;
        if(!is_array($image)||($snapshot['module']??null)!==self::MODULE||($snapshot['actor_id']??null)!==$row['actor_id']||($image['club_id']??null)!==$row['club_id']||($image['item_id']??null)!==$itemId||($image['item_version']??null)!==(int)$row['item_version'])throw new DomainException('Gespeicherten Bildnachweis prüfen.');
        Content::id($image['id']??'');
        (new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($itemId);
        $actual=Db::run($this->db,'SELECT * FROM native_social_images WHERE club_id=? AND item_id=? AND id=?',[$row['club_id'],$itemId,$image['id']])->fetch();
        if($actual!==$image)throw new DomainException('Gespeicherten Bildnachweis prüfen.');
        $images=new Images($this->db,$this->access,$this->mediaRoot);$images->file($image['id']);$images->jpeg($image['id']);
        $metadata=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        if(isset($metadata['story_series'])){
            $paths=StorySeries::paths(dirname($this->mediaRoot).'/communication-images',$image['id']);
            if(($metadata['story_series']['format']??null)!==1||!StorySeries::same(StorySeries::sources($paths),$metadata['story_series']['sha256']??[]))throw new DomainException('Gespeicherte Story-Vorlage prüfen.');
        }
        return ['id'=>$image['id'],'item_id'=>$itemId,'item_version'=>(int)$image['item_version'],'slide'=>(int)$image['slide'],'url'=>'/social-image/'.$image['id']];
    }
    private function lock(array $actor,string $key):string
    {
        $lock='canvas-save:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Bildspeicherung läuft. Gespeicherte Bildfassung prüfen.');
        return$lock;
    }
    /** The same lock prevents a false negative while the original writer is committing. */
    public function review(string $itemId,string $key):?array
    {
        $actor=$this->access->require('social','read');Content::id($itemId);$this->key($key);
        if($this->db->inTransaction())throw new DomainException('Bildfassung braucht einen eigenen Speichervorgang.');
        (new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($itemId);
        $lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$itemId):null;$this->sameActor($actor,'read');return$result;}
        finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    /** The callback writes its receipt inside Images' existing owned file/SQL transaction. */
    public function save(string $itemId,int $version,int $identityVersion,int $slide,array $document,string $source,string $key,callable $save,array $seriesHashes=[]):string
    {
        $actor=$this->access->require('social','edit');$this->access->require('social','read');Content::id($itemId);$this->key($key);
        if($this->db->inTransaction())throw new DomainException('Bildfassung braucht einen eigenen Speichervorgang.');
        if($version<1||$version>=4294967295||$identityVersion<1||$identityVersion>=4294967295||$slide<0||$slide>9)throw new DomainException('Ursprüngliches Bildformular öffnen.');
        $json=json_encode(self::ordered($document),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>100000||preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)||is_link($source)||!is_file($source)||filesize($source)<1||filesize($source)>5*1024*1024)throw new DomainException('Bilddatei und Gestaltung prüfen.');
        $proof=hash_file('sha256',$source);$input=[$itemId,$version,$identityVersion,$slide,$json,$proof];
        if($seriesHashes!==[]){ksort($seriesHashes);$input[]=['story_series'=>$seriesHashes];}
        $hash=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
        $lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,'edit');$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zur ursprünglichen Bildfassung. Gespeicherte Bildfassung prüfen.');return$this->original($row,$itemId)['id'];}
            try{
                return$save(function(string $imageId)use($actor,$key,$hash,$itemId,$version,$source,$proof):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Bildtransaktion fehlt.');
                    if(!hash_equals($proof,hash_file('sha256',$source)))throw new DomainException('Bilddatei während der Speicherung geändert.');
                    $this->sameActor($actor,'edit');$this->sameActor($actor,'read');
                    $image=Db::run($this->db,'SELECT * FROM native_social_images WHERE club_id=? AND item_id=? AND id=?',[$actor['club_id'],$itemId,$imageId])->fetch();
                    if(!$image||(int)$image['item_version']!==$version)throw new DomainException('Gespeicherten Bildnachweis prüfen.');
                    $snapshot=json_encode(['module'=>self::MODULE,'actor_id'=>$actor['id'],'image'=>$image],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],self::MODULE,$actor['id'],$key,$hash,$itemId,$version,$snapshot,hash('sha256',$snapshot)]);
                });
            }catch(Throwable $error){
                if(!$this->db->inTransaction()){$this->sameActor($actor,'edit');$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash))return$this->original($row,$itemId)['id'];}
                throw$error;
            }
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
