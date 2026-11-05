<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** One bounded byte range of an already authenticated immutable private MP4. */
final class VideoResponse
{
    public static function range(int $bytes,mixed $header): array
    {
        if($bytes<1)throw new \DomainException('Videodatei fehlt.');
        if($header===null||$header==='')return [0,$bytes-1,200];
        if(!is_string($header)||strlen($header)>100||!preg_match('/^bytes=([0-9]{0,18})-([0-9]{0,18})$/D',$header,$match)||($match[1]===''&&$match[2]===''))throw new \DomainException('Videobereich prüfen.');
        if($match[1]===''){
            $amount=(int)$match[2];if($amount<1)throw new \DomainException('Videobereich prüfen.');
            return [max(0,$bytes-$amount),$bytes-1,206];
        }
        $start=(int)$match[1];$end=$match[2]===''?$bytes-1:min($bytes-1,(int)$match[2]);
        if($start>=$bytes||$end<$start)throw new \DomainException('Videobereich prüfen.');
        return [$start,$end,206];
    }

    public static function serve(array $file,string $method,mixed $range): void
    {
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Accept-Ranges: bytes');
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
        try {[$start,$end,$status]=self::range($file['bytes'],$range);}
        catch(\DomainException){http_response_code(416);header('Content-Range: bytes */'.$file['bytes']);header('Content-Length: 0');return;}
        $handle=@fopen($file['path'],'rb');
        if(!$handle||fseek($handle,$start)!==0){if(is_resource($handle))fclose($handle);throw new \DomainException('Videodatei ist nicht lesbar.');}
        try {
            http_response_code($status);header('Content-Type: video/mp4');header('Content-Length: '.($end-$start+1));
            header('Content-Disposition: inline; filename="reel.mp4"');header('ETag: "'.$file['sha256'].'"');
            if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$file['bytes']);
            if($method==='HEAD')return;
            $remaining=$end-$start+1;
            while($remaining>0&&!connection_aborted()){
                $chunk=fread($handle,min(65536,$remaining));
                if($chunk===false||$chunk==='')throw new \DomainException('Videodatei ist nicht vollständig lesbar.');
                echo $chunk;$remaining-=strlen($chunk);
            }
        } finally {fclose($handle);}
    }
}
