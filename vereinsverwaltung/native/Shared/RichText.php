<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

use DOMDocument;
use DOMElement;
use DOMNode;
use DomainException;

/** Project-authored HTML allowlist. Stored output has no executable or remotely loaded elements. */
final class RichText
{
    private const ALLOWED = ['p','h2','h3','h4','strong','em','u','s','blockquote','ul','ol','li','br','span','a','table','thead','tbody','tr','th','td','pre','code','hr'];
    private const DROP = ['script','style','iframe','object','embed','svg','math','img','picture','video','audio','source','track','link','meta','base','form','input','button','textarea','select','option','template','noscript'];
    private const CLASSES = ['kh-rt-size-small','kh-rt-size-normal','kh-rt-size-large','kh-rt-size-xlarge','kh-rt-align-left','kh-rt-align-center','kh-rt-align-right','kh-rt-align-justify'];

    public static function sanitize(string $html): string
    {
        if (strlen($html) > 1000000 || preg_match('//u',$html) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$html)) throw new DomainException('Richtext benötigt gültiges UTF-8 und darf höchstens 1 MB umfassen.');
        if ($html === '') return '';
        $source = self::document($html);
        $target = new DOMDocument('1.0','UTF-8');
        $root = $target->createElement('div');
        $target->appendChild($root);
        $nodes = 0;
        $body = $source->getElementsByTagName('body')->item(0);
        if ($body) foreach ($body->childNodes as $node) self::copy($node,$root,$target,0,$nodes);
        if (preg_match_all('/./us',$root->textContent) > 100000) throw new DomainException('Richtext darf höchstens 100.000 Textzeichen enthalten.');
        $result = '';
        foreach ($root->childNodes as $node) $result .= $target->saveHTML($node);
        return trim($result);
    }

    private static function document(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0','UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // Neither DTD loading nor entity substitution is enabled; NONET also blocks network access.
            $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $doc;
    }

    private static function copy(DOMNode $node, DOMNode $parent, DOMDocument $target, int $depth, int &$nodes): void
    {
        if (++$nodes > 10000 || $depth > 64) throw new DomainException('Richtext ist zu komplex.');
        if ($node->nodeType === XML_TEXT_NODE) {
            $parent->appendChild($target->createTextNode($node->textContent));
            return;
        }
        if (!$node instanceof DOMElement) return;
        $original = strtolower($node->tagName);
        if (in_array($original,self::DROP,true)) return;
        $tag = match ($original) {'b'=>'strong','i'=>'em','strike','del'=>'s','font'=>'span','div'=>'p',default=>$original};
        $nestedBlock = false;
        if ($original === 'div') foreach ($node->childNodes as $child) if ($child instanceof DOMElement && in_array(strtolower($child->tagName),['div','p','h2','h3','h4','blockquote','ul','ol','table'],true)) $nestedBlock = true;
        if (!in_array($tag,self::ALLOWED,true) || $nestedBlock) {
            foreach ($node->childNodes as $child) self::copy($child,$parent,$target,$depth+1,$nodes);
            return;
        }
        $element = $target->createElement($tag);
        $classes = [];
        foreach (preg_split('/\s+/',trim($node->getAttribute('class'))) as $class) if (in_array($class,self::CLASSES,true)) $classes[] = $class;
        $styles = [];
        foreach (explode(';',$node->getAttribute('style')) as $declaration) {
            $parts = explode(':',$declaration,2);
            if (count($parts) !== 2) continue;
            $property = strtolower(trim($parts[0]));
            $value = strtolower(trim($parts[1]));
            if (in_array($property,['color','background-color'],true)) {
                $color = self::color($value);
                if ($color !== null) $styles[$property] = $color;
            } elseif ($property === 'text-align' && in_array($value,['left','center','right','justify'],true)) $classes[] = 'kh-rt-align-'.$value;
        }
        $align = strtolower($node->getAttribute('align'));
        if (in_array($align,['left','center','right','justify'],true)) $classes[] = 'kh-rt-align-'.$align;
        if ($original === 'font') {
            $size = $node->getAttribute('size');
            if (preg_match('/^[1-7]$/D',$size)) $classes[] = 'kh-rt-size-'.match (true) {(int)$size<=2=>'small',(int)$size===3=>'normal',(int)$size===4=>'large',default=>'xlarge'};
            $color = self::color(strtolower(trim($node->getAttribute('color'))));
            if ($color !== null) $styles['color'] = $color;
        }
        $classes = array_values(array_unique($classes));
        sort($classes);
        if ($classes) $element->setAttribute('class',implode(' ',$classes));
        if ($styles) {
            ksort($styles);
            $safe = [];
            foreach ($styles as $key=>$value) $safe[] = $key.':'.$value;
            $element->setAttribute('style',implode(';',$safe));
        }
        if ($tag === 'a') {
            $href = self::href($node->getAttribute('href'));
            if ($href !== null) { $element->setAttribute('href',$href); $element->setAttribute('rel','noopener noreferrer'); }
        }
        if (in_array($tag,['td','th'],true)) {
            foreach (['colspan','rowspan'] as $attribute) {
                $value = $node->getAttribute($attribute);
                if (preg_match('/^(?:[1-9]|1[0-9]|20)$/D',$value)) $element->setAttribute($attribute,$value);
            }
        }
        $parent->appendChild($element);
        foreach ($node->childNodes as $child) self::copy($child,$element,$target,$depth+1,$nodes);
    }

    private static function color(string $value): ?string
    {
        if (preg_match('/^#[a-f0-9]{6}$/D',$value)) return $value;
        if (preg_match('/^#([a-f0-9])([a-f0-9])([a-f0-9])$/D',$value,$m)) return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/D',$value,$m) && max((int)$m[1],(int)$m[2],(int)$m[3]) <= 255) return sprintf('#%02x%02x%02x',(int)$m[1],(int)$m[2],(int)$m[3]);
        return null;
    }

    private static function href(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/',$href)) return null;
        if (preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i',$href)) return null;
        if (str_starts_with($href,'/') && !str_starts_with($href,'//') && !str_starts_with($href,'/%2f') && !str_starts_with($href,'/%2F')) return $href;
        $parts = parse_url($href);
        if ($parts && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && filter_var($href,FILTER_VALIDATE_URL)) return $href;
        if(preg_match('/\Amailto:/i',$href)&&!isset($parts['query'])&&!isset($parts['fragment'])&&!isset($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['port'])&&!preg_match('/%(?![0-9a-f]{2})/i',$href)){
            $email=rawurldecode(substr($href,7));
            if(strlen($email)<=254&&!preg_match('/[\x00-\x20\x7f\\\\"]/',$email)&&filter_var($email,FILTER_VALIDATE_EMAIL))return $href;
        }
        if(preg_match('/\Atel:\+?[0-9][0-9().-]{0,38}[0-9](?:;ext=[0-9]{1,10})?\z/iD',$href))return $href;
        return null;
    }

    public static function plain(string $html): string
    {
        $safe = self::sanitize($html);
        $safe = preg_replace('/<br\s*\/?\s*>|<\/(?:p|h[2-4]|blockquote|li|tr)>/i',"\n",$safe);
        $safe = preg_replace('/<\/(?:td|th)>/i',"\t",$safe);
        $text = html_entity_decode(strip_tags($safe),ENT_QUOTES | ENT_HTML5,'UTF-8');
        $text = preg_replace('/[ \t]+\n/',"\n",$text);
        return trim(preg_replace('/\n{3,}/',"\n\n",$text));
    }
}
