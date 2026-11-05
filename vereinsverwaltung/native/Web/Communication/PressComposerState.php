<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Shared\RichText;

/** Read-only browsing retains incomplete text and the submitted concurrency version. */
final class PressComposerState
{
    public static function restore(array $post):array
    {
        $picker=['recipient_ids'=>\Kulturhus\Native\Communication\PressRecipientCatalogue::selection($post),'mode'=>'selected','page'=>1,'search'=>''];
        return self::fromPost($post,$picker);
    }

    public static function fromPost(array $post,array $picker): array
    {
        $result=[];
        foreach(['id'=>32,'version'=>10,'title'=>300,'summary'=>2000,'body'=>100000,'body_html'=>1000000,'kind'=>20,'template'=>64,'blocks_json'=>150000]as$key=>$limit){
            $value=$post[$key]??($key==='blocks_json'?'[]':'');
            if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new \DomainException('Redaktionsfeld prüfen.');
            $result[$key]=$value;
        }
        if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$result['version'])||$result['kind']!=='release')throw new \DomainException('Presseformular erneut öffnen.');
        if(array_key_exists('save_request_key',$post)){if(!is_string($post['save_request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['save_request_key']))throw new \DomainException('Ursprüngliches Presseformular öffnen.');$result['save_request_key']=$post['save_request_key'];}
        if(array_key_exists('body_html',$post)){$result['body_html']=RichText::sanitize($result['body_html']);$result['body']=RichText::plain($result['body_html']);}
        else unset($result['body_html']);
        $result['recipient_ids']=$picker['recipient_ids'];$result['attachment_media_ids']=\Kulturhus\Native\Communication\PressPhotoAttachments::selection($post);
        return ['document'=>$result,'picker'=>$picker];
    }
}
