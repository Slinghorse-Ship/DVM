<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;
use Kulturhus\Native\Cms\{Conflict,FieldPolicy};
use Kulturhus\Native\Shared\Access;

/** A user selects paths; all replacement identities come exclusively from the current server model. */
final class Review
{
    public static function paths(mixed $input):array
    {
        if(!is_array($input)||!array_is_list($input)||!$input||count($input)>1000)throw new \InvalidArgumentException('Ein bis 1000 ursprüngliche Typfelder auswählen.');
        foreach($input as$path){if(!is_string($path)||count($parts=explode('.',$path))>4)throw new \InvalidArgumentException('Typfeldpfad prüfen.');foreach($parts as$part)\Kulturhus\Native\Cms\CustomFields::key($part);}
        if(count(array_unique($input))!==count($input))throw new \InvalidArgumentException('Typfelder nur einmal auswählen.');return $input;
    }
    public static function changes(\PDO $db,Access $access,array $payload,array $definitions):array
    {
        $access->require('website','manage');$access->require('website','edit');$registry=Runtime::registry($db,$access,$definitions,$payload['field_definitions']??[]);$result=[];
        $walk=function(array$current,array$old,string$prefix='',bool$parentsReadable=true,bool$parentsEditable=true)use(&$walk,&$result,$db,$access,$registry):void{
            foreach($current as$key=>$field){$original=$old[$key]??null;if($original===null)continue;$path=$prefix.$key;$readable=$parentsReadable&&FieldPolicy::canRead($db,$access,$field)&&FieldPolicy::canRead($db,$access,$original);$editable=$parentsEditable&&$readable&&FieldPolicy::canEdit($db,$access,$field)&&FieldPolicy::canEdit($db,$access,$original);
                $editable=$editable&&($field['display']??'visible')==='visible';
                if($readable&&self::different($field,$original)){$ready=$field['type']===$original['type'];foreach(['fieldtype','inputfield']as$kind)if(isset($field[$kind])&&!$registry->available($field[$kind],$kind))$ready=false;$result[]=['path'=>$path,'label'=>$field['label'],'stored'=>self::description($original),'current'=>self::description($field),'available'=>$ready&&$editable];}
                $walk($field['item_definitions']??[],$original['item_definitions']??[],$path.'.',$readable,$editable);
            }
        };$walk($definitions,$payload['field_definitions']??[]);return $result;
    }
    public static function authorize(\PDO $db,Access $access,array $payload,array $definitions,array $paths):array
    {
        $paths=self::paths($paths);$changes=array_column(self::changes($db,$access,$payload,$definitions),null,'path');
        foreach($paths as$path){if(!isset($changes[$path]))throw new Conflict('Typauswahl wurde inzwischen geändert. Aktuellen Entwurf laden.');if(!$changes[$path]['available'])throw new \DomainException('Ausgewählte Typfassung ist nicht vollständig freigegeben.');}return $paths;
    }
    private static function different(array $one,array $two):bool
    {
        $bound=isset($one['fieldtype'])||isset($one['inputfield'])||isset($two['fieldtype'])||isset($two['inputfield']);return $bound&&(($one['fieldtype']??null)!==($two['fieldtype']??null)||($one['inputfield']??null)!==($two['inputfield']??null)||$one['type']!==$two['type']);
    }
    private static function description(array $field):string
    {
        $parts=[];foreach(['fieldtype'=>'Typ','inputfield'=>'Eingabe']as$kind=>$label)if(isset($field[$kind])){$binding=Binding::normalize($field[$kind]);$parts[]=$label.' '.$binding['owner'].'/'.$binding['name'].' '.$binding['version'].' · '.substr($binding['source_hash'],0,8);}return $parts?implode(' · ',$parts):'Nativ · '.$field['type'];
    }
}
