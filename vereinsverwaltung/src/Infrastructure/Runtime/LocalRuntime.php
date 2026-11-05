<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/ProductionRuntime.php';

/** Explicit entry gate for the local synthetic club application. */
final class LocalRuntime
{
    public static function sharedServer():bool
    {
        return getenv('KULTURHUS_LOCAL_DEMO')==='1' && getenv('VEREIN_MODE')==='synthetic';
    }

    public static function nativeServer():bool
    {
        return getenv('KULTURHUS_LOCAL_DEMO')==='php' && getenv('VEREIN_MODE')==='synthetic';
    }

    public static function basePath():string
    {
        if(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active())return '/demo/vereinsverwaltung/';
        return self::sharedServer()||self::nativeServer()||ProductionRuntime::enabled()?'/vereinsverwaltung/':'/';
    }

    /**
     * Selects the explicitly configured synthetic MySQL database.
     * Request data never supplies the connection or enables demo mode.
     */
    public static function database():string
    {
        $configured=trim((string)getenv('KULTURHUS_DEMO_DATABASE'));
        if((getenv('KULTURHUS_LOCAL_DEMO')!=='1'&&!self::nativeServer())||!str_starts_with(strtolower($configured),'mysql:'))throw new \RuntimeException('Lokale MySQL-Demodatenbank ist nicht freigeschaltet.');
        return $configured;
    }

    public static function demo():bool
    {
        if(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active())return true;
        return getenv('KULTURHUS_DEMO_DATABASE')!==false && trim((string)getenv('KULTURHUS_DEMO_DATABASE'))!=='';
    }

    public static function acceptsRequest(string $sapi,array $server):bool
    {
        if(getenv('VEREIN_MODE')!=='synthetic')return false;
        if(self::sharedServer()){
            // This Apache instance is exposed only through Docker's loopback-bound proxy.
            // No client-supplied forwarded header enables this mode.
            $path=parse_url((string)($server['REQUEST_URI']??''),PHP_URL_PATH);
            return $sapi==='apache2handler'
                && in_array($server['HTTP_HOST']??'',['127.0.0.1:8092','localhost:8092'],true)
                && is_string($path) && str_starts_with($path,self::basePath());
        }
        if(self::nativeServer()){
            $path=parse_url((string)($server['REQUEST_URI']??''),PHP_URL_PATH);
            if(!is_string($path)||!str_starts_with($path,self::basePath()))return false;
        }
        $host=strtolower((string)($server['HTTP_HOST']??''));
        $host=preg_replace('/:\d+$/','',$host)??$host;
        if($host==='[::1]')$host='::1';
        return $sapi==='cli-server'
            && in_array($server['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
            && in_array($host,['127.0.0.1','localhost','::1'],true);
    }
}
