<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use RuntimeException;

/** Internal filesystem journal. Its caller owns the current database authority and transaction. */
final class MediaQuarantineStore
{
    private string $root;
    private string $removed;
    public function __construct(string $mediaRoot)
    {
        if(is_link($mediaRoot)||!is_dir($mediaRoot)||!($root=realpath($mediaRoot)))throw new DomainException('Privater Bildspeicher nicht verfügbar.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot){if($webroot!==''&&($public=realpath($webroot))&&($root===$public||str_starts_with($root,$public.DIRECTORY_SEPARATOR)))throw new DomainException('Bildquarantäne benötigt einen privaten Speicher.');}
        $this->root=$root;$this->removed=$root.DIRECTORY_SEPARATOR.'.removed';$this->directory($this->removed);
    }

    /** Only a freshly locked own media record may be supplied by the database service. */
    public function prepare(array $asset,string $operation):array
    {
        $id=$asset['id']??null;$this->identifier($id);$this->identifier($operation);
        if(!is_string($asset['club_id']??null)||$asset['club_id']===''||!is_string($asset['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$asset['sha256'])||!is_string($asset['variants_json']??null))throw new DomainException('Gespeichertes Bildoriginal prüfen.');
        $snapshot=$this->json($asset);$hash=hash('sha256',$snapshot);$dir=$this->operation($id,$operation,true);$journal=$dir.DIRECTORY_SEPARATOR.'plan.json';
        if(file_exists($journal)||is_link($journal)){
            $plan=$this->read($id,$operation);
            if(!hash_equals($plan['asset_sha256'],$hash))throw new DomainException('Bildoriginal und ursprünglicher Dateivorgang unterscheiden sich.');
            return $plan;
        }
        $source=$this->activeDirectory($asset);$this->existingDirectory($source);$temporary=$dir.DIRECTORY_SEPARATOR.'plan.pending';
        if(file_exists($temporary)||is_link($temporary)){
            $plan=$this->journal($temporary,$id,$operation);
            if(!hash_equals($plan['asset_sha256'],$hash))throw new DomainException('Bildoriginal und vorbereiteter Dateivorgang unterscheiden sich.');
            $this->verify($source,$plan['files']);
            if(!link($temporary,$journal))throw new RuntimeException('Vorbereiteter privater Bildvorgang konnte nicht bestätigt werden.');
            return $plan;
        }
        $files=$this->inventory($source);$variants=json_decode($asset['variants_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($variants))throw new DomainException('Gespeicherte Bildvarianten prüfen.');
        foreach(['original','large','medium','thumb']as$name){$relative=$variants[$name]['file']??null;$this->relative($relative);if(!isset($files[$relative])||!is_int($variants[$name]['bytes']??null)||$variants[$name]['bytes']!==$files[$relative]['bytes'])throw new DomainException('Ursprüngliche Bildvariante nicht vollständig verfügbar.');}
        if(!hash_equals($asset['sha256'],$files[$variants['original']['file']]['sha256']))throw new DomainException('Ursprüngliche Bildbytes wurden verändert.');
        $plan=['version'=>1,'id'=>$id,'operation'=>$operation,'asset'=>$asset,'asset_sha256'=>$hash,'files'=>$files];$plan['plan_sha256']=hash('sha256',$this->json($plan));$bytes=$this->json($plan);$temporary=$dir.DIRECTORY_SEPARATOR.'plan.pending';
        if(strlen($bytes)>1048576)throw new DomainException('Ursprüngliches Dateiinventar benötigt eine gesonderte Prüfung.');
        $handle=@fopen($temporary,'xb');if(!$handle)throw new RuntimeException('Privater Bildvorgang konnte nicht vorbereitet werden.');
        try{if(!chmod($temporary,0600))throw new RuntimeException('Privater Bildvorgang benötigt geschützte Dateirechte.');$written=0;while($written<strlen($bytes)){$count=fwrite($handle,substr($bytes,$written));if(!is_int($count)||$count<1)throw new RuntimeException('Privater Bildvorgang nicht vollständig gespeichert.');$written+=$count;}if(!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new RuntimeException('Privater Bildvorgang nicht dauerhaft gespeichert.');}finally{fclose($handle);}
        // Publishing by hard link is atomic and cannot replace an earlier journal.
        if(!link($temporary,$journal))throw new RuntimeException('Privater Bildvorgang konnte nicht bestätigt werden.');
        return $plan;
    }

    public function move(string $id,string $operation):array
    {
        $plan=$this->read($id,$operation);$source=$this->activeDirectory($plan['asset']);$destination=$this->operation($id,$operation,false).DIRECTORY_SEPARATOR.'files';
        if(file_exists($destination)||is_link($destination)){
            if(file_exists($source)||is_link($source))throw new DomainException('Beide Bildspeicher sind belegt. Originale prüfen.');
            $this->verify($destination,$plan['files']);return $plan;
        }
        $this->verify($source,$plan['files']);if(!rename($source,$destination))throw new RuntimeException('Bilddateien konnten nicht in den privaten Speicher verschoben werden.');$this->verify($destination,$plan['files']);return $plan;
    }

    public function restore(string $id,string $operation):array
    {
        $plan=$this->read($id,$operation);$source=$this->operation($id,$operation,false).DIRECTORY_SEPARATOR.'files';$destination=$this->activeDirectory($plan['asset']);
        if(file_exists($destination)||is_link($destination)){
            if(file_exists($source)||is_link($source))throw new DomainException('Bildspeicher ist bereits belegt. Bestehende Dateien bleiben erhalten.');
            $this->verify($destination,$plan['files']);return $plan;
        }
        $this->verify($source,$plan['files']);if(!rename($source,$destination))throw new RuntimeException('Ursprüngliche Bilddateien konnten nicht zurückgeholt werden.');$this->verify($destination,$plan['files']);return $plan;
    }

    public function read(string $id,string $operation):array
    {
        $this->identifier($id);$this->identifier($operation);$path=$this->operation($id,$operation,false).DIRECTORY_SEPARATOR.'plan.json';
        return $this->journal($path,$id,$operation);
    }
    /** The caller must hold a freshly confirmed durable purge state before allowing missing files. */
    public function inspectPurge(string $id,string $operation,bool $partial=false):array
    {
        $plan=$this->read($id,$operation);$active=$this->activeDirectory($plan['asset']);
        if(file_exists($active)||is_link($active))throw new DomainException('Aktiver Bildspeicher ist belegt. Bestehende Dateien bleiben erhalten.');
        $directory=$this->operation($id,$operation,false);$owner=dirname($directory);$count=0;
        foreach(new \FilesystemIterator($owner,\FilesystemIterator::SKIP_DOTS)as$entry){
            if(++$count>1000)throw new DomainException('Bildvorgänge benötigen eine gezielte Speicherprüfung.');
            if($entry->getFilename()===$operation)continue;
            $this->identifier($entry->getFilename());$this->existingDirectory($entry->getPathname());
            if(file_exists($entry->getPathname().'/files')||is_link($entry->getPathname().'/files'))throw new DomainException('Ein weiterer Bildvorgang enthält Dateien. Ursprünglichen Speicher prüfen.');
        }
        $files=$directory.DIRECTORY_SEPARATOR.'files';
        if(!file_exists($files)&&!is_link($files)){
            if(!$partial)throw new DomainException('Ursprüngliche Bilddateien sind nicht vollständig verfügbar.');
            return ['plan'=>$plan,'remaining'=>[]];
        }
        $remaining=$this->inventory($files);
        foreach($remaining as$relative=>$file)if(!isset($plan['files'][$relative])||$plan['files'][$relative]!==$file)throw new DomainException('Bildspeicher enthält veränderte oder zusätzliche Dateien. Originale prüfen.');
        if(!$partial&&$remaining!==$plan['files'])throw new DomainException('Ursprüngliche Bilddateien sind nicht vollständig verfügbar.');
        return compact('plan','remaining');
    }
    /** Delete only verified remaining original files; immutable journals are always retained. */
    public function purge(string $id,string $operation):array
    {
        $check=$this->inspectPurge($id,$operation,true);$directory=$this->operation($id,$operation,false).DIRECTORY_SEPARATOR.'files';
        foreach($check['remaining']as$relative=>$original){
            $path=$directory.DIRECTORY_SEPARATOR.$relative;clearstatcache(true,$path);
            if(is_link($path)||realpath($path)!==$path||!is_file($path)||filesize($path)!==$original['bytes']||!hash_equals($original['sha256'],hash_file('sha256',$path)))throw new DomainException('Verbliebene ursprüngliche Bilddatei prüfen.');
            if(!@unlink($path))throw new RuntimeException('Verbliebene Bilddatei konnte nicht gelöscht werden.');
        }
        if(file_exists($directory)||is_link($directory)){
            $this->existingDirectory($directory);$count=0;
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iterator as$entry){if(++$count>4000)throw new DomainException('Verbliebener Bildspeicher benötigt eine gezielte Prüfung.');$path=$entry->getPathname();if(!$entry->isDir()||$entry->isLink())throw new DomainException('Verbliebene Bilddateien prüfen.');$this->existingDirectory($path);if(!@rmdir($path))throw new RuntimeException('Leerer Bildspeicher konnte nicht abgeschlossen werden.');}
            if(!@rmdir($directory))throw new RuntimeException('Bildspeicher konnte nicht abgeschlossen werden.');
        }
        if($this->inspectPurge($id,$operation,true)['remaining']!==[])throw new DomainException('Endgültiges Löschen ist noch nicht abgeschlossen.');
        return $check['plan'];
    }
    private function journal(string $path,string $id,string $operation):array
    {
        if(is_link($path)||!is_file($path)||filesize($path)>1048576)throw new DomainException('Ursprünglicher privater Bildvorgang nicht verfügbar.');
        $plan=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(!is_array($plan))throw new DomainException('Ursprünglichen privaten Bildvorgang prüfen.');$checksum=$plan['plan_sha256']??null;$unsigned=$plan;unset($unsigned['plan_sha256']);
        if(!is_array($plan)||($plan['version']??null)!==1||($plan['id']??null)!==$id||($plan['operation']??null)!==$operation||!is_array($plan['asset']??null)||($plan['asset']['id']??null)!==$id||!is_array($plan['files']??null)||!$plan['files']||!is_string($checksum)||!hash_equals(hash('sha256',$this->json($unsigned)),$checksum)||!is_string($plan['asset_sha256']??null)||!hash_equals(hash('sha256',$this->json($plan['asset'])),$plan['asset_sha256']))throw new DomainException('Ursprünglicher privater Bildvorgang ist nicht unverändert.');
        if(count($plan['files'])>1000)throw new DomainException('Ursprüngliches Dateiinventar benötigt eine gesonderte Prüfung.');foreach($plan['files']as$relative=>$file){$this->relative((string)$relative);if(!is_array($file)||!is_int($file['bytes']??null)||$file['bytes']<0||!is_string($file['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256']))throw new DomainException('Ursprüngliches Dateiinventar prüfen.');}
        return $plan;
    }

    private function inventory(string $directory):array
    {
        $this->existingDirectory($directory);$files=[];$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as$file){if($file->isLink())throw new DomainException('Bilddateien dürfen keine Verknüpfungen enthalten.');if($file->isDir())continue;if(!$file->isFile())throw new DomainException('Ursprüngliche Bilddatei prüfen.');$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($directory)+1));$this->relative($relative);$hash=hash_file('sha256',$file->getPathname());$size=filesize($file->getPathname());if(!is_string($hash)||!is_int($size))throw new DomainException('Ursprüngliche Bildbytes nicht vollständig lesbar.');$files[$relative]=['bytes'=>$size,'sha256'=>$hash];if(count($files)>1000)throw new DomainException('Ursprünglicher Bildspeicher benötigt eine gesonderte Prüfung.');}
        ksort($files,SORT_STRING);return $files;
    }
    private function verify(string $directory,array $files):void
    {
        if($this->inventory($directory)!==$files)throw new DomainException('Ursprüngliche Bilddateien sind nicht unverändert verfügbar.');
    }
    private function operation(string $id,string $operation,bool $create):string
    {
        $this->identifier($id);$this->identifier($operation);$owner=$this->removed.DIRECTORY_SEPARATOR.$id;$directory=$owner.DIRECTORY_SEPARATOR.$operation;
        $this->existingDirectory($this->removed);if($create){$this->directory($owner);$this->directory($directory);}else{$this->existingDirectory($owner);$this->existingDirectory($directory);}return $directory;
    }
    private function activeDirectory(array $asset):string
    {
        $this->identifier($asset['id']??null);$club=$asset['club_id']??null;
        if(!is_string($club)||$club==='')throw new DomainException('Ursprüngliche Vereinszuordnung prüfen.');
        $owner=$this->root.DIRECTORY_SEPARATOR.hash('sha256',$club);$this->existingDirectory($owner);
        return $owner.DIRECTORY_SEPARATOR.$asset['id'];
    }
    private function directory(string $directory):void
    {
        if(is_link($directory))throw new DomainException('Privater Bildspeicher darf keine Verknüpfung sein.');if(!is_dir($directory)&&!mkdir($directory,0700))throw new RuntimeException('Privater Bildspeicher konnte nicht angelegt werden.');$this->existingDirectory($directory);
    }
    private function existingDirectory(string $directory):void
    {
        if(is_link($directory)||!is_dir($directory)||realpath($directory)!==$directory)throw new DomainException('Unverändert zugeordneter privater Bildspeicher erforderlich.');
    }
    private function identifier(mixed $id):void
    {
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Ursprüngliche Bild- und Vorgangskennung prüfen.');
    }
    private function relative(mixed $path):void
    {
        if(!is_string($path)||$path===''||strlen($path)>1000||preg_match('/[\x00-\x1f\x7f\\\\]/',$path)||preg_match('//u',$path)!==1||array_intersect(explode('/',$path),['','.', '..']))throw new DomainException('Ursprünglichen relativen Dateipfad prüfen.');
    }
    private function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
