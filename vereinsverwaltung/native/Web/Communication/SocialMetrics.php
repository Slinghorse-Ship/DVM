<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\{ContentMessages,ManagerMessages};

/** Provider integers stay exact; absence and malformed values remain unavailable. */
final class SocialMetrics
{
    public static function render(string $channel,array $payload,?array $messages=null,string $locale='de'):string
    {
        if(!in_array($channel,['facebook','instagram','stadtlandfunk'],true))return '';
        $messages??=ManagerMessages::messages($locale);$t=static fn(string $source):string=>Html::e(ContentMessages::text($source,$messages));$separator=explode('-',$locale,2)[0]==='en'?',':'.';
        $fields=$channel==='facebook'?['reaction_count'=>'Reaktionen']:['like_count'=>'Likes'];$fields['comments_count']='Kommentare';
        $h='<dl class="social-content-metrics" aria-label="'.$t('Reaktionen und Kommentare').'">';
        foreach($fields as$key=>$label){$value=$payload[$key]??null;$count=is_int($value)&&$value>=0?str_replace($separator,$separator.'<wbr>',Html::e(preg_replace('/(?<=\d)(?=(?:\d{3})+$)/',$separator,(string)$value))):'<span aria-label="'.$t('Nicht verfügbar').'">—</span>';$h.='<div data-social-metric="'.$key.'"><dt>'.$t($label).'</dt><dd>'.$count.'</dd></div>';}
        return$h.'</dl>';
    }
}
