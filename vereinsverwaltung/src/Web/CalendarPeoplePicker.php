<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Read-only paging of the original event form; only its Save action writes. */
final class CalendarPeoplePicker
{
    public static function ids(mixed $raw):array
    {
        if(!is_string($raw)||strlen($raw)>1400000||!str_starts_with(ltrim($raw),'['))throw new DomainException('Teilnehmerauswahl prüfen.');
        try{$ids=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Teilnehmerauswahl prüfen.');}
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>10000)throw new DomainException('Bis zu 10.000 Teilnehmer auswählen.');
        foreach($ids as$id)if(!is_string($id)||$id===''||strlen($id)>64||preg_match('/[\x00-\x1f\x7f]/',$id)||preg_match('//u',$id)!==1)throw new DomainException('Teilnehmerauswahl prüfen.');
        return array_values(array_unique($ids));
    }
    public static function restore(array $post):array
    {
        if(!in_array($post['action']??'',['save-event','series-preview','series-save'],true))throw new DomainException('Teilnehmer im ursprünglichen Termin auswählen.');
        $selected=self::ids($post['calendar_selected']??'[]');$visible=self::ids($post['calendar_visible']??'[]');$checked=$post['attendees']??[];
        if(!is_array($checked)||!array_is_list($checked)||count($checked)>6)throw new DomainException('Angezeigte Teilnehmer prüfen.');
        foreach($checked as$id)if(!is_string($id))throw new DomainException('Angezeigte Teilnehmer prüfen.');
        if(array_diff($checked,$visible))throw new DomainException('Angezeigte Teilnehmer prüfen.');
        $selected=array_values(array_unique([...array_diff($selected,$visible),...$checked]));
        $kind=$post['activity_kind']??'event';$prior=$post['calendar_kind']??$kind;
        if(!in_array($kind,['event','external','assembly','board','other'],true)||!in_array($prior,['event','external','assembly','board','other'],true))throw new DomainException('Terminart prüfen.');
        $browse=$post['calendar_browse']??'save';
        if(!in_array($browse,['save','search','previous','next','page','refresh','all','group','members','board','clear'],true))throw new DomainException('Teilnehmeraktion prüfen.');
        $groupKind=match($browse){'members'=>'assembly','board'=>'board',default=>$kind};if(in_array($browse,['members','board'],true))$browse='group';
        if($kind!==$prior)$selected=[];
        if($browse==='all')$selected=array_values(array_unique([...$selected,...$visible]));
        if($browse==='clear')$selected=[];
        if(count($selected)>10000)throw new DomainException('Bis zu 10.000 Teilnehmer auswählen.');
        $page=$post['calendar_page']??'1';$query=$post['calendar_query']??'';
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$page)||($page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000000000]]))===false||!is_string($query)||strlen($query)>150||preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Teilnehmersuche und Seite prüfen.');
        if($kind!==$prior){$query='';$page=1;}
        $page=match($browse){'search','refresh'=>1,'previous'=>max(1,$page-1),'next'=>min(1000000000,$page+1),default=>$page};
        return ['selected'=>$selected,'kind'=>$kind,'group_kind'=>$groupKind,'query'=>$query,'page'=>$page,'browse'=>$browse,'kind_changed'=>$kind!==$prior,'only_selected'=>$kind===$prior&&isset($post['calendar_only_selected'])];
    }
    public static function page(Registry $registry,array $form,array $state=[]):array
    {
        $kind=(string)$form['activity_kind'];$input=['contact_query'=>$state['query']??'','contact_page'=>$state['page']??1];if($state['only_selected']??false)$input['calendar_ids']=$state['selected'];$result=$registry->contactChoicePage($input,true,$kind);
        $selected=$state['selected']??$form['attendees'];
        if((!$state||($state['kind_changed']??false))&&($form['id']??'')===''&&$kind==='board')$selected=array_column($result['rows'],'id');
        return $result+['selection'=>array_values(array_unique($selected)),'kind'=>$kind,'only_selected'=>$state['only_selected']??false];
    }
}
