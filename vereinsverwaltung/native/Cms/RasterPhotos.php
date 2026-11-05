<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;

/** Prepares newly uploaded large rasters before the existing private media validator. */
final class RasterPhotos
{
    public const MAX_PIXELS=64000000;
    public const MAX_SIDE=12000;
    public const MAX_EDGE=1600;
    public const OUTPUT_BYTES=12*1024*1024;
    private const TYPES=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

    public static function withFile(string $path,callable $consume):mixed
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path))throw new InvalidArgumentException('Keine lokale Bilddatei.');
        $bytes=filesize($path);
        if($bytes===false||$bytes<1||$bytes>CameraPhotos::INPUT_BYTES)throw new InvalidArgumentException('Kamerafoto bis 256 MiB auswählen.');
        $header=@file_get_contents($path,false,null,0,4096);
        $mime=is_string($header)?(new \finfo(FILEINFO_MIME_TYPE))->buffer($header):false;
        $info=@getimagesize($path);
        // Unsupported input retains the canonical validator's existing rejection.
        if(!is_string($mime)||!isset(self::TYPES[$mime])||!$info||($info['mime']??null)!==$mime)return $consume($path);
        [$width,$height]=$info;
        if($width<1||$height<1||$width>self::MAX_SIDE||$height>self::MAX_SIDE||$width*$height>self::MAX_PIXELS)throw new InvalidArgumentException('Foto mit höchstens 64 Megapixeln und 12000 Pixeln je Kante auswählen.');
        $memory=self::memoryLimit();
        // Completed uploads leave reusable Zend pages behind. Return unused pages
        // before budgeting the next image; live data and the safety reserve stay intact.
        if($memory>0&&function_exists('gc_mem_caches'))gc_mem_caches();
        $estimated=$width*$height*8+min($width,2400)*min($height,2400)*8+16*1024*1024;
        if($bytes<=self::OUTPUT_BYTES&&$width*$height<=16000000&&($memory<=0||memory_get_usage(true)+$estimated<=$memory))return $consume($path);
        foreach(['proc_open','proc_get_status','proc_terminate','proc_close']as$function)if(!function_exists($function))throw new InvalidArgumentException('Fotoverarbeitung ist noch nicht eingerichtet.');
        $edge=self::MAX_EDGE;
        if($memory>0){
            // The unchanged canonical GD guard needs16MiB plus16bytes/pixel.
            // Reserve another2MiB for boundary work, then let that final guard recheck.
            $available=$memory-memory_get_usage(true)-18*1024*1024;
            if($available<=0)throw new InvalidArgumentException('Das Bild ist für das verfügbare Arbeitsspeicherlimit zu groß.');
            $edge=min($edge,(int)floor(sqrt($available/(16*(min($width,$height)/max($width,$height))))));
            if($edge<64)throw new InvalidArgumentException('Das Bild ist für das verfügbare Arbeitsspeicherlimit zu groß.');
        }
        $sourceHash=hash_file('sha256',$path);
        if(!is_string($sourceHash))throw new InvalidArgumentException('Lokale Fotodatei konnte nicht gelesen werden.');
        $directory=sys_get_temp_dir().'/dvm-raster-'.bin2hex(random_bytes(16));
        if(!mkdir($directory,0700))throw new InvalidArgumentException('Foto konnte nicht vorbereitet werden.');
        $output=$directory.'/prepared.'.self::TYPES[$mime];$log=$directory.'/decoder.log';$process=null;
        try{
            $node=PhotoRuntime::command();
            if(str_contains($node,"\0"))throw new InvalidArgumentException('Fotolaufzeit prüfen.');
            $process=@proc_open([$node,'--max-old-space-size=128','--wasm-max-mem-pages=8192',dirname(__DIR__,2).'/tools/media/raster-photo.mjs',$mime,$path,$output,$sourceHash,(string)$width,(string)$height,(string)$edge],[0=>['pipe','r'],1=>['file',$log,'wb'],2=>['file',$log,'ab']],$pipes);
            if(!is_resource($process))throw new InvalidArgumentException('Fotoverarbeitung ist noch nicht eingerichtet.');
            fclose($pipes[0]);$started=hrtime(true);
            do{
                $status=proc_get_status($process);if(!$status['running'])break;
                if(hrtime(true)-$started>45000000000){proc_terminate($process,9);throw new InvalidArgumentException('Fotoverarbeitung dauerte zu lange.');}
                usleep(10000);
            }while(true);
            $exit=$status['exitcode'];$closed=proc_close($process);$process=null;
            if(in_array($exit,[126,127],true)||in_array($closed,[126,127],true))throw new InvalidArgumentException('Fotoverarbeitung ist noch nicht eingerichtet.');
            if(($exit!==0&&$closed!==0)||is_link($output)||!is_file($output)||filesize($output)<1||filesize($output)>self::OUTPUT_BYTES)throw new InvalidArgumentException('Bilddaten konnten nicht vollständig decodiert werden.');
            $prepared=@getimagesize($output);
            if(!$prepared||$prepared['mime']!==$mime||$prepared[0]<1||$prepared[1]<1||max($prepared[0],$prepared[1])>$edge)throw new InvalidArgumentException('Vorbereitetes Foto prüfen.');
            if(hash_file('sha256',$path)!==$sourceHash)throw new InvalidArgumentException('Fotodatei wurde während der Verarbeitung geändert. Erneut auswählen.');
            return $consume($output);
        }finally{
            if(is_resource($process)){proc_terminate($process,9);proc_close($process);}
            foreach([$output,$log]as$file)if(!is_link($file)&&is_file($file))@unlink($file);
            @rmdir($directory);
        }
    }

    private static function memoryLimit():int
    {
        $value=trim((string)ini_get('memory_limit'));if($value===''||$value==='-1')return -1;
        return (int)$value*match(strtolower(substr($value,-1))){'g'=>1024*1024*1024,'m'=>1024*1024,'k'=>1024,default=>1};
    }
}
