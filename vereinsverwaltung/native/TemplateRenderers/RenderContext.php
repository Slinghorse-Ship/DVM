<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;

/** Only the anonymous current publication and host-produced fragments cross this boundary. */
final class RenderContext
{
    public function __construct(
        public readonly array $page,
        public readonly string $locale,
        public readonly string $contentLocale,
        public readonly array $route,
        private readonly array $fragments,
        private readonly \Closure $media,
        private readonly \Closure $link,
    ) {}
    public function text(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public function component(string $name):string
    {if(!in_array($name,Definition::COMPONENTS,true))throw new \InvalidArgumentException('Unbekannter öffentlicher Inhaltsbaustein.');return $this->fragments[$name]??'';}
    /** Current-publication-bound variants only. No filesystem path or original metadata is returned. */
    public function image(string $id,string $size='medium'):?array{return ($this->media)($id,$size);}
    /** A visible exact public page only; the host retains locale, URL policy and ownership. */
    public function link(string $id):?array{return ($this->link)($id);}
}
