<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

/** Public user-supplied source text; no consent proof or rights assertion enters it. */
final class AttributionText
{
    public static function parts(array $metadata): array
    {
        $parts=[];
        if(($metadata['credit']??'')!=='')$parts[]=$metadata['credit'];
        foreach(['source'=>'Quelle','license'=>'Lizenz']as$field=>$label){
            $name=$metadata[$field.'_name']??'';$url=$metadata[$field.'_url']??'';
            if($name!==''||$url!=='')$parts[]=$label.': '.implode(' · ',array_filter([$name,$url],static fn($value)=>$value!==''));
        }
        return $parts;
    }
}
