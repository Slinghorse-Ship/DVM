<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Private same-filesystem moves. Original identity and digest come from the durable database intent. */
final class VideoQuarantine
{
    private string $root;
    public function __construct(string $mediaRoot)
    {
        $root=dirname($mediaRoot).'/communication-videos';
        if(is_link($root)||!is_dir($root)||realpath($root)!==$root)throw new \DomainException('Privaten Videospeicher prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$web){$public=$web!==''?realpath($web):false;if($public&&($root===$public||str_starts_with($root,$public.'/')))throw new \DomainException('Video-Papierkorb benötigt privaten Speicher.');}
        $this->root=$root;
    }
    private function paths(array $plan,bool $create):array
    {
        Content::id($plan['source_id']??null);Content::id($plan['operation']??null);
        if(!is_string($plan['club_id']??null)||!is_string($plan['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$plan['sha256'])||!is_int($plan['bytes']??null)||$plan['bytes']<32||$plan['bytes']>ReelFile::MAX_BYTES)throw new \DomainException('Ursprünglichen Videovorgang prüfen.');
        $owner=$this->root.'/'.hash('sha256',$plan['club_id']);
        $trash=$owner.'/.removed/'.$plan['source_id'].'/'.$plan['operation'];
        foreach([$owner,$owner.'/.removed',$owner.'/.removed/'.$plan['source_id'],$trash]as$path){
            if(is_link($path))throw new \DomainException('Privaten Videopfad prüfen.');
            if(!is_dir($path)&&$create&&(!@mkdir($path,0700)&&!is_dir($path)))throw new \DomainException('Video-Papierkorb nicht anlegbar.');
            if(is_dir($path)&&realpath($path)!==$path)throw new \DomainException('Privaten Videopfad prüfen.');
        }
        return [$owner.'/'.$plan['source_id'].'.mp4',$trash.'/original.mp4'];
    }
    private function exact(string $path,array $plan):bool
    {
        clearstatcache(true,$path);
        if(is_link($path))throw new \DomainException('Verknüpfte Videodateien sind gesperrt.');
        if(!file_exists($path))return false;
        if(!is_file($path)||realpath($path)!==$path||filesize($path)!==$plan['bytes']||!hash_equals($plan['sha256'],hash_file('sha256',$path)))throw new \DomainException('Ursprüngliche Videobytes prüfen.');
        return true;
    }
    public function verify(array $plan,bool $removed):void
    {
        [$active,$trash]=$this->paths($plan,false);
        if(!$this->exact($removed?$trash:$active,$plan)||file_exists($removed?$active:$trash)||is_link($removed?$active:$trash))throw new \DomainException('Genau eine ursprüngliche Videodatei erforderlich.');
    }
    public function move(array $plan,bool $restore=false):void
    {
        [$active,$trash]=$this->paths($plan,!$restore);$from=$restore?$trash:$active;$to=$restore?$active:$trash;
        $source=$this->exact($from,$plan);$target=$this->exact($to,$plan);
        if($source&&$target)throw new \DomainException('Doppelten ursprünglichen Videobestand prüfen.');
        if(!$source&&!$target)throw new \DomainException('Ursprüngliche Videodatei fehlt.');
        if($source&&!@rename($from,$to))throw new \DomainException('Videodatei nicht verschiebbar. Vorgang fortsetzen.');
        if(!$this->exact($to,$plan)||file_exists($from)||is_link($from))throw new \DomainException('Verschobene Videobytes prüfen.');
    }
    public function purge(array $plan):void
    {
        [$active,$trash]=$this->paths($plan,false);
        if(file_exists($active)||is_link($active))throw new \DomainException('Aktive Videodatei verhindert endgültiges Löschen.');
        if($this->exact($trash,$plan)&&!@unlink($trash))throw new \DomainException('Endgültiges Videolöschen fortsetzen.');
        clearstatcache(true,$trash);if(file_exists($trash)||is_link($trash))throw new \DomainException('Endgültiges Videolöschen nicht abgeschlossen.');
    }
    public function verifyPurged(array $plan):void
    {
        [$active,$trash]=$this->paths($plan,false);
        foreach([$active,$trash]as$path){clearstatcache(true,$path);if(file_exists($path)||is_link($path))throw new \DomainException('Gelöschten ursprünglichen Videobestand prüfen.');}
    }
}
