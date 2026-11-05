<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;

use DomainException;
use RuntimeException;
use Kulturhus\Native\Communication\Content;

/** One credential-free Eventbrite CDN, pinned public DNS addresses and bounded TLS bytes. */
final class ImageDownload
{
    public const MAX_BYTES=8388608;
    public static function url(mixed $value):string
    {
        $url=Content::url($value);$parts=parse_url($url);
        if(($parts['host']??'')!=='img.evbuc.com'||(isset($parts['port'])&&$parts['port']!==443)||isset($parts['fragment']))throw new DomainException('Eventbrite-Bildquelle prüfen.');
        return $url;
    }
    public static function publicIp(mixed $ip):bool
    {
        return is_string($ip)&&!str_starts_with(strtolower($ip),'::ffff:')&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)!==false;
    }
    public static function receive(string $url,string $destination,int $seconds=20):void
    {
        \Kulturhus\Native\Demo\Network::assertAllowed();
        $url=self::url($url);
        if($seconds<1||$seconds>20)throw new DomainException('Zeitgrenze des Bildabrufs prüfen.');
        if(getenv('KH_NATIVE_CI')==='1'||getenv('KULTURHUS_DISABLE_MAIL')==='1'||(getenv('KULTURHUS_LOCAL_DEMO')!==false&&getenv('KULTURHUS_LOCAL_DEMO')!==''))throw new DomainException('Externe Bildabrufe in der Testumgebung gesperrt.');
        if(!function_exists('curl_init'))throw new DomainException('PHP-cURL für Anbieterbilder erforderlich.');
        $records=dns_get_record('img.evbuc.com',DNS_A|DNS_AAAA);$ips=[];
        if(!is_array($records)||count($records)>32)throw new RuntimeException('Eventbrite-Bildquelle nicht verfügbar.');
        foreach($records as$record){$ip=$record['ip']??($record['ipv6']??null);if($ip===null)continue;if(!self::publicIp($ip))throw new DomainException('Öffentliche Bildquelle erforderlich.');$ips[]=$ip;}
        $ips=array_values(array_unique($ips));if(!$ips)throw new RuntimeException('Eventbrite-Bildquelle nicht verfügbar.');
        if(is_link($destination)||!is_file($destination))throw new DomainException('Privates Bildziel prüfen.');
        $file=fopen($destination,'wb');if($file===false)throw new RuntimeException('Privates Bildziel nicht verfügbar.');
        $bytes=0;$headers=0;$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>min(5,$seconds),CURLOPT_TIMEOUT=>$seconds,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>['img.evbuc.com:443:'.implode(',',array_map(static fn($ip)=>str_contains($ip,':')?'['.$ip.']':$ip,$ips))],CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['Accept: image/jpeg, image/png, image/webp'],CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use($file,&$bytes):int{$length=strlen($chunk);if($bytes+$length>self::MAX_BYTES)return 0;$written=fwrite($file,$chunk);if($written===false)return 0;$bytes+=$written;return $written;},CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers):int{$headers+=strlen($line);return $headers>32768?0:strlen($line);}]);
        try{
            if(curl_exec($curl)===false||curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200||$bytes<1)throw new RuntimeException('Eventbrite-Bild konnte nicht geladen werden.');
            $mime=strtolower(trim(explode(';',(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE))[0]));
            if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new DomainException('Eventbrite liefert kein unterstütztes Bild.');
        }finally{curl_close($curl);fclose($file);}
    }
}
