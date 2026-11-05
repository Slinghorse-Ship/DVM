<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Shared\RichText;
/** Restore rejected human input without writing or replacing its submitted version. */
final class NewsletterComposerState
{
    public static function blocks(mixed $raw):array
    {
        if(!is_string($raw)||strlen($raw)>600000)throw new DomainException('Inhaltsbausteine prüfen.');
        try{$blocks=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Inhaltsbausteine prüfen.');}
        return Content::blocks($blocks);
    }
    public static function restore(array $post):array
    {
        $document=[];
        foreach(['id'=>32,'version'=>10,'title'=>300,'summary'=>2000,'kind'=>20,'template'=>64,'audience'=>10,'blocks_json'=>150000]as$field=>$max){
            if(!array_key_exists($field,$post))throw new DomainException('Newsletterformular nicht vollständig übertragen.');
            $document[$field]=Content::originalText($post[$field],$max*4);
            if(mb_strlen($document[$field],'UTF-8')>$max)throw new DomainException('Newsletterfeld prüfen.');
        }
        if(array_key_exists('save_request_key',$post)){if(!is_string($post['save_request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['save_request_key']))throw new DomainException('Ursprüngliches Newsletterformular öffnen.');$document['save_request_key']=$post['save_request_key'];}
        $documents=PdfAttachments::fromPost($post);if($documents!==null)$document['document_attachments']=$documents;
        $document['attachment_rights']=PdfAttachments::rights($post);
        if($document['id']!=='')Content::id($document['id']);
        if(!preg_match('/\A(0|[1-9][0-9]{0,9})\z/D',$document['version'])||$document['kind']!=='edition'||!in_array($document['audience'],['all','events','news'],true))throw new DomainException('Newsletterformular erneut öffnen.');
        if(array_key_exists('body_html',$post)){
            $document['body_html']=RichText::sanitize(Content::originalText($post['body_html'],4000000));
            $document['body']=RichText::plain($document['body_html']);
        }else{
            if(!array_key_exists('body',$post))throw new DomainException('Newslettertext nicht vollständig übertragen.');
            $document['body']=Content::originalText($post['body'],400000);
        }
        return ['document'=>$document];
    }
}
