<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\ConversationMessages;

/** Compact conversation shell; only declared interface labels are translated. */
final class ChatLayout
{
    public static function pagePicker(string $section,array $context,int $page,int $pages,array $messages=[]):string
    {
        if($pages<=1)return '';$t=static fn(string$s):string=>Html::e(ConversationMessages::text($s,$messages));
        $field=$section==='inbox'?'conversation_page':'review_page';unset($context[$field]);
        $h='<form method="get" class="chat-catalogue-page">';foreach(['view'=>'social','section'=>$section,'chat_list'=>'1']+$context as$key=>$value)$h.=Html::hidden($key,$value);
        return $h.'<label>'.$t('Gesprächsseite').'<input class="form-control" type="number" name="'.$field.'" min="1" max="'.$pages.'" value="'.$page.'" inputmode="numeric" required></label><button class="btn btn-outline theme-secondary">'.$t('Seite öffnen').'</button></form>';
    }
    public static function frame(string $list,string $thread,string $tools='',string $section='review',bool $selected=true,string $listControls='',array $messages=[],?array $input=null):string
    {
        $input??=$_GET;
        $t=static fn(string$s):string=>Html::e(ConversationMessages::text($s,$messages));
        $keys=$section==='inbox'?['conversation_page','inbox_channel','q']:['review_page','review_channel','review_kind','state','q'];
        $context=array_intersect_key($input,array_flip($keys));$listUrl='/?'.http_build_query(['view'=>'social','section'=>$section,'chat_list'=>'1']+$context);
        $showList=($input['chat_list']??null)==='1'||!$selected;$title=$section==='inbox'?'Nachrichten':'Kommentare';
        $options=$tools!==''?'<details class="chat-options"><summary>'.$t('Optionen').'</summary><div class="chat-options-panel">'.$tools.'</div></details>':'';
        $query=is_string($input['q']??null)?mb_substr(trim($input['q']),0,200):'';$filters='';foreach(array_diff($keys,[$section==='inbox'?'conversation_page':'review_page','q'])as$key)if(isset($input[$key])&&is_string($input[$key]))$filters.=Html::hidden($key,$input[$key]);
        $search='<form method="get" class="chat-search">'.Html::hidden('view','social').Html::hidden('section',$section).Html::hidden('chat_list','1').$filters.'<label>'.$t('Suchen').'<input class="form-control" type="search" name="q" data-chat-search autocomplete="off" placeholder="'.$t('Name, Text oder Plattform').'" value="'.Html::e($query).'"></label><button class="btn btn-outline theme-secondary">'.$t('Suchen').'</button></form>';
        return '<div class="social-chat'.($showList?' chat-show-list':'').'" data-social-chat data-conversation-interface="'.Html::e(json_encode(ConversationMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><aside class="social-chat-sidebar"><header><strong>'.$t($title).'</strong></header>'.$search.'<nav class="social-chat-list" aria-label="'.$t('Gespräche').'">'.$list.'</nav>'.$listControls.'</aside><section class="social-chat-thread"><div class="chat-toolbar"><a data-nav class="btn btn-outline theme-secondary chat-mobile" href="'.Html::e($listUrl).'">'.$t('← Gespräche').'</a>'.$options.'</div>'.$thread.'</section></div>';
    }
    public static function choice(string $url,string $name,string $text,string $channel,bool $active,string $state='',string $kind='comment',array $messages=[],string $origin=''):string
    {
        return '<a data-nav class="chat-choice'.($active?' active':'').'" href="'.Html::e($url).'"'.($active?' aria-current="page"':'').'><strong data-original-content>'.Html::e($name).'</strong><span class="chat-preview" data-original-content>'.Html::e(mb_substr($text,0,160)).'</span>'.($origin!==''?'<small class="chat-choice-origin" data-original-content>'.Html::e($origin).'</small>':'').self::identity($kind,$channel,$messages).($state!==''?'<small class="chat-choice-state">'.Html::e($state).'</small>':'').'</a>';
    }
    public static function identity(string $kind,string $channel,array $messages=[]):string
    {
        $type=ConversationMessages::text(['comment'=>'Kommentar','message'=>'Nachricht','reaction'=>'Reaktion'][$kind]??'Aktivität',$messages);
        $platform=['facebook'=>'Facebook','instagram'=>'Instagram','stadtlandfunk'=>'StadtLand.Funk'][$channel]??$channel;
        $color=['Facebook'=>'facebook','Instagram'=>'instagram','StadtLand.Funk'=>'stadtlandfunk'][$platform]??'other';
        return '<small class="chat-identity"><span class="chat-kind">'.Html::e($type).'</span><span class="chat-platform chat-platform-'.$color.'">'.Html::e($platform).'</span></small>';
    }
    private static function query():string{return is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,200):'';}
    public static function bubble(string $name,string $text,string $time,bool $outgoing=false,string $status='',bool $focused=false,string $term='',string $utc=''):string
    {
        $position=$focused&&$term!==''?mb_stripos($text,$term):false;$content=Html::e($text);
        if($position!==false){$length=mb_strlen($term);$content=Html::e(mb_substr($text,0,$position)).'<mark data-chat-hit-focus tabindex="-1" autofocus>'.Html::e(mb_substr($text,$position,$length)).'</mark>'.Html::e(mb_substr($text,$position+$length));}
        $clock=$utc!==''?'<time datetime="'.Html::e((new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')).'">'.Html::e($time).'</time>':Html::e($time);
        return '<article class="chat-bubble'.($outgoing?' outgoing':'').'"'.($focused?' data-chat-hit'.($position===false?' data-chat-hit-focus tabindex="-1" autofocus':''):'').'><strong data-original-content>'.Html::e($name).'</strong><p class="social-caption" data-original-content>'.$content.'</p><small>'.$clock.($status!==''?' <span class="badge">'.Html::e($status).'</span>':'').'</small></article>';
    }
}
