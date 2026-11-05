<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

/** A conventional www alias uses the configured origin and its existing session. */
final class CanonicalOrigin
{
    public static function redirect(string $origin):bool
    {
        $parts=parse_url($origin);if(!is_array($parts)||($parts['scheme']??'')!=='https'||!is_string($parts['host']??null)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))return false;
        $host=strtolower($parts['host']);$request=$_SERVER['HTTP_HOST']??'';$alias=str_starts_with($host,'www.')?substr($host,4):'www.'.$host;
        if(!is_string($request)||strtolower($request)!==$alias)return false;
        $uri=$_SERVER['REQUEST_URI']??'/';if(!is_string($uri)||!str_starts_with($uri,'/')||preg_match('/[\x00-\x20\x7f]/',$uri))return false;
        $method=$_SERVER['REQUEST_METHOD']??'GET';header('Cache-Control: no-store');header('Location: https://'.$host.$uri,true,in_array($method,['GET','HEAD'],true)?302:307);return true;
    }
}
