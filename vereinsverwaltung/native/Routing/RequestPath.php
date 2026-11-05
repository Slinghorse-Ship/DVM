<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use InvalidArgumentException;

/** Strict request target. The mount and trusted origin belong to the dispatcher, never the URL. */
final class RequestPath
{
    public const MAX_LENGTH=1024;
    // 90 one-character original parts, one locale, one demo mount, eight values and a two-part pager.
    public const MAX_PARTS=90+1+1+Policy::MAX_SEGMENTS+2;
    public readonly string $path;
    public readonly string $query;
    public readonly array $parts;
    public readonly bool $trailingSlash;
    public function __construct(string $request)
    {
        if(!preg_match('//u',$request)||$request===''||$request[0]!=='/'||str_starts_with($request,'//')||preg_match('/[\x00-\x20\x7f#\\\\]/',$request))throw new InvalidArgumentException('Lokalen Inhaltspfad ohne Steuerzeichen angeben.');
        [$path,$query]=array_pad(explode('?',$request,2),2,'');
        if(strlen($path)>self::MAX_LENGTH||strlen($query)>8192||($path!=='/'&&!preg_match('~\A/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*/?\z~D',$path)))throw new InvalidArgumentException('Gültigen lokalen Inhaltspfad mit begrenzten Segmenten angeben.');
        $parts=$path==='/'?[]:explode('/',trim($path,'/'));if(count($parts)>self::MAX_PARTS)throw new InvalidArgumentException('Inhaltspfad enthält zu viele Bestandteile.');
        $this->path=$path;$this->query=$query;$this->parts=$parts;$this->trailingSlash=str_ends_with($path,'/');
        foreach(explode('&',$query)as$pair){$key=urldecode(explode('=',$pair,2)[0]);if(preg_match('/[\x00-\x20\x7f]/',$key))throw new InvalidArgumentException('URL-Filternamen ohne Steuerzeichen und mehrdeutige Schreibweisen verwenden.');}
    }
    public function value(string $name):?string
    {
        $found=null;$seen=false;
        foreach(explode('&',$this->query)as$pair){if($pair==='')continue;[$key,$value]=array_pad(explode('=',$pair,2),2,'');$key=urldecode($key);
            if($key!==$name&&!str_starts_with($key,$name.'['))continue;
            if($seen||$key!==$name)throw new InvalidArgumentException('URL-Filter nur einmal als einzelnen Wert angeben.');$seen=true;$found=urldecode($value);
        }return $found;
    }
    public function queryValues():array{parse_str($this->query,$values);return$values;}
    public function withPath(string $path):self{return new self($path.($this->query===''?'':'?'.$this->query));}
}
