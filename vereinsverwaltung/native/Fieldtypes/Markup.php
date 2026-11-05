<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** Custom controls cannot add forms, submission actions, executable markup or remote resources. */
final class Markup
{
    public static function validate(string $html,string $name,bool $readonly=false):string
    {
        if(strlen($html)>32768||str_contains($html,"\0"))throw new \DomainException('Eingabefeldansicht begrenzen.');
        $dom=new \DOMDocument('1.0','UTF-8');$before=libxml_use_internal_errors(true);
        try{$okay=$dom->loadHTML('<?xml encoding="UTF-8"><div id="fieldtype-control-root">'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($before);}
        $root=$dom->getElementById('fieldtype-control-root');if(!$okay||!$root)throw new \DomainException('Eingabefeldansicht prüfen.');
        $xpath=new \DOMXPath($dom);$controls=0;
        foreach($xpath->query('//*')as$node){
            if(in_array($node->tagName,['html','head','body'],true)||$node===$root)continue;
            if(!in_array($node->tagName,['div','span','p','small','strong','em','label','input','textarea','select','option','optgroup','button','fieldset','legend','output','details','summary','br'],true)||!self::within($node,$root))throw new \DomainException('Eingabefeldansicht enthält unzulässige Elemente.');
            foreach(iterator_to_array($node->attributes)as$attribute){$key=strtolower($attribute->name);$value=$attribute->value;
                if(!in_array($key,['id','class','name','type','value','for','disabled','readonly','checked','selected','multiple','required','maxlength','minlength','min','max','step','rows','cols','placeholder','inputmode','autocomplete','pattern','title','role','aria-label','aria-describedby','aria-hidden','aria-live','aria-expanded','open'],true)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new \DomainException('Eingabefeldansicht enthält unzulässige Eigenschaften.');
            }
            if(in_array($node->tagName,['input','select','textarea'],true)){
                if($node->getAttribute('name')!==$name||($node->tagName==='input'&&!in_array($node->getAttribute('type')?:'text',['text','number','date','datetime-local','email','url','checkbox','radio','color','range'],true)))throw new \DomainException('Eingabefeld darf ausschließlich seinen gebundenen Wert senden.');
                if($readonly&&!$node->hasAttribute('disabled'))throw new \DomainException('Schreibgeschütztes Eingabefeld darf keinen Wert senden.');++$controls;
            }
            if($node->tagName==='button'&&($node->getAttribute('type')!=='button'||$node->hasAttribute('name')))throw new \DomainException('Eingabefeld darf keine eigene Formularaktion senden.');
            if($node->hasAttribute('name')&&!in_array($node->tagName,['input','select','textarea'],true))throw new \DomainException('Eingabefeld enthält einen fremden Formularwert.');
        }
        if(!$readonly&&$controls===0)throw new \DomainException('Eingabefeld benötigt einen gebundenen Wert.');
        $result='';foreach($root->childNodes as$child)$result.=$dom->saveHTML($child);return $result;
    }
    private static function within(\DOMNode $node,\DOMNode $root):bool
    {while($node=$node->parentNode)if($node===$root)return true;return false;}
}
