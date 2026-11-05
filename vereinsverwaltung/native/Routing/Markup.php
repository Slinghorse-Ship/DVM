<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;
/** Applies verified request metadata after the ordinary language-aware public renderer. */
final class Markup
{
    public static function apply(string $html,Context $context,Resolver $resolver):string
    {
        $dom=new \DOMDocument();$previous=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $dom->encoding='UTF-8';foreach($dom->childNodes as$node)if($node->nodeType===XML_PI_NODE)$dom->removeChild($node);$xp=new \DOMXPath($dom);
        $canonical=$context->transport->absolute($resolver->pageLink($context,$context->page),$context->policy->definition['https']==='https');
        foreach($xp->query('//link[@rel="canonical"]')as$link)$link->setAttribute('href',$canonical);
        $main=$dom->getElementsByTagName('main')->item(0);$main?->setAttribute('data-url-context',json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        foreach($xp->query('//link[@rel="alternate" and @hreflang] | //nav[@aria-label="Sprache"]//a[@hreflang]')as$link){$href=$link->getAttribute('href');$path=parse_url($href,PHP_URL_PATH);if(!is_string($path))continue;$base=trim(str_starts_with($path,'/site/')?substr($path,6):$path,'/');if($base==='')$base='start';$target=$resolver->alternateLink($context,$base,$link->getAttribute('hreflang'));if($target===null){$link->parentNode?->removeChild($link);continue;}$link->setAttribute('href',$link->nodeName==='link'?$context->transport->absolute($target):$target);}
        $addresses=[];
        foreach($xp->query('//*[@href or (self::form and @action)][not(ancestor-or-self::*[contains(concat(" ",normalize-space(@class)," ")," kh-richtext ")])]')as$node){
            $attribute=$node->nodeName==='form'?'action':'href';$value=$node->getAttribute($attribute);if(!str_starts_with($value,'/site/'))continue;
            if(!array_key_exists($value,$addresses)){$parts=parse_url($value);$target=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');
                try{$resolved=$resolver->resolve($target);$addresses[$value]=$resolved->context!==null?$resolver->pageLink($resolved->context,$resolved->context->page).(isset($parts['fragment'])?'#'.$parts['fragment']:''):null;}catch(\InvalidArgumentException){$addresses[$value]=null;}
            }if($addresses[$value]!==null)$node->setAttribute($attribute,$addresses[$value]);
        }
        return'<!doctype html>'.PHP_EOL.$dom->saveHTML($dom->documentElement);
    }
}
