<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Configuration is supplied by a private server-side entry point, never request data. */
final class ProductionRuntime
{
    public static function enabled():bool{return getenv('VEREIN_MODE')==='production';}
    /** A private deployment decision; request parameters never select the provider. */
    public static function nativeManager():bool{return getenv('KH_NATIVE_MANAGER')==='1'&&getenv('VEREIN_AUTH')==='native';}
    /** Exact installation host names, never inferred from an untrusted HTTP header. */
    public static function allowedHosts(?string $configured=null):array
    {
        if($configured===null){
            $value=getenv('VEREIN_ALLOWED_HOSTS');
            // Compatibility for the existing installation only. Fresh installers always set an explicit list.
            if($value===false)return [];
            $configured=$value;
        }
        if($configured===''||strlen($configured)>4096)return [];
        $hosts=explode(',',$configured);if(count($hosts)>16)return [];
        $valid=[];
        foreach($hosts as $host){
            $host=strtolower(trim($host));
            if(strlen($host)>253||!str_contains($host,'.')||str_ends_with($host,'.')||!preg_match('/^[a-z0-9.-]+$/D',$host)
                ||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false||filter_var($host,FILTER_VALIDATE_IP)!==false)return [];
            $valid[$host]=true;
        }
        return array_keys($valid);
    }
    public static function acceptsRequest(array $server):bool
    {
        return self::enabled() && self::nativeManager()
            && in_array(getenv('KULTURHUS_LOCAL_DEMO'),[false,'','0'],true)
            && is_string($server['HTTP_HOST']??null)
            && in_array(strtolower($server['HTTP_HOST']),self::allowedHosts(),true)
            && in_array($server['HTTPS']??'', ['on','1'],true)
            && str_starts_with((string)parse_url($server['REQUEST_URI']??'',PHP_URL_PATH),'/vereinsverwaltung/');
    }
    public static function database():string
    {
        $path=trim((string)getenv('VEREIN_DATABASE'));
        if(str_starts_with(strtolower($path),'mysql:')){
            if(preg_match('/[\x00\r\n]/',$path)||!str_contains(strtolower($path),'dbname='))throw new \RuntimeException('Private Vereinsdatenbank nicht verfügbar.');
            return $path;
        }
        throw new \RuntimeException('MySQL-Datenbank muss privat konfiguriert sein.');
    }
    public static function sessionDirectory():string
    {
        $root=trim((string)(getenv('VEREIN_SESSION_ROOT')?:getenv('VEREIN_PRIVATE_ROOT')));
        if($root===''||preg_match('/[\x00\r\n]/',$root)||!str_starts_with($root,'/'))throw new \RuntimeException('Privater Sitzungsspeicher muss ausdrücklich konfiguriert sein.');
        return rtrim($root,'/').'/sessions';
    }
    public static function club():string{return self::enabled()?(string)getenv('VEREIN_CLUB'):'kulturhus-demo';}
}
