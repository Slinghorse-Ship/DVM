<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
final class Input
{
    public static function keys(array $data,array $allowed):void{if(array_diff(array_keys($data),$allowed))throw Problem::invalid();}
    public static function version(array $data):int{$v=$data['version']??null;if(!is_int($v)||$v<0||$v>2147483646)throw Problem::invalid();return $v;}
    public static function object(array $data,string $key):array{$value=$data[$key]??null;if(!is_array($value)||($value!==[]&&array_is_list($value)))throw Problem::invalid();return $value;}
    public static function text(array $data,string $key,int $max=200,bool $required=true):string{$value=$data[$key]??'';if(!is_string($value)||!preg_match('//u',$value)||mb_strlen($value)>$max||($required&&$value==='')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw Problem::invalid();return $value;}
    public static function page(array $query):array{
        self::keys($query,['page','limit']);$values=[];foreach(['page'=>1,'limit'=>25] as $key=>$default){$value=$query[$key]??$default;if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$value))throw Problem::invalid();$values[$key]=(int)$value;}
        if($values['limit']>50||($values['page']-1)*$values['limit']>1000000)throw Problem::invalid();return $values+['offset'=>($values['page']-1)*$values['limit']];
    }
    public static function pagination(int $total,array $page):array{return ['page'=>$page['page'],'limit'=>$page['limit'],'total'=>$total,'pages'=>max(1,(int)ceil($total/$page['limit'])),'has_more'=>$page['offset']+$page['limit']<$total];}
    public static function canonical(mixed $value,int $depth=0):mixed{if($depth>64||is_object($value)||is_resource($value)||(is_float($value)&&!is_finite($value)))throw Problem::invalid();if(is_array($value)){if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$item)$item=self::canonical($item,$depth+1);unset($item);}return $value;}
}
