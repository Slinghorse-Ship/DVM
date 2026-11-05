<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\SettingsSchema;
final class NumberingInput
{
    public static function capture(array $input):array
    {
        $keys=['version','data','date'];
        if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input))||!is_array($input['data'])||!is_string($input['date'])||strlen($input['date'])!==10)throw new DomainException('Vollständige ursprüngliche Nummernfelder und Beispieldatum prüfen.',422);
        try{SettingsSchema::date($input['date']);}catch(DomainException $failure){throw new DomainException('Gültiges Beispieldatum auswählen.',422,$failure);}
        $version=GeneralInput::version($input['version']);
        foreach($input['data']as$row){if(!is_array($row))throw new DomainException('Nummernkreis unvollständig.',422);foreach($row as$value)if((!is_string($value)&&!is_int($value))||(is_string($value)&&(preg_match('//u',$value)!==1||strlen($value)>10000)))throw new DomainException('Nummernfeld enthält ungültige Daten.',422);}
        return['version'=>(string)$version,'data'=>$input['data'],'date'=>$input['date']];
    }
}
