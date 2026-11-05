<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
/** Injected only by the strictly owned loopback library fixture; a provider write is never accepted. */
final class JsonHttp
{
    public static function request(string $provider,string $method,string $path,array $headers=[],?array $body=null,?string $encoded=null):array
    {
        $cfg=\Kulturhus\Native\TestSupport::config();
        if(PHP_SAPI!=='cli-server'||($cfg['label']??'')!=='native-social-library'||($cfg['owner']??'')!=='isolated-native-ci'||$provider!=='meta'||$method!=='GET'||$headers!==['Authorization: Bearer synthetic-cache-token']||$body!==null||$encoded!==null)throw new \RuntimeException('Owned fake Meta reads only.');
        $f=json_decode(file_get_contents(getenv('DVM_SOCIAL_LIBRARY_FIXTURE')),true,64,JSON_THROW_ON_ERROR);$key=parse_url($path,PHP_URL_PATH);parse_str(parse_url($path,PHP_URL_QUERY)??'',$parameters);if(isset($parameters['platform']))$key.='?platform='.$parameters['platform'];if(!is_string($key)||!array_key_exists($key,$f['provider']))throw new \RuntimeException('Unexpected owned Meta read.');
        \Kulturhus\Native\Shared\Db::run(\Kulturhus\Native\TestSupport::db(),'INSERT INTO own_library_provider_calls(method,path) VALUES(?,?)',[$method,$path]);
        if(($_SERVER['HTTP_X_OWN_LIBRARY_FAILURE']??null)==='cooldown')return ['status'=>429,'headers'=>['retry-after'=>'1800'],'body'=>[]];
        if(($_SERVER['HTTP_X_OWN_LIBRARY_FAILURE']??null)==='once')throw new \RuntimeException('Own provider read acknowledgement lost.');
        return ['status'=>200,'headers'=>[],'body'=>$f['provider'][$key]];
    }
}
