<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;

/** Local bundled camera codecs; normal JPEG/PNG/WebP uploads stay on their original path. */
final class CameraPhotos
{
    public const RAW_EXTENSIONS=['dng','cr2','cr3','crw','nef','nrw','arw','sr2','srf','rw2','rwl','orf','raf','pef','ptx','raw','iiq','3fr','fff','kdc','dcr','mos','mef','mrw','erf'];
    public const INPUT_BYTES=256*1024*1024;
    public const BATCH_INPUT_BYTES=512*1024*1024;
    public const PREPARED_BATCH_BYTES=40*1024*1024;
    public static function sourceKind(string $path,string $name):?string
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path))throw new InvalidArgumentException('Lokale Fotodatei auswählen.');
        $header=@file_get_contents($path,false,null,0,4096);if(!is_string($header))throw new InvalidArgumentException('Lokale Fotodatei konnte nicht gelesen werden.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($header);
        if(in_array($mime,['image/jpeg','image/png','image/webp'],true))return null;
        $ext=strtolower(pathinfo(basename(str_replace('\\','/',$name)),PATHINFO_EXTENSION));
        return in_array($mime,['image/heic','image/heif'],true)?'heif':(in_array($ext,self::RAW_EXTENSIONS,true)||self::dngHeader($header)?'raw':null);
    }
    /** A bounded TIFF IFD probe also recognizes a real DNG when iOS omits the filename extension. */
    private static function dngHeader(string $header):bool
    {
        if(strlen($header)<8)return false;
        $little=substr($header,0,2)==='II';if(!$little&&substr($header,0,2)!=='MM')return false;
        $u16=static fn(int $offset):int=>unpack($little?'v':'n',substr($header,$offset,2))[1];
        $u32=static fn(int $offset):int=>unpack($little?'V':'N',substr($header,$offset,4))[1];
        if($u16(2)!==42)return false;$offset=$u32(4);$length=strlen($header);
        if($offset<8||$offset>$length-2)return false;$count=min($u16($offset),128);
        for($i=0;$i<$count;$i++){
            $entry=$offset+2+$i*12;if($entry>$length-12)break;
            if($u16($entry)===50706&&$u16($entry+2)===1&&$u32($entry+4)===4&&ord($header[$entry+8])===1)return true;
        }
        return false;
    }
    public static function withFile(string $path,string $name,callable $consume):mixed
    {
        $kind=self::sourceKind($path,$name);if($kind===null)return $consume($path);
        $bytes=filesize($path);if($bytes===false||$bytes<8||$bytes>self::INPUT_BYTES)throw new InvalidArgumentException('Kamerafoto bis 256 MiB auswählen.');
        foreach(['proc_open','proc_get_status','proc_terminate','proc_close']as$function)if(!function_exists($function))throw new InvalidArgumentException('Fotoverarbeitung ist noch nicht eingerichtet.');
        $directory=sys_get_temp_dir().'/dvm-camera-'.bin2hex(random_bytes(16));if(!mkdir($directory,0700))throw new InvalidArgumentException('Foto konnte nicht vorbereitet werden.');
        $png=$directory.'/decoded.png';$jpeg=$directory.'/prepared.jpg';$log=$directory.'/decoder.log';$process=null;
        try{
            $node=PhotoRuntime::command();if(str_contains($node,"\0"))throw new InvalidArgumentException('Fotolaufzeit prüfen.');
            $script=dirname(__DIR__,2).'/tools/media/camera-photo.mjs';
            $process=@proc_open([$node,'--max-old-space-size=128',$script,$kind,$path,$png],[0=>['pipe','r'],1=>['file',$log,'wb'],2=>['file',$log,'ab']],$pipes);
            if(!is_resource($process))throw new InvalidArgumentException('Fotoverarbeitung ist noch nicht eingerichtet.');fclose($pipes[0]);$started=hrtime(true);$status=null;
            do{$status=proc_get_status($process);if(!$status['running'])break;if(hrtime(true)-$started>45000000000){proc_terminate($process,9);throw new InvalidArgumentException('Fotoverarbeitung dauerte zu lange.');}usleep(10000);}while(true);
            $exit=$status['exitcode'];$closed=proc_close($process);$process=null;
            if(($exit!==0&&$closed!==0)||is_link($png)||!is_file($png)||filesize($png)>12*1024*1024)throw new InvalidArgumentException('Kamerafoto konnte nicht sicher umgewandelt werden.');
            $info=@getimagesize($png);if(!$info||$info['mime']!=='image/png'||$info[0]<1||$info[1]<1||max($info[0],$info[1])>1600)throw new InvalidArgumentException('Vorbereitetes Foto prüfen.');
            $image=@imagecreatefrompng($png);if(!$image)throw new InvalidArgumentException('Vorbereitetes Foto prüfen.');
            try{if(!imagejpeg($image,$jpeg,88))throw new InvalidArgumentException('Foto konnte nicht vorbereitet werden.');chmod($jpeg,0600);}finally{imagedestroy($image);}
            return $consume($jpeg);
        }finally{
            if(is_resource($process)){proc_terminate($process,9);proc_close($process);}
            foreach([$png,$jpeg,$log]as$file)if(!is_link($file)&&is_file($file))@unlink($file);@rmdir($directory);
        }
    }
}
