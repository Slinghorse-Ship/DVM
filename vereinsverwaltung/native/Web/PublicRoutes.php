<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Original public page aliases shared by the two native entry points. */
final class PublicRoutes
{
    private const PAGE_ROOTS=['mitglied-werden','aktuelles','galerie','veranstaltungen','verein','kontakt','impressum','datenschutz','satzung','datenloeschung','nutzungsbedingungen'];
    public static function pageAlias(string $path):?string
    {
        $path=rtrim($path,'/');
        if(!preg_match('~\A/([a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)\z~D',$path,$match)||strlen($match[1])>180)return null;
        return in_array(explode('/',$match[1],2)[0],self::PAGE_ROOTS,true)?$match[1]:null;
    }
    /** Request-only alias; persistent original slugs keep their independent 180-byte contract. */
    public static function routingAlias(string $path):?string
    {
        try{$request=new \Kulturhus\Native\Routing\RequestPath($path);}catch(\InvalidArgumentException){return null;}
        if($request->query!==''||!isset($request->parts[0])||!in_array($request->parts[0],self::PAGE_ROOTS,true))return null;
        return implode('/',$request->parts);
    }
    public static function ownsAlias(string $path):bool
    {
        return self::routingAlias($path)!==null||preg_match('~\A/newsletter(?:/|$)~D',$path)===1;
    }
}
