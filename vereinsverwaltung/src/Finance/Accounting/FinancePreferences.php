<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Explicit club preferences for form suggestions and visibility, never booking validation. */
final class FinancePreferences
{
    public const SECTION='finance_preferences';
    public const MAX_CODES=3000;

    /**
     * Mitgelieferte Startvorschläge für Kulturhus. Leere Werte bleiben bewusst
     * offen, wenn die Kategorie ohne den konkreten Anlass nicht rechtssicher
     * einer Sphäre zugeordnet werden kann. Jede Vorgabe bleibt änderbar.
     */
    public const STANDARD_CATEGORY_SPHERES=[
        'general'=>'',
        'membership'=>'1',
        'culture'=>'',
        'donations'=>'1',
        'rooms'=>'',
        'office'=>'1',
        'gema'=>'',
        'artist'=>'',
        'inventory'=>'3',
        'insurance'=>'',
        'postage'=>'1',
    ];

    public function __construct(private PDO $db,private string $actorId){}

    public static function defaults():array
    {
        $accounts=[];
        foreach(TaxCatalog::YEARS as $year){
            // A small, useful start list keeps the invoice form understandable;
            // the complete verified catalogue remains available and selectable.
            $accounts[$year]=['mode'=>'selection','codes'=>array_map('strval',array_keys(TaxCatalog::commonInvoiceAccounts($year)))];
        }
        $spheres=array_replace(array_fill_keys(array_keys(Finance::CATEGORIES),''),self::STANDARD_CATEGORY_SPHERES);
        return ['category_spheres'=>$spheres,'accounts'=>$accounts];
    }

    public static function normalize(array $data):array
    {
        if(array_diff(array_keys($data),['category_spheres','accounts']))throw new DomainException('Unbekannte Finanz-Vorauswahl.');
        $out=self::defaults();$spheres=$data['category_spheres']??[];$accounts=$data['accounts']??[];
        if(!is_array($spheres)||array_diff(array_keys($spheres),array_keys(Finance::CATEGORIES)))throw new DomainException('Buchungskategorien der Vorauswahl prüfen.');
        foreach($spheres as $category=>$sphere)$out['category_spheres'][$category]=SettingsSchema::choice($sphere,array_merge([''],array_map('strval',array_keys(TaxCatalog::costCenters()))));
        if(!is_array($accounts)||array_diff(array_map('strval',array_keys($accounts)),array_map('strval',TaxCatalog::YEARS)))throw new DomainException('Jahr der Steuerkonten-Auswahl prüfen.');
        foreach($accounts as $year=>$row){
            if(!is_array($row)||array_diff(array_keys($row),['mode','codes']))throw new DomainException('Steuerkonten-Auswahl prüfen.');
            $mode=SettingsSchema::choice($row['mode']??'all',['all','selection']);$codes=$row['codes']??[];
            // One bounded hidden value avoids PHP max_input_vars truncating a large chosen catalog.
            if(is_string($codes)){$codes=SettingsSchema::text($codes,self::MAX_CODES*6);$codes=$codes===''?[]:explode(',',$codes);}
            if(!is_array($codes)||!array_is_list($codes)||count($codes)>self::MAX_CODES)throw new DomainException('Zu viele oder ungültige Steuerkonten ausgewählt.');
            foreach($codes as &$code){if(!is_string($code)||!preg_match('/^[0-79][0-9]{4}$/D',$code))throw new DomainException('Fünfstellige Steuerkontonummern auswählen.');}unset($code);
            $codes=array_values(array_unique($codes));sort($codes,SORT_STRING);$out['accounts'][(int)$year]=['mode'=>$mode,'codes'=>$codes];
        }
        return $out;
    }

    /** Recover each safe posted field independently after rejection. Display only, never persistence. */
    public static function formValues(array $data,array $saved):array
    {
        $out=self::defaults();$spheres=is_array($data['category_spheres']??null)?$data['category_spheres']:[];$accounts=is_array($data['accounts']??null)?$data['accounts']:[];
        foreach($out['category_spheres'] as $category=>$_){
            $value=$spheres[$category]??'';
            // An invalid choice is left open for correction, not silently replaced by the saved choice.
            if(is_string($value)&&in_array($value,array_merge([''],array_map('strval',array_keys(TaxCatalog::costCenters()))),true))$out['category_spheres'][$category]=$value;
        }
        foreach($out['accounts'] as $year=>$_){
            if(!array_key_exists($year,$accounts))continue;
            $row=is_array($accounts[$year])?$accounts[$year]:[];
            $out['accounts'][$year]['mode']=in_array($row['mode']??null,['all','selection'],true)?$row['mode']:($saved['accounts'][$year]['mode']??'all');
            // A posted row is authoritative, including an intentionally empty
            // selection; do not append it to the supplied defaults.
            $out['accounts'][$year]['codes']=[];
            $codes=$row['codes']??[];
            if(is_string($codes))$codes=strlen($codes)<=self::MAX_CODES*6?explode(',',$codes):[];
            if(!is_array($codes)||!array_is_list($codes))$codes=[];
            foreach(array_slice($codes,0,self::MAX_CODES) as $code)if(is_string($code)&&preg_match('/^[0-79][0-9]{4}$/D',$code))$out['accounts'][$year]['codes'][]=$code;
            $out['accounts'][$year]['codes']=array_values(array_unique($out['accounts'][$year]['codes']));sort($out['accounts'][$year]['codes'],SORT_STRING);
        }
        return $out;
    }

    /** Run inside the existing versioned Settings transaction; custom accounts are tenant scoped. */
    public function validateAccounts(array $data):void
    {
        $finance=new Finance($this->db,$this->actorId);
        foreach($data['accounts'] as $year=>$row){$catalog=$finance->taxAccounts((int)$year);foreach($row['codes'] as $code)if(!isset($catalog[$code]))throw new DomainException('Steuerkonto '.$code.' ist für '.$year.' nicht im Kontenplan dieses Vereins vorhanden.');}
    }

    public function data():array
    {
        return self::normalize((new Settings($this->db,$this->actorId))->section(self::SECTION)['data']);
    }

    public function categorySphere(string $category):string
    {
        return $this->data()['category_spheres'][$category]??'';
    }

    /** Preserve caller keys and existing selections. Hiding never disables or removes an account. */
    public function visibleAccounts(array $fullAccounts,int $year,array $retainCodes=[]):array
    {
        $config=$this->data()['accounts'][$year]??['mode'=>'all','codes'=>[]];
        if($config['mode']==='all')return $fullAccounts;
        $chosen=array_fill_keys($config['codes'],true);$retain=array_fill_keys(array_map('strval',$retainCodes),true);$out=[];
        foreach($fullAccounts as $key=>$account){$code=(string)$account['code'];if(isset($chosen[$code]))$out[$key]=$account;elseif(isset($retain[$code]))$out[$key]=$account+['preference_hidden'=>true];}
        return $out;
    }
}
