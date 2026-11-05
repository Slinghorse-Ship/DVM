<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
/** Language scope only; services and original form bindings remain authoritative. */
final class ConversationRequest
{
    public static function applies(string $view,array $post,array $query=[]):bool
    {
        if($view!=='social')return false;
        if(in_array($query['section']??null,['inbox','review','replies','mail','sync'],true))return true;
        return is_string($post['operation']??null)&&preg_match('/\Asocial-(?:reply|comments?|inbox|mail|check-plan|attention)(?:-|$)/D',$post['operation'])===1;
    }
}
