<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Read-only observation of aged own upload folders; never adopts, moves or deletes originals. */
final class MediaStorageInventory
{
    public const GRACE_SECONDS=3600;
    private const MAX_ENTRIES=50000;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}

    public function listing(string $after=''): array
    {
        if($after!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$after))throw new InvalidArgumentException('Speicherseite neu öffnen.');
        if($this->db->inTransaction())throw new DomainException('Speicherprüfung benötigt einen eigenen aktuellen Lesevorgang.');
        $club=$this->access->require('website','manage')['club_id'];
        $result=['rows'=>[],'after'=>$after,'next_after'=>'','has_more'=>false,'complete'=>true,'unverified_entries'=>0,'scanned_entries'=>0];
        $owner=$this->owner($club);if($owner===null)return $this->finish($club,$result);
        $candidates=[];$batch=[];$cutoff=time()-self::GRACE_SECONDS;
        $flush=function()use(&$batch,&$candidates):void{
            if(!$batch)return;$ids=array_keys($batch);
            // Autocommit reads see current identities without taking upload-blocking gap locks.
            $known=Db::run($this->db,'SELECT id FROM khcms_media WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll(PDO::FETCH_COLUMN);
            foreach(array_diff($ids,$known)as$id){$candidates[$id]=$batch[$id];ksort($candidates,SORT_STRING);if(count($candidates)>13)array_pop($candidates);}
            $batch=[];
        };
        foreach(new \FilesystemIterator($owner,\FilesystemIterator::SKIP_DOTS)as$entry){
            if(++$result['scanned_entries']>self::MAX_ENTRIES){$result['complete']=false;break;}
            $id=$entry->getFilename();
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||$entry->isLink()||!$entry->isDir()){$result['unverified_entries']++;continue;}
            if($after!==''&&strcmp($id,$after)<=0)continue;
            if(MediaRecoveryLease::reserved($this->mediaRoot,$club,$id))continue;
            $changed=@filemtime($entry->getPathname());if(!is_int($changed)){$result['unverified_entries']++;continue;}
            if($changed>$cutoff)continue;
            $batch[$id]=$entry->getPathname();if(count($batch)===64)$flush();
        }
        $flush();
        if($candidates){
            $ids=array_keys($candidates);$known=Db::run($this->db,'SELECT id FROM khcms_media WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll(PDO::FETCH_COLUMN);
            foreach($known as$id)unset($candidates[$id]);
            foreach($candidates as$id=>$directory){$row=$this->inventory($id,$directory);if($row['recent'])continue;unset($row['recent']);$result['rows'][]=$row;if(!$row['readable'])$result['complete']=false;if(count($result['rows'])===7)break;}
            $result['has_more']=count($result['rows'])>6;$result['rows']=array_slice($result['rows'],0,6);
            if($result['has_more'])$result['next_after']=$result['rows'][array_key_last($result['rows'])]['id'];
            elseif(count($candidates)===13){$result['complete']=false;$result['has_more']=true;$result['next_after']=array_key_last($candidates);}
        }
        if($result['unverified_entries']>0)$result['complete']=false;
        return $this->finish($club,$result);
    }
    public function thumbnail(string $id): array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Vorschaubild neu öffnen.');
        if($this->db->inTransaction())throw new DomainException('Vorschaubild benötigt einen eigenen aktuellen Lesevorgang.');
        $club=$this->access->require('website','manage')['club_id'];$owner=$this->owner($club);
        if($owner===null||$this->known($id)||MediaRecoveryLease::reserved($this->mediaRoot,$club,$id))throw new DomainException('Vorschaubild nicht verfügbar.');
        $directory=$owner.DIRECTORY_SEPARATOR.$id;$row=$this->inventory($id,$directory);
        if($row['recent']){
            $prepared=(new MediaStorageRecovery($this->db,$this->access,$this->mediaRoot))->prepare($id);
            if($prepared['recovery_state']==='')throw new DomainException('Vorschaubild nicht verfügbar.');
        }elseif(!$row['readable']||!$row['has_thumbnail'])throw new DomainException('Vorschaubild nicht verfügbar.');
        $file=$this->preview($directory);if($file===null)throw new DomainException('Vorschaubild nicht verfügbar.');
        $this->finish($club,[]);if($this->known($id))throw new DomainException('Vorschaubild nicht verfügbar.');return $file;
    }
    private function known(string $id): bool { return Db::run($this->db,'SELECT 1 FROM khcms_media WHERE id=?',[$id])->fetchColumn()!==false; }
    private function owner(string $club): ?string
    {
        if(!file_exists($this->mediaRoot)&&!is_link($this->mediaRoot))return null;
        if(is_link($this->mediaRoot)||!is_dir($this->mediaRoot)||!($root=realpath($this->mediaRoot)))throw new DomainException('Privaten Medienspeicher prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot)if($webroot!==''&&($public=realpath($webroot))&&($root===$public||str_starts_with($root,$public.DIRECTORY_SEPARATOR)))throw new DomainException('Speicherprüfung benötigt einen privaten Medienspeicher.');
        $owner=$root.DIRECTORY_SEPARATOR.hash('sha256',$club);
        if(!file_exists($owner)&&!is_link($owner))return null;
        if(is_link($owner)||!is_dir($owner)||realpath($owner)!==$owner)throw new DomainException('Unverändert zugeordneten Vereinsspeicher prüfen.');
        return $owner;
    }
    private function preview(string $directory): ?array
    {
        foreach(['thumb.png'=>'image/png','thumb.jpg'=>'image/jpeg','thumb.webp'=>'image/webp']as$name=>$mime){
            $path=$directory.DIRECTORY_SEPARATOR.$name;
            if(is_link($path)||!is_file($path)||!is_readable($path)||realpath($path)!==$path)continue;
            $bytes=@filesize($path);if(!is_int($bytes)||$bytes<1||$bytes>1048576)continue;
            $size=@getimagesize($path);if(!is_array($size)||($size['mime']??'')!==$mime||$size[0]<1||$size[1]<1||$size[0]>512||$size[1]>512)continue;
            return ['path'=>$path,'mime'=>$mime,'bytes'=>$bytes,'width'=>$size[0],'height'=>$size[1],'name'=>'Vorschaubild'];
        }
        return null;
    }
    private function inventory(string $id,string $directory): array
    {
        $result=['id'=>$id,'file_count'=>null,'bytes'=>null,'first_files'=>[],'changed_at'=>'','readable'=>false,'recent'=>false,'has_thumbnail'=>false];
        try{
            if(is_link($directory)||!is_dir($directory)||realpath($directory)!==$directory)throw new DomainException('Verbliebenen Medienspeicher prüfen.');
            $files=0;$bytes=0;$names=[];$nodes=0;$changed=@filemtime($directory);if(!is_int($changed))throw new DomainException('Verbliebenen Speicher prüfen.');
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
            foreach($iterator as$entry){
                if(++$nodes>1000||$entry->isLink())throw new DomainException('Verbliebene Dateien benötigen eine gezielte Prüfung.');
                $path=$entry->getPathname();$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($directory)+1));
                if(strlen($relative)>1000||preg_match('//u',$relative)!==1||preg_match('/[\x00-\x1f\x7f\\\\]/',$relative)||realpath($path)!==$path)throw new DomainException('Verbliebenen Dateipfad prüfen.');
                if($entry->isDir())continue;if(!$entry->isFile()||!is_readable($path))throw new DomainException('Verbliebene Datei prüfen.');
                $size=@filesize($path);$time=@filemtime($path);if(!is_int($size)||!is_int($time)||$size<0||$bytes>PHP_INT_MAX-$size)throw new DomainException('Verbliebene Dateigrößen prüfen.');
                $files++;$bytes+=$size;$changed=max($changed,$time);$names[]=$relative;sort($names,SORT_STRING);if(count($names)>6)array_pop($names);
            }
            if($changed>time()-self::GRACE_SECONDS){$result['recent']=true;return $result;}
            return ['id'=>$id,'file_count'=>$files,'bytes'=>$bytes,'first_files'=>$names,'changed_at'=>gmdate('Y-m-d H:i:s',$changed),'readable'=>true,'recent'=>false,'has_thumbnail'=>$this->preview($directory)!==null];
        }catch(DomainException|\UnexpectedValueException){return $result;}
    }
    private function finish(string $club,array $result): array
    {
        if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert. Speicherprüfung neu öffnen.');
        return $result;
    }
}
