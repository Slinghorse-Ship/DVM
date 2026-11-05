<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Web\Html;

/** A submitted configuration never obtains a newer revision or a replacement plugin. */
final class ConfigurationForm
{
    public static function prepare(?array $row,array $draft,bool $unavailable=false):array
    {
        $plugin=$draft['plugin']??null;$revision=$draft['revision']??null;$raw=$draft['configuration']??null;
        $canonical=is_string($revision)&&preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$revision);
        $matched=$row!==null&&is_string($plugin)&&$plugin===$row['id'];
        $stale=!$matched||!$canonical||(int)$revision!==(int)$row['revision'];
        $invalid=!is_array($raw);$values=[];
        if($matched&&is_array($raw)){
            if(array_diff(array_keys($raw),array_keys($row['fields'])))$invalid=true;
            foreach($row['fields']as$key=>$field){
                $present=array_key_exists($key,$raw);$value=$present?$raw[$key]:($field['type']==='boolean'?'0':null);
                if($field['type']==='boolean'){
                    $values[$key]=$value==='1';if(!in_array($value,['0','1'],true))$invalid=true;
                }else{
                    $values[$key]=$value;
                    if(!$present||(!is_string($value)&&!is_int($value)))$invalid=true;
                    elseif($field['type']==='select'&&!array_key_exists((string)$value,$field['options']))$invalid=true;
                    elseif($field['type']==='integer'&&!preg_match('/\A-?(?:0|[1-9][0-9]{0,9})\z/D',(string)$value))$invalid=true;
                    elseif($field['type']==='text'&&!is_string($value))$invalid=true;
                }
            }
        }
        $sourceChanged=$matched&&(!$row['available']||$row['needs_upgrade']);
        return ['plugin'=>$plugin,'revision'=>$revision,'configuration'=>$raw,'values'=>$values,'stale'=>$stale,'invalid'=>$invalid,'source_changed'=>$sourceChanged,'blocked'=>$unavailable||$stale||$invalid||$sourceChanged,'unavailable'=>$unavailable];
    }
    public static function hidden(string $name,mixed $value):string
    {
        if(is_array($value)){$out='';foreach($value as$key=>$part)$out.=self::hidden($name.'['.$key.']',$part);return$out;}
        return Html::hidden($name,is_scalar($value)?(string)$value:'');
    }
    public static function literal(mixed $value):string
    {
        return is_string($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
