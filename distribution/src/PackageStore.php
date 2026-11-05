<?php declare(strict_types=1);

/** Offline, integrity checked distribution. No downloader, shell extraction or credential handling. */
final class PackageStore
{
    public function __construct(private string $root) {}
    public static function relative(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, ':') || str_starts_with($path, '/') || str_contains($path,'//') || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) throw new RuntimeException('Unsicherer Paketpfad.');
        return $path;
    }
    public static function json(string $file): array
    {
        $data=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Paketmanifest unvollständig.');return $data;
    }
    public static function check(string $path,array $expected): void
    {
        self::noLinks($path);
        if(!is_file($path))throw new RuntimeException('Datei fehlt oder wurde verändert: '.basename($path));
        if(($expected['normalized_lf']??false)===true){$content=file_get_contents($path);if(!is_string($content))throw new RuntimeException('Quelldatei nicht lesbar.');$content=str_replace("\r\n","\n",$content);$bytes=strlen($content);$hash=hash('sha256',$content);}
        else{$bytes=filesize($path);$hash=hash_file('sha256',$path);}
        if($bytes!==(int)$expected['bytes']||!hash_equals($expected['sha256'],$hash))throw new RuntimeException('Datei fehlt oder wurde verändert: '.basename($path));
    }
    public function verify(): array
    {
        $php=self::json($this->root.'/packages/php-runtime/manifest.json');$linux=self::json($this->root.'/packages/linux-runtime/manifest.json');
        $source=self::json($this->root.'/source-files.json');$bytes=0;$count=0;
        foreach(array_merge($source['files'],$source['installer_files']??[]) as $entry){self::check(dirname($this->root).'/'.self::relative($entry['path']),$entry);$bytes+=$entry['bytes'];$count++;}
        foreach($php['components'] as $entry){
            $base=$this->root.'/packages/php-runtime/';self::check($base.self::relative($entry['archive']??$entry['file']),$entry);$bytes+=$entry['bytes'];$count++;
            if(isset($entry['file_manifest'])&&!hash_equals($entry['file_manifest_sha256'],hash_file('sha256',$base.self::relative($entry['file_manifest']))))throw new RuntimeException('Dateimanifest verändert.');
        }
        foreach(array_merge($linux['components'],$linux['sources']??[]) as $entry){
            $hash=hash_init('sha256');$total=0;
            foreach($entry['parts'] as $part){$file=$this->root.'/packages/linux-runtime/'.self::relative($part['file']);self::check($file,$part);hash_update_file($hash,$file);$total+=$part['bytes'];$count++;}
            if($total!==$entry['bytes']||!hash_equals($entry['sha256'],hash_final($hash)))throw new RuntimeException('Paketbestandteile passen nicht zusammen.');$bytes+=$total;
        }
        return ['files'=>$count,'bytes'=>$bytes,'php'=>$php,'linux'=>$linux,'source'=>$source];
    }
    public function materialize(array $component,string $temporary): string
    {
        $file=$temporary.'/'.preg_replace('/[^a-zA-Z0-9_.-]/','_', $component['id']).'.'.match($component['install']['type']){'tar.gz'=>'tar.gz','zip'=>'zip',default=>'bin'};
        $output=fopen($file,'xb');if(!$output)throw new RuntimeException('Paketablage nicht verfügbar.');
        try{foreach($component['parts'] as $part){$input=fopen($this->root.'/packages/linux-runtime/'.self::relative($part['file']),'rb');try{if(stream_copy_to_stream($input,$output)!==$part['bytes'])throw new RuntimeException('Paket konnte nicht vollständig kopiert werden.');}finally{fclose($input);}}}finally{fclose($output);}
        self::check($file,$component);return $file;
    }
    public static function directory(string $path,int $mode=0700): void
    {
        self::noLinks($path);
        if(!is_dir($path)&&!mkdir($path,$mode,true))throw new RuntimeException('Installationsverzeichnis nicht schreibbar.');
    }
    private static function noLinks(string $path): void
    {
        foreach(self::ancestors($path) as $part){
            if(is_link($part))throw new RuntimeException('Symbolische Links sind als Installationsziel nicht zulässig.');
        }
    }
    /** PHP guards configured roots itself; never probe their inaccessible ancestors. */
    private static function ancestors(string $path): iterable
    {
        $restriction=(string)ini_get('open_basedir');
        $roots=$restriction===''?[]:array_map(self::absolutePath(...),explode(PATH_SEPARATOR,$restriction));
        $first=true;
        for($part=$path;;$part=dirname($part)){
            if($roots){
                $absolute=self::absolutePath($part);$allowed=false;
                foreach($roots as $root)if($absolute===$root||str_starts_with($absolute,rtrim($root,'/').'/')){$allowed=true;break;}
                if(!$allowed){if($first)throw new RuntimeException('Paketpfad liegt außerhalb der freigegebenen Hosting-Verzeichnisse.');break;}
            }
            yield $part;$first=false;
            if($part===dirname($part))break;
        }
    }
    /** Lexical normalization only: filesystem checks outside open_basedir are forbidden. */
    private static function absolutePath(string $path): string
    {
        $path=str_replace('\\','/',$path);
        if(!str_starts_with($path,'/')&&!preg_match('~\A[A-Za-z]:/~',$path))$path=str_replace('\\','/',(string)getcwd()).'/'.$path;
        $prefix='/';
        if(preg_match('~\A([A-Za-z]:)/~',$path,$drive)){$prefix=strtolower($drive[1]).'/';$path=substr($path,3);}
        $parts=[];
        foreach(explode('/',$path)as$part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
        $result=rtrim($prefix.implode('/',$parts),'/');
        return DIRECTORY_SEPARATOR==='\\'?strtolower($result):($result===''?'/':$result);
    }
    public static function writeNew(string $file,string $bytes,int $mode=0600): void
    {
        self::directory(dirname($file));$stream=fopen($file,'xb');if(!$stream)throw new RuntimeException('Vorhandene Datei wird nicht überschrieben.');
        try{if(fwrite($stream,$bytes)!==strlen($bytes))throw new RuntimeException('Datei unvollständig geschrieben.');}finally{fclose($stream);}chmod($file,$mode);
    }
    /** Inspect every archive member before extracting; no links, devices, traversal or overwrite. */
    public static function extract(string $archive,string $target,string $type='tar.gz',int $strip=0): void
    {
        if($type==='file'){self::writeNew($target,(string)file_get_contents($archive));return;}
        self::directory($target);$members=[];$total=0;
        if($type==='zip'){
            $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('ZIP-Paket nicht lesbar.');
            try{
                if($zip->numFiles>100000)throw new RuntimeException('Paket hat zu viele Einträge.');
                for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);if(!is_array($stat))throw new RuntimeException('ZIP-Eintrag nicht lesbar.');$name=$stat['name'];$zip->getExternalAttributesIndex($i,$os,$attr);$mode=($attr>>16)&0xffff;$kind=$mode&0170000;if(!in_array($kind,[0,0100000,0040000],true))throw new RuntimeException('Links und Spezialdateien im Paket sind nicht erlaubt.');$members[]=[$name,(int)$stat['size'],$mode,str_ends_with($name,'/')||$kind===0040000,$i];}
                self::members($members,$strip);self::targets($target,$members);
                foreach($members as [$name,$size,$mode,$dir,$i]){if($name==='')continue;$dest=$target.'/'.$name;if($dir){self::directory($dest,0755);continue;}self::directory(dirname($dest),0755);$in=$zip->getStream($zip->getNameIndex($i));self::streamFile($in,$dest,$size,$mode);}
            }finally{$zip->close();}
        }elseif($type==='tar.gz'){
            // PharData truncates POSIX PAX long paths on supported PHP versions.
            // Inspect the gzip TAR stream ourselves, including extended path/size records.
            $members=self::tarMembers($archive);
            self::members($members,$strip);self::targets($target,$members);
            $input=gzopen($archive,'rb');if(!$input)throw new RuntimeException('TAR-Paket nicht lesbar.');
            try{foreach($members as [$name,$size,$mode,$dir,$offset]){if($name==='')continue;$dest=$target.'/'.$name;if($dir){self::directory($dest,0755);continue;}self::directory(dirname($dest),0755);if(gzseek($input,$offset)!==0)throw new RuntimeException('TAR-Dateiposition nicht lesbar.');self::streamFile($input,$dest,$size,$mode,false);}}finally{gzclose($input);}
        }else throw new RuntimeException('Unbekanntes Paketformat.');
    }
    private static function members(array &$members,int $strip): void
    {
        $seen=[];$total=0;if($strip<0||$strip>64||count($members)>100000)throw new RuntimeException('Paket hat zu viele Einträge oder einen ungültigen Präfix.');
        foreach($members as &$entry){$name=rtrim($entry[0],'/');if(str_starts_with($name,'./'))$name=substr($name,2);if($name===''){$entry[0]='';continue;}self::relative($name);$name=implode('/',array_slice(explode('/',$name),$strip));$entry[0]=$name;if($name==='')continue;if(isset($seen[$name]))throw new RuntimeException('Doppelter Archiveintrag.');$seen[$name]=$entry[3]?'directory':'file';if($entry[1]<0||$entry[1]>3000000000)throw new RuntimeException('Ungültige Paketdateigröße.');$total+=$entry[3]?0:$entry[1];if($total>3000000000)throw new RuntimeException('Paket überschreitet die Entpackgrenze.');}
        unset($entry);
        foreach($seen as $name=>$kind){for($parent=dirname($name);$parent!=='.';$parent=dirname($parent)){if(($seen[$parent]??null)==='file')throw new RuntimeException('Paket verwendet eine Datei gleichzeitig als Verzeichnis.');}}
    }
    /** Check conflicts against the entire destination before any member is written. */
    private static function targets(string $target,array $members): void
    {
        foreach($members as [$name,$size,$mode,$directory]){
            if($name==='')continue;$path=$target.'/'.$name;self::noLinks($path);
            if(file_exists($path)&&(!$directory||!is_dir($path)))throw new RuntimeException('Paket würde eine vorhandene Datei überschreiben.');
            foreach(self::ancestors(dirname($path))as$parent)if(file_exists($parent)&&!is_dir($parent))throw new RuntimeException('Paketverzeichnis ist durch eine vorhandene Datei belegt.');
        }
    }
    /** Two-pass extraction: resolve all PAX paths before creating any member. */
    private static function tarMembers(string $archive):array
    {
        $input=gzopen($archive,'rb');if(!$input)throw new RuntimeException('TAR-Paket nicht lesbar.');
        $members=[];$global=[];$pending=[];$total=0;$headers=0;$metadata=0;
        try{while(true){
            if(++$headers>200000)throw new RuntimeException('Zu viele TAR-Kopfeinträge.');
            $header=self::tarRead($input,512);
            if($header===str_repeat("\0",512)){
                if(self::tarRead($input,512)!==str_repeat("\0",512))throw new RuntimeException('TAR-Abschluss unvollständig.');
                while(!gzeof($input)){$tail=gzread($input,65536);if($tail===false||trim($tail,"\0")!=='')throw new RuntimeException('Unerwartete Daten nach TAR-Abschluss.');}
                if($pending)throw new RuntimeException('TAR-Metadaten ohne Dateieintrag.');
                return $members;
            }
            $sum=array_sum(unpack('C*',substr_replace($header,str_repeat(' ',8),148,8)));
            if(self::tarNumber(substr($header,148,8))!==$sum)throw new RuntimeException('TAR-Kopfprüfsumme stimmt nicht.');
            $name=rtrim(substr($header,0,100),"\0");$prefix=rtrim(substr($header,345,155),"\0");
            if($prefix!=='')$name=$prefix.'/'.$name;
            $kind=$header[156];$size=self::tarNumber(substr($header,124,12));$mode=self::tarNumber(substr($header,100,8));
            if(in_array($kind,['x','g','L'],true)){
                if($size>1048576)throw new RuntimeException('TAR-Metadaten überschreiten die Grenze.');
                $metadata+=$size;if($metadata>16777216)throw new RuntimeException('Zu viele TAR-Metadaten.');
                $data=self::tarRead($input,$size);self::tarRead($input,(512-$size%512)%512);
                if($kind==='L')$pending['path']=rtrim($data,"\0");
                elseif($kind==='g')$global=array_replace($global,self::pax($data));
                else $pending=array_replace($pending,self::pax($data));
                continue;
            }
            if(!in_array($kind,["\0",'0','5'],true))throw new RuntimeException('Links und Spezialdateien im Paket sind nicht erlaubt.');
            $extended=array_replace($global,$pending);$pending=[];
            $name=$extended['path']??$name;
            if(isset($extended['size'])){if(!preg_match('/\A[0-9]{1,10}\z/D',$extended['size']))throw new RuntimeException('Ungültige PAX-Dateigröße.');$size=(int)$extended['size'];}
            $directory=$kind==='5';
            if($size>3000000000||($directory&&$size!==0)||count($members)>=100000)throw new RuntimeException('TAR-Paket überschreitet die Grenzen.');
            $total+=$size;if($total>3000000000)throw new RuntimeException('TAR-Paket überschreitet die Entpackgrenze.');
            $members[]=[$name,$size,$mode,$directory,gztell($input)];
            $remaining=$size+(512-$size%512)%512;
            while($remaining>0){$amount=min($remaining,65536);self::tarRead($input,$amount);$remaining-=$amount;}
        }}finally{gzclose($input);}
    }
    private static function tarRead($input,int $bytes):string
    {
        $data='';while(strlen($data)<$bytes){$part=gzread($input,$bytes-strlen($data));if($part===false||$part==='')throw new RuntimeException('TAR-Paket abgeschnitten.');$data.=$part;}return $data;
    }
    private static function tarNumber(string $field):int
    {
        if((ord($field[0])&128)!==0){
            // Positive GNU base-256 sizes; refuse negative or overflowing values.
            if(ord($field[0])!==128)throw new RuntimeException('Ungültige TAR-Zahl.');
            $value=0;foreach(unpack('C*',substr($field,1)) as $byte){if($value>intdiv(PHP_INT_MAX-$byte,256))throw new RuntimeException('TAR-Zahl zu groß.');$value=$value*256+$byte;}return $value;
        }
        $field=trim($field," \0");if($field==='')return 0;
        if(!preg_match('/\A[0-7]+\z/D',$field)||strlen($field)>20)throw new RuntimeException('Ungültige TAR-Zahl.');
        $value=0;foreach(str_split($field)as$digit){if($value>intdiv(PHP_INT_MAX-(int)$digit,8))throw new RuntimeException('TAR-Zahl zu groß.');$value=$value*8+(int)$digit;}return $value;
    }
    private static function pax(string $data):array
    {
        $values=[];$offset=0;
        while($offset<strlen($data)){
            if(!preg_match('/\A([0-9]{1,7}) /',substr($data,$offset),$match))throw new RuntimeException('PAX-Metadaten ungültig.');
            $length=(int)$match[1];$prefix=strlen($match[0]);
            if($length<=$prefix+2||$offset+$length>strlen($data))throw new RuntimeException('PAX-Metadaten abgeschnitten.');
            $record=substr($data,$offset+$prefix,$length-$prefix);$offset+=$length;
            if(!str_ends_with($record,"\n")||!str_contains($record,'='))throw new RuntimeException('PAX-Datensatz ungültig.');
            [$key,$value]=explode('=',substr($record,0,-1),2);
            if(in_array($key,['path','size'],true))$values[$key]=$value;
        }
        return $values;
    }
    private static function streamFile($input,string $destination,int $size,int $mode,bool $close=true): void
    {
        if(!is_resource($input))throw new RuntimeException('Paketdatei nicht lesbar.');$output=fopen($destination,'xb');if(!$output){if($close)fclose($input);throw new RuntimeException('Paket würde eine vorhandene Datei überschreiben.');}
        try{if(stream_copy_to_stream($input,$output,$close?$size+1:$size)!==$size)throw new RuntimeException('Paketdatei unvollständig oder größer als angegeben.');}finally{if($close)fclose($input);fclose($output);}chmod($destination,($mode&0111)?0755:0644);
    }
}
