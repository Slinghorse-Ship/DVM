<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;

use DomainException;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Communication\Mail\Mime;

/** Original MIME files are private files; MySQL stores only authenticated references. */
final class MailboxOriginalStorage
{
    private const POINTER="DVMMAILFILE1:";
    private const FILE="DVMEML1\0";
    private const CHUNK=65536;
    public static function external(string $cipher):bool{return str_starts_with($cipher,self::POINTER);}
    private static function path(string $club,string $id,bool $create=false):string
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$club)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Mailablage nicht verfügbar.');
        $root=getenv('VEREIN_PRIVATE_ROOT');$root=is_string($root)&&$root!==''?realpath($root):false;
        if(!$root||!is_dir($root))throw new DomainException('Geschützte Mailablage ist nicht eingerichtet.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot){$web=$webroot!==''?realpath($webroot):false;if($web!==false&&($root===$web||str_starts_with($root,$web.DIRECTORY_SEPARATOR)))throw new DomainException('Geschützte Mailablage außerhalb des Webroots einrichten.');}
        foreach(['/mailbox-originals','/'.$club]as$part){$root.=$part;if($create&&!is_dir($root)&&!mkdir($root,0700))throw new DomainException('Mailablage konnte nicht geöffnet werden.');if(is_link($root)||!is_dir($root)||(fileperms($root)&0077)!==0)throw new DomainException('Geschützte Mailablage prüfen.');}
        return $root.'/'.$id.'.sealed';
    }
    private static function write($file,string $bytes):void
    {
        for($offset=0;$offset<strlen($bytes);$offset+=$written){$written=fwrite($file,substr($bytes,$offset));if(!$written)throw new DomainException('Mail konnte nicht vollständig gespeichert werden.');}
    }
    private static function exact($file,int $length):string
    {
        $bytes='';while(strlen($bytes)<$length&&!feof($file)){$part=fread($file,$length-strlen($bytes));if($part===false)throw new DomainException('Originalmail ist nicht lesbar.');$bytes.=$part;}
        if(strlen($bytes)!==$length)throw new DomainException('Originalmail ist nicht vollständig.');return $bytes;
    }
    public static function seal(string $raw,#[\SensitiveParameter]string $key,string $club,string $id,string $hash):string
    {
        if($raw===''||strlen($raw)>Mime::MAX_ORIGINAL_BYTES||!hash_equals($hash,hash('sha256',$raw)))throw new DomainException('Originalnachricht nicht authentifiziert.');
        $path=self::path($club,$id,true);$temp=dirname($path).'/.pending-'.bin2hex(random_bytes(16));$file=fopen($temp,'x+b');if(!$file)throw new DomainException('Mail konnte nicht gespeichert werden.');
        try{
            if(!chmod($temp,0600))throw new DomainException('Geschützte Mailablage prüfen.');
            $fileKey=hash_hkdf('sha256',$key,32,'mail-original-file:'.$club.':'.$id);[$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);self::write($file,self::FILE.$header);
            for($offset=0,$length=strlen($raw);$offset<$length;$offset+=self::CHUNK){$part=substr($raw,$offset,self::CHUNK);$final=$offset+strlen($part)===$length;$sealed=sodium_crypto_secretstream_xchacha20poly1305_push($state,$part,$club.':'.$id.':'.$hash,$final?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);self::write($file,pack('N',strlen($sealed)).$sealed);}
            if(!fflush($file)||!fsync($file))throw new DomainException('Mail konnte nicht dauerhaft gespeichert werden.');fclose($file);$file=null;
            $pointer=self::POINTER.(new SecretBox($key))->seal('general-mail-original-file:'.$club.':'.$id,['bytes'=>$length,'original_hash'=>$hash,'file_sha256'=>hash_file('sha256',$temp)]);
            if(!link($temp,$path))throw new DomainException('Ursprüngliche Mailablage ist bereits vorhanden.');return $pointer;
        }finally{if(is_resource($file))fclose($file);if(is_file($temp))unlink($temp);}
    }
    public static function open(string $cipher,#[\SensitiveParameter]string $key,string $club,string $id,string $hash):string
    {
        if(!self::external($cipher)){$raw=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($cipher,24),$club.':'.$id,substr($cipher,0,24),$key);if(!is_string($raw)||!hash_equals($hash,hash('sha256',$raw)))throw new DomainException('Originalnachricht nicht authentifiziert.');return $raw;}
        $pointer=(new SecretBox($key))->open('general-mail-original-file:'.$club.':'.$id,substr($cipher,strlen(self::POINTER)));$path=self::path($club,$id);
        if(!is_int($pointer['bytes']??null)||$pointer['bytes']<1||$pointer['bytes']>Mime::MAX_ORIGINAL_BYTES||($pointer['original_hash']??null)!==$hash||is_link($path)||!is_file($path)||!hash_equals($pointer['file_sha256']??'',hash_file('sha256',$path)))throw new DomainException('Originalnachricht nicht authentifiziert.');
        $file=fopen($path,'rb');if(!$file)throw new DomainException('Originalmail ist nicht lesbar.');
        try{
            if(self::exact($file,strlen(self::FILE))!==self::FILE)throw new DomainException('Originalnachricht nicht authentifiziert.');$fileKey=hash_hkdf('sha256',$key,32,'mail-original-file:'.$club.':'.$id);$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::exact($file,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),$fileKey);$raw='';$final=false;
            while(!$final){$size=unpack('N',self::exact($file,4))[1];if($size<17||$size>self::CHUNK+17)throw new DomainException('Originalnachricht nicht authentifiziert.');$part=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::exact($file,$size),$club.':'.$id.':'.$hash);if($part===false||strlen($raw)+strlen($part[0])>$pointer['bytes'])throw new DomainException('Originalnachricht nicht authentifiziert.');$raw.=$part[0];$final=$part[1]===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;}
            if(fread($file,1)!==''||strlen($raw)!==$pointer['bytes']||!hash_equals($hash,hash('sha256',$raw)))throw new DomainException('Originalnachricht nicht authentifiziert.');return $raw;
        }finally{fclose($file);}
    }
    public static function discard(string $cipher,#[\SensitiveParameter]string $key,string $club,string $id):void
    {
        if(!self::external($cipher))return;$pointer=(new SecretBox($key))->open('general-mail-original-file:'.$club.':'.$id,substr($cipher,strlen(self::POINTER)));$path=self::path($club,$id);
        if(is_file($path)&&!is_link($path)&&hash_equals($pointer['file_sha256']??'',hash_file('sha256',$path)))unlink($path);
    }
}
