<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Read-only, current observations; an unknown upload commit never authorises adoption or deletion. */
final class VideoStorageInventory
{
    public const GRACE_SECONDS=3600;
    private const MAX_ENTRIES=50000;
    private string $root;
    public function __construct(private PDO $db,private Access $access,string $mediaRoot)
    { $this->root=dirname($mediaRoot).'/communication-videos'; }

    private static function id(string $id):void
    { if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Videodatei erneut auswählen.'); }

    private function begin():string
    {
        if($this->db->inTransaction())throw new DomainException('Videospeicherprüfung benötigt einen eigenen aktuellen Lesevorgang.');
        $club=$this->access->require('social','manage')['club_id'];
        if((int)Db::run($this->db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_social_video_sources','native_social_videos')")->fetchColumn()!==2)throw new DomainException('Videomodul über den Installer aktualisieren.');
        return $club;
    }

    private function owner(string $club):?string
    {
        if(!file_exists($this->root)&&!is_link($this->root))return null;
        if(is_link($this->root)||!is_dir($this->root)||realpath($this->root)!==$this->root)throw new DomainException('Privaten Videospeicher prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot){
            if($webroot!==''&&($public=realpath($webroot))&&($this->root===$public||str_starts_with($this->root,$public.DIRECTORY_SEPARATOR)))throw new DomainException('Videospeicherprüfung benötigt einen privaten Speicher.');
        }
        $owner=$this->root.'/'.hash('sha256',$club);
        if(!file_exists($owner)&&!is_link($owner))return null;
        if(is_link($owner)||!is_dir($owner)||realpath($owner)!==$owner)throw new DomainException('Unverändert zugeordneten Videospeicher prüfen.');
        return $owner;
    }

    private function known(array $ids):array
    {
        if(!$ids)return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        // Global immutable IDs also protect unexpected cross-club/dangling references.
        $sql='SELECT id FROM native_social_video_sources WHERE id IN ('.$marks.') UNION SELECT source_id FROM native_social_videos WHERE source_id IN ('.$marks.')';$args=[...$ids,...$ids];
        if(VideoRecovery::installed($this->db)){$sql.=' UNION SELECT original_id FROM native_video_recoveries WHERE original_id IN ('.$marks.') UNION SELECT target_id FROM native_video_recoveries WHERE target_id IN ('.$marks.')';array_push($args,...$ids,...$ids);}
        return Db::run($this->db,$sql,$args)->fetchAll(PDO::FETCH_COLUMN);
    }

    private function stat(string $path):?array
    {
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!$stat||($stat['mode']&0170000)!==0100000||$stat['nlink']!==1||!is_readable($path)||realpath($path)!==$path)return null;
        return $stat;
    }

    public function listing(string $after=''):array
    {
        if($after!=='')self::id($after);
        $club=$this->begin();$owner=$this->owner($club);
        $result=['rows'=>[],'after'=>$after,'next_after'=>'','has_more'=>false,'complete'=>true,'scanned_entries'=>0,'unverified_entries'=>0];
        if($owner===null)return $this->finish($club,$result);
        $candidates=[];$batch=[];$cutoff=time()-self::GRACE_SECONDS;
        $flush=function()use(&$batch,&$candidates):void{
            if(!$batch)return;
            foreach(array_diff(array_keys($batch),$this->known(array_keys($batch)))as$id){$candidates[$id]=$batch[$id];ksort($candidates,SORT_STRING);if(count($candidates)>7)array_pop($candidates);}
            $batch=[];
        };
        try{
            foreach(new \FilesystemIterator($owner,\FilesystemIterator::SKIP_DOTS)as$entry){
                if(++$result['scanned_entries']>self::MAX_ENTRIES){$result['complete']=false;break;}
                $name=$entry->getFilename();
                if($name==='.removed'&&!$entry->isLink()&&$entry->isDir()&&realpath($entry->getPathname())===$entry->getPathname())continue;
                if(!preg_match('/\A([a-f0-9]{32})\.mp4\z/D',$name,$match)){$result['unverified_entries']++;continue;}
                $id=$match[1];if($after!==''&&strcmp($id,$after)<=0)continue;
                $stat=$this->stat($entry->getPathname());
                if($stat===null){$result['unverified_entries']++;continue;}
                if($stat['mtime']>$cutoff)continue;
                $batch[$id]=$entry->getPathname();if(count($batch)===64)$flush();
            }
        }catch(\UnexpectedValueException){$result['complete']=false;}
        $flush();
        foreach($this->known(array_keys($candidates))as$id)unset($candidates[$id]);
        foreach($candidates as$id=>$path){
            $stat=$this->stat($path);if($stat===null){$result['complete']=false;continue;}
            if($stat['mtime']>$cutoff)continue;
            $result['rows'][]=['id'=>$id,'bytes'=>$stat['size'],'changed_at'=>gmdate('Y-m-d H:i:s',$stat['mtime']),'inspectable'=>$stat['size']>=32&&$stat['size']<=ReelFile::MAX_BYTES];
        }
        $result['has_more']=count($result['rows'])>6;$result['rows']=array_slice($result['rows'],0,6);
        if($result['has_more'])$result['next_after']=$result['rows'][5]['id'];
        elseif(count($candidates)===7){$result['complete']=false;$result['has_more']=true;$result['next_after']=array_key_last($candidates);}
        if($result['unverified_entries'])$result['complete']=false;
        if($this->owner($club)!==$owner)throw new DomainException('Videospeicher inzwischen geändert. Erneut prüfen.');
        return $this->finish($club,$result);
    }

    public function inspect(string $id):array
    {
        self::id($id);$club=$this->begin();$owner=$this->owner($club);
        if($owner===null||$this->known([$id]))throw new DomainException('Nicht zugeordnete Videodatei nicht verfügbar.');
        $path=$owner.'/'.$id.'.mp4';$before=$this->stat($path);
        if($before===null||$before['mtime']>time()-self::GRACE_SECONDS)throw new DomainException('Datei noch im Speichervorgang oder nicht sicher lesbar.');
        if($before['size']<32||$before['size']>ReelFile::MAX_BYTES)throw new DomainException('Videodatei muss zwischen 32 Bytes und 100 MiB liegen.');
        $hash=@hash_file('sha256',$path);$profile=ReelFile::inspect($path);
        $this->finish($club,[]);
        $after=$this->stat($path);
        if($this->owner($club)!==$owner||$after===null||$this->known([$id])||!is_string($hash)||@hash_file('sha256',$path)!==$hash||$profile['bytes']!==$before['size'])throw new DomainException('Datei oder Zuordnung inzwischen geändert. Erneut prüfen.');
        foreach(['dev','ino','size','mtime','ctime']as$key)if($after[$key]!==$before[$key])throw new DomainException('Videodatei inzwischen geändert. Erneut prüfen.');
        return $this->finish($club,['id'=>$id,'bytes'=>$before['size'],'sha256'=>$hash,'changed_at'=>gmdate('Y-m-d H:i:s',$before['mtime']),'profile'=>$profile]);
    }

    private function finish(string $club,array $result):array
    { if($this->access->require('social','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert. Videospeicher erneut öffnen.');return $result; }
}
