<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Read-only choices inside the original admission decision or manual intake. */
final class AdmissionChoicePicker
{
    public static function browse(MembershipApplications $intake,array $post):void
    {
        if(!in_array($post['action']??null,['review-application','manual-application'],true)||!is_string($post['admission_browse']??null)||!preg_match('/\A(family|contact):(search|previous|next|page|clear)\z/D',$post['admission_browse'],$match))throw new DomainException('Zuordnung im ursprünglichen Antragsformular öffnen.');
        $kind=$match[1];$operation=$match[2];$prefix='admission_'.$kind.'_';$page=$post[$prefix.'page']??'1';
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Zuordnungsseite prüfen.');
        $page=match($operation){'search'=>1,'previous'=>max(1,(int)$page-1),'next'=>min(999999999,(int)$page+1),default=>(int)$page};
        $field=$kind==='family'?'family_target':'existing_contact';
        $selected=$operation==='clear'?'':($post[$field]??'');
        $result=$intake->admissionChoicePage($kind,['query'=>$post[$prefix.'query']??'','page'=>$page,'selected'=>$selected]);
        $_POST[$prefix.'page']=(string)$result['page'];
        $_POST[$field]=$selected;
    }

    public static function render(MembershipApplications $intake,string $kind,array $post=[],string $initialFamily='',array $messages=[]):string
    {
        $prefix='admission_'.$kind.'_';$field=$kind==='family'?'family_target':'existing_contact';$selected=$post[$field]??'';
        if($kind==='family'&&!array_key_exists('family_target',$post)&&$initialFamily!==''){
            // A direct family link is resolved through the same own reviewer scope.
            $probe=$intake->admissionChoicePage('family',['selected'=>$initialFamily.':1']);
            $selected=$probe['selected']['id'].':'.$probe['selected']['version'];
        }
        $unavailable=false;$originalProblem=null;
        try{$result=$intake->admissionChoicePage($kind,['query'=>$post[$prefix.'query']??'','page'=>$post[$prefix.'page']??1,'selected'=>$selected]);}
        catch(DomainException $error){
            $unavailable=is_string($selected)&&$selected!==''&&!in_array($selected,['single','new'],true);
            try{if(!$unavailable)throw$error;$result=$intake->admissionChoicePage($kind,['query'=>$post[$prefix.'query']??'','page'=>$post[$prefix.'page']??1]);}
            catch(DomainException){$originalProblem=['query'=>$post[$prefix.'query']??'','page'=>$post[$prefix.'page']??'1','selected'=>$selected];$result=['rows'=>[],'selected'=>null,'total'=>0,'pages'=>1,'query'=>is_string($originalProblem['query'])?$originalProblem['query']:'','page'=>is_string($originalProblem['page'])?$originalProblem['page']:''];}
        }
        $e=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn(string $text,array $values=[]):string=>\Kulturhus\Native\Language\ApplicationReviewMessages::text($text,$messages,$values);
        $label=static fn(array $row):string=>$row['name'].($kind==='family'?' · '.$t('Beitragszahler').': '.$row['payer_name']:'');
        $value=static fn(array $row):string=>$row['id'].($kind==='family'?':'.$row['version']:'');
        $title=$t($kind==='family'?'Familie & Beitrag':'Vorhandenes Kontaktprofil');$noun=$t($kind==='family'?'Familien':'Kontaktprofile');
        $h='<fieldset class="contact-choice-picker admission-choice-picker" data-admission-choice="'.$kind.'"><legend>'.$e($title).'</legend><label>'.$e($t($kind==='family'?'Beitragszuordnung':'Kontaktprofil')).'<select class="form-control" name="'.$field.'"><option value="">'.$e($t($kind==='family'?'Bitte vor der Aufnahme auswählen':'Neues Profil anlegen')).'</option>';
        if($kind==='family')foreach(['single'=>$t('Einzelmitglied · {rate} jährlich',['rate'=>ContributionRates::label($intake->rates()['single']['amount_cents'])]),'new'=>$t('Neue Familie · Antragsteller zahlt {rate} jährlich',['rate'=>ContributionRates::label($intake->rates()['family']['amount_cents'])])]as$key=>$text)$h.='<option value="'.$key.'"'.($selected===$key?' selected':'').'>'.$e($text).'</option>';
        $options=$result['rows'];
        if($result['selected']&&!in_array($result['selected']['id'],array_column($options,'id'),true))array_unshift($options,$result['selected']);
        if($originalProblem!==null)$h.='<option value="__invalid_original_assignment__"'.(!is_string($selected)?' selected':'').'>'.$e($t('Ursprüngliche Zuordnungsangaben prüfen')).'</option>';
        if($unavailable)$h.='<option value="'.$e($selected).'" selected>'.$e($t('Gewählte Zuordnung erneut prüfen')).'</option>';
        foreach($options as$row){$current=$value($row);$chosen=$result['selected']&&$result['selected']['id']===$row['id'];$token=$chosen?$selected:$current;$stale=$kind==='family'&&$chosen&&$selected!==$current;$h.='<option value="'.$e($token).'"'.($chosen?' selected':'').'>'.$e($label($row)).($stale?' · '.$e($t('inzwischen geändert')):'').'</option>';}
        $h.='</select></label><div class="contact-choice-search"><label>'.$e($t($kind==='family'?'Familie oder Beitragszahler':'Name oder E-Mail')).'<input class="form-control" type="search" name="'.$prefix.'query" maxlength="150" value="'.$e($result['query']).'"'.(is_string($post['admission_browse']??null)&&str_starts_with($post['admission_browse'],$kind.':')?' autofocus':'').'></label><button class="btn btn-outline theme-secondary" name="admission_browse" value="'.$kind.':search" formnovalidate>'.$e($t('Suchen')).'</button></div><nav class="contact-choice-pages" aria-label="'.$e($t('{noun} auswählen',['noun'=>$noun])).'"><p>'.$e($t('{count} {noun} · Seite {page}/{pages}',['count'=>$result['total'],'noun'=>$noun,'page'=>$result['page'],'pages'=>$result['pages']])).'</p>';
        foreach(['previous'=>'Zurück','next'=>'Weiter']as$operation=>$text)$h.='<button class="btn btn-outline theme-secondary" name="admission_browse" value="'.$kind.':'.$operation.'" formnovalidate'.(($operation==='previous'&&$result['page']===1)||($operation==='next'&&$result['page']===$result['pages'])?' disabled':'').'>'.$e($t($text)).'</button>';
        if($originalProblem!==null)$h.='<details open><summary>'.$e($t('Ursprüngliche Zuordnungsangaben prüfen')).'</summary><pre>'.$e(json_encode($originalProblem,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details>';
        return $h.'<label>'.$e($t('Seite öffnen')).'<input class="form-control" type="number" name="'.$prefix.'page" min="1" max="'.$result['pages'].'" value="'.$result['page'].'"></label><button class="btn btn-outline theme-secondary" name="admission_browse" value="'.$kind.':page" formnovalidate>'.$e($t('Öffnen')).'</button></nav></fieldset>';
    }
}
