<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;

/** Declarative template-local field settings; shared original definitions remain untouched. */
final class TemplateContexts
{
    private const KEYS=['label','required','min','max','options','display'];

    public static function normalize(mixed $contexts,array $fieldKeys):array
    {
        if(!is_array($contexts)||(array_is_list($contexts)&&$contexts!==[])||count($contexts)>100)throw new InvalidArgumentException('Feldkontexte als Einstellungen der ausgewählten Vorlagenfelder angeben.');
        $clean=[];
        foreach($contexts as $key=>$context){
            if(!is_string($key)||!in_array($key,$fieldKeys,true)||!is_array($context)||(array_is_list($context)&&$context!==[])||array_diff(array_keys($context),self::KEYS))throw new InvalidArgumentException('Feldkontext benötigt ein vorhandenes Vorlagenfeld und erlaubte Einstellungen.');
            $entry=[];
            foreach($context as $property=>$value){
                if($property==='label'){$value=CustomFields::text($value,160);if($value==='')throw new InvalidArgumentException('Feldbezeichnung im Vorlagenkontext angeben.');}
                elseif($property==='required'&&!is_bool($value))throw new InvalidArgumentException('Pflichtfeld im Vorlagenkontext als Ja oder Nein angeben.');
                elseif(in_array($property,['min','max'],true)&&$value!==null&&!is_int($value)&&!is_string($value))throw new InvalidArgumentException('Feldgrenze im Vorlagenkontext prüfen.');
                elseif($property==='options'){
                    if(!is_array($value)||!array_is_list($value)||count($value)>200)throw new InvalidArgumentException('Bis zu 200 Auswahlwerte als Liste angeben.');
                    $value=array_map(static fn(mixed $option):string=>CustomFields::text($option,200),$value);
                    if(in_array('',$value,true)||count(array_unique($value))!==count($value))throw new InvalidArgumentException('Auswahlwerte müssen eindeutig und nicht leer sein.');
                }elseif($property==='display'&&!in_array($value,['visible','readonly','hidden'],true))throw new InvalidArgumentException('Feldanzeige als sichtbar, nur lesbar oder verborgen wählen.');
                $entry[$property]=$value;
            }
            if($entry){ksort($entry,SORT_STRING);$clean[$key]=$entry;}
        }
        ksort($clean,SORT_STRING);return $clean;
    }

    /** Captured versions and resolved repeater children still identify their shared originals. */
    public static function resolve(array $template,array $definitions):array
    {
        $contexts=self::normalize($template['field_contexts']??[],array_keys($definitions));
        foreach($contexts as $key=>$context){
            $original=$definitions[$key];$base=$original;unset($base['item_definitions'],$base['display']);
            $display=$context['display']??null;unset($context['display']);
            $resolved=CustomFields::definition(array_replace($base,$context));
            if(array_key_exists('version',$original))$resolved['version']=$original['version'];
            if(array_key_exists('item_definitions',$original))$resolved['item_definitions']=$original['item_definitions'];
            if($display!==null)$resolved['display']=$display;
            if(in_array($display,['readonly','hidden'],true)&&(!empty($resolved['required'])||isset($resolved['required_when'])))throw new InvalidArgumentException('Ein nicht bearbeitbares Vorlagenfeld darf ohne Vorgabewert kein Pflichtfeld sein.');
            $definitions[$key]=$resolved;
        }
        foreach($definitions as $definition)foreach(['show_when','required_when'] as $kind)foreach($definition[$kind]['conditions']??[] as $condition){
            if(($definitions[$condition['field']]['display']??'visible')==='hidden')throw new InvalidArgumentException('Feldbedingungen dürfen keinen verborgenen Wert als sichtbare Formulargrundlage verwenden.');
        }
        return $definitions;
    }

    /** Missing controls preserve originals; direct forged writes cannot bypass a hidden/readonly UI. */
    public static function authorize(array $definitions,array $values,array $previous):array
    {
        foreach($definitions as $key=>$definition){
            if(($definition['display']??'visible')==='visible')continue;
            $old=$previous[$key]??null;
            if(array_key_exists($key,$values)&&$values[$key]!==$old)throw new DomainException('Dieses Vorlagenfeld ist nicht zur Bearbeitung freigegeben.');
            $values[$key]=$old;
        }
        return $values;
    }
}
