<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use InvalidArgumentException;

/** Same-site canonical and pager paths, with original non-pager query bytes retained. */
final class Links
{
    public static function path(Context $context,?int $page=null,bool $forceQuery=false):string
    {
        $page??=$context->page;$pager=$context->policy->definition['pagination'];
        if($page<1||$page>$pager['maximum']||($pager['mode']==='off'&&$page!==1))throw new InvalidArgumentException('Gültige URL-Seite auswählen.');
        $values=[];foreach($context->policy->definition['segments']as$rule)if(array_key_exists($rule['name'],$context->parameters))$values[]=(string)$context->parameters[$rule['name']];
        $pathPager=$pager['mode']==='path'&&!$forceQuery&&$page>1;
        $path=$context->transport->base($context->basePath,$values!==[]||$pathPager);
        if($values)$path.='/'.implode('/',$values);
        if($pathPager)$path.='/'.$pager['marker'].'/'.$page;
        $path=$context->transport->slash($path,$context->policy);
        $query=self::queryWithout($context->request,$context->pageParameter);
        if($page>1&&!$pathPager)$query.=($query===''?'':'&').$context->pageParameter.'='.$page;
        $result=$path.($query===''?'':'?'.$query);new RequestPath($result);return$result;
    }
    public static function queryWithout(RequestPath $request,string $key):string
    {
        $pairs=[];foreach(explode('&',$request->query)as$pair){if($pair==='')continue;$name=urldecode(explode('=',$pair,2)[0]);if($name===$key||str_starts_with($name,$key.'['))continue;$pairs[]=$pair;}return implode('&',$pairs);
    }
}
