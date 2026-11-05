<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/** Data-only interchange. Archive names never become filesystem paths. */
final class Package
{
    public const FORMAT='dvm-native-cms';
    public const JSON_LIMIT=16777216;
    public const BYTES_LIMIT=134217728;
    public const ARCHIVE_LIMIT=268435456;
    private const CONTENT_KEYS=['format','version','fields','templates','pages','assets','languages'];

    public static function create(string $target,array $content,array $blobs):array
    {
        self::content($content);self::local($target,false);self::directory(dirname($target));
        if(file_exists($target)||is_link($target))throw new InvalidArgumentException('Paketdatei ist bereits belegt.');
        $json=self::json($content);if(strlen($json)>self::JSON_LIMIT)throw new InvalidArgumentException('CMS-Daten überschreiten 16 MiB.');
        $entries=['content.json'=>['sha256'=>hash('sha256',$json),'bytes'=>strlen($json)]];$total=strlen($json);
        foreach($blobs as $sha=>$path){
            if(!is_string($sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$sha)||!is_string($path))throw new InvalidArgumentException('Paket-Dateizuordnung prüfen.');
            self::local($path,true);$bytes=filesize($path);if($bytes===false||$bytes<1||!hash_equals($sha,hash_file('sha256',$path)))throw new InvalidArgumentException('Paketquelle ist nicht mehr unverändert.');
            $total+=$bytes;$entries['blobs/'.$sha]=['sha256'=>$sha,'bytes'=>$bytes];
        }
        self::budget($entries,$total);self::assets($content,$entries);
        $manifest=self::json(['format'=>self::FORMAT,'version'=>1,'entries'=>$entries]);if($total+strlen($manifest)>self::BYTES_LIMIT)throw new InvalidArgumentException('CMS-Paket einschließlich Manifest ist zu groß.');
        $handle=@fopen($target,'x+b');if(!$handle)throw new RuntimeException('Paketdatei konnte nicht reserviert werden.');fclose($handle);chmod($target,0600);$zip=new ZipArchive();$open=false;
        try{
            if($zip->open($target,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP-Unterstützung ist nicht verfügbar.');$open=true;
            if(!$zip->addFromString('manifest.json',$manifest)||!$zip->addFromString('content.json',$json))throw new RuntimeException('Paketdaten konnten nicht geschrieben werden.');
            // STORE keeps large valid office/JSON inputs within the same decompression budget.
            $zip->setCompressionName('manifest.json',ZipArchive::CM_STORE);$zip->setCompressionName('content.json',ZipArchive::CM_STORE);
            foreach($blobs as$sha=>$path){if(!$zip->addFile($path,'blobs/'.$sha))throw new RuntimeException('Paketdatei konnte nicht aufgenommen werden.');$zip->setCompressionName('blobs/'.$sha,ZipArchive::CM_STORE);}
            if(!$zip->close())throw new RuntimeException('Paket konnte nicht abgeschlossen werden.');$open=false;
            foreach($blobs as$sha=>$path)if(filesize($path)!==$entries['blobs/'.$sha]['bytes']||!hash_equals($sha,hash_file('sha256',$path)))throw new RuntimeException('Eine Paketquelle wurde während des Exports geändert.');
            return ['path'=>$target,'name'=>'CMS-Inhalte.dvm.zip','mime'=>'application/zip','bytes'=>filesize($target),'sha256'=>hash_file('sha256',$target)];
        }catch(\Throwable $error){if($open)$zip->close();if(is_file($target)&&!is_link($target))unlink($target);throw $error;}
    }

    public static function read(string $source,string $privateDirectory):array
    {
        self::local($source,true);$size=filesize($source);if($size===false||$size<1||$size>self::ARCHIVE_LIMIT)throw new InvalidArgumentException('CMS-Paket: höchstens 256 MiB hochladen.');
        $entryCount=self::archiveDirectory($source,$size);$before=hash_file('sha256',$source);$root=self::directory($privateDirectory);$owned=$root.'/package-'.bin2hex(random_bytes(16));
        if(!mkdir($owned,0700))throw new RuntimeException('Privater Paketbereich konnte nicht angelegt werden.');
        $result=['owned_root'=>$owned,'content'=>[],'blobs'=>[],'sha256'=>$before,'bytes'=>$size];$zip=new ZipArchive();$open=false;
        try{
            if($zip->open($source,ZipArchive::RDONLY)!==true)throw new InvalidArgumentException('Kein lesbares natives CMS-Paket.');$open=true;
            if($zip->numFiles!==$entryCount)throw new InvalidArgumentException('Paketverzeichnis und tatsächliche Einträge stimmen nicht überein.');
            $stats=[];$total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=$stat['name']??'';
                if(!is_string($name)||(!in_array($name,['manifest.json','content.json'],true)&&!preg_match('~\Ablobs/[a-f0-9]{64}\z~D',$name))||isset($stats[$name]))throw new InvalidArgumentException('Nicht erlaubter oder doppelter Paketeintrag.');
                $opsys=0;$attributes=0;if(!$zip->getExternalAttributesIndex($i,$opsys,$attributes))throw new InvalidArgumentException('Paketeintrag ist nicht prüfbar.');
                $type=($attributes>>16)&0170000;
                if(($opsys===ZipArchive::OPSYS_UNIX&&$type!==0&&$type!==0100000)||($stat['encryption_method']??ZipArchive::EM_NONE)!==ZipArchive::EM_NONE)throw new InvalidArgumentException('Verknüpfte oder verschlüsselte Paketeinträge sind nicht erlaubt.');
                $bytes=$stat['size']??-1;$compressed=$stat['comp_size']??-1;
                if(!is_int($bytes)||$bytes<1||!is_int($compressed)||$compressed<0||$bytes>self::BYTES_LIMIT||($bytes>1048576&&$bytes>max(1,$compressed)*300))throw new InvalidArgumentException('Entpackgrenzen des CMS-Pakets überschritten.');
                if(($name==='manifest.json'&&$bytes>65536)||($name==='content.json'&&$bytes>self::JSON_LIMIT))throw new InvalidArgumentException('Paket-Metadaten sind zu groß.');
                $total+=$bytes;$stats[$name]=$stat;
            }
            if($total>self::BYTES_LIMIT||!isset($stats['manifest.json'],$stats['content.json']))throw new InvalidArgumentException('Paket ist zu groß oder unvollständig.');
            $raw=self::stream($zip,'manifest.json',65536,null);$manifest=self::decodeJson($raw,32);
            if(!is_array($manifest)||array_diff(array_keys($manifest),['format','version','entries'])||($manifest['format']??null)!==self::FORMAT||($manifest['version']??null)!==1||!is_array($manifest['entries']??null)||array_is_list($manifest['entries']))throw new InvalidArgumentException('Unbekanntes CMS-Paketformat.');
            $entries=$manifest['entries'];self::budget($entries,$total);
            if(count($entries)!==count($stats)-1)throw new InvalidArgumentException('Manifest und Paketeinträge stimmen nicht überein.');
            foreach($entries as$name=>$descriptor){
                if(!is_string($name)||$name==='manifest.json'||!isset($stats[$name])||!is_array($descriptor)||array_diff(array_keys($descriptor),['sha256','bytes'])||!is_string($descriptor['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])||!is_int($descriptor['bytes']??null)||$descriptor['bytes']!==$stats[$name]['size'])throw new InvalidArgumentException('Ungültiger Manifest-Dateinachweis.');
                if($name==='content.json'){$json=self::stream($zip,$name,self::JSON_LIMIT,null);if(!hash_equals($descriptor['sha256'],hash('sha256',$json)))throw new InvalidArgumentException('CMS-Daten stimmen nicht mit dem Manifest überein.');$result['content']=self::decodeJson($json);continue;}
                $sha=substr($name,6);if($sha!==$descriptor['sha256'])throw new InvalidArgumentException('Dateiname und Dateihash widersprechen sich.');
                $path=$owned.'/'.bin2hex(random_bytes(16)).'.blob';self::stream($zip,$name,$descriptor['bytes'],$path);
                if(filesize($path)!==$descriptor['bytes']||!hash_equals($sha,hash_file('sha256',$path)))throw new InvalidArgumentException('Dateibytes stimmen nicht mit dem Manifest überein.');$result['blobs'][$sha]=$path;
            }
            self::content($result['content']);self::assets($result['content'],$entries);
            if(!$zip->close())throw new RuntimeException('Paketprüfung konnte nicht abgeschlossen werden.');$open=false;
            if(filesize($source)!==$size||!hash_equals($before,hash_file('sha256',$source)))throw new InvalidArgumentException('Paket während der Prüfung geändert.');
            return $result;
        }catch(\Throwable $error){if($open)$zip->close();self::cleanup($result);if($error instanceof \JsonException)throw new InvalidArgumentException('CMS-Paket enthält ungültige JSON-Daten.',0,$error);throw $error;}
    }

    public static function cleanup(array $descriptor):void
    {
        $root=$descriptor['owned_root']??null;if(!is_string($root)||!preg_match('~/package-[a-f0-9]{32}\z~D',$root)||is_link($root)||!is_dir($root))return;
        foreach(new \FilesystemIterator($root,\FilesystemIterator::SKIP_DOTS)as$file)if(!$file->isLink()&&$file->isFile()&&preg_match('/\A[a-f0-9]{32}\.blob\z/D',$file->getFilename()))unlink($file->getPathname());
        @rmdir($root);
    }

    public static function directory(string $path):string
    {
        self::local($path,false);if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Privater Paketbereich fehlt.');$root=realpath($path);
        if($root===false||is_link($path))throw new InvalidArgumentException('Privaten Paketbereich prüfen.');
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??'']as$public){$public=$public!==''?realpath($public):false;if($public!==false&&($root===$public||str_starts_with($root,$public.DIRECTORY_SEPARATOR)))throw new InvalidArgumentException('CMS-Pakete außerhalb des Webroots speichern.');}
        return $root;
    }

    /** Count allocation-producing syntax before PHP materializes an untrusted object graph. */
    public static function decodeJson(string $json,int $depth=64):mixed
    {
        $length=strlen($json);if($length>self::JSON_LIMIT+1048576)throw new InvalidArgumentException('CMS-Daten sind zu groß.');$string=false;$escaped=false;$containers=0;$tokens=0;
        for($i=0;$i<$length;$i++){$char=$json[$i];if($string){if($escaped){$escaped=false;continue;}if($char==='\\'){$escaped=true;continue;}if($char==='"')$string=false;continue;}if($char==='"'){$string=true;continue;}if($char==='['||$char==='{')++$containers;if($char===':'||$char===',')++$tokens;if($containers>20000||$tokens>100000)throw new InvalidArgumentException('CMS-Paket enthält zu viele Datenstrukturen. Eine kleinere Auswahl übertragen.');}
        $configured=trim((string)ini_get('memory_limit'));$limit=(int)$configured;$unit=strtolower(substr($configured,-1));if($unit==='g')$limit*=1073741824;elseif($unit==='m')$limit*=1048576;elseif($unit==='k')$limit*=1024;
        $estimated=$length*2+$containers*256+$tokens*96;
        if($limit>0&&memory_get_usage(true)+$estimated+8388608>$limit)throw new InvalidArgumentException('Paket ist für den verfügbaren Arbeitsspeicher zu groß. Eine kleinere Auswahl übertragen.');
        try{return json_decode($json,true,$depth,JSON_THROW_ON_ERROR);}catch(\JsonException $error){throw new InvalidArgumentException('CMS-Paket enthält ungültige JSON-Daten.',0,$error);}
    }

    private static function content(mixed $content):void
    {
        if(!is_array($content)||array_diff(array_keys($content),self::CONTENT_KEYS)||array_diff(self::CONTENT_KEYS,array_keys($content))||($content['format']??null)!==self::FORMAT||($content['version']??null)!==1)throw new InvalidArgumentException('Nur das native CMS-Datenformat wird unterstützt.');
        foreach(['fields','templates','pages','assets','languages']as$key)if(!is_array($content[$key])||!array_is_list($content[$key]))throw new InvalidArgumentException('CMS-Paketlisten prüfen.');
        if(count($content['pages'])>100||count($content['fields'])+count($content['templates'])>200||count($content['assets'])>100||count($content['languages'])>1000)throw new InvalidArgumentException('CMS-Paket umfasst zu viele Inhalte.');
    }
    private static function assets(array $content,array $entries):void
    {
        $used=[];foreach($content['assets']as$asset){if(!is_array($asset)||!is_string($asset['sha256']??null)||!isset($entries['blobs/'.$asset['sha256']])||($asset['bytes']??null)!==$entries['blobs/'.$asset['sha256']]['bytes'])throw new InvalidArgumentException('CMS-Dateinachweise prüfen.');$used['blobs/'.$asset['sha256']]=true;}
        foreach($entries as$name=>$_)if($name!=='content.json'&&!isset($used[$name]))throw new InvalidArgumentException('Paket enthält nicht zugeordnete Dateibytes.');
    }
    private static function budget(array $entries,int $total):void
    {if(count($entries)<1||count($entries)>101||$total>self::BYTES_LIMIT)throw new InvalidArgumentException('CMS-Paketgrenzen überschritten.');}
    /** Bound central-directory allocation before libzip opens the untrusted archive. */
    private static function archiveDirectory(string $source,int $size):int
    {
        $handle=@fopen($source,'rb');if(!$handle)throw new InvalidArgumentException('CMS-Paket ist nicht lesbar.');
        try{
            $length=min($size,65557);if(fseek($handle,$size-$length)!==0)throw new InvalidArgumentException('Paketende ist nicht lesbar.');$tail=fread($handle,$length);if($tail===false||strlen($tail)!==$length)throw new InvalidArgumentException('Paketende ist unvollständig.');
            $offset=strrpos($tail,"PK\x05\x06");$end=null;
            while($offset!==false){if($offset+22<=strlen($tail)){$candidate=unpack('vdisk/vstart/ventries_disk/ventries/Vbytes/Voffset/vcomment',substr($tail,$offset+4,18));if($offset+22+$candidate['comment']===$length){$end=$candidate;break;}}$offset=$offset>0?strrpos(substr($tail,0,$offset),"PK\x05\x06"):false;}
            if($end===null||$end['disk']!==0||$end['start']!==0||$end['entries_disk']!==$end['entries']||$end['entries']<2||$end['entries']>102||$end['bytes']<92||$end['bytes']>1048576||$end['offset']<4||$end['offset']+$end['bytes']!==$size-$length+$offset)throw new InvalidArgumentException('Nur begrenzte, vollständige native ZIP-Pakete ohne ZIP64 oder Teilarchive sind erlaubt.');
            if(fseek($handle,$end['offset'])!==0)throw new InvalidArgumentException('Paketverzeichnis ist nicht lesbar.');$central=fread($handle,$end['bytes']);if($central===false||strlen($central)!==$end['bytes'])throw new InvalidArgumentException('Paketverzeichnis ist unvollständig.');
            $position=0;$count=0;
            while($position<strlen($central)){
                if(++$count>$end['entries']||substr($central,$position,4)!=="PK\x01\x02"||$position+46>strlen($central))throw new InvalidArgumentException('Paketverzeichnis enthält unerwartete Einträge.');
                $header=unpack('vname/vextra/vcomment/vdisk',substr($central,$position+28,8));$local=unpack('Voffset',substr($central,$position+42,4))['offset'];$next=$position+46+$header['name']+$header['extra']+$header['comment'];
                if($header['disk']!==0||$header['name']<1||$next>strlen($central)||$local>=$end['offset'])throw new InvalidArgumentException('Paketverzeichnis enthält ungültige Grenzen.');$position=$next;
            }
            if($count!==$end['entries'])throw new InvalidArgumentException('Anzahl der Paketeinträge stimmt nicht überein.');return $count;
        }finally{fclose($handle);}
    }
    private static function local(string $path,bool $exists):void
    {if($path===''||str_contains($path,"\0")||preg_match('~\A[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||($exists&&!is_file($path)))throw new InvalidArgumentException('Lokale unverknüpfte Paketdatei erforderlich.');}
    private static function json(array $value):string
    {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function stream(ZipArchive $zip,string $name,int $limit,?string $target):string
    {
        $in=$zip->getStream($name);if(!$in)throw new InvalidArgumentException('Paketeintrag nicht lesbar.');$out=null;$text='';$bytes=0;
        try{
            if($target!==null){$out=@fopen($target,'x+b');if(!$out)throw new RuntimeException('Privater Dateibereich nicht schreibbar.');chmod($target,0600);}
            while(!feof($in)){$part=fread($in,min(65536,$limit-$bytes+1));if($part===false||($part===''&&!feof($in)))throw new InvalidArgumentException('Paketeintrag beschädigt.');$bytes+=strlen($part);if($bytes>$limit)throw new InvalidArgumentException('Paketeintrag überschreitet die erlaubte Größe.');if($out!==null){if(fwrite($out,$part)!==strlen($part))throw new RuntimeException('Paketeintrag unvollständig geschrieben.');}else$text.=$part;}
            return $text;
        }finally{fclose($in);if($out!==null)fclose($out);}
    }
}
