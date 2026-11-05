<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;
use Kulturhus\Native\Web\Html;
final class View
{
    public static function render(array $template,?Registry $registry=null, ?callable $interfaceText=null):string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $selected=isset($template['frontend_renderer'])?Binding::token($template['frontend_renderer']):'';$choices=[''=>$t('Grundlayout verwenden')];
        foreach($registry?->choices()??[]as$row)$choices[Binding::token($row['binding'])]=$row['label'].' · '.$row['binding']['version'];
        if($selected!==''&&!isset($choices[$selected]))$choices[$selected]=$t('Gespeicherte Darstellung · derzeit nicht verfügbar');
        return ('<details class="template-renderer-settings"><summary>' . Html::e($t('Frontenddarstellung')) . '</summary>').Html::hidden('frontend_renderer_settings','1').Html::select('frontend_renderer_binding',$t('Geprüfte Darstellung'),$choices,$selected).'</details>';
    }
    public static function submitted(array $post):array
    {
        if(!array_key_exists('frontend_renderer_settings',$post))return [];
        if($post['frontend_renderer_settings']!=='1')throw new \InvalidArgumentException('Frontenddarstellung prüfen.');
        $binding=Binding::fromToken($post['frontend_renderer_binding']??null);return ['frontend_renderer'=>$binding];
    }
    /** Existing exact bindings survive unavailable modules; a newly selected binding must be live. */
    public static function validate(?array $binding,?array $previous,Registry $registry):?array
    {if($binding===null)return null;$binding=Binding::normalize($binding);if($binding!==$previous&&!$registry->available($binding))throw new \InvalidArgumentException('Neue Frontenddarstellung muss aktuell geprüft und aktiviert sein.');return $binding;}
}
