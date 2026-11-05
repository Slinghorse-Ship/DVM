<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
final class UrlScope
{
    public function __construct(private readonly Urls $urls,private readonly string $owner,private readonly \Closure $guard) {}
    public function register(string $suffix,\Closure $handler): void
    {
        if($suffix==='/renderers'||str_starts_with($suffix,'/renderers/'))throw new \DomainException('Diese Erweiterungsadresse ist für geprüfte Renderer-Assets reserviert.');
        if($suffix!==''&&!preg_match('~^/(?:[a-z0-9-]+|\{[a-z][a-z0-9_]*\})(?:/(?:[a-z0-9-]+|\{[a-z][a-z0-9_]*\}))*$~D',$suffix))throw new \DomainException('Erweiterungsadresse ungültig.');
        $this->urls->register('/extensions/'.$this->owner.$suffix,new Contract('extension.'.$this->owner.'.url.'.hash('crc32b',$suffix),'url',$this->guard,static fn(array $args):bool=>true,Runtime::validResponse(...)),$handler);
    }
}
