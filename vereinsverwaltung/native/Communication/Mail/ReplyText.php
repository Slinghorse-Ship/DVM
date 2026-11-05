<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use DomainException;
use Kulturhus\Native\Communication\Content;

/** Extract the human reply above common mail quotes; retain the raw MIME separately. */
final class ReplyText
{
    public static function withoutHtmlQuotes(string $html):string
    {
        $previous=libxml_use_internal_errors(true);
        try{
            $document=HtmlDocument::parse('<html><body>'.$html.'</body></html>');
            $xpath=new \DOMXPath($document);
            // Outlook quotes are frequently siblings of this divider instead
            // of nested in a blockquote. Remove the divider and following tail.
            foreach($xpath->query('//*[@id="divRplyFwdMsg"]')as$divider){
                $node=$divider;
                while($node&&$node->parentNode&&$node->nodeName!=='body'){
                    while($node->nextSibling)$node->parentNode->removeChild($node->nextSibling);
                    $node=$node->parentNode;
                }
                if($divider->parentNode){
                    $parent=$divider->parentNode;$parent->removeChild($divider);
                    while($parent->nodeName!=='body'&&$parent->parentNode&&trim($parent->textContent)===''){$next=$parent->parentNode;$next->removeChild($parent);$parent=$next;}
                }
            }
            foreach($xpath->query('//blockquote|//*[contains(concat(" ",normalize-space(@class)," ")," gmail_quote ") or contains(concat(" ",normalize-space(@class)," ")," yahoo_quoted ") or contains(concat(" ",normalize-space(@class)," ")," moz-cite-prefix ") or contains(concat(" ",normalize-space(@class)," ")," moz-signature ")]')as$node)if($node->parentNode)$node->parentNode->removeChild($node);
            return $document->saveHTML($document->getElementsByTagName('body')->item(0));
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    public static function extract(string $text):string
    {
        $lines=preg_split('/(?<=\n)/',$text);$result='';$split=false;
        foreach($lines as$i=>$line){
            $value=rtrim($line,"\r\n");
            $tail=implode('',array_slice($lines,$i+1));
            $quote=preg_match('/\A[ \t]*>/u',$value)===1;
            $intro=preg_match('/\A(?:On .+wrote:|Am .+(?:schrieb|hat .+geschrieben).*:|Le .+(?:a écrit|écrit).*:)\s*\z/iu',$value)===1&&preg_match('/^\s*>/m',$tail);
            $outlook=preg_match('/\A(?:From|Von|De):\s*.+/iu',$value)===1&&preg_match('/^(?:To|An|À):\s*.+/mi',$tail)&&preg_match('/^(?:Subject|Betreff|Objet):\s*.+/mi',$tail);
            if($quote||$intro||$outlook||$value==='-- '||preg_match('/\A\s*-{3,}\s*(?:Original Message|Ursprüngliche Nachricht|Weitergeleitete Nachricht)\s*-{3,}\s*\z/iu',$value)){$split=true;break;}
            $result.=$line;
        }
        if($split)$result=preg_replace('/(?:\r?\n[ \t]*)+\z/','',$result);
        $result=Content::originalText($result,8000,true);
        if(trim($result)==='')throw new DomainException('Mail enthält keine neue Antwort über dem Zitat.');
        return $result;
    }
}
