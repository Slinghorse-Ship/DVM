<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Exact recipient choices in the original preview and confirmation forms. */
final class CalendarRecipientPicker
{
    public static function restore(array $post):array
    {
        $action=$post['action']??'';$kind=match($action){'preview-event-mail','send-event-mail'=>'mail','preview-event-print','create-event-print'=>'print',default=>throw new DomainException('Empfänger im ursprünglichen Einladungsformular wählen.')};
        $scope=$post['recipient_scope']??'choice';$confirmed=in_array($action,['send-event-mail','create-event-print'],true);
        if($scope!==($confirmed?'confirmed':'choice')||($confirmed&&isset($post['recipient_browse'])))throw new DomainException('Ursprüngliche Vorschau erneut prüfen.');
        $selected=CalendarPeoplePicker::ids($post['recipient_selected']??'[]');
        if($confirmed)return ['kind'=>$kind,'selected'=>$selected,'scope'=>$scope,'query'=>'','page'=>1,'browse'=>'confirm','only_selected'=>false];
        $visible=CalendarPeoplePicker::ids($post['recipient_visible']??'[]');$checked=$post['recipients']??[];
        if(!is_array($checked)||!array_is_list($checked)||count($checked)>6)throw new DomainException('Angezeigte Empfänger prüfen.');
        foreach($checked as$id)if(!is_string($id))throw new DomainException('Angezeigte Empfänger prüfen.');
        if(array_diff($checked,$visible))throw new DomainException('Angezeigte Empfänger prüfen.');
        $selected=array_values(array_unique([...array_diff($selected,$visible),...$checked]));
        $browse=$post['recipient_browse']??'preview';if(!in_array($browse,['preview','search','previous','next','page','page-all','all','clear'],true))throw new DomainException('Empfängeraktion prüfen.');
        if($browse==='page-all')$selected=array_values(array_unique([...$selected,...$visible]));
        if($browse==='clear')$selected=[];
        if(count($selected)>10000)throw new DomainException('Bis zu 10.000 Empfänger auswählen.');
        $query=$post['recipient_query']??'';$page=$post['recipient_page']??'1';
        if(!is_string($query)||strlen($query)>150||preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$page)||($page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false)throw new DomainException('Empfängersuche und Seite prüfen.');
        $page=match($browse){'search'=>1,'previous'=>max(1,$page-1),'next'=>min(1000000000,$page+1),default=>$page};
        return ['kind'=>$kind,'scope'=>$scope,'selected'=>$selected,'query'=>$query,'page'=>$page,'browse'=>$browse,'only_selected'=>isset($post['recipient_only_selected'])];
    }
    public static function confirmation(array $selected):string
    {
        return self::hidden('recipient_scope','confirmed').self::hidden('recipient_selected',json_encode(array_values($selected),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    private static function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function hidden(string $name,string $value):string{return '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';}
    public static function render(CalendarInvitations $service,string $eventId,string $kind,array $selected=[],array $state=[],?callable $text=null):string
    {
        $text??=static fn(string $source):string=>$source;
        $format=static fn(string $source,array $values):string=>strtr($text($source),array_combine(array_map(static fn(string $key):string=>'{'.$key.'}',array_keys($values)),array_map('strval',array_values($values))));
        $input=['contact_query'=>$state['query']??'','contact_page'=>$state['page']??1];if($state['only_selected']??false)$input['calendar_ids']=$selected;$page=$service->selectionPage($eventId,$input);
        $h='<section class="contact-choice-picker" data-calendar-recipient="'.self::e($kind).'" data-recipient-total="'.$page['total'].'">'.self::hidden('recipient_scope','choice').self::hidden('recipient_selected',json_encode(array_values($selected),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).self::hidden('recipient_visible',json_encode(array_column($page['rows'],'id'),JSON_THROW_ON_ERROR));
        $h.='<div class="contact-choice-search"><label>'.self::e($text('Name suchen')).'<input class="form-control" type="search" name="recipient_query" maxlength="150" value="'.self::e($page['query']).'"></label><button class="btn btn-outline theme-secondary" name="recipient_browse" value="search" formnovalidate>'.self::e($text('Suchen')).'</button></div><label class="check"><input class="check" type="checkbox" name="recipient_only_selected" value="1"'.(($state['only_selected']??false)?' checked':'').'>'.self::e($text('Nur ausgewählte Personen anzeigen')).'</label><p role="status" data-recipient-count>'.self::e($format('{selected} ausgewählt · {total} Suchtreffer',['selected'=>count($selected),'total'=>$page['total']])).'</p><div class="actions"><button class="btn btn-outline theme-secondary" name="recipient_browse" value="page-all" formnovalidate>'.self::e($text('Diese Seite auswählen')).'</button><button class="btn btn-outline theme-secondary" name="recipient_browse" value="all" formnovalidate>'.self::e($text('Alle verfügbaren auswählen')).'</button><button class="btn btn-outline theme-secondary" name="recipient_browse" value="clear" formnovalidate>'.self::e($text('Gesamte Auswahl aufheben')).'</button></div><fieldset class="contact-choice-options"><legend>'.self::e($text('Personen auswählen')).'</legend>';
        foreach($page['rows']as$person)$h.='<label class="check"><input class="check" type="checkbox" name="recipients[]" value="'.self::e($person['id']).'"'.(in_array($person['id'],$selected,true)?' checked':'').'><span>'.self::e($person['name']).($kind==='mail'?'<small>'.self::e($person['email']?:$text('keine E-Mail')).($person['email_source']==='family'?' · '.self::e($text('Familienadresse')):'').'</small>':'').'</span></label>';
        if(!$page['rows'])$h.='<p>'.self::e($text('Keine passenden Personen.')).'</p>';
        $h.='</fieldset><nav class="contact-choice-pages" aria-label="'.self::e($text('Empfängerseiten')).'"><p>'.self::e($format('Seite {page} von {pages}',['page'=>$page['page'],'pages'=>$page['pages']])).'</p><button class="btn btn-outline theme-secondary" name="recipient_browse" value="previous" formnovalidate'.($page['page']===1?' disabled':'').'>'.self::e($text('Zurück')).'</button><button class="btn btn-outline theme-secondary" name="recipient_browse" value="next" formnovalidate'.($page['page']===$page['pages']?' disabled':'').'>'.self::e($text('Weiter')).'</button><label>'.self::e($text('Seite öffnen')).'<input class="form-control" type="number" name="recipient_page" min="1" max="'.$page['pages'].'" value="'.$page['page'].'"></label><button class="btn btn-outline theme-secondary" name="recipient_browse" value="page" formnovalidate>'.self::e($text('Öffnen')).'</button></nav></section>';
        return $h;
    }
}
