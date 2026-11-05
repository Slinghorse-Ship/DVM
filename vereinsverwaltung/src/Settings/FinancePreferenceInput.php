<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\{Finance,FinancePreferences,SettingsSchema,TaxCatalog};
/** Whole requested selections, including invalid text, are retained before normalization. */
final class FinancePreferenceInput
{
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);if($keys!==['data','version']||!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Finanz-Vorauswahl prüfen.',422);$data=$input['data'];$keys=array_keys($data);sort($keys);if($keys!==['accounts','category_spheres']||!is_array($data['accounts'])||!is_array($data['category_spheres']))throw new DomainException('Vollständige ursprüngliche Finanz-Vorauswahl prüfen.',422);
        $expected=array_keys(Finance::CATEGORIES);sort($expected);$keys=array_keys($data['category_spheres']);sort($keys);if($keys!==$expected)throw new DomainException('Alle ursprünglichen Buchungskategorien prüfen.',422);
        $expected=array_map('strval',TaxCatalog::YEARS);sort($expected);$keys=array_map('strval',array_keys($data['accounts']));sort($keys);if($keys!==$expected)throw new DomainException('Alle ursprünglichen Kontenjahre prüfen.',422);
        $field=static function(mixed $value):void{if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>100000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Vollständiges Finanz-Auswahlfeld prüfen.',422);};
        foreach($data['category_spheres']as$value)$field($value);foreach($data['accounts']as$row){if(!is_array($row))throw new DomainException('Vollständige ursprüngliche Steuerkontenauswahl prüfen.',422);$keys=array_keys($row);sort($keys);if($keys!==['codes','mode'])throw new DomainException('Vollständige ursprüngliche Steuerkontenauswahl prüfen.',422);$field($row['mode']);$field($row['codes']);}
        return['version'=>(string)GeneralInput::version($input['version']),'data'=>$data];
    }
    public static function initial(array $state):array
    {
        $data=$state['data'];foreach($data['accounts']as&$row)$row['codes']=implode(',',$row['codes']);unset($row);return['version'=>(string)$state['version'],'data'=>$data];
    }
    public static function normalize(array $data):array
    {
        // No absent year, empty category or invalid whole CSV is replaced with a default.
        $raw=self::capture(['version'=>'0','data'=>$data]);foreach($raw['data']['accounts']as$row)if(strlen($row['codes'])>FinancePreferences::MAX_CODES*6)throw new DomainException('Höchstens 3000 vollständige Steuerkonten auswählen.',422);
        try{return FinancePreferences::normalize($raw['data']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
    }
}
