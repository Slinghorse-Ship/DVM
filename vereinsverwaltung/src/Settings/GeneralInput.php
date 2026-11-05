<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** Closed original form values; canonical normalization belongs to the explicit review. */
final class GeneralInput
{
    public static function version(mixed $raw):int
    {
        if((!is_string($raw)&&!is_int($raw))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$raw)||(int)$raw>2147483647)throw new DomainException('Ursprüngliche Einstellungsfassung prüfen.',422);
        return(int)$raw;
    }
    public static function capture(string $section,array $input):array
    {
        if($section==='numbers')return NumberingInput::capture($input);
        if($section==='bank')return FinanceBankInput::capture($input);
        if($section==='tax')return FinanceTaxInput::capture($input);
        if($section===\Kulturhus\Verein\FinancePreferences::SECTION)return FinancePreferenceInput::capture($input);
        if(!GeneralValues::supports($section)||array_keys($input)!==['version','data']||!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Einstellungsfelder prüfen.',422);
        $input['version']=(string)self::version($input['version']);$data=$input['data'];
        $keys=$section==='club'?array_keys(SettingsSchema::fields('club')):($section==='salutations'?['mode','titles','rows']:['rows']);
        if(array_diff($keys,array_keys($data))||array_diff(array_keys($data),$keys))throw new DomainException('Vollständige ursprüngliche Einstellungsfelder prüfen.',422);
        $walk=static function(mixed $value,int $depth=0)use(&$walk):void{
            if($depth>5)throw new DomainException('Vollständige ursprüngliche Einstellungsfelder prüfen.',422);
            if(is_array($value)){foreach($value as$item)$walk($item,$depth+1);return;}
            if((!is_string($value)&&!is_int($value)&&!is_bool($value))||(is_string($value)&&(preg_match('//u',$value)!==1||strlen($value)>40000)))throw new DomainException('Textfeld enthält ungültige Daten.',422);
        };$walk($data);
        if($section!=='club'){
            if(!is_array($data['rows'])||count($data['rows'])>($section==='salutations'?30:50))throw new DomainException('Zu viele oder ungültige Zeilen.',422);
            foreach($data['rows']as$row){
                if(!is_array($row))throw new DomainException('Zu viele oder ungültige Zeilen.',422);
                if($section==='fields'&&trim(is_string($row['name']??null)?$row['name']:'')!==''&&(!is_string($row['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$row['id'])))throw new DomainException('Feldkennung prüfen.',422);
            }
        }
        return['version'=>$input['version'],'data'=>$data];
    }
}
