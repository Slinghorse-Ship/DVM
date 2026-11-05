<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use DomainException;
use Kulturhus\Native\Shared\OperationGate;

/** Private immutable source intention plus an OS-held exclusive copy lease. */
final class MediaRecoveryLease
{
    public static function directory(string $root,string $club):?string
    {
        if(!file_exists($root)&&!is_link($root))return null;
        $base=realpath($root);if($base===false||is_link($root)||!is_dir($base))throw new DomainException('Privaten Medienspeicher prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot)if($webroot!==''&&($public=realpath($webroot))&&($base===$public||str_starts_with($base,$public.DIRECTORY_SEPARATOR)))throw new DomainException('Wiederherstellung benötigt privaten Medienspeicher.');
        $path=$base;
        foreach(['.recovery',hash('sha256',$club)]as$part){$path.=DIRECTORY_SEPARATOR.$part;if(!file_exists($path)&&!is_link($path))return null;if(is_link($path)||!is_dir($path)||realpath($path)!==$path||(fileperms($path)&0077))throw new DomainException('Privaten Wiederherstellungsvorgang prüfen.');}
        return $path;
    }
    public static function intention(string $root,string $club,string $target):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$target)||!($directory=self::directory($root,$club)))throw new DomainException('Ursprünglichen Wiederherstellungsvorgang prüfen.');
        $path=$directory.DIRECTORY_SEPARATOR.$target;
        if(is_link($path)||!is_dir($path)||realpath($path)!==$path||(fileperms($path)&0077))throw new DomainException('Privaten Wiederherstellungsvorgang prüfen.');
        $data=self::proof($path.'/intent.json');
        if(!is_string($data['source_id']??null)||!is_string($data['fingerprint']??null))throw new DomainException('Ursprünglichen Quellenbeweis prüfen.');
        $expected=['format'=>1,'club_id'=>$club,'source_id'=>$data['source_id'],'fingerprint'=>$data['fingerprint'],'target_id'=>$target];
        self::bound($data,$expected);
        self::identity(realpath($root),$club,$data['source_id'],$data['fingerprint'],$target,realpath($root).DIRECTORY_SEPARATOR.hash('sha256',$club).DIRECTORY_SEPARATOR.$target);
        return $data;
    }
    /** A prepared target cannot become the source of a second independent recovery. */
    public static function reserved(string $root,string $club,string $id):bool
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Ursprüngliche Dateigruppe prüfen.');
        $base=realpath($root);if($base===false||is_link($root))throw new DomainException('Privaten Medienspeicher prüfen.');
        $path=$base;
        foreach(['.recovery',hash('sha256',$club)]as$part){$path.=DIRECTORY_SEPARATOR.$part;if(!file_exists($path)&&!is_link($path))return false;if(is_link($path)||!is_dir($path)||realpath($path)!==$path||(fileperms($path)&0077))throw new DomainException('Privaten Wiederherstellungsvorgang prüfen.');}
        $target=$path.DIRECTORY_SEPARATOR.$id;return file_exists($target)||is_link($target);
    }
    public static function state(string $root,string $club,string $source,string $fingerprint,string $target,string $targetDirectory):string
    {
        $base=realpath($root);if($base===false||is_link($root))throw new DomainException('Privaten Medienspeicher prüfen.');
        self::identity($base,$club,$source,$fingerprint,$target,$targetDirectory);
        $path=$base;
        foreach(['.recovery',hash('sha256',$club),$target]as$part){$path.=DIRECTORY_SEPARATOR.$part;if(!file_exists($path)&&!is_link($path))return '';if(is_link($path)||!is_dir($path)||realpath($path)!==$path||(fileperms($path)&0077))throw new DomainException('Privaten Wiederherstellungsvorgang prüfen.');}
        $file=$path.'/intent.json';if(!file_exists($file)&&!is_link($file))return '';
        self::verify($file,['format'=>1,'club_id'=>$club,'source_id'=>$source,'fingerprint'=>$fingerprint,'target_id'=>$target]);
        if((file_exists($targetDirectory)||is_link($targetDirectory))&&(is_link($targetDirectory)||!is_dir($targetDirectory)||realpath($targetDirectory)!==$targetDirectory||(fileperms($targetDirectory)&0077)))throw new DomainException('Vorbereitete Bildkopien prüfen.');
        $lock=$path.'/operations.lock';
        if(!file_exists($lock)&&!is_link($lock))return 'pending';
        if(is_link($lock)||!is_file($lock)||realpath($lock)!==$lock||(fileperms($lock)&0077))throw new DomainException('Private Wiederherstellungssperre prüfen.');
        $handle=@fopen($lock,'rb');if(!is_resource($handle))throw new DomainException('Private Wiederherstellungssperre prüfen.');
        try{if(!flock($handle,LOCK_SH|LOCK_NB))return 'running';flock($handle,LOCK_UN);return 'pending';}finally{fclose($handle);}
    }
    private function __construct(private OperationGate $gate){}
    public static function acquire(string $root,string $club,string $source,string $fingerprint,string $target,string $targetDirectory):self
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$source)||!preg_match('/\A[a-f0-9]{32}\z/D',$target)||!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new DomainException('Wiederherstellung erneut öffnen.');
        $base=realpath($root);if($base===false||is_link($root)||!is_dir($base))throw new DomainException('Privaten Medienspeicher prüfen.');
        self::identity($base,$club,$source,$fingerprint,$target,$targetDirectory);
        $path=$base;
        foreach(['.recovery',hash('sha256',$club),$target]as$part){$path.=DIRECTORY_SEPARATOR.$part;if(is_link($path)||(!is_dir($path)&&!@mkdir($path,0700)&&!is_dir($path))||realpath($path)!==$path||(fileperms($path)&0077))throw new DomainException('Privaten Wiederherstellungsvorgang prüfen.');}
        $gate=OperationGate::exclusive($path);$file=$path.'/intent.json';$expected=['format'=>1,'club_id'=>$club,'source_id'=>$source,'fingerprint'=>$fingerprint,'target_id'=>$target];
        try{
            if(is_link($file))throw new DomainException('Ursprünglichen Wiederherstellungsbeweis prüfen.');
            if(is_file($file)){
                self::verify($file,$expected);
            }else{
                if(file_exists($targetDirectory)||is_link($targetDirectory))throw new DomainException('Bereits vorhandene Dateien werden nicht als eigene Kopien übernommen.');
                $temporary=$path.'/intent-'.bin2hex(random_bytes(16)).'.tmp';
                $old=umask(0077);try{$handle=@fopen($temporary,'xb');}finally{umask($old);}
                if(!is_resource($handle))throw new DomainException('Wiederherstellung konnte nicht vorbereitet werden.');
                try{
                    $bytes=json_encode($expected,JSON_THROW_ON_ERROR)."\n";
                    try{if(fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new DomainException('Wiederherstellungsbeweis konnte nicht bestätigt werden.');}finally{fclose($handle);}
                    if(!@chmod($temporary,0600)||!@link($temporary,$file))throw new DomainException('Privaten Wiederherstellungsbeweis prüfen.');
                }finally{if(is_file($temporary)&&!is_link($temporary))@unlink($temporary);}
            }
            return new self($gate);
        }catch(\Throwable $error){$gate->close();throw $error;}
    }
    private static function identity(string $base,string $club,string $source,string $fingerprint,string $target,string $directory):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$source)||!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)||$target!==substr(hash('sha256','native-media-recovery:'.$club.':'.$source.':'.$fingerprint),0,32)||$directory!==$base.DIRECTORY_SEPARATOR.hash('sha256',$club).DIRECTORY_SEPARATOR.$target)throw new DomainException('Ursprüngliche Wiederherstellungskennung prüfen.');
    }
    private static function verify(string $file,array $expected):void
    {
        self::bound(self::proof($file),$expected);
    }
    private static function proof(string $file):array
    {
        $size=@filesize($file);
        if(is_link($file)||!is_file($file)||realpath($file)!==$file||!is_int($size)||$size<1||$size>4096||(fileperms($file)&0077))throw new DomainException('Privaten Wiederherstellungsbeweis prüfen.');
        try{$data=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException $error){throw new DomainException('Ursprünglichen Wiederherstellungsbeweis prüfen.',0,$error);}
        if(!is_array($data))throw new DomainException('Ursprünglichen Wiederherstellungsvorgang prüfen.');
        return $data;
    }
    private static function bound(array $data,array $expected):void
    {
        if(count($data)!==count($expected))throw new DomainException('Ursprünglichen Wiederherstellungsvorgang prüfen.');
        foreach($expected as$key=>$value)if(($data[$key]??null)!==$value)throw new DomainException('Wiederherstellung ist nicht an diese ursprüngliche Quelle gebunden.');
    }
    public function close():void{$this->gate->close();}
    public function __destruct(){$this->close();}
}
