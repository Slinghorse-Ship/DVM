<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;

use InvalidArgumentException;
use Kulturhus\Native\Cms\{TemplateContexts,TemplatePolicy,Templates,WebsiteDesign};
use Kulturhus\Native\Web\Html;

/** Ordinary labelled form controls for optional template rules and local field settings. */
final class TemplateSettings
{
    public static function render(array $template,array $fields,array $templates=[],?\Kulturhus\Native\TemplateRenderers\Registry $renderers=null, ?callable $interfaceText=null):string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $policy=array_replace(TemplatePolicy::DEFAULTS,$template['policy']??[]);$contexts=$template['field_contexts']??[];
        $choices=[];foreach($templates as $row)$choices[$row['key']]=$row['label'];
        if(!$choices)foreach(Templates::all() as $key=>$row)$choices[$key]=$row['label'];
        if(($template['key']??'')!=='')$choices[$template['key']]=$template['label'];
        foreach([...($policy['parents']??[]),...($policy['children']??[])] as $key)$choices[$key]??=$key;
        $h=Html::hidden('template_settings','1').('<details class="template-settings"><summary>' . Html::e($t('Familien, Zugriff und Darstellung')) . '</summary>').self::check('policy_enabled',$t('Eigene Vorlagenregeln verwenden'),!empty($template['policy']), interfaceText:$t).('<details class="template-family-settings"><summary>' . Html::e($t('Vorlagenfamilie')) . '</summary>');
        foreach(['parents'=>$t('Übergeordnete Vorlagen'),'children'=>$t('Untergeordnete Vorlagen')] as $key=>$label){
            $selected=$policy[$key];$mode=$selected===null?'any':($selected===[]?'none':'selected');
            $h.=Html::select('policy_'.$key.'_mode',$label,['any'=>$t('Alle Vorlagen zulassen'),'selected'=>$t('Ausgewählte Vorlagen zulassen'),'none'=>$t('Keine Vorlage zulassen')],$mode).'<label>'.Html::e(str_replace('{label}',$label,$t('{label} auswählen'))).'<select class="form-control" name="policy_'.$key.'[]" multiple size="4">';
            foreach($choices as $value=>$title)$h.='<option data-original-content value="'.Html::e($value).'"'.(in_array($value,$selected??[],true)?' selected':'').'>'.Html::e($title).'</option>';$h.='</select></label>';
        }
        $h.=self::check('policy_root',$t('Seiten ohne übergeordnete Seite zulassen'),$policy['root'], interfaceText:$t).('</details><details class="template-access-settings"><summary>' . Html::e($t('Zugriff und Vererbung')) . '</summary>').self::check('policy_inherit',$t('Zugriffsregeln der Elternseiten übernehmen'),$policy['inherit'], interfaceText:$t).self::check('policy_public',$t('Geprüfte Veröffentlichungen öffentlich anzeigen'),$policy['public'], interfaceText:$t);
        $levels=['read'=>'Website-Leseberechtigung','edit'=>'Website-Redaktion','publish'=>$t('Website-Veröffentlichung'),'manage'=>'Website-Administration'];$levels=array_map($t,$levels);
        foreach(['read'=>$t('Seiten lesen'),'edit'=>$t('Seiten bearbeiten'),'publish'=>$t('Seiten veröffentlichen')] as $key=>$label){$allowed=match($key){'read'=>$levels,'edit'=>array_diff_key($levels,['read'=>true]),default=>array_intersect_key($levels,['publish'=>true,'manage'=>true])};$h.=Html::select('policy_'.$key,str_replace('{label}',$label,$t('Zusätzlich benötigte Berechtigung: {label}')),$allowed,$policy[$key]);}
        $h.=('</details><details class="template-presentation-settings"><summary>' . Html::e($t('Design und Zwischenspeicherung')) . '</summary>');
        $themes=[''=>$t('Vereinsdesign übernehmen')];foreach(WebsiteDesign::templates() as $key=>$row)$themes[$key]=$t($row['name']);
        $h.=Html::select('policy_theme','Website-Design',$themes,$policy['theme']??'').Html::select('policy_cache','Zwischenspeicherung',['inherit'=>$t('Vereinsvorgabe übernehmen'),'off'=>$t('Für diese Vorlage ausschalten'),'standard'=>$t('Standard verwenden')],$policy['cache']).'</details></details>';
        $byKey=array_column($fields,null,'key');$h.=('<details class="template-field-contexts"><summary>' . Html::e($t('Felder für diese Vorlage anpassen')) . '</summary>');
        if(!$template['fields'])$h.=('<p>' . Html::e($t('Zuerst die gewünschten Felder auswählen und die Vorlage speichern.')) . '</p>');
        foreach($template['fields'] as $key){
            if(!isset($byKey[$key]))continue;$field=$byKey[$key];$context=$contexts[$key]??[];$n='template_contexts['.$key.']';
            $h.='<details><summary>'.Html::e($field['label']).'</summary>'.self::check($n.'[enabled]',$t('Eigene Feldeinstellungen für diese Vorlage'),isset($contexts[$key]), interfaceText:$t).Html::select($n.'[label_mode]',$t('Feldbezeichnung'),['inherit'=>$t('Gemeinsame Bezeichnung verwenden'),'custom'=>$t('Eigene Bezeichnung verwenden')],array_key_exists('label',$context)?'custom':'inherit').Html::input($n.'[label]',$t('Eigene Feldbezeichnung'),$context['label']??$field['label'],'text','maxlength="160"');
            $h.=Html::select($n.'[required]','Pflichtangabe',['inherit'=>$t('Gemeinsame Vorgabe übernehmen'),'1'=>'Pflichtangabe','0'=>$t('Freiwillige Angabe')],array_key_exists('required',$context)?($context['required']?'1':'0'):'inherit');
            if(in_array($field['type'],['text','textarea','richtext','url','email','select','integer','decimal','date'],true))foreach(['min'=>$t('Untergrenze'),'max'=>$t('Obergrenze')] as $bound=>$label){$mode=!array_key_exists($bound,$context)?'inherit':($context[$bound]===null?'none':'custom');$h.=Html::select($n.'['.$bound.'_mode]',$label,['inherit'=>$t('Gemeinsame Grenze übernehmen'),'none'=>$t('Keine Grenze'),'custom'=>$t('Eigene Grenze verwenden')],$mode).Html::input($n.'['.$bound.']','Eigene '.$label,$context[$bound]??'','text','maxlength="40"');}
            if($field['type']==='select')$h.=Html::select($n.'[options_mode]','Auswahlwerte',['inherit'=>$t('Gemeinsame Auswahl verwenden'),'custom'=>$t('Eigene Auswahl verwenden')],array_key_exists('options',$context)?'custom':'inherit').('<label>' . Html::e($t('Eigene Auswahlwerte, je Zeile ein Wert')) . '<textarea class="form-control" name="').Html::e($n.'[options]').'" rows="4">'.Html::e(implode("\n",$context['options']??$field['options'])).'</textarea></label>';
            $h.=Html::select($n.'[display]','Feldanzeige',['inherit'=>$t('Gemeinsame Anzeige verwenden'),'visible'=>$t('Sichtbar und bearbeitbar'),'readonly'=>$t('Nur lesbar'),'hidden'=>'Verborgen'],$context['display']??'inherit').'</details>';
        }
        return $h.'</details>'.\Kulturhus\Native\Routing\View::render($template,interfaceText:$t).\Kulturhus\Native\TemplateRenderers\View::render($template,$renderers,interfaceText:$t);
    }

    public static function submitted(array $post,array $fields):array
    {
        if(!array_key_exists('template_settings',$post))return [];
        if($post['template_settings']!=='1')throw new InvalidArgumentException('Vorlageneinstellungen prüfen.');
        $keys=isset($fields[0])&&is_array($fields[0])?array_column($fields,'key'):$fields;$contexts=[];$input=$post['template_contexts']??[];
        if(!is_array($input)||(array_is_list($input)&&$input!==[]))throw new InvalidArgumentException('Feldeinstellungen dieser Vorlage prüfen.');
        foreach($input as $key=>$row){
            if(!is_string($key)||!is_array($row))throw new InvalidArgumentException('Nur ausgewählte Vorlagenfelder anpassen.');
            // Unchecking a template field deliberately removes its former local context as well.
            if(!in_array($key,$keys,true))continue;
            if(!self::checked($row,'enabled'))continue;$entry=[];
            $label=self::choice($row,'label_mode',['inherit','custom'],'inherit');if($label==='custom')$entry['label']=self::text($row['label']??null);
            $required=self::choice($row,'required',['inherit','0','1'],'inherit');if($required!=='inherit')$entry['required']=$required==='1';
            foreach(['min','max'] as $bound){$mode=self::choice($row,$bound.'_mode',['inherit','none','custom'],'inherit');if($mode!=='inherit')$entry[$bound]=$mode==='none'?null:self::text($row[$bound]??null);}
            if(self::choice($row,'options_mode',['inherit','custom'],'inherit')==='custom'){$options=self::text($row['options']??null);$entry['options']=preg_split('/\r\n|\r|\n/',$options);}
            $display=self::choice($row,'display',['inherit','visible','readonly','hidden'],'inherit');if($display!=='inherit')$entry['display']=$display;
            $contexts[$key]=$entry;
        }
        $policy=[];
        if(self::checked($post,'policy_enabled')){
            foreach(['parents','children'] as $kind){$mode=self::choice($post,'policy_'.$kind.'_mode',['any','selected','none'],'any');$policy[$kind]=match($mode){'any'=>null,'none'=>[],default=>$post['policy_'.$kind]??[]};}
            foreach(['root','inherit','public'] as $key)$policy[$key]=self::checked($post,'policy_'.$key);
            foreach(['read','edit','publish','theme','cache'] as $key)$policy[$key]=self::text($post['policy_'.$key]??TemplatePolicy::DEFAULTS[$key]??'');if($policy['theme']==='')$policy['theme']=null;
        }
        return ['policy'=>TemplatePolicy::normalize($policy),'field_contexts'=>TemplateContexts::normalize($contexts,$keys)]+\Kulturhus\Native\Routing\View::submitted($post)+\Kulturhus\Native\TemplateRenderers\View::submitted($post);
    }

    private static function check(string $name,string $label,bool $checked,string $value='1', ?callable $interfaceText=null):string{
        $t=$interfaceText??static fn(string $source):string=>$source;return '<label class="check"><input class="check" type="checkbox" name="'.Html::e($name).'" value="'.Html::e($value).'"'.($checked?' checked':'').'>'.Html::e($t($label)).'</label>';}
    private static function checked(array $post,string $key):bool{if(!array_key_exists($key,$post))return false;if($post[$key]!=='1')throw new InvalidArgumentException('Auswahl ausdrücklich als Ja oder Nein angeben.');return true;}
    private static function text(mixed $value):string{if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>50000)throw new InvalidArgumentException('Vorlagenwert als begrenzten Text angeben.');return $value;}
    private static function choice(array $row,string $key,array $choices,string $default):string{$value=$row[$key]??$default;if(!in_array($value,$choices,true))throw new InvalidArgumentException('Eine gültige Vorlageneinstellung auswählen.');return $value;}
}
