<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;
use Kulturhus\Native\Cms\{FieldValues};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

/** Compact native controls with preserved original identities and ordinary HTML removal/save. */
final class CollectionControls
{
    public function __construct(private PDO $db,private Access $access){}
    public function render(array$field,mixed$value,string$name,string$id):string
    {
        $value=is_array($value)?$value:[];$limit=$field['max_items']??50;$token='__ITEM_'.substr(hash('sha256',$name),0,12).'__';
        $h='<fieldset class="model-field typed-collection border rounded p-3 mb-3" data-model-field="'.Html::e($field['key']).'" data-typed-collection data-collection-token="'.$token.'" data-max-items="'.$limit.'"><legend class="float-none w-auto px-2 fs-6">'.Html::e($field['label']).'</legend><div data-collection-items>';
        foreach($value as$i=>$item)$h.=$this->row($field,$item,$name.'['.$i.']',$id.'-'.$i,false);
        // One empty slot makes adding possible with JavaScript disabled, without a second endpoint.
        if(($field['_field_items_editable']??true)&&count($value)<$limit)$h.=$this->row($field,null,$name.'['.count($value).']',$id.'-'.count($value),true);
        $h.='</div>';if(!($field['_field_items_editable']??true))return $h.Html::hidden($name.'[__complete]','1').'</fieldset>';
        $h.=Html::hidden($name.'[__complete]','1').'<template data-collection-template>'.$this->row($field,null,$name.'['.$token.']',$id.'-'.$token,true,true).'</template>';
        return $h.'<button type="button" class="btn btn-outline theme-secondary" data-collection-add>Weiterer Eintrag</button><p class="hint mb-0" aria-live="polite" data-collection-message>Bis zu '.$limit.' Einträge. Leere neue Einträge werden ausgelassen.</p></fieldset>';
    }

    private function row(array$field,mixed$value,string$name,string$id,bool$fresh,bool$open=false):string
    {
        $repeater=$field['type']==='repeater';$canEdit=!$repeater||$fresh||FieldValues::canEdit($value,$this->access);
        $h='<details class="typed-collection-item border rounded p-2 mb-2" data-collection-item'.($open?' open':'').'><summary>'.Html::e($fresh?'Neuer Eintrag':($repeater?$this->title($value,$field):$this->scalarTitle($value))).'</summary><div class="pt-2">';
        if($repeater){
            $value??=['id'=>'','version'=>0,'values'=>[],'editors'=>[],'public'=>true];
            $h.=Html::hidden($name.'[id]',$value['id']).Html::hidden($name.'[version]',$value['version']);
            if(!$canEdit){
                $h.='<p>Dieser Datensatz ist für die Bearbeitung geschützt.</p>'.Html::hidden($name.'[preserve]','1');
            }else {
                $states=\Kulturhus\Native\Cms\FieldConditions::states($field['item_definitions'],$value['values']);
                $rules=array_map(static fn($child)=>array_intersect_key($child,array_flip(['type','required','show_when','required_when'])),$field['item_definitions']);
                $h.='<div class="model-fields" data-field-prefix="'.Html::e($name.'[values]').'"'.($fresh?' data-empty-repeater="1"':'').' data-field-conditions="'.Html::e(json_encode($rules,JSON_THROW_ON_ERROR)).'">';
                foreach($field['item_definitions']as$key=>$child){
                    $child['required']=$fresh?false:$states[$key]['required'];if(($field['display']??'visible')==='readonly'&&($child['display']??'visible')!=='hidden')$child['display']='readonly';
                    $h.=(new Controls($this->db,$this->access,true))->render($child,$value['values'][$key]??null,$name.'[values]['.$key.']',$id.'-'.$key);
                }
                $h.='<p class="hint" role="status" aria-live="polite" data-field-condition-status></p></div>';
            }
            $manage=false;try{$this->access->require('website','manage');$manage=true;}catch(\DomainException){}
            if($manage){
                $h.='<details><summary>Sichtbarkeit und Bearbeitung</summary><label for="'.Html::e($id.'-public').'">Website</label><select class="form-control" id="'.Html::e($id.'-public').'" name="'.Html::e($name.'[public]').'"><option value="1"'.($value['public']?' selected':'').'>Öffentlich mit der Seite</option><option value="0"'.(!$value['public']?' selected':'').'>Nur intern</option></select><label for="'.Html::e($id.'-editors').'">Bearbeitungsberechtigte</label><select class="form-control" multiple size="3" id="'.Html::e($id.'-editors').'" name="'.Html::e($name.'[editors][]').'">';
                $directory=$this->access->directory('website');foreach($directory as$actor)$h.='<option value="'.Html::e($actor['id']).'"'.(in_array($actor['id'],$value['editors'],true)?' selected':'').'>'.Html::e($actor['name']).'</option>';
                foreach(array_diff($value['editors'],array_column($directory,'id'))as$oldEditor)$h.='<option value="'.Html::e($oldEditor).'" selected>Frühere Berechtigung · nicht mehr aktiv</option>';
                $h.='</select><p class="hint">Ohne Auswahl dürfen alle berechtigten Redakteure bearbeiten. Die Verwaltung behält Zugriff.</p></details>';
            }else{
                $h.=Html::hidden($name.'[public]',$value['public']?'1':'0');foreach($value['editors']as$editor)$h.=Html::hidden($name.'[editors][]',$editor);
            }
        }else{
            $scalar=$field;unset($scalar['multiple'],$scalar['max_items']);if($scalar['type']==='pagetable')$scalar['type']='page-reference';$scalar['required']=false;
            $h.=(new Controls($this->db,$this->access,true))->render($scalar,$value,$name.'[value]',$id.'-value');
        }
        if($canEdit&&($field['_field_items_editable']??true))$h.='<div class="d-flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-outline theme-secondary" data-collection-up aria-label="Eintrag nach oben">↑</button><button type="button" class="btn btn-outline theme-secondary" data-collection-down aria-label="Eintrag nach unten">↓</button><label class="form-check d-flex align-items-center gap-2 m-0"><input class="check m-0" type="checkbox" name="'.Html::e($name.'[remove]').'" value="1"> Beim Speichern entfernen</label></div>';
        return $h.'</div></details>';
    }
    private function scalarTitle(mixed$value):string
    {return is_scalar($value)?mb_substr((string)$value,0,80):'Eintrag';}
    private function title(array$item,array$field):string
    {foreach($item['values']as$key=>$value)if(isset($field['item_definitions'][$key])&&($field['item_definitions'][$key]['type']??'')!=='password'&&($field['item_definitions'][$key]['display']??'visible')!=='hidden'&&is_string($value)&&$value!=='')return mb_substr(strip_tags($value),0,80);return 'Datensatz · Version '.$item['version'];}
}
