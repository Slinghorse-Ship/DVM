<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;

/** Keep the versions actually selected, including when a later page shows a newer row. */
final class SubscriberSelection
{
    public static function versions(array $selection): array
    {
        if (count($selection)>100) throw new DomainException('Höchstens 100 Empfänger gleichzeitig auswählen.');
        $result=[];
        foreach ($selection as $id=>$version) {
            Content::id($id);
            $result[$id]=self::number($version,4294967295);
        }
        ksort($result,SORT_STRING);
        return $result;
    }

    public static function number(mixed $value,int $max=1000000): int
    {
        if ((!is_string($value)&&!is_int($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value) || (int)$value>$max) throw new DomainException('Gültige Empfängerseite und Version verwenden.');
        return (int)$value;
    }

    public static function state(array $post, bool $browse=false): array
    {
        foreach (['selected','visible','choice'] as $key) if (isset($post[$key])&&!is_array($post[$key])) throw new DomainException('Empfängerauswahl erneut öffnen.');
        $selected=self::versions($post['selected']??[]);
        $visible=self::versions($post['visible']??[]);
        if (count($visible)>6) throw new DomainException('Empfängerauswahl erneut öffnen.');
        $choices=$post['choice']??[];
        if (!array_is_list($choices)||count($choices)>6) throw new DomainException('Empfängerauswahl erneut öffnen.');
        foreach ($choices as $id) { Content::id($id); if (!isset($visible[$id])) throw new DomainException('Empfänger auf der angezeigten Seite auswählen.'); }
        if(count(array_unique($choices,SORT_STRING))!==count($choices)) throw new DomainException('Empfängerauswahl erneut öffnen.');
        foreach ($visible as $id=>$version) {
            if (!in_array($id,$choices,true)) unset($selected[$id]);
            elseif (!isset($selected[$id])) $selected[$id]=$version;
        }
        $selected=self::versions($selected);
        $focus=$post['contact_focus']??'';
        if (!is_string($focus)) throw new DomainException('Empfänger auswählen.');
        if ($focus!=='') Content::id($focus);
        $state=['selected'=>$selected,'query'=>Content::text($post['contact_query']??'',200),'status'=>$post['contact_status']??'all','page'=>self::number($post['contact_page']??1),'mode'=>$post['contact_mode']??'all','reason'=>Content::text($post['reason']??'',500),'focus'=>$focus,'history_page'=>self::number($post['contact_history_page']??1)];
        if (!in_array($state['status'],['all','confirmed','pending','unsubscribed'],true)||!in_array($state['mode'],['all','selected'],true)) throw new DomainException('Empfängerfilter prüfen.');
        if ($browse) {
            switch ($post['contact_browse']??'') {
                case 'search': $state['page']=1; break;
                case 'next': $state['page']=min(1000000,$state['page']+1); break;
                case 'previous': $state['page']=max(1,$state['page']-1); break;
                case 'selected': $state['mode']='selected'; $state['page']=1; break;
                case 'all': $state['mode']='all'; $state['page']=1; break;
                case 'clear': $state['selected']=[]; break;
                case 'close': $state['focus']=''; break;
                case 'history-next': $state['history_page']=min(1000000,$state['history_page']+1); break;
                case 'history-previous': $state['history_page']=max(1,$state['history_page']-1); break;
                default: $state['focus']=Content::id($post['contact_browse']??''); $state['history_page']=1;
            }
        }
        return $state;
    }
}
