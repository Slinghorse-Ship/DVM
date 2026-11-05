<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;

/** Immutable identity; no source path, class name or executable configuration enters a template. */
final class Binding
{
    public static function name(mixed $name):string
    {
        if(!is_string($name)||!preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D',$name))throw new \InvalidArgumentException('Geprüfte Rendererkennung auswählen.');
        return $name;
    }
    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||count($input)!==4||array_diff(array_keys($input),['owner','name','version','source_hash']))throw new \InvalidArgumentException('Vollständige Rendererbindung erforderlich.');
        $version=$input['version']??null;$hash=$input['source_hash']??null;
        if(!is_string($version)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\z/D',$version)||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new \InvalidArgumentException('Feste Rendererfassung und geprüfte Quelle erforderlich.');
        return ['owner'=>self::name($input['owner']??null),'name'=>self::name($input['name']??null),'version'=>$version,'source_hash'=>$hash];
    }
    public static function key(array $binding):string{$binding=self::normalize($binding);return $binding['owner'].':'.$binding['name'];}
    public static function token(array $binding):string{return rtrim(strtr(base64_encode(json_encode(self::normalize($binding),JSON_THROW_ON_ERROR)),'+/','-_'),'=');}
    public static function fromToken(mixed $value):?array
    {
        if($value==='')return null;
        if(!is_string($value)||strlen($value)>512||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$value))throw new \InvalidArgumentException('Eine registrierte Frontenddarstellung auswählen.');
        try{$json=base64_decode(strtr($value,'-_','+/'),true);$binding=self::normalize(json_decode($json?:'',true,8,JSON_THROW_ON_ERROR));if(self::token($binding)!==$value)throw new \InvalidArgumentException();return $binding;}catch(\Throwable){throw new \InvalidArgumentException('Eine registrierte Frontenddarstellung auswählen.');}
    }
}
