<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,FinancePreferences,TaxCatalog};
/** Native draft operations retain every other requested field, even when it is invalid. */
final class FinancePreferenceSelection
{
    public static function codes(mixed $raw):array
    {
        if(!is_string($raw)||strlen($raw)>FinancePreferences::MAX_CODES*6)throw new DomainException('Höchstens 3000 vollständige Steuerkonten auswählen.',422);if($raw==='')return[];$codes=explode(',',$raw);if(count($codes)>FinancePreferences::MAX_CODES)throw new DomainException('Höchstens 3000 vollständige Steuerkonten auswählen.',422);foreach($codes as$code)if(!preg_match('/\A[0-79][0-9]{4}\z/D',$code))throw new DomainException('Vollständige fünfstellige Kontonummern durch Komma trennen.',422);return$codes;
    }
    public static function apply(PDO $db,string $actor,array $input,array $choices,string $intent):array
    {
        $input=FinancePreferenceInput::capture($input);$year=$choices['year']??null;if(!is_string($year)||!in_array($year,array_map('strval',TaxCatalog::YEARS),true))throw new DomainException('Vollständiges verfügbares Kontenjahr auswählen.',422);$codes=$intent==='clear-year'?[]:self::codes($input['data']['accounts'][$year]['codes']);$catalog=(new Finance($db,$actor))->taxAccounts((int)$year);
        if($intent==='clear-year')$codes=[];
        elseif(preg_match('/\A(add|remove):([0-79][0-9]{4})\z/D',$intent,$parts)){
            if($parts[1]==='remove')$codes=array_values(array_filter($codes,static fn(string $code):bool=>$code!==$parts[2]));else{if(!isset($catalog[$parts[2]])||!$catalog[$parts[2]]['active'])throw new DomainException('Aktuelles aktives eigenes Steuerkonto auswählen.',422);$codes[]=$parts[2];}
        }elseif(in_array($intent,['add-group','remove-group'],true)){
            $group=$choices['group']??null;if(!is_string($group)||($group!==''&&!array_key_exists($group,TaxCatalog::GROUPS)))throw new DomainException('Vollständigen verfügbaren Kontenbereich auswählen.',422);
            if($intent==='add-group'){foreach($catalog as$row)if($row['active']&&($group===''||(string)$row['category']===$group))$codes[]=(string)$row['code'];}else$codes=array_values(array_filter($codes,static fn(string $code):bool=>$group!==''&&TaxCatalog::group($code)!==$group));
        }else throw new DomainException('Gültige Finanz-Auswahlaktion auswählen.',422);
        $codes=array_values(array_unique($codes));sort($codes,SORT_STRING);if(count($codes)>FinancePreferences::MAX_CODES)throw new DomainException('Höchstens 3000 vollständige Steuerkonten auswählen.',422);$input['data']['accounts'][$year]['codes']=implode(',',$codes);return$input;
    }
}
