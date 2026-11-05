<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use Kulturhus\Native\Cms\CameraPhotos;

/** A bounded document derivative. Camera source bytes are never loaded wholesale into PHP. */
final class CameraDocumentUploads
{
    public const INPUT_BYTES=CameraPhotos::INPUT_BYTES;
    public const BATCH_INPUT_BYTES=CameraPhotos::BATCH_INPUT_BYTES;
    public const DOCUMENT_BYTES=10485760;
    public const BATCH_DOCUMENT_BYTES=20971520;
    private function __construct(public readonly string $name,public readonly string $bytes,public readonly ?array $source=null){}
    public static function camera(string $path,string $name):bool
    {return CameraPhotos::sourceKind($path,$name)!==null;}
    public static function read(string $path,string $name,int $maximum=self::DOCUMENT_BYTES):self
    {
        self::name($name);
        if($maximum<1||$maximum>self::DOCUMENT_BYTES)throw new DomainException('Erlaubte Größe der vorbereiteten Datei prüfen.');
        $kind=CameraPhotos::sourceKind($path,$name);$size=filesize($path);
        if($size===false||$size<1||$size>($kind===null?$maximum:self::INPUT_BYTES))throw new DomainException($kind===null?'Datei überschreitet die erlaubte Größe oder ist leer.':'Kamerafoto bis 256 MiB auswählen.');
        if($kind===null){$bytes=LiveUploads::bytes($path,$maximum);return new self(self::rasterName($name,$bytes),$bytes);}
        $before=stat($path);$hash=hash_file('sha256',$path);
        if(!$before||!is_string($hash))throw new DomainException('Kameraoriginal konnte nicht geprüft werden.');
        try{return CameraPhotos::withFile($path,$name,function(string $prepared)use($path,$name,$maximum,$kind,$size,$before,$hash):self{
            $bytes=LiveUploads::bytes($prepared,$maximum);clearstatcache(true,$path);$after=@stat($path);
            if(!$after||is_link($path)||!is_file($path))throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');
            foreach(['dev','ino','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');
            if(!hash_equals($hash,(string)hash_file('sha256',$path)))throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');
            return new self(self::jpegName($name),$bytes,['version'=>1,'kind'=>$kind,'name'=>$name,'bytes'=>$size,'sha256'=>$hash,'prepared_sha256'=>hash('sha256',$bytes)]);
        });}catch(\InvalidArgumentException|\RuntimeException $error){throw new DomainException($error->getMessage(),0,$error);}
    }
    /** Programmatic byte uploads retain their old raster/document bounds and use the same camera decoder. */
    public static function fromBytes(string $name,string $bytes,int $maximum=self::DOCUMENT_BYTES):self
    {
        self::name($name);if($maximum<1||$maximum>self::DOCUMENT_BYTES)throw new DomainException('Erlaubte Größe der vorbereiteten Datei prüfen.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes,0,4096));
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){
            if($bytes===''||strlen($bytes)>$maximum)throw new DomainException('Datei überschreitet die erlaubte Größe oder ist leer.');
            return new self(self::rasterName($name,$bytes),$bytes);
        }
        if(!in_array($mime,['image/heic','image/heif'],true)&&!in_array($extension,CameraPhotos::RAW_EXTENSIONS,true)&&!in_array(substr($bytes,0,4),["II\x2a\0","MM\0\x2a"],true)){
            if($bytes===''||strlen($bytes)>$maximum)throw new DomainException('Datei überschreitet die erlaubte Größe oder ist leer.');return new self($name,$bytes);
        }
        if(strlen($bytes)<8||strlen($bytes)>self::INPUT_BYTES)throw new DomainException('Kamerafoto bis 256 MiB auswählen.');
        $path=tempnam(sys_get_temp_dir(),'dvm-document-camera-');if($path===false)throw new DomainException('Kamerafoto konnte nicht vorbereitet werden.');
        try{chmod($path,0600);if(file_put_contents($path,$bytes)!==strlen($bytes))throw new DomainException('Kamerafoto konnte nicht vorbereitet werden.');return self::read($path,$name,$maximum);}finally{if(!is_link($path)&&is_file($path))@unlink($path);}
    }
    /** Only an authenticated LiveUploads session supplies this retained provenance, never POST fields. */
    public static function staged(string $path,string $name,?array $source,int $maximum=self::DOCUMENT_BYTES):self
    {
        $file=self::read($path,$name,$maximum);if($source===null)return$file;
        if(array_keys($source)!==['version','kind','name','bytes','sha256','prepared_sha256']||$source['version']!==1||!in_array($source['kind'],['heif','raw'],true)||!is_string($source['name'])||!is_int($source['bytes'])||$source['bytes']<8||$source['bytes']>self::INPUT_BYTES||!is_string($source['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$source['sha256'])||!is_string($source['prepared_sha256'])||!hash_equals($source['prepared_sha256'],hash('sha256',$file->bytes)))throw new DomainException('Gesicherte Kameraherkunft bitte erneut prüfen.');
        self::name($source['name']);return new self($file->name,$file->bytes,$source);
    }
    private static function name(string $name):void
    {if($name===''||strlen($name)>240||preg_match('/[\\\\\/:\x00-\x1f]/',$name)||in_array($name,['.','..'],true))throw new DomainException('Ungültiger Dateiname.');}
    private static function jpegName(string $name):string
    {$stem=pathinfo($name,PATHINFO_FILENAME);while(strlen($stem)>236)$stem=(string)preg_replace('/.$/us','',$stem);return ($stem===''?'Kamerafoto':$stem).'.jpg';}
    private static function rasterName(string $name,string $bytes):string
    {
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($extension,array_merge(CameraPhotos::RAW_EXTENSIONS,['heic','heif','hif']),true))return$name;
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes,0,4096));$suffix=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>null};
        return $suffix===null?$name:pathinfo($name,PATHINFO_FILENAME).'.'.$suffix;
    }
}
