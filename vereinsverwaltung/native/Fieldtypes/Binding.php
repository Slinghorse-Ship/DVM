<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** Stored declarative identity; it never contains a PHP class, path or callback. */
final class Binding
{
    public static function name(mixed $name):string
    {
        if(!is_string($name)||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$name))throw new \InvalidArgumentException('Lokale Typkennung prüfen.');
        return $name;
    }
    public static function version(mixed $version):string
    {
        if(!is_string($version)||strlen($version)>64||!preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[a-zA-Z0-9][a-zA-Z0-9.-]{0,31})?\z/D',$version))throw new \InvalidArgumentException('Feste Typversion prüfen.');
        return $version;
    }
    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||count($input)!==4||array_diff(array_keys($input),['owner','name','version','source_hash']))throw new \InvalidArgumentException('Geschlossene Typbindung prüfen.');
        if(!is_string($input['source_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$input['source_hash']))throw new \InvalidArgumentException('Geprüfte Typquelle fehlt.');
        return ['owner'=>self::name($input['owner']??null),'name'=>self::name($input['name']??null),'version'=>self::version($input['version']??null),'source_hash'=>$input['source_hash']];
    }
    public static function token(array $binding):string
    {return rtrim(strtr(base64_encode(json_encode(self::normalize($binding),JSON_THROW_ON_ERROR)),'+/','-_'),'=');}
    public static function fromToken(mixed $token):array
    {
        if(!is_string($token)||strlen($token)>512||!preg_match('/\A[a-zA-Z0-9_-]+\z/D',$token))throw new \InvalidArgumentException('Gespeicherte Typauswahl prüfen.');
        $json=base64_decode(strtr($token,'-_','+/'),true);if($json===false)throw new \InvalidArgumentException('Gespeicherte Typauswahl prüfen.');try{$binding=self::normalize(json_decode($json,true,8,JSON_THROW_ON_ERROR));}catch(\JsonException){throw new \InvalidArgumentException('Gespeicherte Typauswahl prüfen.');}if(self::token($binding)!==$token)throw new \InvalidArgumentException('Kanonische Typauswahl erforderlich.');return $binding;
    }
}
