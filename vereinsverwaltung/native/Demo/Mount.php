<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Rewrite only URL-bearing structures; never replace business text or download bytes. */
final class Mount
{
    private static string $request='/';
    private const URL_KEYS=['url','href','src','redirect','location','thumb','original_url','print_url','download_url','preview_url','endpoint'];

    public static function url(string $value):string
    {
        if($value===''||$value[0]==='#'||preg_match('/\A(?:data|blob|mailto|tel|javascript):/i',$value))return $value;
        try{$uri=\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri(Runtime::origin().self::$request),new \GuzzleHttp\Psr7\Uri($value));}catch(\InvalidArgumentException){return $value;}
        if((string)$uri->withPath('')->withQuery('')->withFragment('')!==Runtime::origin())return $value;
        $path=$uri->getPath()?:'/';if(!preg_match('~\A/demo(?:/|$)~D',$path))$path='/demo'.$path;
        return $path.($uri->getQuery()!==''?'?'.$uri->getQuery():'').($uri->getFragment()!==''?'#'.$uri->getFragment():'');
    }

    public static function adapter():string
    {
        return ';globalThis.DVM_DEMO_CONFIGURATION='.json_encode(['origin'=>Runtime::origin(),'mount'=>'/demo','namespace'=>'dvm-demo-'.Runtime::instance().':'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).";\n".file_get_contents(__DIR__.'/adapter.js');
    }

    public static function style():string{return '.dvm-demo-banner{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;padding:1rem;background:#fff3ce;color:#302600;font:1rem/1.5 sans-serif}.dvm-demo-banner form{margin:0}.dvm-demo-banner button{color:#fff;background:#205c3e;border-color:#205c3e;min-height:44px;white-space:normal;max-width:100%}';}
    private static function assets():array
    {
        return ['/demo/_adapter.js'=>['url'=>'/demo/_adapter.js?v='.substr(hash('sha256',self::adapter()),0,16),'kind'=>'script','module'=>false],'/demo/_demo.css'=>['url'=>'/demo/_demo.css?v='.substr(hash('sha256',self::style()),0,16),'kind'=>'style','module'=>false]];
    }

    public static function dispatch(callable $dispatch):void
    {
        self::$request='/demo'.($_SERVER['REQUEST_URI']??'/');$buffer='';
        ob_start(static function(string $bytes,int $phase)use(&$buffer):string{
            $type='text/html';$attachment=false;
            foreach(headers_list() as $header){if(str_starts_with(strtolower($header),'content-type:'))$type=strtolower(trim(explode(';',substr($header,13),2)[0]));if(str_starts_with(strtolower($header),'content-disposition:')&&stripos($header,'attachment')!==false)$attachment=true;}
            $transform=!$attachment&&in_array($type,['text/html','application/json','application/manifest+json','text/css','text/javascript','application/javascript'],true);
            if(!$transform)return $bytes;
            $buffer.=$bytes;if(strlen($buffer)>32*1024*1024){http_response_code(503);$buffer='';throw new \RuntimeException('Demo-Antwort zu groß.');}
            if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
            self::headers(true);$body=$buffer;$buffer='';
            if($type==='text/html')return self::html($body);
            if(in_array($type,['application/json','application/manifest+json'],true)){
                try{$data=json_decode($body,true,128,JSON_THROW_ON_ERROR);$data=self::json($data);if($type==='application/manifest+json'&&is_array($data))foreach(['id','start_url','scope'] as $key)if(is_string($data[$key]??null))$data[$key]=self::url($data[$key]);return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}catch(\JsonException){return $body;}
            }
            if($type==='text/css')return self::css($body);
            return ($_GET['dvm_demo_worker']??'')==='1'?self::adapter()."\n".$body:$body;
        },65536);
        try{$dispatch();}finally{if(ob_get_level()>0)ob_end_flush();self::headers();}
    }

    private static function headers(bool $changed=false):void
    {
        if(headers_sent())return;
        foreach(headers_list() as $header){
            if(preg_match('/\A(Location|Content-Location):\s*(.*)\z/i',$header,$match))header($match[1].': '.self::url($match[2]),true);
            if(str_starts_with(strtolower($header),'content-security-policy:')&&str_contains($header,"script-src 'self'")){
                $policy=preg_replace("/;\\s*worker-src 'self'\\s*(?=;|$)/","; worker-src 'self' blob:",$header);
                if(!str_contains($policy,'worker-src'))$policy.="; worker-src 'self' blob:";
                header($policy,true);
            }
        }
        if($changed){header_remove('Content-Length');header_remove('ETag');}
    }

    private static function json(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        foreach($value as $key=>$item)$value[$key]=is_string($item)&&in_array($key,self::URL_KEYS,true)?self::url($item):(is_array($item)?self::json($item):$item);
        return $value;
    }

    public static function css(string $body):string
    {
        return preg_replace_callback('~url\(\s*([\x22\x27]?)(/[^\s\x22\x27)]+)\1\s*\)~i',static fn($match)=>'url('.$match[1].self::url($match[2]).$match[1].')',$body)??$body;
    }

    private static function html(string $body):string
    {
        if($body==='')return $body;
        $full=preg_match('~<!doctype|<html\b~i',$body)===1;
        $dom=new \DOMDocument();$original=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="UTF-8">'.($full?$body:'<div id="dvm-demo-fragment-root">'.$body.'</div>'),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($original);}
        foreach(iterator_to_array($dom->childNodes) as $node)if($node instanceof \DOMProcessingInstruction)$dom->removeChild($node);
        foreach($dom->getElementsByTagName('*') as $element){
            foreach(iterator_to_array($element->attributes) as $attribute){
                $name=strtolower($attribute->name);
                if(in_array($name,['href','src','action','formaction','poster','data-url','data-href','data-src'],true)||preg_match('/\Adata-.*-(?:url|href|src|endpoint)\z/D',$name))$element->setAttribute($name,self::url($attribute->value));
                elseif($name==='style')$element->setAttribute($name,self::css($attribute->value));
            }
            if($element->tagName==='style')$element->nodeValue=self::css($element->textContent);
            if($element->tagName==='meta'&&in_array($element->getAttribute('name'),['manager-navigation'],true)){
                try{$data=self::json(json_decode($element->getAttribute('content'),true,64,JSON_THROW_ON_ERROR));if(is_array($data['assets']??null))$data['assets']+=self::assets();$element->setAttribute('content',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}catch(\JsonException){}
            }
        }
        if($full){
            $head=$dom->getElementsByTagName('head')->item(0);$pageBody=$dom->getElementsByTagName('body')->item(0);
            $scripts=false;foreach(headers_list() as $header)if(str_starts_with(strtolower($header),'content-security-policy:'))$scripts=preg_match("/(?:^|;)\\s*script-src(?:-elem)?\\s+[^;]*'self'/i",substr($header,24))===1;
            if($head&&$scripts){$script=$dom->createElement('script');$script->setAttribute('src',self::assets()['/demo/_adapter.js']['url']);$head->insertBefore($script,$head->firstChild);}
            if($head){$style=$dom->createElement('link');$style->setAttribute('rel','stylesheet');$style->setAttribute('href',self::assets()['/demo/_demo.css']['url']);$head->appendChild($style);}
            if($pageBody&&isset($_SESSION['demo_role'])){
                $aside=$dom->createElement('aside');$aside->setAttribute('class','dvm-demo-banner');$aside->setAttribute('aria-label','Demo');
                $aside->appendChild($dom->createTextNode(Runtime::locale()==='en'?'Demo · shared fictional data · external actions disabled.':'Demo · gemeinsame fiktive Daten · externe Aktionen gesperrt.'));
                $aside->appendChild($dom->createTextNode(Runtime::locale()==='en'?' Exercise confirmation: demo.':' Übungsbestätigung: demo.'));
                $form=$dom->createElement('form');$form->setAttribute('method','post');$form->setAttribute('action','/demo/leave-role');
                $csrf=$dom->createElement('input');$csrf->setAttribute('type','hidden');$csrf->setAttribute('name','csrf');$csrf->setAttribute('value',$_SESSION['csrf']);$form->appendChild($csrf);
                $button=$dom->createElement('button',Runtime::locale()==='en'?'Choose another role':'Andere Rolle wählen');$form->appendChild($button);$aside->appendChild($form);$pageBody->insertBefore($aside,$pageBody->firstChild);
            }
            return $dom->saveHTML();
        }
        $root=$dom->getElementById('dvm-demo-fragment-root');if(!$root)return $body;$html='';foreach($root->childNodes as $node)$html.=$dom->saveHTML($node);return $html;
    }
}
