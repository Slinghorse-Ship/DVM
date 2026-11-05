<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\{Iban,SettingsSchema};

/** Whole invoice bank details remain separate from account and transport settings. */
final class FinanceBankInput
{
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);
        if($keys!==['data','version']||!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Rechnungsbankdaten prüfen.',422);
        $expected=array_keys(SettingsSchema::fields('bank'));sort($expected);$keys=array_keys($input['data']);sort($keys);
        if($keys!==$expected)throw new DomainException('Vollständige ursprüngliche Rechnungsbankdaten prüfen.',422);
        foreach($input['data']as$value)if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>40000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Vollständiges Bankdatenfeld prüfen.',422);
        return['version'=>(string)GeneralInput::version($input['version']),'data'=>$input['data']];
    }
    public static function initial(array $state):array
    {
        return self::capture(['version'=>(string)$state['version'],'data'=>$state['data']]);
    }
    public static function normalize(array $data):array
    {
        $data=self::capture(['version'=>'0','data'=>$data])['data'];$out=[];
        foreach(SettingsSchema::fields('bank')as$key=>$field){
            [$label,$type,$limit]=$field;
            try{
                $value=GeneralValues::text($data[$key],$limit);
                if($type==='iban')$value=Iban::normalize($value);
                if($type==='bic'&&$value!==''){$value=strtoupper($value);if(!preg_match('/\A[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?\z/D',$value))throw new DomainException('BIC prüfen.',422);}
                if($type==='url'&&$value!==''&&(!filter_var($value,FILTER_VALIDATE_URL)||strtolower((string)parse_url($value,PHP_URL_SCHEME))!=='https'||parse_url($value,PHP_URL_USER)||parse_url($value,PHP_URL_PASS)))throw new DomainException('HTTPS-Adresse ohne Zugangsdaten erforderlich.',422);
                $out[$key]=$value;
            }catch(DomainException $failure){throw new DomainException($label.': '.$failure->getMessage(),422,$failure);}
        }
        return$out;
    }
    /** Existing service callers may deliberately omit optional bank fields. */
    public static function canonical(array $data):array
    {
        $defaults=SettingsSchema::defaults('bank');
        if(array_diff(array_keys($data),array_keys($defaults)))throw new DomainException('Unerwartetes Einstellungsfeld.',422);
        foreach($data as&$value)if(is_int($value))$value=(string)$value;unset($value);
        return self::normalize(array_replace($defaults,$data));
    }
}
