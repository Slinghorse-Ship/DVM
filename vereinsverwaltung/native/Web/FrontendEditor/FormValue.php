<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\FrontendEditor;
use InvalidArgumentException;
use Kulturhus\Native\Cms\FieldValues;

/** HTML transport conversion only. Canonical Pages validation owns types, originals and rights. */
final class FormValue
{
    public static function read(array $field,mixed $value,int $depth=0):mixed
    {
        if($depth>3)throw new InvalidArgumentException('Datensätze dürfen höchstens drei Ebenen enthalten.');
        if(FieldValues::collection($field)){
            if(!is_array($value)||($value['__complete']??null)!=='1')throw new InvalidArgumentException('Das Formular wurde nicht vollständig übertragen. Eingaben prüfen und erneut speichern.');
            foreach($value as $i=>$item){
                if($i==='__complete')continue;
                if($field['type']==='repeater'&&is_array($item)&&is_array($item['values']??null)){
                    foreach($field['item_definitions'] as $key=>$child)if(array_key_exists($key,$item['values']))$value[$i]['values'][$key]=self::read($child,$item['values'][$key],$depth+1);
                }elseif($field['type']!=='repeater'){
                    $scalar=$field;unset($scalar['multiple']);if($scalar['type']==='pagetable')$scalar['type']='page-reference';
                    if(is_array($item)&&array_key_exists('value',$item))$value[$i]['value']=self::read($scalar,$item['value'],$depth+1);
                    else $value[$i]=self::read($scalar,$item,$depth+1);
                }
            }
            return $value;
        }
        if(is_string($value)&&in_array($field['type'],['integer','decimal','boolean','date','datetime'],true))$value=trim($value);
        if($field['type']==='decimal'&&is_string($value))$value=str_replace(',','.',$value);
        if($field['type']==='integer'&&is_string($value)&&preg_match('/\A(-?)([0-9]+)\z/D',$value,$parts)){$value=ltrim($parts[2],'0')?:'0';if($parts[1]==='-'&&$value!=='0')$value='-'.$value;}
        return $value;
    }
}
