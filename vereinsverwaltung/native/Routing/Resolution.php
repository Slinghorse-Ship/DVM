<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

/** A caller renders, redirects or returns a generic miss; this object never emits HTTP headers. */
final class Resolution
{
    private function __construct(public readonly string $kind,public readonly ?Context $context=null,public readonly ?string $location=null,public readonly ?array $published=null){}
    public static function page(Context $context,array $published):self{return new self('page',$context,null,$published);}
    public static function redirect(Context $context,array $published,string $location):self{return new self('redirect',$context,$location,$published);}
    public static function missing():self{return new self('missing');}
    public static function unclaimed():self{return new self('unclaimed');}
}
