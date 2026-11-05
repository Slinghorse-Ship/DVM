<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;

/** Ordered private image cards; provider publication is a separate capability. */
final class Carousel
{
    public const LIMIT=10;

    public static function normalize(mixed $slides):array
    {
        if(!is_array($slides)||!array_is_list($slides)||count($slides)>self::LIMIT)throw new DomainException('Höchstens zehn Karussell-Bilder auswählen.');
        $out=[];
        foreach($slides as$slide){
            if(!is_array($slide)||array_diff(array_keys($slide),['title','text','media_ref']))throw new DomainException('Karussell-Bild prüfen.');
            $media=$slide['media_ref']??'';
            $out[]=['title'=>Content::text($slide['title']??'',200,true),'text'=>Content::text($slide['text']??'',1200),'media_ref'=>$media===''?'':Content::id($media)];
        }
        return $out;
    }

    public static function count(array $item):int
    {
        if(($item['kind']??null)!=='carousel')throw new DomainException('Eigenes Karussell öffnen.');
        $count=count(self::normalize($item['carousel']??[]));
        if($count<2)throw new DomainException('Ein Karussell benötigt zwei bis zehn Bilder.');
        return $count;
    }
}
