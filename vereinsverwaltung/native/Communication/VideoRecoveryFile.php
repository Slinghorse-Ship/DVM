<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;

/** Exact private copies; partial own copies resume only after a byte-for-byte prefix check. */
final class VideoRecoveryFile
{
    private string $root;
    public function __construct(string $mediaRoot){$this->root=dirname($mediaRoot).'/communication-videos';}

    private function path(string $club,string $id):string
    {
        Content::id($id);$owner=$this->root.'/'.hash('sha256',$club);
        foreach([$this->root,$owner]as$directory){
            if(is_link($directory)||!is_dir($directory)||realpath($directory)!==$directory||(fileperms($directory)&0077))throw new DomainException('Privaten Videospeicher prüfen.');
        }
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot)if($webroot!==''&&($public=realpath($webroot))&&($this->root===$public||str_starts_with($this->root,$public.'/')))throw new DomainException('Wiederherstellung benötigt einen privaten Videospeicher.');
        return $owner.'/'.$id.'.mp4';
    }

    private function stat(string $path):array
    {
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!$stat||($stat['mode']&0170000)!==0100000||$stat['nlink']!==1||($stat['mode']&0077)||!is_readable($path)||realpath($path)!==$path)throw new DomainException('Unveränderte private Videodatei prüfen.');
        return $stat;
    }

    public function inspect(string $club,string $id,bool $grace=true):array
    {
        $path=$this->path($club,$id);$before=$this->stat($path);
        if($grace&&$before['mtime']>time()-VideoStorageInventory::GRACE_SECONDS)throw new DomainException('Laufenden Upload unverändert lassen. Später erneut prüfen.');
        if($before['size']<32||$before['size']>ReelFile::MAX_BYTES)throw new DomainException('Vollständige MP4-Datei bis 100 MiB auswählen.');
        $sha=@hash_file('sha256',$path);$profile=ReelFile::inspect($path);$after=$this->stat($path);
        foreach(['dev','ino','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])throw new DomainException('Videodatei inzwischen geändert. Erneut prüfen.');
        if(!is_string($sha)||@hash_file('sha256',$path)!==$sha||$profile['bytes']!==$before['size'])throw new DomainException('Ursprüngliche Videobytes erneut prüfen.');
        return ['bytes'=>$before['size'],'sha256'=>$sha,'profile'=>$profile];
    }

    private function matches(array $actual,array $plan):void
    {
        if($actual['bytes']!==$plan['bytes']||$actual['sha256']!==$plan['sha256']||$actual['profile']!=$plan['profile'])throw new DomainException('Videodatei stimmt nicht mit dem ursprünglichen Vorgang überein.');
    }

    public function verify(array $plan):array
    {
        $this->matches($this->inspect($plan['club_id'],$plan['target_id'],false),$plan);
        return ['bytes'=>$plan['bytes'],'sha256'=>$plan['sha256'],'profile'=>$plan['profile']];
    }

    /** Caller holds the original-bound database advisory lock and has a durable intention. */
    public function copy(array $plan):void
    {
        if($plan['target_id']===$plan['original_id'])throw new DomainException('Neue Videokennung erforderlich.');
        $this->matches($this->inspect($plan['club_id'],$plan['original_id'],false),$plan);
        $from=$this->path($plan['club_id'],$plan['original_id']);$to=$this->path($plan['club_id'],$plan['target_id']);
        $present=file_exists($to)||is_link($to);$offset=0;
        if($present){$stat=$this->stat($to);$offset=$stat['size'];if($offset>$plan['bytes'])throw new DomainException('Abweichende vorbereitete Kopie bleibt unverändert.');}
        $input=@fopen($from,'rb');$mask=umask(0077);try{$output=@fopen($to,$present?'r+b':'x+b');}finally{umask($mask);}
        if(!is_resource($input)||!is_resource($output)){if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);throw new DomainException('Private Videokopie nicht verfügbar.');}
        try{
            $opened=fstat($output);$current=$this->stat($to);
            if(!$opened||$opened['dev']!==$current['dev']||$opened['ino']!==$current['ino']||$current['size']!==$offset)throw new DomainException('Vorbereitete Videokopie inzwischen geändert.');
            $remaining=$offset;
            while($remaining>0){$length=min(65536,$remaining);$left=fread($input,$length);$right=fread($output,$length);if(!is_string($left)||strlen($left)!==$length||$left!==$right)throw new DomainException('Abweichende vorbereitete Kopie bleibt unverändert.');$remaining-=$length;}
            if(stream_copy_to_stream($input,$output,$plan['bytes']-$offset+1)!==$plan['bytes']-$offset||!fflush($output)||(function_exists('fsync')&&!fsync($output)))throw new DomainException('Videokopie konnte nicht vollständig bestätigt werden.');
        }finally{fclose($input);fclose($output);}
        $this->verify($plan);$this->matches($this->inspect($plan['club_id'],$plan['original_id'],false),$plan);
    }
}
