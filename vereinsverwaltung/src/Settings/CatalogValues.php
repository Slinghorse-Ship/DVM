<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\{Finance,Settings,SettingsSchema,TaxCatalog};

/** Canonical tariff values retain the existing decimal and formatted-variable contracts. */
final class CatalogValues
{
    public const KINDS=['contribution','donation','service'];
    public static function normalize(string $kind,array $data):array
    {
        if(!in_array($kind,self::KINDS,true))throw new DomainException('Eigene Tarifart auswählen.',422);
        if(array_diff(array_keys($data),array_keys(Settings::catalogDefaults($kind))))throw new DomainException('Ungültiges Tariffeld.',422);
        try{
            $out=['name'=>GeneralValues::text($data['name']??'',160,true),'description'=>GeneralValues::text($data['description']??'',2000),'amount'=>Finance::decimal(Finance::cents(GeneralValues::text($data['amount']??'',30))),
                'interval'=>SettingsSchema::choice($data['interval']??'',['once','month','quarter','half','year']),'billing'=>SettingsSchema::choice($data['billing']??'current',['previous','current','next']),
                'tax_rate'=>SettingsSchema::choice($data['tax_rate']??'0',$kind==='donation'?['0']:['0','7','19']),'sphere'=>GeneralValues::text($data['sphere']??'',2),'tax_account'=>GeneralValues::text($data['tax_account']??'',6),'tags'=>GeneralValues::text($data['tags']??'',300),
                'purpose'=>GeneralValues::formatted($data['purpose']??'',500),'pricing'=>SettingsSchema::choice($data['pricing']??'fixed',['fixed','age','duration']),'tiers'=>[],'active'=>SettingsSchema::bool($data['active']??false)];
            if($out['sphere']!==''&&TaxCatalog::sphereOf($out['sphere'])==='')throw new DomainException('Sphäre prüfen.');
            if(($out['sphere']==='')!==($out['tax_account']===''))throw new DomainException('Sphäre und Steuerkonto gemeinsam auswählen.');
            $tiers=$out['pricing']==='fixed'?[]:SettingsSchema::rows($data['tiers']??[],20);
            foreach($tiers as$tier){
                if(array_diff(array_keys($tier),['from','amount']))throw new DomainException('Ungültiges Staffelfeld.');
                if(($tier['from']??'')===''&&($tier['amount']??'')==='')continue;
                $out['tiers'][]=['from'=>SettingsSchema::integer($tier['from']??'',0,150),'amount'=>Finance::decimal(Finance::cents(GeneralValues::text($tier['amount']??'',30)))];
            }
            usort($out['tiers'],static fn(array $a,array $b):int=>$a['from']<=>$b['from']);
            if($out['pricing']!=='fixed'&&(!$out['tiers']||$out['tiers'][0]['from']!==0||count(array_unique(array_column($out['tiers'],'from')))!==count($out['tiers'])))throw new DomainException('Staffel muss bei 0 beginnen; jede Alters-/Jahresgrenze nur einmal verwenden.');
            return$out;
        }catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
    }
}
