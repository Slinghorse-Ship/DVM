<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;
use Kulturhus\Native\Web\Html;

/** Normal selects bind only reviewed provider identities; no executable model input or JSON editor. */
final class View
{
    public static function render(array $field,array $nativeLabels,Registry $registry, ?callable $interfaceText=null):string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $types=$nativeLabels;$selected=$field['type']??'text';$controls=[''=>$t('Natives Eingabefeld')];$controlSelected='';
        foreach($registry->choices()as$choice)$types['registered:'.Binding::token($choice['binding'])]=$choice['label'];
        if(isset($field['fieldtype'])){$selected='registered:'.Binding::token($field['fieldtype']);if(!isset($types[$selected]))$types[$selected]=$t('Ursprünglicher Typ · derzeit nicht verfügbar');}
        foreach($registry->choices('inputfield')as$choice)if(in_array($field['type']??'text',$choice['types'],true))$controls[Binding::token($choice['binding'])]=$choice['label'];
        if(isset($field['inputfield'])){$controlSelected=Binding::token($field['inputfield']);if(!isset($controls[$controlSelected]))$controls[$controlSelected]=$t('Ursprüngliches Eingabefeld · derzeit nicht verfügbar');}
        return Html::hidden('field_provider_settings','1').Html::select('type',$t('Feldtyp'),$types,$selected).('<details class="field-provider-controls"><summary>' . Html::e($t('Eingabefeld')) . '</summary>').Html::select('inputfield_binding',$t('Darstellung'),$controls,$controlSelected).'</details>';
    }
    public static function submitted(array $post,Registry $registry,?array $previous=null):array
    {
        if(!array_key_exists('field_provider_settings',$post))return [];
        if($post['field_provider_settings']!=='1'||!is_string($post['type']??null)||!is_string($post['inputfield_binding']??null))throw new \InvalidArgumentException('Geprüfte Feldauswahl prüfen.');
        $type=$post['type'];$result=['type'=>$type,'fieldtype'=>null,'inputfield'=>null];
        if(str_starts_with($type,'registered:')){$binding=Binding::fromToken(substr($type,strlen('registered:')));if($previous!==null&&($previous['fieldtype']??null)===$binding){$native=$previous['type'];}else{$native=$registry->descriptor($binding)['type'];}$result['type']=$native;$result['fieldtype']=$binding;}
        if($post['inputfield_binding']!==''){$binding=Binding::fromToken($post['inputfield_binding']);if($previous===null||($previous['inputfield']??null)!==$binding){$descriptor=$registry->descriptor($binding,'inputfield');if(!in_array($result['type'],$descriptor['types'],true))throw new \InvalidArgumentException('Eingabefeld passt nicht zum Feldtyp.');}$result['inputfield']=$binding;}
        return $result;
    }
}
