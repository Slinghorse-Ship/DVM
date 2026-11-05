<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Cms\CameraPhotos;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};
/** One conscious editorial save per own form key; accepted originals are read, never recreated. */
final class EditionSaveRequests
{
    public function __construct(private PDO $db,private Access $access,private ?string $mediaRoot=null,private string $module='newsletter'){if(!in_array($module,['newsletter','press','social'],true))throw new DomainException('Redaktionsbereich prüfen.');}
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_communication_save_requests (
            club_id VARCHAR(64) NOT NULL,module VARCHAR(16) NOT NULL,actor_id VARCHAR(64) NOT NULL,
            request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            item_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,item_version INT UNSIGNED NOT NULL,
            snapshot_json MEDIUMTEXT NOT NULL,snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,module,actor_id,request_key),KEY communication_save_item(club_id,module,item_id,item_version),
            CHECK(JSON_VALID(snapshot_json)),CHECK(item_version>0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
    public static function installed(PDO $db):bool
    {
        if((int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_schema_versions','native_communication_save_requests')")->fetchColumn()!==2)return false;
        return (int)Db::run($db,"SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()>=16;
    }
    private function key(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliches Speicherformular öffnen.');
        if(!self::installed($this->db))throw new DomainException('Speichervorgänge über den Installer aktivieren.');
    }
    private static function ordered(mixed $value):mixed
    {
        if(!is_array($value))return$value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        return array_map(self::ordered(...),$value);
    }
    private function inputHash(array $data,int $version):string
    {
        if($version<0||$version>=4294967295||array_diff(array_keys($data),['id','title','summary','body','body_html','template','kind','audience','blocks','channels','story','carousel','destination_url','recipient_ids','status','document_attachments','attachment_rights',...($this->module==='press'?['attachment_media_ids']:[]),...($this->module==='social'?['image_design','story_link_requested','video_design']:[])]))throw new DomainException('Redaktionsformular prüfen.');
        if(!empty($data['id']))Content::id($data['id']);
        if($this->module==='press'&&(array_key_exists('document_attachments',$data)||array_key_exists('attachment_rights',$data)))throw new DomainException('Pressefoto-Auswahl prüfen.');
        if($this->module==='social'&&array_intersect(array_keys($data),['document_attachments','attachment_rights','recipient_ids','audience']))throw new DomainException('Social-Formular erneut öffnen.');
        Content::document($this->module,$data);
        return hash('sha256',json_encode(['version'=>$version,'input'=>self::ordered($data)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    private static function photoProofs(array $files):array
    {
        if(!array_is_list($files)||count($files)>10)throw new DomainException('Bis zu zehn Fotoanlagen auswählen.');$out=[];$total=0;
        foreach($files as$file){
            if(!is_array($file)||array_diff(array_keys($file),['path','name']))throw new DomainException('Fotodatei prüfen.');$path=$file['path']??null;$name=$file['name']??null;
            if(!is_string($path)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path)||!is_string($name)||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Fotodatei prüfen.');
            $name=basename(str_replace('\\','/',$name));$bytes=filesize($path);$total+=(int)$bytes;
            if($bytes===false||$bytes<1||$bytes>CameraPhotos::INPUT_BYTES||$total>CameraPhotos::BATCH_INPUT_BYTES||$name===''||mb_strlen($name)>240)throw new DomainException('Kameraoriginale bis 256 MiB je Datei und zusammen bis 512 MiB auswählen.');
            $out[]=['name'=>$name,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$path)];
        }
        return $out;
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],$this->module,$actor['id'],$key])->fetch();
    }
    private function original(array $row):array
    {
        if(!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Originalnachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Originalnachweis prüfen.');}
        if(!is_array($snapshot)||($snapshot['id']??null)!==$row['item_id']||($snapshot['club_id']??null)!==$row['club_id']||($snapshot['module']??null)!==$this->module||($snapshot['version']??null)!==(int)$row['item_version'])throw new DomainException('Gespeicherten Originalnachweis prüfen.');
        $original=(new Workspace($this->db,$this->access,$this->module,$this->mediaRoot))->revision($row['item_id'],(int)$row['item_version']);
        if($original!==$snapshot)throw new DomainException('Gespeicherten Originalnachweis prüfen.');
        return$snapshot;
    }
    /** Pure lookup of this actor's original save; no new item, revision or transport. */
    public function review(string $key):?array
    {
        $actor=$this->access->require($this->module,'read');$this->key($key);$row=$this->row($actor,$key);
        if(!$row)return null;
        $original=$this->original($row);
        $current=$this->access->require($this->module,'read');
        if($current['club_id']!==$actor['club_id']||$current['id']!==$actor['id'])throw new DomainException('Vereinszuordnung geändert.');
        return$original;
    }
    public function save(array $data,int $version,string $key,array $files=[]):array
    {
        $actor=$this->access->require($this->module,'edit');$this->access->require($this->module,'read');$this->key($key);
        if($this->db->inTransaction())throw new DomainException('Ausgabe braucht einen eigenen Speichervorgang.');
        if($this->module==='social'&&$files)throw new DomainException('Social-Dateien über ihre eigene Medienauswahl übernehmen.');
        $hash=$this->inputHash($data,$version);
        $proofs=$this->module==='press'?self::photoProofs($files):DocumentAttachments::fileProofs($files);if($files)$hash=hash('sha256',json_encode([$hash,$proofs],JSON_THROW_ON_ERROR));
        $lock='edition-save:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$actor['club_id'],$this->module,$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Speicherung läuft. Gespeicherten Stand prüfen.');
        try{
            try{
                return \Kulturhus\Native\Cms\UploadTransaction::run($this->db,function($transaction)use($actor,$key,$hash,$data,$version,$files,$proofs):array{
                    $current=$this->access->require($this->module,'edit');
                    if($current['club_id']!==$actor['club_id']||$current['id']!==$actor['id'])throw new DomainException('Speicherzugang geändert.');
                    $row=$this->row($actor,$key);
                    if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Dieses Formular gehört zur ursprünglichen Eingabe. Neuen Entwurf bewusst öffnen.');return$this->original($row);}
                    $saveData=$data;
                    if($files&&$this->module==='press'){
                        if($this->mediaRoot===null)throw new DomainException('Fotospeicher fehlt.');
                        $added=(new \Kulturhus\Native\Cms\MediaUploads($this->db,new PressUploadAccess($this->db,$this->access),$this->mediaRoot))->uploadMany($files,null,$transaction);
                        if(self::photoProofs($files)!==$proofs)throw new DomainException('Foto während der Speicherung geändert.');
                        $saveData['attachment_media_ids']=[...($data['attachment_media_ids']??[]),...array_column($added,'id')];
                    }elseif($files){
                        if($this->mediaRoot===null)throw new DomainException('PDF-Speicher fehlt.');
                        $store=new DocumentAttachments($this->db,$this->access,$this->mediaRoot);
                        $added=$store->uploadMany($files,$data['attachment_rights']??[],$transaction);
                        if(DocumentAttachments::fileProofs($files)!==$proofs)throw new DomainException('PDF während der Speicherung geändert.');
                        $saveData['document_attachments']=[...($data['document_attachments']??[]),...$added];
                    }
                    unset($saveData['attachment_rights']);
                    $workspace=new Workspace($this->db,$this->access,$this->module,$this->mediaRoot);$saved=$workspace->save($saveData,$version);
                    $snapshot=$workspace->revision($saved['id'],$saved['version']);$json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    if(strlen($json)>8000000)throw new DomainException('Ausgabenoriginal zu groß.');
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],$this->module,$actor['id'],$key,$hash,$saved['id'],$saved['version'],$json,hash('sha256',$json)]);
                    if($this->access->require($this->module,'edit')['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
                    return$snapshot;
                });
            }catch(Throwable $error){
                // An actual accepted COMMIT can lose only its acknowledgement. Never rerun the save.
                if(!$this->db->inTransaction()){
                    $current=$this->access->require($this->module,'edit');
                    if($current['club_id']===$actor['club_id']&&$current['id']===$actor['id']){
                        $row=$this->row($actor,$key);
                        if($row&&hash_equals($row['input_sha256'],$hash))return$this->original($row);
                    }
                }
                throw$error;
            }
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
