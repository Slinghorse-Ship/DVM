<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Only nonsecret method/device/account/date choices and versions; credentials are never collected. */
final class BankDialogFields
{
    private const FIELDS=['fints-select'=>['mode','medium'],'fints-statement'=>['uid','from','to']];
    public static function version(array $body):int
    {
        $value=$body['dialog_version']??null;
        if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)||strlen($value)>strlen((string)PHP_INT_MAX)||(string)(int)$value!==$value)throw new \DomainException('Ursprünglichen Bankdialogstand prüfen. Formular bewusst neu öffnen.',422);
        return (int)$value;
    }
    public static function state(array $body,string $action,array $defaults):array
    {
        $posted=($body['action']??null)===$action;$values=[];$original=[];$unsupported=false;
        foreach(self::FIELDS[$action]??[]as$field){
            $present=$posted&&array_key_exists($field,$body);$value=$present?$body[$field]:($posted?'':($defaults[$field]??''));
            $values[$field]=is_string($value)?$value:'';
            if($present){$original[$field]=$value;if(!is_string($value))$unsupported=true;}
        }
        if($posted){
            if(array_key_exists('dialog_version',$body))$original['dialog_version']=$body['dialog_version'];
            try{self::version($body);}catch(\DomainException){$unsupported=true;}
        }
        return ['values'=>$values,'posted'=>$posted,'unsupported'=>$unsupported,'original'=>$original];
    }
    public static function original(array $state):string
    {
        return json_encode($state['original'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
    public static function dateType(string $value):string
    {
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value))return 'text';
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date&&$date->format('Y-m-d')===$value?'date':'text';
    }
}
