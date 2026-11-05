<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;
use Kulturhus\Native\Cms\{FieldConditions,FieldPolicy,FieldValues};
use Kulturhus\Native\Shared\Access;

/** Narrow canonical hooks; native values, roles, references and original counters remain authoritative. */
final class Runtime
{
    public static function queryValue(\PDO $db,Access $access,array $field,mixed $value,bool $forSearch=false):mixed
    {
        if(!isset($field['fieldtype']))return \Kulturhus\Native\Cms\CustomFields::value($field,$value,$forSearch);
        if(!FieldPolicy::canRead($db,$access,$field))throw new \DomainException('Feldtyp nicht lesbar.');$registry=self::registry($db,$access,[$field]);$scalar=$field;unset($scalar['multiple'],$scalar['max_items']);
        // The canonical query guard is already false for unavailable providers. No withdrawn code runs.
        if($forSearch&&!$registry->available($field['fieldtype']))return in_array($field['type'],['text','textarea','richtext','url','email','select'],true)?($value===null||$value===''?null:\Kulturhus\Native\Cms\CustomFields::text($value,$field['type']==='select'?200:100000)):\Kulturhus\Native\Cms\CustomFields::value($scalar,$value,true);
        $result=$registry->normalize($field['fieldtype'],$scalar,$value,$forSearch);
        if(!FieldPolicy::canRead($db,$access,$field))throw new \DomainException('Feldfreigabe wurde inzwischen geändert.');return $result;
    }
    public static function queryAvailable(\PDO $db,Access $access,array $field):bool
    {if(!isset($field['fieldtype']))return true;return self::registry($db,$access,[$field])->available($field['fieldtype']);}
    public static function assertBindings(\PDO $db,Access $access,array $definitions):void
    {
        if(!self::hasBindings($definitions))return;$registry=self::registry($db,$access,$definitions);$walk=function(array$fields)use(&$walk,$registry):void{foreach($fields as$field){if(!self::ready($registry,$field))throw new \DomainException('Gespeicherte Typfassung ist nicht mehr freigegeben.');$walk($field['item_definitions']??[]);}};$walk($definitions);
    }
    public static function assertDefinition(\PDO $db,Access $access,array $field,?array $previous=null):array
    {
        $access->require('website','manage');
        foreach(['fieldtype','inputfield']as$kind)if(!array_key_exists($kind,$field)&&array_key_exists($kind,$previous??[]))$field[$kind]=$previous[$kind];
        if($previous!==null&&(self::bound($field)||self::bound($previous))&&$field['type']!==$previous['type'])throw new \DomainException('Für eine andere native Typgrundlage ein neues Feld anlegen.');
        if(!self::hasBindings([$field]))return $field;$registry=self::registry($db,$access,[$field],$previous===null?[]:[$previous]);
        foreach(['fieldtype','inputfield']as$kind){if(($field[$kind]??null)===null)continue;$binding=Binding::normalize($field[$kind]);$field[$kind]=$binding;
            if($previous!==null&&($previous[$kind]??null)===$binding&&($previous['type']??null)===($field['type']??null))continue;
            $descriptor=$registry->descriptor($binding,$kind);$accepted=$kind==='fieldtype'?[$descriptor['type']]:$descriptor['types'];if(!in_array($field['type']??null,$accepted,true))throw new \DomainException('Registrierter Typ passt nicht zur nativen Feldgrundlage.');
        }return $field;
    }
    /** The returned values still pass through final FieldConditions/FieldValues authorization. */
    public static function prepare(\PDO $db,Access $access,array $definitions,array $values,array $previous=[],bool $preserve=false,bool $trustedInitial=false,array $reviewPaths=[]):array
    {
        $old=$previous['field_definitions']??[];if(!self::hasBindings($definitions)&&!self::hasBindings($old))return ['definitions'=>$definitions,'values'=>$values];
        $registry=self::registry($db,$access,$definitions,$old);$reviews=$reviewPaths?array_fill_keys(Review::authorize($db,$access,$previous,$definitions,$reviewPaths),true):[];$used=$reviews;
        [$definitions,$values]=self::prepareLevel($db,$access,$registry,$definitions,$values,$old,$previous['custom_fields']??[],0,false,$preserve||$trustedInitial,'',$reviews,$used);
        // Reject stale/unauthorized rows BEFORE any custom callback; discard provisional counters/IDs.
        $typed=FieldConditions::values($definitions,$values);FieldValues::authorize($definitions,$typed,$previous['custom_fields']??($preserve?$typed:[]),$access,$preserve);
        [$definitions,$typed]=self::prepareLevel($db,$access,$registry,$definitions,$typed,$old,$previous['custom_fields']??[],0,!$preserve&&!$trustedInitial,$preserve||$trustedInitial,'',$reviews,$used);
        if(array_diff_key($reviews,$used))throw new \InvalidArgumentException('Ausgewählte Typfelder gehören nicht zu gespeicherten Originalbindungen.');
        return ['definitions'=>$definitions,'values'=>$typed];
    }
    private static function prepareLevel(\PDO $db,Access $access,Registry $registry,array $definitions,array $values,array $oldDefinitions,array $original,int $depth,bool $execute,bool $preserve,string $prefix,array $reviews,array &$used):array
    {
        if($depth>3)throw new \DomainException('Registrierte Feldstruktur begrenzen.');
        foreach($definitions as$key=>$field){$path=$prefix.$key;$reviewing=isset($reviews[$path]);$old=$oldDefinitions[$key]??null;$present=array_key_exists($key,$original);$bound=self::bound($field)||($old!==null&&self::bound($old));
            if($bound){$editable=FieldPolicy::canEdit($db,$access,$field)&&($old===null||FieldPolicy::canEdit($db,$access,$old));$same=$old===null||self::sameBindings($field,$old);$ready=self::ready($registry,$field);
                if($reviewing){$used[$path]=true;if(!$editable||!$ready||$old===null||$field['type']!==$old['type'])throw new \DomainException('Typfassung kann nicht mit diesen Originalrechten übernommen werden.');}
                if($preserve&&(!$same||!$ready))throw new \DomainException('Gespeicherte Typfassung zuerst erneut prüfen.');
                if(!$editable||(!$same&&!$reviewing)||!$ready||($present&&($field['display']??'visible')!=='visible')){
                    if($present&&array_key_exists($key,$values)&&$values[$key]!==$original[$key])throw new \DomainException('Gespeicherte Typfassung ist nicht bearbeitbar.');
                    if(!$present&&($values[$key]??null)!==null&&($values[$key]??null)!==[])throw new \DomainException('Geprüfte lokale Typfassung ist nicht verfügbar.');
                    if($present){$values[$key]=$original[$key];if($old!==null)$definitions[$key]=$old;}continue;
                }
                if($execute&&isset($field['fieldtype'])&&(!$present||($values[$key]??null)!==$original[$key]||$reviewing&&($field['fieldtype']??null)!==($old['fieldtype']??null))){
                    if(!empty($field['multiple'])){$scalar=$field;unset($scalar['multiple'],$scalar['max_items']);foreach($values[$key]??[]as$position=>$value)$values[$key][$position]=$registry->normalize($field['fieldtype'],$scalar,$value);}
                    else $values[$key]=$registry->normalize($field['fieldtype'],$field,$values[$key]??null);
                    if(!FieldPolicy::canEdit($db,$access,$field)||($old!==null&&!FieldPolicy::canEdit($db,$access,$old)))throw new \DomainException('Feldfreigabe wurde inzwischen geändert.');
                }
            }
            if(($field['type']??null)==='repeater'){$items=array_column($original[$key]??[],null,'id');foreach($values[$key]??[]as$position=>$item){if(!is_array($item)||!isset($item['values'])||!is_array($item['values']))continue;$prior=$items[$item['id']??'']??null;[$children,$childValues]=self::prepareLevel($db,$access,$registry,$field['item_definitions']??[],$item['values'],$old['item_definitions']??[],$prior['values']??[], $depth+1,$execute,$preserve,$path.'.',$reviews,$used);$definitions[$key]['item_definitions']=$children;$values[$key][$position]['values']=$childValues;}}
        }return [$definitions,$values];
    }
    /** Caller first applies FieldPolicy::editorDefinitions; this method can only restrict further. */
    public static function editorDefinitions(\PDO $db,Access $access,array $definitions,?array $captured=null):array
    {
        if(!self::hasBindings($definitions)&&!self::hasBindings($captured??[]))return $definitions;$registry=self::registry($db,$access,$definitions,$captured??[]);
        $walk=function(array$defs,array$old=[])use(&$walk,$registry):array{foreach($defs as$key=>&$field){if((self::bound($field)||isset($old[$key])&&self::bound($old[$key]))&&(!self::ready($registry,$field)||isset($old[$key])&&!self::sameBindings($field,$old[$key]))){$field['_provider_unavailable']=true;if(($field['display']??'visible')!=='hidden')$field['display']='readonly';}if(isset($field['item_definitions']))$field['item_definitions']=$walk($field['item_definitions'],$old[$key]['item_definitions']??[]);}unset($field);return $defs;};return $walk($definitions,$captured??[]);
    }
    /** A failing/revoked widget degrades to the native readonly original, never to an active fallback. */
    public static function control(\PDO $db,Access $access,array $field,string $name,mixed $value,?Registry $registry=null):array
    {
        if(!isset($field['inputfield']))return ['field'=>$field,'html'=>null];
        if(!FieldPolicy::canRead($db,$access,$field))return ['field'=>array_replace($field,['display'=>'hidden']),'html'=>''];
        $registry??=self::registry($db,$access,[$field]);$readonly=($field['display']??'visible')!=='visible'||!FieldPolicy::canEdit($db,$access,$field);
        if(!empty($field['_provider_unavailable'])||!self::ready($registry,$field))return ['field'=>array_replace($field,['display'=>'readonly']),'html'=>null];
        try{$html=$registry->render($field['inputfield'],$field,$name,$value,$readonly);}catch(\DomainException|\InvalidArgumentException){return ['field'=>array_replace($field,['display'=>'readonly']),'html'=>null];}
        if(!FieldPolicy::canRead($db,$access,$field))return ['field'=>array_replace($field,['display'=>'hidden']),'html'=>''];
        if(!$readonly&&!FieldPolicy::canEdit($db,$access,$field))return ['field'=>array_replace($field,['display'=>'readonly']),'html'=>null];
        return ['field'=>$readonly?array_replace($field,['display'=>'readonly']):$field,'html'=>$html];
    }
    /** Parse actual controls only; API writes already carry native values. Missing keys stay missing. */
    public static function formValues(\PDO $db,Access $access,array $definitions,array $values,?array $captured=null):array
    {
        if(!self::hasBindings($definitions))return $values;$registry=self::registry($db,$access,$definitions,$captured??[]);
        $walk=function(array$defs,array$posted,array$old=[])use(&$walk,$registry,$db,$access):array{
            foreach($defs as$key=>$field){
                if(!array_key_exists($key,$posted)||($field['display']??'visible')!=='visible'||!FieldPolicy::canEdit($db,$access,$field)||(isset($old[$key])&&!FieldPolicy::canEdit($db,$access,$old[$key])))continue;
                if(isset($field['inputfield'])&&(!isset($old[$key])||self::sameBindings($field,$old[$key]))&&self::ready($registry,$field)){
                    if(!empty($field['multiple'])&&is_array($posted[$key])){
                        $scalar=$field;unset($scalar['multiple'],$scalar['max_items']);
                        foreach($posted[$key]as$position=>$item){if($position==='__complete')continue;if(is_array($item)){if(isset($item['remove']))continue;if(array_key_exists('value',$item))$posted[$key][$position]['value']=$registry->parse($field['inputfield'],$scalar,$item['value']);}else $posted[$key][$position]=$registry->parse($field['inputfield'],$scalar,$item);}
                    }else $posted[$key]=$registry->parse($field['inputfield'],$field,$posted[$key]);
                }
                if(($field['type']??null)==='repeater'&&is_array($posted[$key]))foreach($posted[$key]as$i=>$item)if(is_array($item)&&isset($item['values'])&&is_array($item['values']))$posted[$key][$i]['values']=$walk($field['item_definitions']??[],$item['values'],$old[$key]['item_definitions']??[]);
            }return $posted;
        };return $walk($definitions,$values,$captured??[]);
    }

    public static function registry(\PDO $db,Access $access,array ...$sets):Registry
    {
        $owners=[];$walk=function(array$definitions)use(&$walk,&$owners):void{foreach($definitions as$field){foreach(['fieldtype','inputfield']as$kind)if(isset($field[$kind])){$binding=Binding::normalize($field[$kind]);$owners[$binding['owner']]=true;}$walk($field['item_definitions']??[]);}};foreach($sets as$definitions)$walk($definitions);
        return Factory::registry($db,$access->club(),array_keys($owners));
    }
    private static function hasBindings(array $definitions):bool
    {foreach($definitions as$field)if(self::bound($field)||self::hasBindings($field['item_definitions']??[]))return true;return false;}
    private static function bound(array $field):bool{return isset($field['fieldtype'])||isset($field['inputfield']);}
    private static function sameBindings(array $one,array $two):bool
    {return ($one['type']??null)===($two['type']??null)&&($one['fieldtype']??null)===($two['fieldtype']??null)&&($one['inputfield']??null)===($two['inputfield']??null);}
    private static function ready(Registry $registry,array $field):bool
    {foreach(['fieldtype','inputfield']as$kind)if(isset($field[$kind])&&!$registry->available($field[$kind],$kind))return false;return true;}
}
