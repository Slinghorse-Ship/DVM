<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use InvalidArgumentException;
use Kulturhus\Native\Cms\TemplatePolicy;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;

/** Optional controls INSIDE the existing page-save form; never a second unchecked write route. */
final class PagePolicyPanel
{
    public static function render(array $page,Access $access):string
    {
        try{$access->require('website','manage');}catch(\DomainException){return '';}
        $policy=TemplatePolicy::pagePolicy($page['page_policy']??[]);
        $h='<details class="page-policy-panel"><summary>Zugriff auf diese Seite</summary>'.Html::hidden('page_policy_present','1');
        foreach(['inherit'=>'Zugriffsregeln übergeordneter Seiten','public'=>'Öffentliche Anzeige nach Veröffentlichung'] as $key=>$label)$h.=Html::select('page_policy_'.$key,$label,['inherit'=>'Vorlagenregel übernehmen','1'=>'Zulassen','0'=>'Ausschalten'],array_key_exists($key,$policy)?($policy[$key]?'1':'0'):'inherit');
        $levels=['read'=>'Website-Leseberechtigung','edit'=>'Website-Redaktion','publish'=>'Website-Veröffentlichung','manage'=>'Website-Administration'];
        foreach(['read'=>'Lesen','edit'=>'Bearbeiten','publish'=>'Veröffentlichen'] as $key=>$label){$choices=['inherit'=>'Vorlagenregel übernehmen']+match($key){'read'=>$levels,'edit'=>array_diff_key($levels,['read'=>true]),default=>array_intersect_key($levels,['publish'=>true,'manage'=>true])};$h.=Html::select('page_policy_'.$key,'Zusätzliche Berechtigung: '.$label,$choices,$policy[$key]??'inherit');}
        return $h.'</details>';
    }

    /** null preserves an earlier form's original rules; [] deliberately restores all template defaults. */
    public static function submitted(array $post,Access $access):?array
    {
        if(!array_key_exists('page_policy_present',$post))return null;
        $access->require('website','manage');
        if($post['page_policy_present']!=='1')throw new InvalidArgumentException('Seitenzugriff ausdrücklich bestätigen.');
        $policy=[];
        foreach(['inherit','public','read','edit','publish'] as $key){
            $value=$post['page_policy_'.$key]??'inherit';if(!is_string($value))throw new InvalidArgumentException('Gültige Zugriffsregel auswählen.');
            if($value==='inherit')continue;
            if(in_array($key,['inherit','public'],true)){if(!in_array($value,['0','1'],true))throw new InvalidArgumentException('Freigabe als Ja, Nein oder Vorlagenvorgabe wählen.');$policy[$key]=$value==='1';}
            else $policy[$key]=$value;
        }
        return TemplatePolicy::pagePolicy($policy);
    }
}
