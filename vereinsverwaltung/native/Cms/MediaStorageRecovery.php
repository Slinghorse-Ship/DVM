<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Explicit recovery copies a complete native orphan to a distinct deterministic identity. */
final class MediaStorageRecovery
{
    private const SIZES=['original'=>2400,'large'=>1920,'medium'=>960,'thumb'=>320];
    private const TYPES=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}

    public function prepare(string $source,bool $decode=true):array
    {
        $this->outside();$actor=$this->actor();$snapshot=$this->snapshot($source,$actor['club_id'],$decode);$this->same($actor,$this->actor());
        $existing=$this->existing($actor['club_id'],$snapshot['target_id'],$source,$snapshot['fingerprint']);
        return ['source_id'=>$source,'fingerprint'=>$snapshot['fingerprint'],'target_id'=>$snapshot['target_id'],'mime'=>$snapshot['mime'],'width'=>$snapshot['variants']['original']['width'],'height'=>$snapshot['variants']['original']['height'],'bytes'=>array_sum(array_column($snapshot['variants'],'bytes')),'existing'=>$existing!==null,'recovery_state'=>$existing!==null?'':MediaRecoveryLease::state($this->mediaRoot,$actor['club_id'],$source,$snapshot['fingerprint'],$snapshot['target_id'],$snapshot['owner'].DIRECTORY_SEPARATOR.$snapshot['target_id'])];
    }

    public function recover(string $source,string $fingerprint,string $name='',string $alt=''):array
    {
        $this->outside();if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new InvalidArgumentException('Dateigruppe erneut prüfen.');
        foreach([[$name,240],[$alt,500]]as[$value,$max])if(!preg_match('//u',$value)||mb_strlen($value,'UTF-8')>$max||preg_match('/[\x00-\x1F\x7F]/',$value))throw new InvalidArgumentException('Bildname und Beschreibung prüfen.');
        $initial=$this->actor();$club=$initial['club_id'];$snapshot=$this->snapshot($source,$club);
        if(!hash_equals($fingerprint,$snapshot['fingerprint']))throw new Conflict('Dateigruppe inzwischen geändert. Erneut prüfen.');
        $id=$snapshot['target_id'];$old=$this->existing($club,$id,$source,$fingerprint);
        if($old!==null){$this->same($initial,$this->actor());return $old+['created'=>false];}
        $name=trim($name);if($name==='')$name=$snapshot['variants']['original']['file'];
        $name=basename(str_replace('\\','/',$name));if($name===''||$name==='.'||$name==='..')throw new InvalidArgumentException('Bildname prüfen.');
        $alt=trim($alt);if($alt==='')$alt=pathinfo($name,PATHINFO_FILENAME);if($alt==='')$alt='Bild';
        $directory=$snapshot['owner'].DIRECTORY_SEPARATOR.$id;
        $lease=MediaRecoveryLease::acquire($this->mediaRoot,$club,$source,$fingerprint,$id,$directory);
        $created=false;$files=[];$committed=false;$databaseOwned=false;
        try{
            $saved=$this->existing($club,$id,$source,$fingerprint);
            if($saved!==null){$this->same($initial,$this->actor());$lease->close();return $saved+['created'=>false];}
            if(is_link($directory))throw new DomainException('Vorbereitete Bildkopien prüfen.');
            if(!is_dir($directory)){if(!@mkdir($directory,0700))throw new Conflict('Bildkopien werden bereits bearbeitet.');$created=true;}
            if(realpath($directory)!==$directory||(fileperms($directory)&0077))throw new DomainException('Ursprünglichen Wiederherstellungsordner prüfen.');
            $allowed=array_column($snapshot['variants'],'file');
            foreach(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS)as$entry)if($entry->isLink()||!$entry->isFile()||!in_array($entry->getFilename(),$allowed,true))throw new DomainException('Unbekannte vorbereitete Dateien bleiben unverändert.');
        }catch(\Throwable $error){$lease->close();throw $error;}

        try{
            $variants=$snapshot['variants'];
            foreach($variants as$size=>$variant){
                $from=$snapshot['directory'].DIRECTORY_SEPARATOR.$variant['file'];$to=$directory.DIRECTORY_SEPARATOR.$variant['file'];
                $offset=0;$present=is_file($to);
                if($present){
                    clearstatcache(true,$to);$offset=filesize($to);
                    if(is_link($to)||!is_int($offset)||$offset>$variant['bytes']||(fileperms($to)&0077))throw new DomainException('Abweichende vorbereitete Dateien bleiben unverändert.');
                    if($offset===$variant['bytes']){
                        if(hash_file('sha256',$to)!==$variant['sha256'])throw new DomainException('Abweichende vorbereitete Dateien bleiben unverändert.');
                        unset($variants[$size]['sha256']);continue;
                    }
                }
                $input=@fopen($from,'rb');
                $oldMask=umask(0077);try{$output=@fopen($to,$present?'r+b':'xb');}finally{umask($oldMask);}
                if(!is_resource($input)||!is_resource($output)){if(is_resource($input))fclose($input);if(is_resource($output)){fclose($output);if(!$present)$files[]=$to;}throw new DomainException('Bilddateien erneut prüfen.');}
                if(!$present)$files[]=$to;
                try{
                    if(!$present&&!@chmod($to,0600))throw new DomainException('Private Bildkopie prüfen.');
                    $remaining=$offset;
                    while($remaining>0){$length=min(65536,$remaining);$left=fread($input,$length);$right=fread($output,$length);if(!is_string($left)||strlen($left)!==$length||$left!==$right)throw new DomainException('Abweichende vorbereitete Dateien bleiben unverändert.');$remaining-=$length;}
                    if(stream_copy_to_stream($input,$output,$variant['bytes']-$offset+1)!==$variant['bytes']-$offset||!fflush($output)||(function_exists('fsync')&&!fsync($output)))throw new Conflict('Dateigruppe inzwischen geändert.');
                }finally{fclose($input);fclose($output);}
                if(hash_file('sha256',$to)!==$variant['sha256'])throw new Conflict('Bildkopie konnte nicht bestätigt werden.');
                unset($variants[$size]['sha256']);
            }
            $variants['original']['recovered_from']=['source_id'=>$source,'fingerprint'=>$fingerprint];
            $databaseOwned=true;
            $result=UploadTransaction::run($this->db,function($tx)use($initial,$club,$source,$fingerprint,$snapshot,$id,$name,$alt,$variants,$files,$created,$directory):array{
                $tx->onRollback($this->db,static function()use($files,$created,$directory):void{foreach($files as$file)if(is_file($file)&&!is_link($file))@unlink($file);if($created)@rmdir($directory);});
                $actor=$this->actor();$this->same($initial,$actor);
                MediaQuota::assertUpload($this->db,$club,array_sum(array_column($variants,'bytes')));
                if($this->existing($club,$id,$source,$fingerprint)!==null)throw new Conflict('Wiederherstellung bereits gespeichert. Bild prüfen.');
                $current=$this->snapshot($source,$club,false);
                if(!hash_equals($fingerprint,$current['fingerprint']))throw new Conflict('Dateigruppe während der Vorbereitung geändert. Erneut prüfen.');
                Db::run($this->db,'INSERT INTO khcms_media (id,club_id,original_name,alt,mime,width,height,variants_json,sha256,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$club,$name,$alt,$snapshot['mime'],$variants['original']['width'],$variants['original']['height'],json_encode($variants,JSON_THROW_ON_ERROR),$snapshot['variants']['original']['sha256'],$actor['id']]);
                return ['id'=>$id,'source_id'=>$source,'fingerprint'=>$fingerprint,'created'=>true];
            });
            $committed=true;return $result;
        }finally{
            if(!$committed&&!$databaseOwned){foreach($files as$file)if(is_file($file)&&!is_link($file))@unlink($file);if($created)@rmdir($directory);}
            $lease->close();
        }
    }

    private function existing(string $club,string $id,string $source,string $fingerprint):?array
    {
        $row=Db::run($this->db,'SELECT club_id,variants_json FROM khcms_media WHERE id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$id])->fetch();if(!$row)return null;
        $variants=json_decode($row['variants_json'],true,32,JSON_THROW_ON_ERROR);
        if($row['club_id']!==$club||($variants['original']['recovered_from']['source_id']??null)!==$source||($variants['original']['recovered_from']['fingerprint']??null)!==$fingerprint)throw new Conflict('Wiederherstellungskennung bereits verwendet. Bestand prüfen.');
        return ['id'=>$id,'source_id'=>$source,'fingerprint'=>$fingerprint];
    }
    private function outside():void{if($this->db->inTransaction())throw new DomainException('Wiederherstellung benötigt einen eigenen aktuellen Vorgang.');}
    private function actor():array
    {
        $actor=$this->access->require('website','manage');foreach(['read','edit']as$cap)$this->same($actor,$this->access->require('website',$cap));return $actor;
    }
    private function same(array $first,array $current):void{if($first['id']!==$current['id']||$first['club_id']!==$current['club_id'])throw new Conflict('Aktuelle Person oder Vereinszuordnung geändert.');}
    private function snapshot(string $source,string $club,bool $decode=true):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$source))throw new InvalidArgumentException('Dateigruppe erneut öffnen.');
        if(Db::run($this->db,'SELECT 1 FROM khcms_media WHERE id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$source])->fetchColumn()!==false)throw new DomainException('Diese Dateigruppe gehört bereits zur Mediathek.');
        if(is_link($this->mediaRoot)||!is_dir($this->mediaRoot)||!($root=realpath($this->mediaRoot)))throw new DomainException('Privaten Medienspeicher prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$webroot)if($webroot!==''&&($public=realpath($webroot))&&($root===$public||str_starts_with($root,$public.DIRECTORY_SEPARATOR)))throw new DomainException('Wiederherstellung benötigt privaten Medienspeicher.');
        $owner=$root.DIRECTORY_SEPARATOR.hash('sha256',$club);$directory=$owner.DIRECTORY_SEPARATOR.$source;
        if(MediaRecoveryLease::reserved($root,$club,$source))throw new DomainException('Vorbereitete Kopien über die ursprüngliche Dateigruppe fortsetzen.');
        if(is_link($owner)||is_link($directory)||!is_dir($directory)||realpath($owner)!==$owner||realpath($directory)!==$directory)throw new DomainException('Eigene Dateigruppe prüfen.');
        $cutoff=time()-MediaStorageInventory::GRACE_SECONDS;$time=@filemtime($directory);if(!is_int($time))throw new DomainException('Ursprüngliche Dateigruppe prüfen.');$recent=$time>$cutoff;
        $names=[];foreach(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS)as$entry){if(count($names)>=4||!$entry->isFile()||$entry->isLink())throw new DomainException('Nur vollständige native Bilddateigruppen wiederherstellen.');$names[]=$entry->getFilename();}
        sort($names,SORT_STRING);$mime=null;$extension=null;
        foreach(self::TYPES as$type=>$ext){$expected=array_map(static fn($size)=>$size.'.'.$ext,array_keys(self::SIZES));sort($expected,SORT_STRING);if($names===$expected){$mime=$type;$extension=$ext;break;}}
        if($mime===null)throw new DomainException('Vier ursprüngliche native Bildvarianten erforderlich.');
        $variants=[];foreach(self::SIZES as$size=>$edge){
            $file=$size.'.'.$extension;$path=$directory.DIRECTORY_SEPARATOR.$file;clearstatcache(true,$path);
            if(is_link($path)||realpath($path)!==$path||!is_readable($path))throw new DomainException('Unveränderte Bilddateien prüfen.');
            $bytes=@filesize($path);$changed=@filemtime($path);$info=@getimagesize($path);
            if(!is_int($bytes)||$bytes<1||$bytes>12*1048576||!is_int($changed)||!is_array($info)||($info['mime']??'')!==$mime||$info[0]<1||$info[1]<1||max($info[0],$info[1])>$edge)throw new DomainException('Vollständige ältere Bildvarianten prüfen.');
            $recent=$recent||$changed>$cutoff;
            if($size!=='original'){$original=$variants['original'];$scale=min(1,$edge/max($original['width'],$original['height']));if(abs($info[0]-max(1,(int)round($original['width']*$scale)))>1||abs($info[1]-max(1,(int)round($original['height']*$scale)))>1)throw new DomainException('Zusammengehörige Bildvarianten prüfen.');}
            if($decode){
                $limit=ini_get('memory_limit');$memory=$limit==='-1'?0:(int)$limit*match(strtolower(substr($limit,-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
                if($memory>0&&memory_get_usage(true)+$info[0]*$info[1]*8+8*1048576>$memory)throw new DomainException('Bild für diese Wiederherstellung zu groß.');
                $image=match($mime){'image/png'=>@imagecreatefrompng($path),'image/jpeg'=>@imagecreatefromjpeg($path),'image/webp'=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($path):false};if(!$image)throw new DomainException('Ursprüngliche Bildpixel nicht vollständig lesbar.');unset($image);
            }
            $sha=hash_file('sha256',$path);if(!is_string($sha))throw new DomainException('Dateibeweis erneut prüfen.');
            $variants[$size]=['file'=>$file,'width'=>$info[0],'height'=>$info[1],'bytes'=>$bytes,'sha256'=>$sha];
        }
        $fingerprint=hash('sha256',json_encode(['club'=>$club,'source'=>$source,'mime'=>$mime,'variants'=>$variants],JSON_THROW_ON_ERROR));
        $target=substr(hash('sha256','native-media-recovery:'.$club.':'.$source.':'.$fingerprint),0,32);
        if($target===$source)throw new DomainException('Neue Wiederherstellungskennung erforderlich.');
        // Relocated private files have new mtimes; only an exact prior immutable intention can waive the upload grace period.
        if($recent&&MediaRecoveryLease::state($root,$club,$source,$fingerprint,$target,$owner.DIRECTORY_SEPARATOR.$target)==='')throw new DomainException('Aktuelle Upload-Dateien bleiben unverändert. Später erneut prüfen.');
        return ['owner'=>$owner,'directory'=>$directory,'mime'=>$mime,'variants'=>$variants,'fingerprint'=>$fingerprint,'target_id'=>$target];
    }
}
