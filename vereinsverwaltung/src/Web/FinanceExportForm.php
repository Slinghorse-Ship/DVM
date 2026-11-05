<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use Kulturhus\Verein\Finance;

/** Preserve whole interface values; financial and DATEV services own all export rules. */
final class FinanceExportForm
{
    public const ACTIONS=['finance-export','finance-bundle','datev-preview','datev-download'];

    public static function documents(array $input,bool $requirePeriod=false):array
    {
        try{$filters=FinanceCatalogueFilters::read($input);}
        catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
        if($requirePeriod)foreach(['from','to']as$key)if(!array_key_exists($key,$filters)||self::dateInputType($filters[$key])!=='date')throw new DomainException('Den vollständigen Belegzeitraum vor dem Download prüfen.',422);
        return $filters;
    }

    public static function datev(array $input):array
    {
        $values=[];
        foreach(['from','to','advisor','client']as$key){$value=array_key_exists($key,$input)?$input[$key]:'';if(!is_string($value))throw new DomainException('Alle DATEV-Angaben vollständig als einzelne Werte prüfen.',422);$values[$key]=$value;}
        foreach(['bank_codes','excess_codes']as$key){$value=array_key_exists($key,$input)?$input[$key]:[];if(!is_array($value))throw new DomainException('Die vollständige DATEV-Kontenzuordnung prüfen.',422);foreach($value as$code)if(!is_string($code))throw new DomainException('Die vollständige DATEV-Kontenzuordnung prüfen.',422);$values[$key]=$value;}
        return $values;
    }

    /** No scalar is trimmed or replaced while the user is repairing a form. */
    public static function fields(array $input,array $defaults):array
    {
        $values=[];$unsupported=[];
        foreach($defaults as$key=>$default){if(!array_key_exists($key,$input)){$values[$key]=$default;continue;}$value=$input[$key];if(is_string($value)&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value))$values[$key]=$value;else{$values[$key]='';$unsupported[$key]=$value;}}
        return ['values'=>$values,'unsupported'=>$unsupported];
    }

    public static function dateInputType(string $value):string
    {
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)||(int)substr($value,0,4)===0)return 'text';
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date&&$date->format('Y-m-d')===$value?'date':'text';
    }

    public static function period(array $query,string $today):array
    {
        $state=self::fields($query,['from'=>substr($today,0,4).'-01-01','to'=>$today]);
        $state+=['checked'=>array_key_exists('check_export',$query),'error'=>'','rows'=>[],'checks'=>[],'preview_error'=>''];
        if($state['checked']&&$query['check_export']!=='1'){$state['error']='Die ursprüngliche Zeitraumprüfung erneut ausdrücklich anfordern.';if(!is_string($query['check_export']))$state['unsupported']['check_export']=$query['check_export'];}
        if($state['unsupported']!==[])$state['error']='Zeitraum vollständig als einzelne Datumsangaben prüfen.';
        elseif(self::dateInputType($state['values']['from'])!=='date'||self::dateInputType($state['values']['to'])!=='date')$state['error']='Ungültiges Datum.';
        elseif($state['values']['from']>$state['values']['to'])$state['error']='Von-Datum liegt nach Bis-Datum.';
        return $state;
    }

    public static function inspect(Finance $finance,array $query,string $today):array
    {
        if(!$finance->canRead())throw new DomainException('Finanzberechtigung erforderlich.',403);
        $state=self::period($query,$today);
        $selection=self::fields($query,['export_kind'=>'','export_status'=>'']);
        if($selection['unsupported']!==[]){$state['unsupported']+=$selection['unsupported'];$state['error']='Belegtyp und Status als einzelne Auswahl prüfen.';}
        elseif(!array_key_exists($selection['values']['export_kind'],[''=>'']+Finance::KINDS)||!in_array($selection['values']['export_status'],['','open','partial','reversed','paid','cancelled'],true))$state['error']='Belegtyp oder Status ist nicht verfügbar. Bitte die Auswahl ändern.';
        if(!$state['checked']||$state['error']!=='')return $state;
        try{
            $filters=$state['values']+['kind'=>$selection['values']['export_kind'],'status'=>$selection['values']['export_status']];
            $rows=$finance->entries($filters);$checks=[];
            foreach($rows as$row){
                if(in_array($row['payment_state'],['cancelled','reversed'],true))continue;
                $route=['view'=>'finance','tab'=>'receipts','entry'=>$row['id']];
                foreach($finance->positions('entry',$row['id'])as$position)if($position['sphere']===''||$position['account_code']===''){$checks[]=['label'=>$row['display_number'],'message'=>'Vereinsbereich (Sphäre) und Steuerkonto fehlen. Den tatsächlichen Zweck prüfen und die Positionen ergänzen.','route'=>$route+['edit_positions'=>1],'button'=>'Kontierung dieser Rechnung prüfen'];break;}
                if($row['remaining_cents']>0)$checks[]=['label'=>$row['display_number'],'message'=>'Noch nicht vollständig einem Kontoumsatz zugeordnet. Prüfen, ob die Rechnung noch offen ist oder eine vorhandene Zahlung zugeordnet werden muss.','route'=>$route,'button'=>'Rechnung und Zahlungen öffnen'];
            }
            $state['rows']=$rows;$state['checks']=$checks;
        }catch(DomainException $failure){$state['preview_error']=$failure->getMessage();}
        return $state;
    }
}
