<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

/** RFC 9110 weak If-None-Match comparison for an existing GET/HEAD representation. */
final class HttpValidators
{
    public static function unchanged(string $method,mixed $header,string $etag):bool
    {
        if(!in_array($method,['GET','HEAD'],true)||!is_string($header)||strlen($header)>8192)return false;
        $header=trim($header," \t");if($header==='*')return true;
        $tag='(?:W/)?"[\x21\x23-\x7e\x80-\xff]*"';
        // HTTP list recipients ignore empty members, including leading/trailing commas.
        if(!preg_match('~\A(?:[ \t]*,[ \t]*)*'.$tag.'(?:[ \t]*,[ \t]*(?:'.$tag.')?)*\z~D',$header))return false;
        preg_match_all('~(?:W/)?("[\x21\x23-\x7e\x80-\xff]*")~',$header,$matches);
        return in_array($etag,$matches[1],true);
    }
}
