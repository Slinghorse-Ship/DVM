<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Read-only person selection inside the original assignment form. */
final class ContactChoicePicker
{
    public static function restore(array $post):array
    {
        $action=$post['action']??null;$fields=match($action){'settings-assign'=>['catalog_id'=>64,'contact_id'=>64,'start'=>10,'end'=>10],'donation-contact'=>['source_type'=>20,'source_id'=>64,'source_version'=>10,'contact_id'=>64],default=>throw new DomainException('Kontaktauswahl im ursprünglichen Formular öffnen.')};$data=[];
        foreach($fields as$key=>$max){$value=$post[$key]??'';if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Auswahl prüfen.');$data[$key]=$value;}
        $query=$post['contact_query']??'';$page=$post['contact_page']??'1';$browse=$post['contact_browse']??'search';
        if(!is_string($query)||strlen($query)>150||preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$page)||($page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false||!in_array($browse,['search','previous','next','page','clear'],true))throw new DomainException('Kontaktsuche und Seite prüfen.');
        $page=match($browse){'search'=>1,'previous'=>max(1,$page-1),'next'=>min(1000000000,$page+1),default=>$page};if($browse==='clear')$data['contact_id']='';
        return ['action'=>$action,'data'=>$data,'contact_query'=>$query,'contact_page'=>$page,'contact_id'=>$data['contact_id']];
    }
    public static function render(Registry $registry,array $input=[],array $messages=[]):string
    {
        $unavailable=false;try{$result=$registry->contactChoicePage($input);}catch(DomainException $error){if(!is_string($input['contact_id']??null)||$input['contact_id']===''||strlen($input['contact_id'])>64)throw$error;$result=$registry->contactChoicePage(array_replace($input,['contact_id'=>'']));$unavailable=true;}
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn(string $source):string=>$e(\Kulturhus\Native\Language\DonationContactMessages::text($source,$messages));
        $h='<fieldset class="contact-choice-picker" data-contact-choice><legend>'.$t('Kontakt auswählen').'</legend><input type="hidden" name="contact_id" value="'.$e($result['selected']['id']??($unavailable?$input['contact_id']:'')).'"><p data-contact-selected>'.($unavailable?$t('Gewählten Kontakt erneut prüfen.'):($result['selected']?$t('Gewählt: ').$e($result['selected']['name']):$t('Noch kein Kontakt gewählt'))).'</p><div class="contact-choice-search"><label>'.$t('Name oder E-Mail').'<input class="form-control" type="search" name="contact_query" maxlength="150" value="'.$e($result['query']).'"></label><button class="btn btn-outline theme-secondary" name="contact_browse" value="search" formnovalidate>'.$t('Suchen').'</button></div><p>'.$result['total'].' '.$t('Kontakte').'</p><div class="contact-choice-options">';
        foreach($result['rows']as$row)$h.='<label class="check"><input class="check" type="radio" name="contact_id" value="'.$e($row['id']).'"'.(($result['selected']['id']??null)===$row['id']?' checked':'').'><span>'.$e($row['name']).'</span></label>';
        if(!$result['rows'])$h.='<p>'.$t('Keine passenden Kontakte.').'</p>';
        $h.='</div><nav class="contact-choice-pages" aria-label="'.$t('Kontaktseiten').'"><p>'.$t('Seite').' '.$result['page'].' '.$t('von').' '.$result['pages'].'</p><button class="btn btn-outline theme-secondary" name="contact_browse" value="previous" formnovalidate'.($result['page']===1?' disabled':'').'>'.$t('Zurück').'</button><button class="btn btn-outline theme-secondary" name="contact_browse" value="next" formnovalidate'.($result['page']===$result['pages']?' disabled':'').'>'.$t('Weiter').'</button><label>'.$t('Seite öffnen').'<input class="form-control" type="number" name="contact_page" min="1" max="'.$result['pages'].'" value="'.$result['page'].'"></label><button class="btn btn-outline theme-secondary" name="contact_browse" value="page" formnovalidate>'.$t('Öffnen').'</button></nav></fieldset>';
        return $h;
    }
}
