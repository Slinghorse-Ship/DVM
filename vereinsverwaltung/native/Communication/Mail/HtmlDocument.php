<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

/** Parse mail HTML already decoded by MIME as UTF-8, regardless of its older HTML charset hints. */
final class HtmlDocument
{
    public static function parse(string $html):\DOMDocument
    {
        if(!mb_check_encoding($html,'UTF-8'))throw new \DomainException('Dekodierter Mailtext benötigt gültiges UTF-8.');
        // A leading BOM before a whole HTML document would otherwise become body content
        // after the inserted meta element and displace the original head/body attributes.
        if(str_starts_with($html,"\xef\xbb\xbf"))$html=substr($html,3);
        $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{
            // libxml honours the first actual charset meta, even ahead of a later legacy
            // http-equiv declaration. An XML encoding PI alone does not establish this.
            if(!$dom->loadHTML('<meta charset="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new \DomainException('HTML-Mail nicht lesbar.');
            $dom->encoding='UTF-8';return$dom;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
