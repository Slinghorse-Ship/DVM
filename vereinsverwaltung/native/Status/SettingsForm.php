<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;

use Kulturhus\Native\Web\Html;

/** Keeps the submitted intent separate from the current saved settings. */
final class SettingsForm
{
    public static function prepare(array $current,array $post=[],bool $unavailable=false):array
    {
        $present=($post['action']??null)==='system-status-save';
        $version=$present?($post['version']??null):(string)$current['version'];
        $values=$current;$invalid=[];
        if($present){
            foreach(['public_enabled','automatic_enabled']as$key){
                $raw=$post[$key]??null;
                $values[$key]=$raw==='1';
                if(array_key_exists($key,$post)&&$raw!=='1')$invalid[$key]=$raw;
            }
            foreach(['public_services','disabled_services']as$key){
                $raw=$post[$key]??[];$values[$key]=[];
                try{$values[$key]=Catalog::ids($raw);}catch(\DomainException){$invalid[$key]=$raw;}
            }
            if(!is_string($post['group']??null)||!isset(Catalog::GROUPS[$post['group']]))$invalid['group']=$post['group']??null;
        }
        $canonical=is_string($version)&&preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$version);
        $stale=$present&&(!$canonical||(int)$version!==(int)$current['version']);
        if(!$canonical)$invalid['version']=$version;
        return ['present'=>$present,'version'=>$version,'group'=>$present?($post['group']??null):null,'values'=>$values,'invalid'=>$invalid,'stale'=>$stale,'blocked'=>$unavailable||$stale||$invalid!==[]];
    }

    public static function hidden(string $name,mixed $value):string
    {
        if(is_array($value)){$html='';foreach($value as$key=>$part)$html.=self::hidden($name.'['.$key.']',$part);return$html;}
        return Html::hidden($name,is_scalar($value)?(string)$value:'');
    }
}
