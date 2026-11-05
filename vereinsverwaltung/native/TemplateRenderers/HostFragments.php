<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;

/** Extract an existing native public list, keeping the common page shell and title exactly once. */
final class HostFragments
{
    public static function listing(string $html):string
    {
        $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{if(!$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new \DomainException('Öffentliche Liste nicht verfügbar.');}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $xpath=new \DOMXPath($dom);$main=$dom->getElementById('main');if(!$main||$main->tagName!=='main')throw new \DomainException('Öffentliche Liste nicht verfügbar.');
        $out='';foreach($main->childNodes as$node){if($node instanceof \DOMElement&&$node->tagName==='header'&&in_array('page-hero',explode(' ',$node->getAttribute('class')),true))continue;$out.=$dom->saveHTML($node);}
        return $out;
    }
}
