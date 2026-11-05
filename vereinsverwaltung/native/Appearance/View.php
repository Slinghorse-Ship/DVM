<?php declare(strict_types=1);
namespace Kulturhus\Native\Appearance;

/** Ordinary independent form. Failed saves retain the submitted choice and old CAS. */
final class View
{
    public static function render(array $state,string $csrf,?array $submitted=null,string $error='',string $locale='de',string $managerUrl=''):string
    {
        $en=explode('-',$locale,2)[0]==='en';$e=self::escape(...);
        $t=static fn(string $de,string $english):string=>$en?$english:$de;
        $data=$state['data'];$version=(string)$state['version'];$scope=$state['scope'];
        if($submitted!==null){foreach(Values::defaults()as$key=>$default)$data[$key]=is_string($submitted[$key]??null)?$submitted[$key]:'';$version=is_string($submitted['appearance_version']??null)?$submitted['appearance_version']:'';$scope=is_string($submitted['appearance_scope']??null)?$submitted['appearance_scope']:'';}
        // A fresh explicit GET must also reload when the current page already has this fragment.
        $reload=($managerUrl!==''?$managerUrl.'&refresh=':'/account?section=appearance&refresh=').bin2hex(random_bytes(8)).'#account-appearance';
        $html='<div class="admin-appearance" data-admin-appearance>';
        if($error!=='')$html.='<p class="appearance-result" role="alert" tabindex="-1">'.$e($error).'</p><p><a class="btn btn-outline theme-secondary" href="'.$e($reload).'">'.$t('Aktuellen Stand öffnen','Open current version').'</a></p>';
        $html.='<form method="post" action="'.$e($managerUrl).'#account-appearance" data-appearance-form>';
        foreach(['csrf'=>$csrf,($managerUrl!==''?'action':'operation')=>($managerUrl!==''?'appearance-save':'select-appearance'),'appearance_version'=>$version,'appearance_scope'=>$scope]as$key=>$value)$html.='<input type="hidden" name="'.$key.'" value="'.$e($value).'">';
        $html.='<fieldset class="appearance-views"><legend>'.$t('Verwaltungsansicht','Administration layout').'</legend>';
        foreach(['studio'=>[$t('Studio','Studio'),$t('Navigation links','Navigation on the left')],'focus'=>[$t('Fokus','Focus'),$t('Navigation oben','Navigation at the top')],'compact'=>[$t('Kompakt','Compact'),$t('Listen im Mittelpunkt','More space for lists')]]as$key=>[$label,$description]){
            $html.='<label class="appearance-choice"><input type="radio" name="admin_view" value="'.$key.'" required'.($data['admin_view']===$key?' checked':'').'><span class="appearance-preview appearance-preview-'.$key.'" aria-hidden="true"><i></i><span><b></b><b></b><b></b></span></span><strong>'.$e($label).'</strong><small>'.$e($description).'</small></label>';
        }
        $html.='</fieldset><div class="appearance-options">';
        foreach(['admin_color'=>[$t('Farbmodus','Color mode'),['system'=>$t('Wie auf diesem Gerät','Use device setting'),'light'=>$t('Hell','Light'),'dark'=>$t('Dunkel','Dark')]],'admin_density'=>[$t('Abstände','Spacing'),['comfortable'=>$t('Großzügig','Comfortable'),'compact'=>$t('Kompakt','Compact')]]]as$key=>[$label,$choices]){
            $html.='<label>'.$e($label).'<select class="form-control" name="'.$key.'" required>';
            foreach($choices as$value=>$text)$html.='<option value="'.$value.'"'.($data[$key]===$value?' selected':'').'>'.$e($text).'</option>';
            $html.='</select></label>';
        }
        return$html.'</div><div class="appearance-actions"><button class="btn btn-solid theme-primary" type="submit">'.$t('Ansicht speichern','Save layout').'</button><a class="btn btn-outline theme-secondary" data-appearance-discard href="'.$e($reload).'">'.$t('Änderungen verwerfen','Discard changes').'</a></div></form></div>';
    }
    private static function escape(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
