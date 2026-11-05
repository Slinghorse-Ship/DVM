<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{WebsiteDocuments,UploadTransaction,MediaPolicy};
/** New private newsletter PDFs, immutable selected originals and bounded encoded mail copies. */
final class DocumentAttachments
{
    public const MAX_FILES=10;
    public const MAX_BYTES=20000000;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_communication_documents (
            club_id VARCHAR(64) NOT NULL,module VARCHAR(16) NOT NULL,document_id CHAR(32) NOT NULL,
            name VARCHAR(240) NOT NULL,bytes INT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,
            rights_json JSON NOT NULL,created_by VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,module,document_id),UNIQUE(document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public static function installed(PDO $db):bool
    {
        if((int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_schema_versions','native_communication_documents','khcms_documents','khcms_media_policy')")->fetchColumn()!==4)return false;
        return (int)Db::run($db,"SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()>=17;
    }
    public static function normalize(mixed $refs):array
    {
        if(!is_array($refs)||!array_is_list($refs)||count($refs)>self::MAX_FILES)throw new DomainException('Bis zu zehn PDF-Anlagen auswählen.');
        $out=[];$seen=[];
        foreach($refs as$ref){
            if(!is_array($ref)||array_diff(array_keys($ref),['id','name','bytes','sha256','rights']))throw new DomainException('PDF-Anlage prüfen.');
            $id=Content::id($ref['id']??null);if(isset($seen[$id]))throw new DomainException('PDF-Anlage nur einmal auswählen.');$seen[$id]=true;
            $out[]=$ref;
        }
        return$out;
    }
    public static function rights(array $input):array
    {
        $out=['confirmed'=>($input['confirmed']??false)===true];
        foreach(['credit','source_name','license_name']as$key)$out[$key]=Content::text($input[$key]??'',500);
        return$out;
    }
    public static function guardRights(PDO $db,string $club,array $rights):void
    {
        $policy=MediaPolicy::current($db,$club);
        if($policy['check_rights']&&!($rights['confirmed']??false))throw new DomainException('Nutzungsrechte der PDF-Anlagen bestätigen.');
        if($policy['require_details'])foreach(['credit','source_name','license_name']as$key)if(trim($rights[$key]??'')==='')throw new DomainException('Credit, Quelle und Lizenz der PDF-Anlagen ergänzen.');
    }
    public static function fileProofs(array $files):array
    {
        if(!array_is_list($files)||count($files)>self::MAX_FILES)throw new DomainException('Bis zu zehn PDF-Dateien auswählen.');
        $proofs=[];$total=0;
        foreach($files as$file){
            $path=$file['path']??null;$name=$file['name']??null;
            if(!is_string($path)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path)||!is_string($name)||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('PDF-Datei prüfen.');
            $name=basename(str_replace('\\','/',$name));$bytes=filesize($path);$total+=(int)$bytes;
            if($name===''||mb_strlen($name)>240||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf'||$bytes===false||$bytes<8||$bytes>WebsiteDocuments::MAX_BYTES||$total>self::MAX_BYTES)throw new DomainException('PDF bis 10 MiB, Anlagen zusammen bis 20 MB auswählen.');
            if((new \finfo(FILEINFO_MIME_TYPE))->file($path)!=='application/pdf')throw new DomainException('Nur echte PDF-Dateien auswählen.');
            $sha=hash_file('sha256',$path);if(!is_string($sha))throw new DomainException('PDF-Datei prüfen.');
            $proofs[]=['name'=>$name,'bytes'=>$bytes,'sha256'=>$sha];
        }
        return$proofs;
    }
    private function store():WebsiteDocuments{return new WebsiteDocuments($this->db,new DocumentAttachmentAccess($this->db,$this->access),dirname($this->mediaRoot).'/communication-documents');}
    public function uploadMany(array $files,array $rights,UploadTransaction $transaction):array
    {
        $actor=$this->access->require('newsletter','edit');if(!self::installed($this->db))throw new DomainException('Newsletteranlagen über den Installer aktivieren.');
        self::fileProofs($files);$rights=self::rights($rights);self::guardRights($this->db,$actor['club_id'],$rights);
        return$this->store()->uploadMany($files,function(array$copies)use($actor,$rights):array{
            $refs=[];foreach($copies as$file){Db::run($this->db,"INSERT INTO native_communication_documents(club_id,module,document_id,name,bytes,sha256,rights_json,created_by,created_at) VALUES(?,'newsletter',?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$actor['club_id'],$file['id'],$file['name'],$file['bytes'],$file['sha256'],json_encode($rights,JSON_THROW_ON_ERROR),$actor['id']]);$refs[]=['id'=>$file['id']];}
            return$this->capture($refs,'edit');
        },$transaction);
    }
    public function capture(array $refs,string $capability='read',bool $verifyFiles=true):array
    {
        $actor=$this->access->require('newsletter',$capability);$refs=self::normalize($refs);if(!$refs)return[];
        if(!self::installed($this->db))throw new DomainException('Newsletteranlagen über den Installer aktivieren.');
        $out=[];$total=0;
        foreach($refs as$ref){
            $row=Db::run($this->db,"SELECT * FROM native_communication_documents WHERE club_id=? AND module='newsletter' AND document_id=?",[$actor['club_id'],$ref['id']])->fetch();if(!$row)throw new DomainException('PDF-Anlage gehört nicht zu diesem Newsletterbereich.');
            $snapshot=['id'=>$row['document_id'],'name'=>$row['name'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'rights'=>json_decode($row['rights_json'],true,32,JSON_THROW_ON_ERROR)];
            foreach(['name','bytes','sha256','rights']as$key)if(array_key_exists($key,$ref)&&$ref[$key]!==$snapshot[$key])throw new DomainException('Ursprüngliche PDF-Anlage geändert.');
            if($verifyFiles){$file=$this->store()->file($snapshot['id']);if($file['sha256']!==$snapshot['sha256']||$file['bytes']!==$snapshot['bytes']||$file['name']!==$snapshot['name'])throw new DomainException('Ursprüngliche PDF-Anlage geändert.');}
            $total+=$snapshot['bytes'];if($total>self::MAX_BYTES)throw new DomainException('PDF-Anlagen zusammen auf 20 MB begrenzen.');$out[]=$snapshot;
        }
        if($this->access->require('newsletter',$capability)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung geändert.');
        return$out;
    }
    public function assertPublication(array $refs):void
    {
        $actor=$this->access->require('newsletter','publish');foreach($this->capture($refs)as$ref)self::guardRights($this->db,$actor['club_id'],$ref['rights']);
    }
    public function file(array $item,string $id):array
    {
        $actor=$this->access->require('newsletter','read');Content::id($id);
        if(($item['module']??null)!=='newsletter'||($item['club_id']??null)!==$actor['club_id']||!is_int($item['version']??null))throw new DomainException('Ursprüngliche Newsletterfassung prüfen.');
        $original=(new Workspace($this->db,$this->access,'newsletter',$this->mediaRoot))->revision(Content::id($item['id']??null),$item['version']);$refs=$original['document_attachments']??[];
        $selected=array_values(array_filter($refs,static fn($ref)=>($ref['id']??null)===$id));if(count($selected)!==1)throw new DomainException('PDF ist nicht in dieser ursprünglichen Ausgabe enthalten.');
        $this->capture($selected);return$this->store()->file($id);
    }
    public function freeze(array $refs,bool $test=false):array
    {
        if($test){$actor=$this->access->require('newsletter','edit');foreach($this->capture($refs)as$ref)self::guardRights($this->db,$actor['club_id'],$ref['rights']);}else $this->assertPublication($refs);$out=[];
        foreach($this->capture($refs)as$ref){$file=$this->store()->file($ref['id']);$raw=file_get_contents($file['path']);if(!is_string($raw)||strlen($raw)!==$ref['bytes']||!hash_equals($ref['sha256'],hash('sha256',$raw)))throw new DomainException('Ursprüngliche PDF-Anlage geändert.');$out[]=['id'=>$ref['id'],'name'=>mb_strcut(pathinfo($ref['name'],PATHINFO_FILENAME),0,180,'UTF-8').'.pdf','mime'=>'application/pdf','bytes'=>$ref['bytes'],'sha256'=>$ref['sha256'],'base64'=>base64_encode($raw)];}
        return$out;
    }
    public static function open(mixed $files):array
    {
        if(!is_array($files)||!array_is_list($files)||count($files)>self::MAX_FILES)throw new DomainException('Gespeicherte PDF-Anlagen prüfen.');$out=[];$total=0;
        foreach($files as$file){Content::id($file['id']??null);$encoded=$file['base64']??null;$name=$file['name']??null;if(!is_string($encoded)||strlen($encoded)>28000000||!is_string($name)||$name===''||strlen($name)>200||preg_match('~[\x00-\x1f/\\\\]~',$name)||($file['mime']??null)!=='application/pdf'||!is_int($file['bytes']??null)||$file['bytes']<8||$file['bytes']>WebsiteDocuments::MAX_BYTES||!preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256']??''))throw new DomainException('Gespeicherte PDF-Anlagen prüfen.');$raw=base64_decode($encoded,true);$total+=is_string($raw)?strlen($raw):0;if(!is_string($raw)||strlen($raw)!==$file['bytes']||$total>self::MAX_BYTES||!hash_equals($file['sha256'],hash('sha256',$raw))||!str_starts_with($raw,'%PDF-')||!preg_match('/%%EOF\s*$/D',substr($raw,-2048)))throw new DomainException('Ursprüngliche PDF-Anlage beschädigt.');$out[]=['name'=>$name,'mime'=>'application/pdf','bytes'=>$raw];}
        return$out;
    }
}
