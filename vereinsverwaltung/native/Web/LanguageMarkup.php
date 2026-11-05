<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
/** Applies server-owned language metadata; it never translates or rewrites saved user content. */
final class LanguageMarkup
{
    public static function apply(string $html,array $identity,string $origin):string
    {
        if(!isset($identity['language']))return$html;
        $locale=$identity['language'];$content=$identity['content_language']??$locale;$path=$identity['language_path']??'';
        if(!self::locale($locale)||!self::locale($content)||!self::path($path))throw new \InvalidArgumentException('Sprachkontext prüfen.');
        $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $dom->encoding='UTF-8';foreach($dom->childNodes as$node)if($node->nodeType===XML_PI_NODE)$dom->removeChild($node);
        $xpath=new \DOMXPath($dom);$dom->documentElement->setAttribute('lang',$content);
        $search=$identity['language_search']??null;
        if($search!==null&&(!is_array($search)||!is_string($search['query']??null)||!self::locale($search['source']??null)))throw new \InvalidArgumentException('Suchsprachkontext prüfen.');
        $searchHref=static fn(string $code):string=>'/search?'.http_build_query(['lang'=>$code,'q'=>$search['query']??''],'','&',PHP_QUERY_RFC3986);
        foreach($xpath->query('//link[@rel="canonical"]')as$link)$link->setAttribute('href',rtrim($origin,'/').($search!==null?$searchHref($locale):'/site/'.$path));
        $head=$dom->getElementsByTagName('head')->item(0);$switch=$dom->createElement('nav');$switch->setAttribute('aria-label','Sprache');$switch->setAttribute('class','site-languages d-flex flex-wrap gap-2');
        foreach($identity['language_alternates']??[]as$code=>$target){if(!self::locale($code)||!self::path($target))continue;
            $href=$search!==null?$searchHref($code):'/site/'.$target;$alternate=$dom->createElement('link');$alternate->setAttribute('rel','alternate');$alternate->setAttribute('hreflang',$code);$alternate->setAttribute('href',rtrim($origin,'/').$href);$head?->appendChild($alternate);
            $link=$dom->createElement('a');$link->appendChild($dom->createTextNode(strtoupper($code)));$link->setAttribute('href',$href);$link->setAttribute('hreflang',$code);$link->setAttribute('lang',$code);$link->setAttribute('class','btn btn-outline theme-secondary');if($code===$locale)$link->setAttribute('aria-current','true');$switch->appendChild($link);
        }
        if($switch->childNodes->length>1)$xpath->query('//header[contains(concat(" ",normalize-space(@class)," ")," site-header ")]')->item(0)?->appendChild($switch);
        $home='/site/'.(str_starts_with($path,$locale.'/')?$locale.'/':'').'start';
        foreach($xpath->query('//a[@href="/site/start" and contains(concat(" ",normalize-space(@class)," ")," brand ")]')as$link)$link->setAttribute('href',$home);
        foreach($xpath->query('//a[@href="/search"]')as$link)$link->setAttribute('href','/search?'.http_build_query(['lang'=>$locale],'','&',PHP_QUERY_RFC3986));
        return$dom->saveHTML();
    }
    private static function locale(mixed $value):bool{return is_string($value)&&strlen($value)<=16&&(bool)preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}$/D',$value);}
    private static function path(mixed $value):bool{return is_string($value)&&strlen($value)<=197&&(bool)preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$~D',$value);}
}
