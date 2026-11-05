<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** The whole review keeps literal tax choices separate from canonical stored values. */
final class FinanceTaxInput
{
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);
        if($keys!==['data','version']||!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Steuereinstellungen prüfen.',422);
        $fields=SettingsSchema::fields('tax');$expected=array_keys($fields);sort($expected);$keys=array_keys($input['data']);sort($keys);
        if($keys!==$expected)throw new DomainException('Vollständige ursprüngliche Steuereinstellungen prüfen.',422);
        foreach($input['data']as$key=>$value){
            if($fields[$key][1]==='checkbox'&&(is_bool($value)||is_int($value)))continue;
            if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>40000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Vollständiges Steuerdatenfeld prüfen.',422);
        }
        return ['version'=>(string)GeneralInput::version($input['version']),'data'=>$input['data']];
    }
    public static function initial(array $state):array
    {
        return self::capture(['version'=>(string)$state['version'],'data'=>array_replace(SettingsSchema::defaults('tax'),$state['data'])]);
    }
    public static function normalize(array $data):array
    {
        $data=self::capture(['version'=>'0','data'=>$data])['data'];$out=[];
        foreach(SettingsSchema::fields('tax')as$key=>$field){
            [$label,$type,$limit]=$field;
            try{
                $value=match($type){
                    'checkbox'=>SettingsSchema::bool($data[$key]),
                    'select'=>SettingsSchema::choice($data[$key],array_map('strval',array_keys($limit))),
                    default=>GeneralValues::text($data[$key],$limit)
                };
                if($type==='date'&&$value!=='')SettingsSchema::date($value);
                $out[$key]=$value;
            }catch(DomainException $failure){throw new DomainException($label.': '.$failure->getMessage(),422,$failure);}
        }
        if($out['input_tax_eligibility']==='no')$out['input_tax_default']='none';
        return $out;
    }
    /** Existing service callers may still omit optional settings. */
    public static function canonical(array $data):array
    {
        $defaults=SettingsSchema::defaults('tax');
        if(array_diff(array_keys($data),array_keys($defaults)))throw new DomainException('Unerwartetes Einstellungsfeld.',422);
        foreach($data as$key=>&$value)if(is_int($value)&&SettingsSchema::fields('tax')[$key][1]!=='checkbox')$value=(string)$value;unset($value);
        return self::normalize(array_replace($defaults,$data));
    }
}
