<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;

/** Retain the selected versions, including across new pages and rejected edits. */
final class PressContactSelection
{
    public static function state(array $post,bool $browse=false): array
    {
        foreach(['selected','visible','choice']as$key)if(isset($post[$key])&&!is_array($post[$key]))throw new DomainException('Kontaktauswahl prüfen.');
        $selected=SubscriberSelection::versions($post['selected']??[]);$visible=SubscriberSelection::versions($post['visible']??[]);$choice=$post['choice']??[];
        if(count($visible)>6||!array_is_list($choice)||count($choice)>6)throw new DomainException('Sichtbare Kontaktauswahl prüfen.');
        foreach($choice as$id){Content::id($id);if(!isset($visible[$id]))throw new DomainException('Kontakt auf dieser Seite auswählen.');}
        if(count(array_unique($choice,SORT_STRING))!==count($choice))throw new DomainException('Doppelte Kontaktauswahl prüfen.');
        foreach($visible as$id=>$version){if(!in_array($id,$choice,true))unset($selected[$id]);elseif(!isset($selected[$id]))$selected[$id]=$version;}
        $state=['selected'=>SubscriberSelection::versions($selected),'query'=>Content::text($post['contact_query']??'',200),'status'=>$post['contact_status']??'all','page'=>SubscriberSelection::number($post['contact_page']??1),'mode'=>$post['contact_mode']??'all','focus'=>$post['contact_focus']??'','new'=>($post['contact_new']??'0')==='1'];
        if(!in_array($state['status'],['all','active','inactive'],true)||!in_array($state['mode'],['all','selected'],true)||!is_string($state['focus']))throw new DomainException('Kontaktfilter prüfen.');if($state['focus']!=='')Content::id($state['focus']);
        if($browse)switch($post['contact_browse']??''){
            case 'search':$state['page']=1;break;
            case 'previous':$state['page']=max(1,$state['page']-1);break;
            case 'next':$state['page']=min(1000000,$state['page']+1);break;
            case 'selected':$state['mode']='selected';$state['page']=1;break;
            case 'all':$state['mode']='all';$state['page']=1;break;
            case 'clear':$state['selected']=[];break;
            case 'new':$state['new']=true;$state['focus']='';break;
            case 'close':$state['new']=false;$state['focus']='';break;
            default:$state['focus']=Content::id($post['contact_browse']??'');$state['new']=false;
        }
        return$state;
    }
}
